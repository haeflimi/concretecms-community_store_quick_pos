<?php

namespace Concrete\Package\CommunityStoreQuickPos\Src\CommunityStore\Payment;

use Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method as StorePaymentMethod;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Payment taken at the Quick POS register. These methods are installed disabled and only set on POS orders, so they
 * never show up at the web checkout. The money is received at the counter: the order is paid at once, no receipt.
 */
abstract class QuickPosMethod extends StorePaymentMethod
{
    public function dashboardForm()
    {
    }

    public function save(array $data = [])
    {
    }

    public function validate($args, $e)
    {
        return $e;
    }

    public function checkoutForm()
    {
    }

    public function submitPayment()
    {
        return ['error' => 0, 'transactionReference' => ''];
    }

    public function getPaymentMinimum()
    {
        return 0;
    }

    public function getPaymentMaximum()
    {
        return 1000000;
    }

    public function markPaid()
    {
        return true;
    }

    public function sendReceipt()
    {
        return false;
    }

    public function isExternal()
    {
        return false;
    }

    public function getPaymentInstructions()
    {
        return '';
    }
}
