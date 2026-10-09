<?php

namespace Concrete\Package\CommunityStoreQuickPos\Src\CommunityStore\Payment\Methods\QuickPosCard;

use Concrete\Package\CommunityStoreQuickPos\Src\CommunityStore\Payment\QuickPosMethod;

defined('C5_EXECUTE') or die('Access Denied.');

class QuickPosCardPaymentMethod extends QuickPosMethod
{
    public function getName()
    {
        return t('Card (POS)');
    }
}
