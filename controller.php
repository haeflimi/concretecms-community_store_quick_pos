<?php

namespace Concrete\Package\CommunityStoreQuickPos;

use CommunityStoreQuickPos\Kiosk;
use Concrete\Core\Application\UserInterface\Dashboard\Navigation\NavigationCache;
use Concrete\Core\Asset\Asset;
use Concrete\Core\Asset\AssetList;
use Concrete\Core\Attribute\Category\CategoryService;
use Concrete\Core\Attribute\Key\CollectionKey;
use Concrete\Core\Attribute\TypeFactory;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Entity\Attribute\Set as AttributeSet;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Composer\Control\Type\Type as ComposerControlType;
use Concrete\Core\Page\Type\Composer\FormLayoutSet;
use Concrete\Core\Page\Type\PublishTarget\Type\Type as PublishTargetType;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\Routing\Router;
use Doctrine\ORM\EntityManagerInterface;
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
    protected $pkgVersion = '0.4.0';
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
        // figures for the block "Event Figure" (community_event_manager): only asked for when that package is installed
        $this->app->make('director')->addListener('on_event_figures_register', static function ($event) {
            \CommunityStoreQuickPos\Figure\ShopFigures::register($event->getSubject());
        });
        $router = $this->app->make(Router::class);
        $kiosk = 'CommunityStoreQuickPos\Controller\KioskApi::';
        foreach (['enroll', 'scan', 'self', 'checkout', 'logout'] as $action) {
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
        // 0.4.0: the kiosk block's files are gone, and the core refreshes the package's block types first: convert
        // the kiosk pages and drop the block type before that
        $this->installKioskPageType($this->getPackageEntity());
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
        if (!BlockType::getByHandle('quick_pos_register')) {
            BlockType::installBlockType('quick_pos_register', $pkg);
        }
        $this->installKioskPageType($pkg);
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

    /**
     * Kiosks are pages: page type "quick_pos_kiosk" with the template "kiosk" (the theme's kiosk.php, or the package's
     * page_templates/kiosk.php inside the theme's view), configured by page attributes in the set "Kiosk" (see
     * CommunityStoreQuickPos\Kiosk). The composer offers name, address, event, product groups, headline, text, tab
     * limit and timeout; kiosk pages stay out of navigation, search and the sitemap.
     */
    protected function installKioskPageType($pkg): void
    {
        $category = $this->app->make(CategoryService::class)->getByHandle('collection');
        $controller = $category->getController();
        $types = $this->app->make(TypeFactory::class);
        $type = $types->getByHandle(Kiosk::GROUPS_TYPE) ?: $types->add(Kiosk::GROUPS_TYPE, 'Kiosk product groups', $pkg);
        if (!$category->getAttributeTypes()->contains($type)) {
            $category->associateAttributeKeyType($type);
        }
        $em = $this->app->make(EntityManagerInterface::class);
        $set = $em->getRepository(AttributeSet::class)->findOneBy(['asHandle' => Kiosk::ATTRIBUTE_SET, 'category' => $controller->getCategoryEntity()])
            ?: $controller->getSetManager()->addSet(Kiosk::ATTRIBUTE_SET, t('Kiosk'), $pkg);
        // uncached lookups: a cached "no such key" from earlier in this request would hide the keys just added
        $keys = [];
        foreach (Kiosk::attributes() as $handle => [$typeHandle, $name]) {
            $keys[$handle] = $controller->getAttributeKeyByHandleUncached($handle)
                ?: $controller->add($typeHandle, ['akHandle' => $handle, 'akName' => $name, 'asID' => $set->getAttributeSetID()], null, $pkg);
        }
        $keys[Kiosk::ATTR_EVENT] = $controller->getAttributeKeyByHandleUncached(Kiosk::ATTR_EVENT);

        $template = PageTemplate::getByHandle(Kiosk::TEMPLATE) ?: PageTemplate::add(Kiosk::TEMPLATE, 'Kiosk', 'full.png', $pkg);
        $pageType = PageType::getByHandle(Kiosk::PAGE_TYPE);
        if (!$pageType instanceof PageType) {
            $pageType = PageType::add([
                'handle' => Kiosk::PAGE_TYPE,
                'name' => 'Kiosk',
                'defaultTemplate' => $template,
                'allowedTemplates' => 'C',
                'templates' => [$template],
                'ptLaunchInComposer' => false,
                'ptIsFrequentlyAdded' => false,
            ], $pkg);
            $target = PublishTargetType::getByHandle('all');
            $pageType->setConfiguredPageTypePublishTargetObject($target->configurePageTypePublishTarget($pageType, []));
            $defaults = $pageType->getPageTypePageTemplateDefaultPageObject($template);
            foreach (['exclude_nav', 'exclude_search_index', 'exclude_sitemapxml'] as $handle) {
                if (CollectionKey::getByHandle($handle)) {
                    $defaults->setAttribute($handle, true);
                }
            }
        }
        if (count(FormLayoutSet::getList($pageType)) === 0) {
            $set = $pageType->addPageTypeComposerFormLayoutSet(t('Kiosk'), t('Which event and products the kiosk sells, and what its start screen says. The text is the page description.'));
            $properties = ComposerControlType::getByHandle('core_page_property');
            $attributes = ComposerControlType::getByHandle('collection_attribute');
            $controls = [
                [$properties, 'name'], [$properties, 'url_slug'], [$attributes, Kiosk::ATTR_EVENT], [$attributes, Kiosk::ATTR_GROUPS],
                [$attributes, Kiosk::ATTR_HEADLINE], [$properties, 'description'], [$attributes, Kiosk::ATTR_TAB_LIMIT], [$attributes, Kiosk::ATTR_IDLE],
            ];
            foreach ($controls as [$controlType, $handle]) {
                if ($controlType === $attributes) {
                    $control = isset($keys[$handle]) ? $attributes->getPageTypeComposerControlByIdentifier($keys[$handle]->getAttributeKeyID()) : null;
                } else {
                    $control = $controlType->configureFromImportHandle($handle);
                }
                if ($control) {
                    $control->addToPageTypeComposerFormLayoutSet($set);
                }
            }
        }
        $this->convertKioskBlocks($pageType, $template);
    }

    /**
     * 0.4.0: before, the kiosk was a block. Pages that hold one become kiosk pages with the block's settings as
     * attributes and its text as page description; then the block type goes. Its files are gone, so the rows are
     * removed by hand (the core's BlockType::delete() needs the block controller).
     */
    protected function convertKioskBlocks(PageType $pageType, \Concrete\Core\Entity\Page\Template $template): void
    {
        $db = $this->app->make(Connection::class);
        $btID = (int) $db->fetchOne("SELECT btID FROM BlockTypes WHERE btHandle = 'quick_pos_kiosk'");
        if ($btID === 0) {
            return;
        }
        $blocks = $db->fetchAllAssociative('SELECT cvb.cID, cvb.bID FROM CollectionVersionBlocks cvb JOIN Blocks b ON b.bID = cvb.bID WHERE b.btID = ? ORDER BY cvb.cvID', [$btID]);
        $byPage = [];
        foreach ($blocks as $row) {
            // ascending versions: the newest version's block wins
            $byPage[(int) $row['cID']] = (int) $row['bID'];
        }
        $hasTable = $db->getSchemaManager()->tablesExist(['btQuickPosKiosk']);
        foreach ($byPage as $cID => $bID) {
            $page = Page::getByID($cID, 'RECENT');
            $data = $hasTable ? $db->fetchAssociative('SELECT * FROM btQuickPosKiosk WHERE bID = ?', [$bID]) : null;
            if (!$page || $page->isError() || !$data) {
                continue;
            }
            Kiosk::applyLegacySettings($page, $data);
            $page->update(['ptID' => $pageType->getPageTypeID(), 'pTemplateID' => $template->getPageTemplateID(), 'cDescription' => (string) $data['intro']]);
        }
        $bIDs = array_values(array_unique(array_map('intval', array_column($blocks, 'bID'))));
        $bIDs = array_merge($bIDs, array_map('intval', $db->fetchFirstColumn('SELECT bID FROM Blocks WHERE btID = ?', [$btID])));
        $bIDs = array_values(array_unique($bIDs));
        if ($bIDs) {
            $tables = ['CollectionVersionBlocks', 'CollectionVersionBlockStyles', 'CollectionVersionBlocksCacheSettings', 'CollectionVersionBlocksOutputCache', 'BlockPermissionAssignments', 'BlockRelations', 'BlockFeatureAssignments', 'Blocks'];
            $placeholders = implode(',', array_fill(0, count($bIDs), '?'));
            foreach ($tables as $table) {
                if ($db->getSchemaManager()->tablesExist([$table])) {
                    $db->executeStatement("DELETE FROM $table WHERE bID IN ($placeholders)", $bIDs);
                }
            }
        }
        $db->executeStatement('DROP TABLE IF EXISTS btQuickPosKiosk');
        $db->delete('BlockTypeSetBlockTypes', ['btID' => $btID]);
        $db->delete('BlockTypes', ['btID' => $btID]);
        $this->app->make('cache')->flush();
        $this->app->make('cache/request')->flush();
    }
}
