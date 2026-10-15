<?php

namespace CommunityStoreQuickPos;

use CommunityEventManager\Entity\Event;
use CommunityEventManager\Event\EventService;
use CommunityStoreQuickPos\Controller\KioskApi;
use CommunityStoreQuickPos\Device\DeviceService;
use CommunityStoreQuickPos\Sale\Catalog;
use CommunityStoreQuickPos\Sale\EventTab;
use Concrete\Core\Attribute\Key\CollectionKey;
use Concrete\Core\Entity\Calendar\CalendarEvent;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\Url;
use Concrete\Core\User\User;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * A kiosk is a page of the page type "quick_pos_kiosk" (template "kiosk"). Its settings are page attributes, so
 * there can be any number of kiosks: the event (the event manager's "event" attribute; empty = the check-in event
 * that is on now), the product groups with their icons (attribute type quick_pos_groups; the event's shop groups
 * are always sold), the headline of the start screen (empty = the page name), the tab limit and the idle timeout.
 * The page description is the text of the start screen.
 */
class Kiosk
{
    public const PAGE_TYPE = 'quick_pos_kiosk';
    public const TEMPLATE = 'kiosk';
    public const ATTRIBUTE_SET = 'quick_pos_kiosk';
    public const GROUPS_TYPE = 'quick_pos_groups';

    /** community_event_manager's page attribute (calendar_event) */
    public const ATTR_EVENT = 'event';
    public const ATTR_GROUPS = 'quick_pos_groups';
    public const ATTR_HEADLINE = 'quick_pos_headline';
    public const ATTR_TAB_LIMIT = 'quick_pos_tab_limit';
    public const ATTR_IDLE = 'quick_pos_idle_seconds';

    /**
     * The package's own page attributes: handle => [attribute type, name].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function attributes(): array
    {
        return [
            self::ATTR_GROUPS => [self::GROUPS_TYPE, t('Kiosk: product groups')],
            self::ATTR_HEADLINE => ['text', t('Kiosk: headline')],
            self::ATTR_TAB_LIMIT => ['number', t('Kiosk: tab limit')],
            self::ATTR_IDLE => ['number', t('Kiosk: sign out after (seconds)')],
        ];
    }

    /**
     * The kiosk page with this ID, if it is one and the visitor may see it.
     */
    public static function page(int $cID): ?Page
    {
        $page = $cID > 0 ? Page::getByID($cID, 'ACTIVE') : null;
        if (!$page || $page->isError() || !$page->isActive() || $page->getPageTypeHandle() !== self::PAGE_TYPE) {
            return null;
        }

        return (new Checker($page))->canViewPage() ? $page : null;
    }

    /**
     * The event the kiosk sells for: the page's "event" attribute, else the check-in event that is on now.
     */
    public static function event(Page $page): ?Event
    {
        if (!EventTab::isAvailable()) {
            return null;
        }
        $calendarEvent = $page->getAttribute(self::ATTR_EVENT);
        if ($calendarEvent instanceof CalendarEvent) {
            $event = app(EventService::class)->getByCalendarEventID((int) $calendarEvent->getID());
            if ($event !== null) {
                return $event;
            }
        }

        return app(EventTab::class)->resolveEvent(0);
    }

    /**
     * @return array{groups: string, tabLimit: float, idleSeconds: int, headline: string, intro: string}
     */
    public static function settings(Page $page): array
    {
        $idle = (int) $page->getAttribute(self::ATTR_IDLE);
        $headline = trim((string) $page->getAttribute(self::ATTR_HEADLINE));

        return [
            'groups' => (string) $page->getAttribute(self::ATTR_GROUPS),
            'tabLimit' => max(0.0, round((float) $page->getAttribute(self::ATTR_TAB_LIMIT), 2)),
            'idleSeconds' => $idle > 0 ? max(15, min(600, $idle)) : 60,
            'headline' => $headline !== '' ? $headline : (string) $page->getCollectionName(),
            'intro' => trim((string) $page->getCollectionDescription()),
        ];
    }

    public static function catalog(Page $page, ?Event $event): Catalog
    {
        return PosBlock::catalog($event, self::settings($page)['groups']);
    }

    /**
     * What the kiosk views get (the theme's kiosk.php template or the package's fallback), see js/src/core/types.ts.
     */
    public static function config(Page $page, Request $request, bool $editMode): array
    {
        $app = Application::getFacadeApplication();
        $settings = self::settings($page);
        $event = self::event($page);
        $device = $app->make(DeviceService::class)->current($request);
        $staff = Settings::isStaff();
        // page editors (no badge reader at hand) use the kiosk as themselves, but not while editing the page
        $self = !$editMode && Settings::canEditPage($page);
        $token = $app->make('token');

        return [
            'kind' => 'kiosk',
            'cID' => (int) $page->getCollectionID(),
            'api' => (string) Url::to('/ccm/quick_pos/kiosk'),
            'enrolled' => $device !== null,
            'deviceName' => $device['name'] ?? null,
            'staff' => $staff,
            'enrollToken' => $staff ? $token->generate(KioskApi::ENROLL_TOKEN) : null,
            'self' => $self,
            'selfName' => $self ? (string) $app->make(User::class)->getUserName() : null,
            'selfToken' => $self ? $token->generate(KioskApi::SELF_TOKEN) : null,
            'eventName' => $event !== null ? $event->getName() : null,
            'available' => $event !== null && EventTab::isAvailable(),
            'unavailableMessage' => $event === null ? EventTab::message(EventTab::NO_EVENT) : '',
            'idleSeconds' => $settings['idleSeconds'],
            // the page is the kiosk: cover the screen, except while it is being edited
            'fullscreen' => !$editMode,
            'headline' => $settings['headline'],
            'intro' => $settings['intro'],
            'tabLimit' => $settings['tabLimit'],
            'money' => Labels::money(),
            'labels' => Labels::kiosk(),
        ] + PosBlock::catalog($event, $settings['groups'])->toArray();
    }

    /**
     * 0.4.0: a page that held the former kiosk block gets the block's settings as attributes.
     *
     * @param array<string, mixed> $block the btQuickPosKiosk row
     */
    public static function applyLegacySettings(Page $page, array $block): void
    {
        $page->setAttribute(self::ATTR_GROUPS, (string) $block['groups']);
        $page->setAttribute(self::ATTR_HEADLINE, (string) $block['headline']);
        $page->setAttribute(self::ATTR_TAB_LIMIT, (float) $block['tabLimit']);
        $page->setAttribute(self::ATTR_IDLE, (int) $block['idleSeconds']);
        if ((int) $block['eventID'] > 0 && EventTab::isAvailable() && CollectionKey::getByHandle(self::ATTR_EVENT)) {
            $event = app(EventService::class)->getByID((int) $block['eventID']);
            $calendarEvent = $event && $event->getCalendarEventID() ? app(\Concrete\Core\Calendar\Event\EventService::class)->getByID($event->getCalendarEventID()) : null;
            if ($calendarEvent) {
                $page->setAttribute(self::ATTR_EVENT, $calendarEvent);
            }
        }
    }
}
