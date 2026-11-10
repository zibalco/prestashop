<?php
/**
 * Zibal payment gateway for PrestaShop 1.7.0 – 9.x
 *
 * Compatibility notes:
 *  - Syntax is kept PHP 5.4 compatible (PrestaShop 1.7.0 minimum) and is
 *    free of PHP 8.x deprecations (no dynamic properties, no implicit nullables).
 *  - Only core APIs present in every version from 1.7.0.0 to 9.x are used.
 *
 * @author    zibal.ir
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Zibalpayment extends PaymentModule
{
    const API_BASE = 'https://gateway.zibal.ir/v1/';
    const START_URL = 'https://gateway.zibal.ir/start/';

    const TX_TABLE = 'zibal_transaction';

    /** Transaction states */
    const TX_PENDING = 'pending';
    const TX_VERIFIED = 'verified';
    const TX_PAID = 'paid';
    const TX_MISMATCH = 'mismatch';
    const TX_FAILED = 'failed';
    /** Zibal says "already verified" (201) but it could not be confirmed yet: retried with inquiry, never marked failed. */
    const TX_INQUIRY = 'inquiry';

    /** PrestaShop 1.7.0 limits Message::$message to 1600 characters. */
    const ORDER_MESSAGE_MAX = 1600;
    const ERROR_LOG_MAX = 4000;

    /** Largest Zibal response we accept (bytes). */
    const MAX_RESPONSE_BYTES = 65536;

    /** Seconds a callback waits for another callback of the same cart (longer than verify + inquiry together). */
    const LOCK_WAIT = 50;

    const CONF_MERCHANT = 'ZIBAL_MERCHANT';
    const CONF_CURRENCY_UNIT = 'ZIBAL_CURRENCY_UNIT';

    /** Currency unit modes */
    const UNIT_AUTO = 'auto';
    const UNIT_RIAL = 'IRR';
    const UNIT_TOMAN = 'IRT';

    /** @var string raw transport/parse error of the last callZibal() (cURL text, HTTP code, body excerpt) */
    protected $lastApiError = '';

    /**
     * Verify/inquiry results that mean "the shop is misconfigured" (merchant not found / inactive / invalid,
     * server IP not registered) — NOT that the customer's payment failed.
     */
    public static function isConfigErrorResult($result)
    {
        return in_array((int) $result, array(102, 103, 104, 115), true);
    }

    /** Zibal transaction statuses meaning the money went back to the payer (refunded / being refunded / reversed). */
    public static function isRefundedStatus($status)
    {
        return in_array((int) $status, array(15, 16, 18), true);
    }

    public function __construct()
    {
        $this->name = 'zibalpayment';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.0';
        $this->author = 'zibal.ir';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->controllers = array('payment', 'validation');
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('درگاه پرداخت زیبال');
        $this->description = $this->l('پرداخت آنلاین توسط درگاه پرداخت زیبال');
        $this->confirmUninstall = $this->l('آیا از حذف این ماژول مطمئن هستید؟');

        if (!$this->getMerchant()) {
            $this->warning = $this->l('شناسه درگاه (مرچنت) زیبال تنظیم نشده است.');
        }

        if ($this->id) {
            $currencies = Currency::checkPaymentCurrencies($this->id);
            if (!is_array($currencies) || !count($currencies)) {
                $this->warning = $this->l('هیچ ارزی برای این ماژول فعال نشده است.');
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Install / uninstall                                                 */
    /* ------------------------------------------------------------------ */

    public function install()
    {
        if (!extension_loaded('curl')) {
            $this->_errors[] = $this->l('افزونه cURL در PHP سرور فعال نیست.');

            return false;
        }

        return parent::install()
            && self::installTransactionTable()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayPaymentReturn')
            && Configuration::updateValue(self::CONF_CURRENCY_UNIT, self::UNIT_AUTO);
    }

    public function uninstall()
    {
        Configuration::deleteByName(self::CONF_MERCHANT);
        Configuration::deleteByName(self::CONF_CURRENCY_UNIT);
        Configuration::deleteByName('ZIBAL_HASHKEY');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TX_TABLE . '`');

        return parent::uninstall();
    }

    /**
     * Stores every Zibal attempt: one row per trackId, bound to one cart and the exact Rial amount requested.
     * Static so the upgrade script can call it.
     */
    public static function installTransactionTable()
    {
        $engine = defined('_MYSQL_ENGINE_') ? _MYSQL_ENGINE_ : 'InnoDB';

        return (bool) Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TX_TABLE . '` (
                `id_zibal_transaction` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_cart` INT UNSIGNED NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_currency` INT UNSIGNED NOT NULL,
                `track_id` VARCHAR(32) NULL DEFAULT NULL,
                `zibal_order_id` VARCHAR(64) NOT NULL,
                `merchant` VARCHAR(64) NOT NULL,
                `amount` BIGINT UNSIGNED NOT NULL,
                `cart_total` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL,
                `id_order` INT UNSIGNED NULL DEFAULT NULL,
                `ref_number` VARCHAR(64) NULL DEFAULT NULL,
                `error_log` TEXT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_zibal_transaction`),
                UNIQUE KEY `track_id` (`track_id`),
                KEY `id_cart` (`id_cart`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Back office configuration                                           */
    /* ------------------------------------------------------------------ */

    /** Module configuration page URL (works the same on 1.7.0 – 9.x). */
    protected function getConfigUrl(array $params = array())
    {
        return AdminController::$currentIndex
            . '&configure=' . urlencode($this->name)
            . '&token=' . Tools::getAdminTokenLite('AdminModules')
            . ($params ? '&' . http_build_query($params) : '');
    }

    public function getContent()
    {
        $output = '';

        if (Tools::getValue('zibalInquiry')) {
            $output .= $this->processAdminInquiry((int) Tools::getValue('zibalInquiry'));
        }

        if (Tools::isSubmit('submitZibalpayment')) {
            $merchant = trim((string) Tools::getValue(self::CONF_MERCHANT));
            $unit = (string) Tools::getValue(self::CONF_CURRENCY_UNIT);
            $errors = array();

            if ($merchant === '') {
                $errors[] = $this->l('شناسه درگاه (مرچنت) را وارد کنید.');
            } elseif (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $merchant)) {
                $errors[] = $this->l('شناسه درگاه (مرچنت) نامعتبر است.');
            }

            if (!in_array($unit, array(self::UNIT_AUTO, self::UNIT_RIAL, self::UNIT_TOMAN), true)) {
                $errors[] = $this->l('واحد پول نامعتبر است.');
            }

            if (count($errors)) {
                foreach ($errors as $error) {
                    $output .= $this->displayError($error);
                }
            } else {
                Configuration::updateValue(self::CONF_MERCHANT, $merchant);
                Configuration::updateValue(self::CONF_CURRENCY_UNIT, $unit);
                $output .= $this->displayConfirmation($this->l('تنظیمات ذخیره شد.'));
            }
        }

        return $output . $this->renderForm() . $this->renderTransactionList();
    }

    /**
     * "Re-inquiry" button: asks Zibal for the current state of one stored transaction and shows the raw answer.
     * Only a refund/reversal changes the local state (to "failed"); creating an order stays a manual decision.
     */
    protected function processAdminInquiry($idTransaction)
    {
        $row = $this->getTransactionById($idTransaction);
        if (!$row || empty($row['track_id'])) {
            return $this->displayError($this->l('تراکنش یافت نشد.'));
        }

        $response = $this->callZibal('inquiry', array(
            'merchant' => $this->getTransactionMerchant($row),
            'trackId' => (string) $row['track_id'],
        ));

        if ($response === null) {
            $error = $this->getLastApiError();
            $this->recordTransactionError($row['id_zibal_transaction'], 'admin inquiry', $error);

            return $this->displayError($this->l('استعلام از زیبال انجام نشد:') . ' ' . $error);
        }

        $answer = $this->describeZibalResponse($response);
        if (isset($response['amount'])) {
            $answer .= ' | amount: ' . (int) $response['amount'] . ' IRR';
        }
        if (isset($response['orderId']) && is_scalar($response['orderId'])) {
            $answer .= ' | orderId: ' . (string) $response['orderId'];
        }

        if ((int) $response['result'] === 100 && isset($response['status']) && self::isRefundedStatus($response['status'])
            && empty($row['id_order'])
        ) {
            $this->updateTransaction($row['id_zibal_transaction'], array('status' => self::TX_FAILED));
            $this->recordTransactionError($row['id_zibal_transaction'], 'admin inquiry', 'refunded: ' . $answer);
        }

        return $this->displayConfirmation(
            $this->l('پاسخ زیبال برای trackId') . ' ' . $row['track_id'] . ': ' . $this->cleanForMessage($answer, 600)
        );
    }

    protected function renderTransactionList()
    {
        $rows = $this->getTransactionsNeedingAttention();
        foreach ($rows as &$row) {
            $lines = $row['error_log'] ? explode("\n", (string) $row['error_log']) : array();
            $row['last_error'] = $lines ? end($lines) : '';
            $row['inquiry_url'] = $this->getConfigUrl(array('zibalInquiry' => (int) $row['id_zibal_transaction']));
        }
        unset($row);

        $this->context->smarty->assign(array('zibal_transactions' => $rows));

        return $this->display(__FILE__, 'views/templates/admin/transactions.tpl');
    }

    protected function renderForm()
    {
        $form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('تنظیمات درگاه زیبال'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('شناسه درگاه (مرچنت)'),
                        'name' => self::CONF_MERCHANT,
                        'required' => true,
                        'desc' => $this->l('برای تست از مقدار zibal استفاده کنید.'),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('واحد پول ارز ایران در فروشگاه'),
                        'name' => self::CONF_CURRENCY_UNIT,
                        'desc' => $this->l('این درگاه فقط برای ارزهای ریال و تومان (کد IRR، IRT، TOM یا TMN) نمایش داده می‌شود. اگر ارز را با کد IRR ساخته‌اید ولی قیمت‌ها به تومان است، «تومان» را انتخاب کنید.'),
                        'options' => array(
                            'query' => array(
                                array('id' => self::UNIT_AUTO, 'name' => $this->l('خودکار (IRR = ریال، IRT = تومان)')),
                                array('id' => self::UNIT_RIAL, 'name' => $this->l('ریال')),
                                array('id' => self::UNIT_TOMAN, 'name' => $this->l('تومان')),
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('ذخیره'),
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = 'id_module';
        $helper->submit_action = 'submitZibalpayment';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->currentIndex = AdminController::$currentIndex
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => array(
                self::CONF_MERCHANT => Tools::getValue(self::CONF_MERCHANT, $this->getMerchant()),
                self::CONF_CURRENCY_UNIT => Tools::getValue(self::CONF_CURRENCY_UNIT, $this->getCurrencyUnitSetting()),
            ),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($form));
    }

    /* ------------------------------------------------------------------ */
    /* Hooks                                                               */
    /* ------------------------------------------------------------------ */

    public function hookPaymentOptions($params)
    {
        if (!$this->active || empty($params['cart'])) {
            return array();
        }

        $cart = $params['cart'];
        if (!$this->isPaymentAvailable($cart)) {
            return array();
        }

        $this->context->smarty->assign(array(
            'zibal_logo' => $this->_path . 'zibal.png',
        ));

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->l('پرداخت آنلاین با زیبال'))
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true))
            ->setAdditionalInformation($this->fetch('module:' . $this->name . '/views/templates/hook/payment_info.tpl'));

        return array($option);
    }

    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active || empty($params['order'])) {
            return '';
        }

        $order = $params['order'];
        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $this->context->smarty->assign(array(
            'zibal_reference' => $order->reference,
            'zibal_is_paid' => $order->hasBeenPaid() > 0,
        ));

        return $this->fetch('module:' . $this->name . '/views/templates/hook/payment_return.tpl');
    }

    /* ------------------------------------------------------------------ */
    /* Configuration helpers                                               */
    /* ------------------------------------------------------------------ */

    public function getMerchant()
    {
        return trim((string) Configuration::get(self::CONF_MERCHANT));
    }

    public function getCurrencyUnitSetting()
    {
        $unit = (string) Configuration::get(self::CONF_CURRENCY_UNIT);

        return in_array($unit, array(self::UNIT_AUTO, self::UNIT_RIAL, self::UNIT_TOMAN), true) ? $unit : self::UNIT_AUTO;
    }

    public function isPaymentAvailable(Cart $cart)
    {
        if (!$this->getMerchant()) {
            return false;
        }

        $currency = new Currency((int) $cart->id_currency);
        if (!Validate::isLoadedObject($currency) || !$this->isSupportedCurrency($currency)) {
            return false;
        }

        $allowed = $this->getCurrency((int) $cart->id_currency);
        $isAllowed = false;
        if (is_array($allowed)) {
            foreach ($allowed as $row) {
                if ((int) $row['id_currency'] === (int) $currency->id) {
                    $isAllowed = true;
                    break;
                }
            }
        }

        return $isAllowed && $this->getRialAmount($cart) !== false;
    }

    /* ------------------------------------------------------------------ */
    /* Amount / currency                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Rial multiplier for an Iranian currency, or false if the currency is not Iranian.
     */
    protected function getIranianFactor(Currency $currency)
    {
        $iso = strtoupper((string) $currency->iso_code);
        if (!in_array($iso, array('IRR', 'IRT', 'TOM', 'TMN'), true)) {
            return false;
        }

        $setting = $this->getCurrencyUnitSetting();
        if ($setting === self::UNIT_RIAL) {
            return 1;
        }
        if ($setting === self::UNIT_TOMAN) {
            return 10;
        }

        return $iso === 'IRR' ? 1 : 10;
    }

    /**
     * Only Rial and Toman are supported. Any other currency returns false and the
     * payment option is hidden; there is no exchange-rate conversion.
     */
    public function isSupportedCurrency(Currency $currency)
    {
        return $this->getIranianFactor($currency) !== false;
    }

    /**
     * Converts an amount in the cart currency (Rial or Toman) to Rial (int).
     *
     * @return int|false false for any other currency
     */
    public function toRial($amount, Currency $currency)
    {
        $factor = $this->getIranianFactor($currency);
        if ($factor === false) {
            return false;
        }

        $rial = round((float) $amount * $factor);
        if ($rial > PHP_INT_MAX) {
            return false; // 32-bit PHP cannot represent it (Zibal accepts up to 4,000,000,000 IRR)
        }

        return (int) $rial;
    }

    /**
     * Converts a Rial amount back to the cart currency (Rial or Toman).
     *
     * @return float|false false for any other currency
     */
    public function fromRial($rial, Currency $currency)
    {
        $factor = $this->getIranianFactor($currency);
        if ($factor === false) {
            return false;
        }

        return (float) $rial / $factor;
    }

    /**
     * Puts the cart owner's cart/customer/currency/language into the context, so totals
     * computed on the callback match what validateOrder() computes (the customer may
     * return in another browser whose context currency/precision differs).
     *
     * @return array previous values, for restoreContext()
     */
    public function applyCartContext(Cart $cart, Customer $customer)
    {
        $previous = array(
            'cart' => $this->context->cart,
            'customer' => $this->context->customer,
            'currency' => $this->context->currency,
            'language' => $this->context->language,
        );

        $this->context->cart = $cart;
        $this->context->customer = $customer;
        $this->context->currency = new Currency((int) $cart->id_currency, null, (int) $cart->id_shop);
        $language = new Language((int) $cart->id_lang);
        if (Validate::isLoadedObject($language)) {
            $this->context->language = $language;
        }

        return $previous;
    }

    public function restoreContext(array $previous)
    {
        foreach ($previous as $property => $value) {
            $this->context->$property = $value;
        }
    }

    /**
     * @return int|false
     */
    public function getRialAmount(Cart $cart)
    {
        $currency = new Currency((int) $cart->id_currency);
        if (!Validate::isLoadedObject($currency)) {
            return false;
        }

        return $this->toRial($cart->getOrderTotal(true, Cart::BOTH), $currency);
    }

    /* ------------------------------------------------------------------ */
    /* Callback security                                                   */
    /* ------------------------------------------------------------------ */

    public function getCallbackKey(Cart $cart)
    {
        return hash_hmac('sha256', (int) $cart->id . '|' . (string) $cart->secure_key, _COOKIE_KEY_);
    }

    public function isValidCallbackKey(Cart $cart, $key)
    {
        return self::safeEquals($this->getCallbackKey($cart), (string) $key);
    }

    public function getCallbackUrl(Cart $cart, array $extra = array())
    {
        return $this->context->link->getModuleLink(
            $this->name,
            'validation',
            array_merge(array('cart_id' => (int) $cart->id, 'key' => $this->getCallbackKey($cart)), $extra),
            true
        );
    }

    /**
     * Zibal orderId sent with the request: "<id_cart>-<timestamp>" so retries stay unique.
     */
    public function buildZibalOrderId(Cart $cart)
    {
        return (int) $cart->id . '-' . time();
    }

    /** hash_equals() is PHP >= 5.6; keep a fallback for PrestaShop 1.7.0 on PHP 5.4/5.5. */
    public static function safeEquals($known, $user)
    {
        if (function_exists('hash_equals')) {
            return hash_equals((string) $known, (string) $user);
        }

        $known = (string) $known;
        $user = (string) $user;
        if (strlen($known) !== strlen($user)) {
            return false;
        }
        $diff = 0;
        for ($i = 0, $len = strlen($known); $i < $len; ++$i) {
            $diff |= ord($known[$i]) ^ ord($user[$i]);
        }

        return $diff === 0;
    }

    /* ------------------------------------------------------------------ */
    /* Orders / locking                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Uncached lookup (Cart::orderExists() is cached in 1.7.0 and Order::getIdByCartId() is missing there).
     */
    public function getOrderIdByCart($idCart)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = ' . (int) $idCart . ' ORDER BY `id_order` ASC',
            false
        );
    }

    /* ------------------------------------------------------------------ */
    /* Zibal transactions (trackId <-> cart binding)                       */
    /* ------------------------------------------------------------------ */

    /**
     * Saves the trackId returned by Zibal before the customer is redirected.
     */
    public function saveTransaction(Cart $cart, $trackId, $zibalOrderId, $merchant, $amountRial, $cartTotal)
    {
        $now = date('Y-m-d H:i:s');

        return (bool) Db::getInstance()->insert(self::TX_TABLE, array(
            'id_cart' => (int) $cart->id,
            'id_shop' => (int) $cart->id_shop,
            'id_currency' => (int) $cart->id_currency,
            'track_id' => pSQL((string) $trackId),
            'zibal_order_id' => pSQL((string) $zibalOrderId),
            'merchant' => pSQL((string) $merchant),
            'amount' => (int) $amountRial,
            'cart_total' => (float) $cartTotal,
            'status' => pSQL(self::TX_PENDING),
            'date_add' => pSQL($now),
            'date_upd' => pSQL($now),
        ));
    }

    /**
     * @return array|false
     */
    public function getTransaction($trackId)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TX_TABLE . '` WHERE `track_id` = \'' . pSQL((string) $trackId) . '\'',
            false
        );
    }

    /**
     * Recent attempts of a cart that may already hold the customer's money (newest first).
     *
     * @return array
     */
    public function getOpenTransactions($idCart, $limit = 3)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TX_TABLE . '`
             WHERE `id_cart` = ' . (int) $idCart . '
               AND `id_order` IS NULL
               AND `track_id` IS NOT NULL
               AND `status` IN (\'' . self::TX_PENDING . '\', \'' . self::TX_VERIFIED . '\', \'' . self::TX_INQUIRY . '\')
               AND `date_add` > \'' . pSQL(date('Y-m-d H:i:s', time() - 86400)) . '\'
             ORDER BY `id_zibal_transaction` DESC
             LIMIT ' . (int) $limit,
            true,
            false
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * Transactions the merchant should look at: money possibly taken but no order, or an order with a mismatch.
     *
     * @return array
     */
    public function getTransactionsNeedingAttention($limit = 50)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TX_TABLE . '`
             WHERE (`status` IN (\'' . self::TX_VERIFIED . '\', \'' . self::TX_INQUIRY . '\', \'' . self::TX_MISMATCH . '\')
                    OR (`status` = \'' . self::TX_PENDING . '\' AND `error_log` IS NOT NULL))
               AND `track_id` IS NOT NULL
             ORDER BY `id_zibal_transaction` DESC
             LIMIT ' . (int) $limit,
            true,
            false
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array|false
     */
    public function getTransactionById($idTransaction)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TX_TABLE . '` WHERE `id_zibal_transaction` = ' . (int) $idTransaction,
            false
        );
    }

    /** Merchant the transaction was created with (falls back to the current one for old rows). */
    public function getTransactionMerchant(array $row)
    {
        return !empty($row['merchant']) ? (string) $row['merchant'] : $this->getMerchant();
    }

    public function updateTransaction($idTransaction, array $fields)
    {
        $data = array('date_upd' => pSQL(date('Y-m-d H:i:s')));
        foreach ($fields as $key => $value) {
            $data[$key] = is_int($value) ? $value : pSQL((string) $value);
        }

        return (bool) Db::getInstance()->update(
            self::TX_TABLE,
            $data,
            '`id_zibal_transaction` = ' . (int) $idTransaction
        );
    }

    protected function getLockName($idCart)
    {
        return 'zibal_' . substr(md5(_DB_NAME_ . _DB_PREFIX_), 0, 16) . '_' . (int) $idCart;
    }

    /** MySQL/MariaDB named lock, prevents two concurrent callbacks from creating duplicate orders. */
    public function acquireCartLock($idCart, $timeout = self::LOCK_WAIT)
    {
        return (int) Db::getInstance()->getValue(
            "SELECT GET_LOCK('" . pSQL($this->getLockName($idCart)) . "', " . (int) $timeout . ')',
            false
        ) === 1;
    }

    public function releaseCartLock($idCart)
    {
        Db::getInstance()->getValue(
            "SELECT RELEASE_LOCK('" . pSQL($this->getLockName($idCart)) . "')",
            false
        );
    }

    public function getOrderConfirmationUrl(Cart $cart, $idOrder)
    {
        return $this->context->link->getPageLink(
            'order-confirmation',
            true,
            null,
            array(
                'id_cart' => (int) $cart->id,
                'id_module' => (int) $this->id,
                'id_order' => (int) $idOrder,
                'key' => $cart->secure_key,
            )
        );
    }

    /* ------------------------------------------------------------------ */
    /* Zibal API                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @return array|null Decoded response, or null on transport/parse error
     */
    public function callZibal($endpoint, array $data, $timeout = 20)
    {
        $this->lastApiError = '';
        $ch = curl_init(self::API_BASE . $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, (int) $timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno) {
            $this->lastApiError = 'cURL error #' . $errno . ': ' . $error;
            $this->log('Zibal ' . $endpoint . ' ' . $this->lastApiError, 3);

            return null;
        }

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            $this->lastApiError = 'HTTP ' . $httpCode . ', response too large (' . strlen($body) . ' bytes)';
            $this->log('Zibal ' . $endpoint . ' ' . $this->lastApiError, 3);

            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['result']) || !is_scalar($decoded['result'])) {
            $this->lastApiError = 'HTTP ' . $httpCode . ', invalid response: ' . Tools::substr(trim((string) $body), 0, 300);
            $this->log('Zibal ' . $endpoint . ' invalid response (HTTP ' . $httpCode . ')', 3);

            return null;
        }

        return $decoded;
    }

    /** Raw error of the last failed callZibal() (cURL text or HTTP code + body excerpt). */
    public function getLastApiError()
    {
        return $this->lastApiError !== '' ? $this->lastApiError : 'no response';
    }

    /**
     * What Zibal actually returned: result code, its meaning, Zibal's own "message" and "status".
     */
    public function describeZibalResponse(array $response)
    {
        $result = isset($response['result']) ? (int) $response['result'] : 0;
        $text = 'Zibal result ' . $result . ' (' . $this->getResultMessage($result) . ')';
        if (isset($response['message']) && is_scalar($response['message']) && (string) $response['message'] !== '') {
            $text .= ' | message: ' . (string) $response['message'];
        }
        if (isset($response['status']) && is_scalar($response['status']) && (string) $response['status'] !== '') {
            $status = (int) $response['status'];
            $statusText = $this->getStatusMessage($status);
            $text .= ' | status: ' . $status . ($statusText !== '' ? ' (' . $statusText . ')' : '');
        }

        return $text;
    }

    /* ------------------------------------------------------------------ */
    /* Private order messages / error history                              */
    /* ------------------------------------------------------------------ */

    /**
     * Makes provider/cURL text safe for Message::$message (isCleanHtml, max 1600 chars on 1.7.0).
     */
    public function cleanForMessage($text, $max = self::ORDER_MESSAGE_MAX)
    {
        $text = strip_tags((string) $text);
        $text = str_replace(array('<', '>'), '', $text);
        $clean = @preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = $clean === null ? preg_replace('/[^\x20-\x7E\n]/', '?', $text) : $clean;
        // isCleanHtml rejects "script:" and "on<event>=" anywhere in the text.
        $text = preg_replace('/script\s*:/i', 'script :', $text);
        $text = preg_replace('/\b(on\w+)\s*=/i', '$1 :', $text);
        if (!Validate::isCleanHtml($text)) {
            $text = preg_replace('/[^\p{L}\p{N}\s\.\,\:\;\#\-\_\(\)\/\*\|\[\]\+]/u', ' ', $text);
        }

        return Tools::substr(trim($text), 0, (int) $max);
    }

    /**
     * Appends one timestamped line to the transaction's error history.
     */
    public function recordTransactionError($idTransaction, $step, $text)
    {
        $idTransaction = (int) $idTransaction;
        if (!$idTransaction) {
            return false;
        }

        $current = (string) Db::getInstance()->getValue(
            'SELECT `error_log` FROM `' . _DB_PREFIX_ . self::TX_TABLE . '` WHERE `id_zibal_transaction` = ' . $idTransaction,
            false
        );
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $step . ': ' . $this->cleanForMessage($text, 600);
        $log = $current === '' ? $line : $current . "\n" . $line;
        if (Tools::strlen($log) > self::ERROR_LOG_MAX) {
            $log = Tools::substr($log, -self::ERROR_LOG_MAX);
        }

        return $this->updateTransaction($idTransaction, array('error_log' => $log));
    }

    /**
     * A failed request has no trackId yet; it is still recorded so it can be shown on the order later.
     */
    public function saveFailedRequest(Cart $cart, $zibalOrderId, $merchant, $amountRial, $error)
    {
        $now = date('Y-m-d H:i:s');
        $ok = Db::getInstance()->insert(self::TX_TABLE, array(
            'id_cart' => (int) $cart->id,
            'id_shop' => (int) $cart->id_shop,
            'id_currency' => (int) $cart->id_currency,
            'zibal_order_id' => pSQL((string) $zibalOrderId),
            'merchant' => pSQL((string) $merchant),
            'amount' => (int) $amountRial,
            'status' => pSQL(self::TX_FAILED),
            'date_add' => pSQL($now),
            'date_upd' => pSQL($now),
        ));
        if ($ok) {
            $this->recordTransactionError((int) Db::getInstance()->Insert_ID(), 'request', $error);
        }

        return (bool) $ok;
    }

    /**
     * Adds a private (back-office only) message to an order. Never throws: a message must not break the order flow.
     */
    public function addPrivateOrderMessage($idOrder, Cart $cart, $text)
    {
        $text = $this->cleanForMessage($text);
        if (!(int) $idOrder || $text === '') {
            return false;
        }

        try {
            $message = new Message();
            $message->message = $text;
            $message->id_cart = (int) $cart->id;
            $message->id_customer = (int) $cart->id_customer;
            $message->id_order = (int) $idOrder;
            $message->private = 1;

            return (bool) $message->add();
        } catch (Exception $e) {
            $this->log('could not add private order message: ' . $e->getMessage(), 2, $cart->id);
        } catch (Throwable $e) {
            $this->log('could not add private order message: ' . $e->getMessage(), 2, $cart->id);
        }

        return false;
    }

    /**
     * Copies the error history of every Zibal attempt of this cart onto the order as private messages.
     */
    public function attachErrorHistoryToOrder($idOrder, Cart $cart)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `track_id`, `status`, `amount`, `error_log` FROM `' . _DB_PREFIX_ . self::TX_TABLE . '`
             WHERE `id_cart` = ' . (int) $cart->id . ' AND `error_log` IS NOT NULL AND `error_log` <> \'\'
             ORDER BY `id_zibal_transaction` ASC',
            true,
            false
        );
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $header = 'Zibal error | trackId: ' . ($row['track_id'] ? $row['track_id'] : '-')
                . ' | status: ' . $row['status'] . ' | amount: ' . (int) $row['amount'] . " IRR\n";
            $body = (string) $row['error_log'];
            $room = self::ORDER_MESSAGE_MAX - Tools::strlen($header);
            if (Tools::strlen($body) > $room) {
                $body = Tools::substr($body, -$room); // keep the most recent errors
            }
            $this->addPrivateOrderMessage($idOrder, $cart, $header . $body);
        }
    }

    public function getResultMessage($code)
    {
        $messages = array(
            100 => $this->l('با موفقیت تایید شد.'),
            102 => $this->l('مرچنت یافت نشد.'),
            103 => $this->l('مرچنت غیرفعال است.'),
            104 => $this->l('مرچنت نامعتبر است.'),
            105 => $this->l('مبلغ باید بیشتر از ۱٬۰۰۰ ریال باشد.'),
            106 => $this->l('آدرس بازگشت (callbackUrl) نامعتبر است.'),
            113 => $this->l('مبلغ تراکنش از سقف مجاز بیشتر است.'),
            114 => $this->l('کد ملی ارسالی نامعتبر است.'),
            115 => $this->l('IP سرور فروشگاه در پنل زیبال ثبت نشده است.'),
            140 => $this->l('آدرس بازگشت (callbackUrl) ارسال نشده است.'),
            201 => $this->l('این تراکنش قبلاً تایید شده است.'),
            202 => $this->l('سفارش پرداخت نشده یا ناموفق بوده است.'),
            203 => $this->l('شناسه تراکنش (trackId) نامعتبر است.'),
        );
        $code = (int) $code;

        return isset($messages[$code]) ? $messages[$code] : $this->l('خطای ناشناخته از درگاه پرداخت.');
    }

    public function getStatusMessage($status)
    {
        $messages = array(
            -1 => $this->l('در انتظار پرداخت'),
            -2 => $this->l('خطای داخلی'),
            1 => $this->l('پرداخت شده - تایید شده'),
            2 => $this->l('پرداخت شده - تایید نشده'),
            3 => $this->l('پرداخت توسط کاربر لغو شد.'),
            4 => $this->l('شماره کارت نامعتبر است.'),
            5 => $this->l('موجودی حساب کافی نیست.'),
            6 => $this->l('رمز وارد شده اشتباه است.'),
            7 => $this->l('تعداد درخواست‌ها بیش از حد مجاز است.'),
            8 => $this->l('تعداد پرداخت اینترنتی روزانه بیش از حد مجاز است.'),
            9 => $this->l('مبلغ پرداخت اینترنتی روزانه بیش از حد مجاز است.'),
            10 => $this->l('صادرکننده کارت نامعتبر است.'),
            11 => $this->l('خطای سوییچ'),
            12 => $this->l('کارت قابل دسترسی نیست.'),
            15 => $this->l('تراکنش استرداد شده است.'),
            16 => $this->l('تراکنش در حال استرداد است.'),
            18 => $this->l('تراکنش ریورس شده است.'),
            21 => $this->l('پذیرنده نامعتبر است.'),
        );

        if ($status === null || $status === '' || $status === false) {
            return '';
        }
        $status = (int) $status;

        return isset($messages[$status]) ? $messages[$status] : '';
    }

    public function log($message, $severity = 1, $idCart = null)
    {
        PrestaShopLogger::addLog(
            '[zibalpayment] ' . $message,
            (int) $severity,
            null,
            $idCart ? 'Cart' : null,
            $idCart ? (int) $idCart : null,
            true
        );
    }
}
