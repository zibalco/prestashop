<?php
/**
 * Creates a Zibal transaction for the current cart and redirects the customer to the gateway.
 *
 * @author    zibal.ir
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ZibalpaymentPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /** @var string */
    private $zibalError = '';

    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!Validate::isLoadedObject($cart)
            || !$cart->id_customer
            || !$cart->id_address_delivery
            || !$cart->id_address_invoice
            || !$this->module->active
        ) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, array('step' => 1)));
        }

        if (!$this->isModuleAuthorized()) {
            $this->zibalError = $this->module->l('این روش پرداخت در دسترس نیست.', 'payment');

            return;
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, array('step' => 1)));
        }

        if (!$this->module->isPaymentAvailable($cart)) {
            $this->zibalError = $this->module->l('پرداخت با زیبال برای ارز یا تنظیمات فعلی فروشگاه امکان‌پذیر نیست.', 'payment');

            return;
        }

        $amount = $this->module->getRialAmount($cart);
        if ($amount === false || $amount <= 0) {
            $this->zibalError = $this->module->l('مبلغ سفارش نامعتبر است.', 'payment');

            return;
        }

        // One payment start at a time per cart (double click / two tabs must not create two charges).
        if (!$this->module->acquireCartLock($cart->id, 10)) {
            $this->zibalError = $this->module->l('درخواست پرداخت قبلی شما در حال پردازش است. لطفاً چند لحظه بعد دوباره تلاش کنید.', 'payment');

            return;
        }

        $redirectUrl = $this->startPayment($cart, $amount);
        $this->module->releaseCartLock($cart->id);

        if ($redirectUrl !== '') {
            Tools::redirect($redirectUrl);
        }
    }

    /**
     * @return string URL to redirect to (Zibal gateway, or the callback of an earlier paid attempt), '' on error
     */
    private function startPayment(Cart $cart, $amount)
    {
        // An earlier attempt for this cart may already hold the customer's money (verified but no order,
        // or paid at Zibal but the customer never came back). Finish that one instead of charging again.
        $recoverTrackId = $this->findPaidOpenTransaction($cart);
        if ($recoverTrackId === null) {
            // Could not ask Zibal about an earlier attempt: do not risk charging the customer twice.
            $this->zibalError = $this->module->l('وضعیت پرداخت قبلی شما در حال بررسی است. لطفاً چند دقیقه بعد دوباره تلاش کنید؛ اگر وجهی از حساب شما کسر شده، نیازی به پرداخت دوباره نیست.', 'payment');

            return '';
        }
        if ($recoverTrackId !== '') {
            $this->module->log('resuming paid transaction instead of a new request, trackId=' . $recoverTrackId, 2, $cart->id);
            return $this->module->getCallbackUrl($cart, array('trackId' => $recoverTrackId));
        }

        $merchant = $this->module->getMerchant();
        $zibalOrderId = $this->module->buildZibalOrderId($cart);
        $data = array(
            'merchant' => $merchant,
            'amount' => $amount,
            'callbackUrl' => $this->module->getCallbackUrl($cart),
            'orderId' => $zibalOrderId,
            'description' => Tools::substr(Configuration::get('PS_SHOP_NAME') . ' - ' . $this->module->l('سبد', 'payment') . ' #' . (int) $cart->id, 0, 255),
        );

        $mobile = $this->getCustomerMobile($cart);
        if ($mobile) {
            $data['mobile'] = $mobile;
        }

        $response = $this->module->callZibal('request', $data);

        if ($response === null) {
            $this->module->saveFailedRequest($cart, $zibalOrderId, $merchant, $amount, $this->module->getLastApiError());
            $this->zibalError = $this->module->l('ارتباط با درگاه پرداخت برقرار نشد. لطفاً دوباره تلاش کنید.', 'payment');

            return '';
        }

        $trackId = isset($response['trackId']) ? (string) $response['trackId'] : '';

        if ((int) $response['result'] !== 100 || !preg_match('/^\d{1,20}$/', $trackId)) {
            $this->module->saveFailedRequest($cart, $zibalOrderId, $merchant, $amount, $this->module->describeZibalResponse($response));
            $this->module->log('request failed, result=' . (int) $response['result'], 2, $cart->id);
            $this->zibalError = $this->module->l('خطا در ایجاد تراکنش:', 'payment') . ' ' . $this->module->getResultMessage($response['result']);

            return '';
        }

        // Bind trackId to this cart and the exact amount BEFORE redirecting; the callback only accepts stored trackIds.
        $cartTotal = (float) $cart->getOrderTotal(true, Cart::BOTH);
        if (!$this->module->saveTransaction($cart, $trackId, $zibalOrderId, $merchant, $amount, $cartTotal)) {
            $this->module->log('could not store transaction, trackId=' . $trackId, 3, $cart->id);
            $this->zibalError = $this->module->l('ذخیره اطلاعات تراکنش ممکن نشد. لطفاً دوباره تلاش کنید.', 'payment');

            return '';
        }

        return Zibalpayment::START_URL . $trackId;
    }

    public function initContent()
    {
        parent::initContent();

        $this->context->smarty->assign(array(
            'zibal_error' => $this->zibalError ? $this->zibalError : $this->module->l('خطای ناشناخته.', 'payment'),
            'zibal_retry_url' => $this->context->link->getPageLink('order', true),
        ));

        $this->setTemplate('module:zibalpayment/views/templates/front/payment_error.tpl');
    }

    /**
     * @return string|null trackId of an earlier attempt that is (or may be) paid,
     *                     '' if a new payment is safe, null if Zibal could not be asked (do not start a new payment)
     */
    private function findPaidOpenTransaction(Cart $cart)
    {
        // At most 3 recent attempts, 8 s per inquiry: the "Pay" button never hangs for long.
        foreach ($this->module->getOpenTransactions($cart->id, 3) as $row) {
            // Already verified, or "already verified" at Zibal but not yet confirmed locally.
            if ($row['status'] === Zibalpayment::TX_VERIFIED || $row['status'] === Zibalpayment::TX_INQUIRY) {
                return (string) $row['track_id'];
            }

            // Pending: ask Zibal whether the customer actually paid it (1 = paid & verified, 2 = paid, not verified).
            $inquiry = $this->module->callZibal('inquiry', array(
                'merchant' => $this->module->getTransactionMerchant($row),
                'trackId' => (string) $row['track_id'],
            ), 8);
            if ($inquiry === null) {
                $this->module->recordTransactionError($row['id_zibal_transaction'], 'inquiry before new payment', $this->module->getLastApiError());

                return null;
            }
            if ((int) $inquiry['result'] !== 100) {
                // Zibal answered but with an error (-1 internal error, config errors, unknown codes...):
                // the earlier attempt may still be paid, so treat it exactly like "no answer".
                $this->module->recordTransactionError($row['id_zibal_transaction'], 'inquiry before new payment', $this->module->describeZibalResponse($inquiry));

                return null;
            }
            if (isset($inquiry['status'])) {
                if (in_array((int) $inquiry['status'], array(1, 2), true)) {
                    return (string) $row['track_id'];
                }
                // Definitively not paid (cancelled, card/bank errors, refunded...): close it so it is not asked again.
                // -1 (waiting for payment) and -2 (internal error) stay pending.
                if ((int) $inquiry['status'] >= 3) {
                    $this->module->updateTransaction($row['id_zibal_transaction'], array('status' => Zibalpayment::TX_FAILED));
                }
            }
        }

        return '';
    }

    private function isModuleAuthorized()
    {
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->module->name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mobile number in 09XXXXXXXXX format, used by Zibal for card-binding features (optional).
     */
    private function getCustomerMobile(Cart $cart)
    {
        $address = new Address((int) $cart->id_address_invoice);
        if (!Validate::isLoadedObject($address)) {
            return '';
        }

        foreach (array($address->phone_mobile, $address->phone) as $phone) {
            $digits = preg_replace('/\D+/', '', (string) $phone);
            if (strpos($digits, '98') === 0 && strlen($digits) === 12) {
                $digits = '0' . substr($digits, 2);
            }
            if (preg_match('/^09\d{9}$/', $digits)) {
                return $digits;
            }
        }

        return '';
    }
}
