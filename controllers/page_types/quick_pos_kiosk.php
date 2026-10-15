<?php

namespace Concrete\Package\CommunityStoreQuickPos\Controller\PageType;

use CommunityStoreQuickPos\Kiosk;
use Concrete\Core\Page\Controller\PageTypeController;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Kiosk pages (page type quick_pos_kiosk): the data of the kiosk from the page's attributes, for the theme's
 * kiosk.php template or the package's page_templates/kiosk.php fallback. See CommunityStoreQuickPos\Kiosk.
 */
class QuickPosKiosk extends PageTypeController
{
    public function view()
    {
        $page = $this->getPageObject();
        $this->set('kiosk', Kiosk::config($page, $this->request, $page->isEditMode()));
    }
}
