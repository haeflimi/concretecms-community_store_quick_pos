<?php

namespace Concrete\Package\CommunityStoreQuickPos\Src\CommunityStore\Payment\Methods\QuickPosTwint;

use Concrete\Package\CommunityStoreQuickPos\Src\CommunityStore\Payment\QuickPosMethod;

defined('C5_EXECUTE') or die('Access Denied.');

class QuickPosTwintPaymentMethod extends QuickPosMethod
{
    public function getName()
    {
        return t('TWINT (POS)');
    }
}
