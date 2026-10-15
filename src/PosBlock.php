<?php

namespace CommunityStoreQuickPos;

use CommunityEventManager\Entity\Event;
use CommunityStoreQuickPos\Sale\Catalog;
use CommunityStoreQuickPos\Sale\EventTab;
use Concrete\Core\Block\Block;
use Concrete\Package\CommunityStore\Src\CommunityStore\Group\GroupList;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * What the kiosk pages and the register block share: the event they sell for, the product groups and the catalog.
 * "groups" is a JSON object {gID: {"on": bool}} (the kiosk page attribute, the register block record); the event's
 * shop groups are always included. The register block record keeps "eventID" (0 = the check-in event on now,
 * -1 = none). Icons are picked by the icon picker (PosIcons), not chosen by hand.
 */
class PosBlock
{
    /**
     * The POS block with this ID and handle, or null.
     */
    public static function load(int $bID, string $handle): ?Block
    {
        $block = $bID > 0 ? Block::getByID($bID) : null;

        return $block && $block->getBlockTypeHandle() === $handle ? $block : null;
    }

    public static function groups(?string $json): array
    {
        $groups = json_decode((string) $json, true);

        return is_array($groups) ? $groups : [];
    }

    public static function event(int $eventID): ?Event
    {
        return $eventID >= 0 ? app(EventTab::class)->resolveEvent($eventID) : null;
    }

    public static function catalog(?Event $event, ?string $groupsJson): Catalog
    {
        $ids = app(EventTab::class)->shopGroupIDs($event);
        foreach (self::groups($groupsJson) as $gID => $group) {
            if (!empty($group['on'])) {
                $ids[] = (int) $gID;
            }
        }

        return new Catalog($ids);
    }

    /**
     * For the forms: every store group with its setting.
     *
     * @return array<int, array{id: int, name: string, on: bool}>
     */
    public static function groupRows(?string $groupsJson): array
    {
        $groups = self::groups($groupsJson);
        $rows = [];
        foreach (GroupList::getGroupList() as $group) {
            $gID = (int) $group->getGroupID();
            $rows[] = ['id' => $gID, 'name' => (string) $group->getGroupName(), 'on' => !empty($groups[$gID]['on'])];
        }

        return $rows;
    }

    /**
     * From a form's posted "groups" array.
     */
    public static function groupsFromPost($posted): string
    {
        $groups = [];
        foreach ((array) $posted as $gID => $group) {
            if (!empty($group['on'])) {
                $groups[(int) $gID] = ['on' => true];
            }
        }

        return json_encode($groups, JSON_FORCE_OBJECT);
    }

    /**
     * @return array<int, string> eventID => label, with "current" and "none"
     */
    public static function eventOptions(): array
    {
        $options = [];
        if (EventTab::isAvailable()) {
            $options[0] = t('The event that is on now (check-in)');
            $options += app(EventTab::class)->eventOptions();
        }
        $options[-1] = t('No event');

        return $options;
    }

    /**
     * Display data of a customer.
     *
     * @return array{uID: int, name: string, avatar: string|null}
     */
    public static function person(\Concrete\Core\User\UserInfo $user): array
    {
        $avatar = null;
        if ($user->hasAvatar()) {
            $avatar = (string) $user->getUserAvatar()->getPath();
        }

        return ['uID' => (int) $user->getUserID(), 'name' => (string) $user->getUserName(), 'avatar' => $avatar];
    }
}
