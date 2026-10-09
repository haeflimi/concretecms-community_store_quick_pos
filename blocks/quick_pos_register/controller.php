<?php

namespace Concrete\Package\CommunityStoreQuickPos\Block\QuickPosRegister;

use CommunityStoreQuickPos\Controller\RegisterApi;
use CommunityStoreQuickPos\Labels;
use CommunityStoreQuickPos\PosBlock;
use CommunityStoreQuickPos\Sale\EventTab;
use CommunityStoreQuickPos\Sale\SaleService;
use CommunityStoreQuickPos\Settings;
use Concrete\Core\Block\BlockController;
use Concrete\Core\Support\Facade\Url;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Staff register: sells the same catalog for cash, card, TWINT, store credit or onto a participant's event tab, and
 * tops up store credit. Customers are picked by badge or name. Staff = view permission on Store › Quick POS.
 */
class Controller extends BlockController
{
    protected $btTable = 'btQuickPosRegister';
    protected $btInterfaceWidth = 640;
    protected $btInterfaceHeight = 620;
    protected $btDefaultSet = 'other';
    protected $btCacheBlockOutput = false;
    protected $btCacheBlockRecord = true;

    public const METHODS = [SaleService::METHOD_CASH, SaleService::METHOD_CARD, SaleService::METHOD_TWINT, SaleService::METHOD_CREDIT, SaleService::METHOD_TAB];

    /** @var int 0 = the check-in event on now, -1 = none */
    public $eventID;
    /** @var string|null JSON, see PosBlock */
    public $groups;
    /** @var string|null comma separated payment methods */
    public $methods;
    /** @var int */
    public $topUp;

    public function getBlockTypeName()
    {
        return t('Quick POS Register');
    }

    public function getBlockTypeDescription()
    {
        return t('Register for the staff: cash, card, TWINT, store credit or the event tab, and credit top-ups.');
    }

    public function add()
    {
        $this->set('eventID', 0);
        $this->set('groups', '{}');
        $this->set('methods', implode(',', self::METHODS));
        $this->set('topUp', 1);
        $this->setFormData();
    }

    public function edit()
    {
        $this->setFormData();
    }

    public function save($args)
    {
        $methods = array_values(array_intersect(self::METHODS, (array) ($args['methods'] ?? [])));
        parent::save([
            'eventID' => (int) ($args['eventID'] ?? 0),
            'groups' => PosBlock::groupsFromPost($args['groups'] ?? []),
            'methods' => implode(',', $methods ?: [SaleService::METHOD_CASH]),
            'topUp' => empty($args['topUp']) ? 0 : 1,
        ]);
    }

    /**
     * Payment methods offered here and usable now (credit needs the credit package, the tab an event with a tab).
     *
     * @return string[]
     */
    public function getMethods(): array
    {
        $methods = array_values(array_intersect(self::METHODS, explode(',', (string) $this->methods)));
        if (!SaleService::creditAvailable()) {
            $methods = array_values(array_diff($methods, [SaleService::METHOD_CREDIT]));
        }
        if ((int) $this->eventID < 0 || !EventTab::isAvailable()) {
            $methods = array_values(array_diff($methods, [SaleService::METHOD_TAB]));
        }

        return $methods;
    }

    public function allowsTopUp(): bool
    {
        return (bool) $this->topUp && SaleService::creditAvailable();
    }

    public function view()
    {
        $staff = Settings::isStaff();
        $this->set('staff', $staff);
        if (!$staff) {
            return;
        }
        $event = PosBlock::event((int) $this->eventID);
        $names = SaleService::methodNames();
        $this->set('register', [
            'kind' => 'register',
            'bID' => (int) $this->bID,
            'api' => (string) Url::to('/ccm/quick_pos/register'),
            'token' => $this->app->make('token')->generate(RegisterApi::TOKEN),
            'eventName' => $event !== null ? $event->getName() : null,
            'methods' => array_map(static function ($method) use ($names) {
                return ['id' => $method, 'name' => $names[$method], 'needsCustomer' => in_array($method, [SaleService::METHOD_CREDIT, SaleService::METHOD_TAB], true)];
            }, $this->getMethods()),
            'topUp' => $this->allowsTopUp(),
            'topUpMethods' => array_map(static function ($method) use ($names) {
                return ['id' => $method, 'name' => $names[$method]];
            }, [SaleService::METHOD_CASH, SaleService::METHOD_TWINT, SaleService::METHOD_CARD]),
            'money' => Labels::money(),
            'labels' => Labels::register(),
        ] + PosBlock::catalog($event, $this->groups)->toArray());
    }

    protected function setFormData(): void
    {
        $this->set('eventOptions', PosBlock::eventOptions());
        $this->set('groupRows', PosBlock::groupRows($this->groups));
        $this->set('icons', PosBlock::icons());
        $this->set('methodOptions', SaleService::methodNames());
    }
}
