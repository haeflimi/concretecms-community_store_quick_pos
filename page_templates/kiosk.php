<?php

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Fallback for themes without their own kiosk.php: the plain Bootstrap kiosk inside the theme's view.php. The Blackout
 * theme has its own themes/blackout/kiosk.php.
 *
 * @var Concrete\Core\Page\View\PageView $view
 * @var array $kiosk
 */
$view->requireAsset('community_store_quick_pos');
View::element('kiosk', ['kiosk' => $kiosk], 'community_store_quick_pos');
