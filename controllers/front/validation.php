<?php
/**
 * Zibal callback: verifies the transaction and creates the order.
 *
 * The cart is loaded from the signed callback URL (not from the browser cookie),
 * so the payment is not lost if the customer returns in another browser.
 *
 * @author    zibal.ir
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ZibalpaymentValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /** @var string */
    private $zibalError = '';

    public function postProcess()
    {
        $idCart = (int) Tools::getValue('cart_id');
        $key = (string) Tools::getValue('key');
        $trackId = (string) Tools::getValue('trackId');
        $status = Tools::getValue('status', null);

        if (!preg_match('/^\d{1,20}$/', $trackId)) {
            $trackId = '';
        }

        $cart = new Cart($idCart);
        if (!$idCart || !Validate::isLoadedObject($cart) || !$this->module->isValidCallbackKey($cart, $key)) {
            $this->zibalError = $this->module->l('درخواست بازگشت از درگاه نامعتبر است.', 'validation');

            return;
        }

        if (!$this->module->active) {
            $this->zibalError = $this->module->l('این روش پرداخت در دسترس نیست.', 'validation');

            return;
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->zibalError = $this->module->l('مشتری این سفارش یافت نشد.', 'validation');

            return;
        }

        if (!$this->module->acquireCartLock($cart->id)) {
            $this->zibalError = $this->module->l('سفارش شما در حال پردازش است. لطفاً چند لحظه بعد صفحه را بارگذاری مجدد کنید.', 'validation');

            return;
        }

        // Order already created (page refresh, double callback): just show the confirmation.
        $idOrder = $this->module->getOrderIdByCart($cart->id);
        if ($idOrder) {
            $this->module->releaseCartLock($cart->id);
            Tools::redirect($this->module->getOrderConfirmationUrl($cart, $idOrder));
        }

        if ($trackId === '') {
            $this->module->releaseCartLock($cart->id);
            $statusMessage = $this->module->getStatusMessage($status);
            $this->zibalError = $statusMessage ? $statusMessage : $this->module->l('پرداخت انجام نشد.', 'validation');

            return;
        }

        // The trackId must be one we stored for THIS cart when the request was sent.
        $stored = $this->module->getTransaction($trackId);
        if (!$stored || (int) $stored['id_cart'] !== (int) $cart->id) {
            $this->module->releaseCartLock($cart->id);
            $this->module->log('trackId not bound to this cart, trackId=' . $trackId, 4, $cart->id);
            $this->zibalError = $this->module->l('این تراکنش متعلق به این سبد خرید نیست.', 'validation');

            return;
        }

        $transaction = $this->verifyTransaction($trackId, $cart, $stored);
        if ($transaction === null) {
            $this->module->releaseCartLock($cart->id);

            return; // $this->zibalError is already set
        }

        $this->createOrder($cart, $customer, $trackId, $transaction, $stored);
    }

    /**
     * Verifies the transaction; on "already verified" (201) falls back to inquiry so a
     * verified-but-not-recorded payment (e.g. crash after verify) can still create its order.
     *
     * @return array|null
     */
    private function verifyTransaction($trackId, Cart $cart, array $stored)
    {
        // Always the merchant the transaction was created with, even if the setting changed since.
        $data = array(
            'merchant' => $this->module->getTransactionMerchant($stored),
            'trackId' => $trackId,
        );

        $alreadyVerified = in_array(
            (string) $stored['status'],
            array(Zibalpayment::TX_VERIFIED, Zibalpayment::TX_INQUIRY),
            true
        );

        if ($alreadyVerified) {
            // verify already succeeded earlier for this trackId: only an inquiry can confirm it now.
            $verify = array('result' => 201);
        } else {
            $verify = $this->module->callZibal('verify', $data);
            if ($verify === null) {
                // Network ambiguity: the row stays "pending" and a reload retries safely.
                $this->module->recordTransactionError($stored['id_zibal_transaction'], 'verify', $this->module->getLastApiError());
                $this->zibalError = $this->module->l('ارتباط با درگاه برای تایید پرداخت برقرار نشد. لطفاً چند لحظه بعد همین صفحه را بارگذاری مجدد کنید؛ نیازی به پرداخت دوباره نیست. اگر مشکل ادامه داشت، با پشتیبانی فروشگاه تماس بگیرید.', 'validation');

                return null;
            }
        }

        $result = (int) $verify['result'];

        if ($result === 201) {
            $inquiry = $this->module->callZibal('inquiry', $data);
            if ($inquiry !== null && (int) $inquiry['result'] === 100 && isset($inquiry['status']) && (int) $inquiry['status'] === 1) {
                $verify = $inquiry;
                $result = 100;
            } elseif ($inquiry !== null && (int) $inquiry['result'] === 100 && isset($inquiry['status'])
                && Zibalpayment::isRefundedStatus($inquiry['status'])
            ) {
                // The money already went back to the payer: this attempt is over, a new payment is allowed.
                $this->module->updateTransaction($stored['id_zibal_transaction'], array('status' => Zibalpayment::TX_FAILED));
                $this->module->recordTransactionError($stored['id_zibal_transaction'], 'inquiry after 201', 'refunded: ' . $this->module->describeZibalResponse($inquiry));
                $this->zibalError = $this->module->l('این پرداخت به کارت شما بازگشت داده شده است. در صورت تمایل دوباره پرداخت کنید.', 'validation');

                return null;
            } else {
                // Zibal says the money is already settled (201) but we could not confirm the details:
                // park it in "inquiry" (never "failed"); a reload or a new payment attempt retries the inquiry.
                $this->module->updateTransaction($stored['id_zibal_transaction'], array('status' => Zibalpayment::TX_INQUIRY));
                $this->module->recordTransactionError(
                    $stored['id_zibal_transaction'],
                    'inquiry after 201',
                    $inquiry === null ? $this->module->getLastApiError() : $this->module->describeZibalResponse($inquiry)
                );
                $this->module->log(
                    'already verified (201) but inquiry not confirmed, trackId=' . $trackId
                    . ', inquiry=' . ($inquiry === null ? 'no response' : 'result ' . (int) $inquiry['result']
                    . ', status ' . (isset($inquiry['status']) ? (int) $inquiry['status'] : '-')),
                    3,
                    $cart->id
                );
                $this->zibalError = $this->module->l('پرداخت شما در زیبال تأیید شده است ولی استعلام نهایی هنوز انجام نشد. لطفاً چند دقیقه بعد همین صفحه را بارگذاری مجدد کنید؛ نیازی به پرداخت دوباره نیست.', 'validation');

                return null;
            }
        }

        if (Zibalpayment::isConfigErrorResult($result)) {
            // Shop misconfiguration (merchant / server IP), not a payment failure: keep the row "pending"
            // so a reload after the merchant fixes it can still verify the customer's payment.
            $this->module->recordTransactionError($stored['id_zibal_transaction'], 'verify', $this->module->describeZibalResponse($verify));
            $this->module->log('verify rejected by gateway configuration, trackId=' . $trackId . ', result=' . $result, 4, $cart->id);
            $this->zibalError = $this->module->l('به دلیل مشکل در تنظیمات درگاه فروشگاه، پرداخت شما هنوز تأیید نشده است. لطفاً با پشتیبانی فروشگاه تماس بگیرید و دوباره پرداخت نکنید.', 'validation');

            return null;
        }

        if ($result !== 100) {
            $verifyAnswer = $this->module->describeZibalResponse($verify);

            if ($result === 202) {
                // 202 = "not paid / failed". Confirm with an inquiry before closing the attempt: only a
                // definitive "not paid" status (>= 3: cancelled, card/bank error, refunded...) marks it failed.
                $inquiry = $this->module->callZibal('inquiry', $data);
                if ($inquiry !== null && (int) $inquiry['result'] === 100 && isset($inquiry['status'])
                    && (int) $inquiry['status'] >= 3
                ) {
                    $this->module->updateTransaction($stored['id_zibal_transaction'], array('status' => Zibalpayment::TX_FAILED));
                    $this->module->recordTransactionError(
                        $stored['id_zibal_transaction'],
                        'verify',
                        $verifyAnswer . ' | inquiry: ' . $this->module->describeZibalResponse($inquiry)
                    );
                    $statusMessage = $this->module->getStatusMessage(Tools::getValue('status', null));
                    $this->zibalError = $this->module->getResultMessage($result) . ($statusMessage ? ' (' . $statusMessage . ')' : '');
                    $this->module->log('verify failed, trackId=' . $trackId . ', result=' . $result . ', inquiry status=' . (int) $inquiry['status'], 2, $cart->id);

                    return null;
                }
                // Inquiry did not confirm the failure (no answer, error, or a not-final status): treat as ambiguous.
                $verifyAnswer .= ' | inquiry: ' . ($inquiry === null
                    ? $this->module->getLastApiError()
                    : $this->module->describeZibalResponse($inquiry));
            }

            // Ambiguous answer (-1 internal error, -2, unknown codes, unconfirmed 202): the customer may have paid.
            // Same as a network error: keep the row "pending" (listed for the merchant via its error log)
            // so a reload retries verify safely, and never invite the customer to pay again.
            $this->module->recordTransactionError($stored['id_zibal_transaction'], 'verify', $verifyAnswer);
            $this->module->log('verify not confirmed, kept pending, trackId=' . $trackId . ', result=' . $result, 3, $cart->id);
            $this->zibalError = $this->module->l('تایید پرداخت از درگاه هنوز انجام نشد. لطفاً چند لحظه بعد همین صفحه را بارگذاری مجدد کنید؛ نیازی به پرداخت دوباره نیست. اگر مشکل ادامه داشت، با پشتیبانی فروشگاه تماس بگیرید.', 'validation');

            return null;
        }

        if (!isset($verify['amount'])) {
            $this->module->recordTransactionError(
                $stored['id_zibal_transaction'],
                'verify',
                'response without amount: ' . $this->module->describeZibalResponse($verify)
            );
            $this->zibalError = $this->module->l('پاسخ درگاه نامعتبر است.', 'validation');
            $this->module->log('verify response without amount, trackId=' . $trackId, 3, $cart->id);

            return null;
        }

        if (isset($verify['orderId']) && (string) $verify['orderId'] !== ''
            && (string) $verify['orderId'] !== (string) $stored['zibal_order_id']
        ) {
            $this->module->recordTransactionError(
                $stored['id_zibal_transaction'],
                'verify',
                'orderId mismatch: Zibal returned ' . (string) $verify['orderId'] . ', expected ' . $stored['zibal_order_id']
            );
            $this->zibalError = $this->module->l('این تراکنش متعلق به این سبد خرید نیست.', 'validation');
            $this->module->log('orderId mismatch, trackId=' . $trackId . ', orderId=' . $verify['orderId'], 4, $cart->id);

            return null;
        }

        return $verify;
    }

    private function createOrder(Cart $cart, Customer $customer, $trackId, array $transaction, array $stored)
    {
        // Compute totals as the cart owner, exactly like validateOrder() will.
        $previousContext = $this->module->applyCartContext($cart, $customer);

        $currency = new Currency((int) $cart->id_currency);
        $cartTotal = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $expectedRial = $this->module->getRialAmount($cart);
        $storedRial = (int) $stored['amount'];
        $paidRial = (int) $transaction['amount'];

        // Two checks: Zibal paid exactly what we requested for this trackId,
        // and the cart was not changed after the payment started.
        $amountMatches = $paidRial === $storedRial && $expectedRial !== false && $expectedRial === $storedRial;

        if ($amountMatches) {
            $amountPaid = $cartTotal;
        } else {
            // Money was taken but does not match: PrestaShop records the order
            // in the "Payment error" state, so the merchant can see and resolve it.
            $amountPaid = $this->module->fromRial($paidRial, $currency);
            if ($amountPaid === false) {
                $amountPaid = 0.0; // cart currency is no longer Rial/Toman; the order is recorded as a payment error
            }
            $mismatch = 'amount mismatch: paid ' . $paidRial . ' IRR, requested ' . $storedRial
                . ' IRR, current cart ' . ($expectedRial === false ? 'unsupported currency' : $expectedRial . ' IRR');
            $this->module->recordTransactionError($stored['id_zibal_transaction'], 'amount check', $mismatch);
            $this->module->log($mismatch . ', trackId=' . $trackId, 3, $cart->id);
        }

        $refNumber = !empty($transaction['refNumber']) ? Tools::substr((string) $transaction['refNumber'], 0, 64) : '';
        $this->module->updateTransaction($stored['id_zibal_transaction'], array(
            'status' => Zibalpayment::TX_VERIFIED,
            'ref_number' => $refNumber,
        ));

        // Private order message, e.g. "Zibal trackId: 3714061657 | refNumber: R1 | card: 62741****44 | amount: 1250000 IRR"
        $message = 'Zibal trackId: ' . $trackId;
        if (!empty($transaction['refNumber'])) {
            $message .= ' | refNumber: ' . $this->module->cleanForMessage($transaction['refNumber'], 64);
        }
        // Always present; "-" when Zibal returns no card (e.g. the test merchant).
        $card = !empty($transaction['cardNumber']) ? $this->module->cleanForMessage($transaction['cardNumber'], 32) : '';
        $message .= ' | card: ' . ($card !== '' ? $card : '-');
        $message .= ' | amount: ' . $paidRial . ' IRR';

        try {
            $this->module->validateOrder(
                (int) $cart->id,
                (int) Configuration::get('PS_OS_PAYMENT'),
                $amountPaid,
                $this->module->displayName,
                $message,
                array('transaction_id' => $trackId),
                (int) $currency->id,
                false,
                $cart->secure_key
            );
        } catch (Exception $e) {
            $this->module->restoreContext($previousContext);
            $this->handleOrderFailure($cart, $trackId, $stored, $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->module->restoreContext($previousContext);
            $this->handleOrderFailure($cart, $trackId, $stored, $e->getMessage());

            return;
        }

        $idOrder = $this->module->currentOrder ? (int) $this->module->currentOrder : $this->module->getOrderIdByCart($cart->id);
        $this->module->updateTransaction($stored['id_zibal_transaction'], array(
            'status' => $amountMatches ? Zibalpayment::TX_PAID : Zibalpayment::TX_MISMATCH,
            'id_order' => (int) $idOrder,
        ));

        // Every error of every Zibal attempt for this cart (raw cURL text, Zibal result/message/status)
        // is shown on the order as private messages, next to the success line above.
        $this->module->attachErrorHistoryToOrder($idOrder, $cart);

        $this->module->releaseCartLock($cart->id);

        Tools::redirect($this->module->getOrderConfirmationUrl($cart, $idOrder));
    }

    private function handleOrderFailure(Cart $cart, $trackId, array $stored, $error)
    {
        $this->module->recordTransactionError($stored['id_zibal_transaction'], 'validateOrder', $error);
        $this->module->releaseCartLock($cart->id);
        $this->module->log('validateOrder failed after successful payment, trackId=' . $trackId . ': ' . $error, 4, $cart->id);

        $this->zibalError = $this->module->l('پرداخت شما با موفقیت انجام شد اما ثبت سفارش با خطا مواجه شد. لطفاً با پشتیبانی فروشگاه تماس بگیرید.', 'validation');
    }

    public function initContent()
    {
        parent::initContent();

        $this->context->smarty->assign(array(
            'zibal_error' => $this->zibalError ? $this->zibalError : $this->module->l('خطای ناشناخته.', 'validation'),
            'zibal_retry_url' => $this->context->link->getPageLink('order', true),
        ));

        $this->setTemplate('module:zibalpayment/views/templates/front/payment_error.tpl');
    }
}
