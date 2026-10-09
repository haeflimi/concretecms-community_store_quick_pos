<?php

namespace Concrete\Package\CommunityStoreQuickPos\Block\QuickPosKiosk;

use CommunityStoreQuickPos\Controller\KioskApi;
use CommunityStoreQuickPos\Device\DeviceService;
use CommunityStoreQuickPos\Labels;
use CommunityStoreQuickPos\PosBlock;
use CommunityStoreQuickPos\Sale\EventTab;
use CommunityStoreQuickPos\Settings;
use Concrete\Core\Block\BlockController;
use Concrete\Core\Support\Facade\Url;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Self-service kiosk: visitors identify with their badge, pick products and put them on their event tab, confirmed
 * with a second scan. Works on enrolled devices only (a staff member registers the tablet once). The page itself
 * may be public; the plain view is Bootstrap, themes can render their own from $kiosk.
 */
class Controller extends BlockController
{
    protected $btTable = 'btQuickPosKiosk';
    protected $btInterfaceWidth = 640;
    protected $btInterfaceHeight = 620;
    protected $btDefaultSet = 'other';
    protected $btCacheBlockOutput = false;
    protected $btCacheBlockRecord = true;

    /** @var int 0 = the check-in event on now, -1 = none */
    public $eventID;
    /** @var string|null JSON, see PosBlock */
    public $groups;
    /** @var float 0 = no limit */
    public $tabLimit;
    /** @var int */
    public $idleSeconds;
    /** @var int */
    public $fullscreen;
    /** @var string|null */
    public $headline;
    /** @var string|null */
    public $intro;

    public function getBlockTypeName()
    {
        return t('Quick POS Kiosk');
    }

    public function getBlockTypeDescription()
    {
        return t('Self-service kiosk: visitors scan their badge and put drinks and snacks on their event tab.');
    }

    public function add()
    {
        $this->set('eventID', 0);
        $this->set('groups', '{}');
        $this->set('tabLimit', 0);
        $this->set('idleSeconds', 60);
        $this->set('fullscreen', 1);
        $this->set('headline', t('Drinks, snacks, *cables.*'));
        $this->set('intro', t('Hold your badge to the reader to shop. Everything goes on your LAN tab.'));
        $this->setFormData();
    }

    public function edit()
    {
        $this->setFormData();
    }

    public function save($args)
    {
        parent::save([
            'eventID' => (int) ($args['eventID'] ?? 0),
            'groups' => PosBlock::groupsFromPost($args['groups'] ?? []),
            'tabLimit' => max(0, round((float) str_replace(',', '.', (string) ($args['tabLimit'] ?? 0)), 2)),
            'idleSeconds' => max(15, min(600, (int) ($args['idleSeconds'] ?? 60))),
            'fullscreen' => empty($args['fullscreen']) ? 0 : 1,
            'headline' => trim((string) ($args['headline'] ?? '')),
            'intro' => trim((string) ($args['intro'] ?? '')),
        ]);
    }

    public function view()
    {
        $event = PosBlock::event((int) $this->eventID);
        $catalog = PosBlock::catalog($event, $this->groups);
        $device = $this->app->make(DeviceService::class)->current($this->request);
        $staff = Settings::isStaff();
        $this->set('kiosk', [
            'kind' => 'kiosk',
            'bID' => (int) $this->bID,
            'api' => (string) Url::to('/ccm/quick_pos/kiosk'),
            'enrolled' => $device !== null,
            'deviceName' => $device['name'] ?? null,
            'staff' => $staff,
            'enrollToken' => $staff ? $this->app->make('token')->generate(KioskApi::ENROLL_TOKEN) : null,
            'eventName' => $event !== null ? $event->getName() : null,
            'available' => $event !== null && EventTab::isAvailable(),
            'unavailableMessage' => $event === null ? EventTab::message(EventTab::NO_EVENT) : '',
            'idleSeconds' => (int) $this->idleSeconds ?: 60,
            // never cover the page while it is being edited
            'fullscreen' => (bool) $this->fullscreen && !$this->isEditMode(),
            'headline' => (string) $this->headline,
            'intro' => (string) $this->intro,
            'tabLimit' => (float) $this->tabLimit,
            'money' => Labels::money(),
            'labels' => Labels::kiosk(),
        ] + $catalog->toArray());
    }

    protected function isEditMode(): bool
    {
        $page = $this->request->getCurrentPage();

        return $page && !$page->isError() && $page->isEditMode();
    }

    protected function setFormData(): void
    {
        $this->set('eventOptions', PosBlock::eventOptions());
        $this->set('groupRows', PosBlock::groupRows($this->groups));
        $this->set('icons', PosBlock::icons());
    }
}
