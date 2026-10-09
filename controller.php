<?php

namespace Concrete\Package\CommunityStoreQuickPos;

use Concrete\Core\Application\UserInterface\Dashboard\Navigation\NavigationCache;
use Concrete\Core\Asset\Asset;
use Concrete\Core\Asset\AssetList;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;
use Concrete\Core\Routing\Router;
use Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method as PaymentMethod;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Quick POS for Community Store: a self-service kiosk for visitors (sales go on their event tab), a register for the
 * staff (cash, card, TWINT, store credit, event tab, credit top-up) and badge authentication (hashed badges that
 * tolerate truncated reads, enrolled kiosk devices, a second scan to pay).
 */
class Controller extends Package
{
    /** Handles of the payment methods this package installs (disabled: they never appear at the web checkout) */
    public const PAYMENT_METHODS = [
        'quick_pos_cash' => 'Cash (POS)',
        'quick_pos_card' => 'Card (POS)',
        'quick_pos_twint' => 'TWINT (POS)',
    ];

    protected $pkgHandle = 'community_store_quick_pos';
    protected $appVersionRequired = '9.0.0';
    protected $phpVersionRequired = '8.0';
    protected $pkgVersion = '0.1.0';
    protected $pkgAutoloaderRegistries = [
        'src' => 'CommunityStoreQuickPos',
        // Community Store loads payment methods from Concrete\Package\<Package>\Src\CommunityStore\Payment\Methods\…
        'src/CommunityStore' => 'Concrete\Package\CommunityStoreQuickPos\Src\CommunityStore',
    ];

    public function getPackageName()
    {
        return t('Community Store Quick POS');
    }

    public function getPackageDescription()
    {
        return t('Self-service kiosk and staff register for Community Store, with badge authentication.');
    }

    public function on_start()
    {
        $router = $this->app->make(Router::class);
        $kiosk = 'CommunityStoreQuickPos\Controller\KioskApi::';
        foreach (['enroll', 'scan', 'checkout', 'logout'] as $action) {
            $router->post('/ccm/quick_pos/kiosk/' . $action, $kiosk . $action);
        }
        $register = 'CommunityStoreQuickPos\Controller\RegisterApi::';
        foreach (['customer', 'search', 'sell', 'topup', 'assign', 'recent'] as $action) {
            $router->post('/ccm/quick_pos/register/' . $action, $register . $action);
        }

        $assets = AssetList::getInstance();
        $assets->register('javascript', 'community_store_quick_pos', 'js/dist/quick-pos.js', ['version' => $this->pkgVersion, 'position' => Asset::ASSET_POSITION_FOOTER, 'minify' => false, 'combine' => false], $this);
        $assets->register('css', 'community_store_quick_pos', 'css/quick-pos.css', ['version' => $this->pkgVersion, 'minify' => false, 'combine' => false], $this);
        $assets->registerGroup('community_store_quick_pos', [['javascript', 'community_store_quick_pos'], ['css', 'community_store_quick_pos']]);
    }

    public function install()
    {
        $installed = Package::getInstalledHandles();
        if (!is_array($installed) || !in_array('community_store', $installed, true)) {
            throw new \Exception(t('This package requires that Community Store be installed.'));
        }
        $pkg = parent::install();
        $this->installContent();

        return $pkg;
    }

    public function upgrade()
    {
        parent::upgrade();
        $this->installContent();
    }

    /**
     * Sales, badges and devices stay in the database on uninstall; the payment methods go.
     */
    public function uninstall()
    {
        foreach (array_keys(self::PAYMENT_METHODS) as $handle) {
            $pm = PaymentMethod::getByHandle($handle);
            if ($pm) {
                $pm->delete();
            }
        }
        parent::uninstall();
    }

    protected function installContent(): void
    {
        $pkg = $this->getPackageEntity();
        foreach (['quick_pos_kiosk', 'quick_pos_register'] as $handle) {
            if (!BlockType::getByHandle($handle)) {
                BlockType::installBlockType($handle, $pkg);
            }
        }
        foreach (self::PAYMENT_METHODS as $handle => $name) {
            if (!PaymentMethod::getByHandle($handle)) {
                PaymentMethod::add($handle, $name, $pkg, '', false);
            }
        }
        // the HMAC key of the badge hashes: generated once, never shown; losing it means re-enrolling every badge
        $config = $this->app->make('config/database');
        if (!$config->get('community_store_quick_pos.badge_secret')) {
            $config->save('community_store_quick_pos.badge_secret', bin2hex(random_bytes(32)));
        }
        $path = '/dashboard/store/quick_pos';
        $page = Page::getByPath($path);
        if (!$page || $page->isError()) {
            $page = SinglePage::add($path, $pkg);
        }
        if ($page && !$page->isError()) {
            $page->update(['cName' => t('Quick POS'), 'cDescription' => t('Kiosk devices, badges and POS settings')]);
            $this->app->make(NavigationCache::class)->clear();
        }
    }
}
