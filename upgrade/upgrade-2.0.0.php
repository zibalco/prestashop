<?php
/**
 * Upgrade from 1.x: removes unused settings and moves to the displayPaymentReturn hook.
 *
 * @author    zibal.ir
 * @license   https://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_0($module)
{
    if (!Zibalpayment::installTransactionTable()) {
        return false;
    }

    // 1.x used currencies_mode "radio", stored as id_currency = -2 in module_currency.
    // 2.x uses "checkbox", which only matches real currency ids: without this the
    // payment option would disappear for every upgraded shop.
    Db::getInstance()->execute(
        'DELETE FROM `' . _DB_PREFIX_ . 'module_currency` WHERE `id_module` = ' . (int) $module->id
    );
    if (!$module->addCheckboxCurrencyRestrictionsForModule()) {
        return false;
    }

    Configuration::deleteByName('ZIBAL_HASHKEY');

    if (!Configuration::get('ZIBAL_CURRENCY_UNIT')) {
        Configuration::updateValue('ZIBAL_CURRENCY_UNIT', 'auto');
    }

    if ($module->isRegisteredInHook('paymentReturn')) {
        $module->unregisterHook('paymentReturn');
    }

    return $module->registerHook('paymentOptions')
        && $module->registerHook('displayPaymentReturn');
}
