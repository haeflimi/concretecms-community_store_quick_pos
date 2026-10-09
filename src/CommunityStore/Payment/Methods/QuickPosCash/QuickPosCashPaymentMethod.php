<?php

namespace Concrete\Package\CommunityStoreQuickPos\Src\CommunityStore\Payment\Methods\QuickPosCash;

use Concrete\Package\CommunityStoreQuickPos\Src\CommunityStore\Payment\QuickPosMethod;

defined('C5_EXECUTE') or die('Access Denied.');

class QuickPosCashPaymentMethod extends QuickPosMethod
{
    public function getName()
    {
        return t('Cash (POS)');
    }
}
