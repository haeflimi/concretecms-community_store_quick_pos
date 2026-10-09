<?php

namespace CommunityStoreQuickPos;

use CommunityEventManager\Entity\Event;
use CommunityStoreQuickPos\Sale\Catalog;
use CommunityStoreQuickPos\Sale\EventTab;
use Concrete\Core\Block\Block;
use Concrete\Package\CommunityStore\Src\CommunityStore\Group\GroupList;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * What both POS blocks share: the event they sell for, the product groups (with an icon each) and the catalog.
 * The block record keeps "eventID" (0 = the check-in event on now, -1 = none) and "groups", a JSON object
 * {gID: {"on": bool, "icon": "lucide-name"}}; the event's shop groups are always included.
 */
class PosBlock
{
    /**
     * Lucide icons offered for the categories (all of them are in the Blackout theme's icon map), with their labels.
     *
     * @return array<string, string>
     */
    public static function icons(): array
    {
        return [
            'package' => t('Package'), 'cup-soda' => t('Drink'), 'zap' => t('Energy'), 'beer' => t('Beer'), 'coffee' => t('Coffee'),
            'popcorn' => t('Snack'), 'cookie' => t('Sweets'), 'pizza' => t('Pizza'), 'sandwich' => t('Sandwich'), 'utensils' => t('Food'),
            'shirt' => t('Merch'), 'tag' => t('Stickers'), 'plug-zap' => t('Hardware'), 'network' => t('Cable'), 'star' => t('Favourite'),
            'ticket' => t('Ticket'), 'gift' => t('Gift'),
        ];
    }

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
        $groups = self::groups($groupsJson);
        $ids = app(EventTab::class)->shopGroupIDs($event);
        foreach ($groups as $gID => $group) {
            if (!empty($group['on'])) {
                $ids[] = (int) $gID;
            }
        }
        $icons = [];
        foreach ($groups as $gID => $group) {
            if (!empty($group['icon']) && isset(self::icons()[$group['icon']])) {
                $icons[(int) $gID] = $group['icon'];
            }
        }

        return new Catalog($ids, $icons);
    }

    /**
     * For the block forms: every store group with its setting.
     *
     * @return array<int, array{id: int, name: string, on: bool, icon: string}>
     */
    public static function groupRows(?string $groupsJson): array
    {
        $groups = self::groups($groupsJson);
        $rows = [];
        foreach (GroupList::getGroupList() as $group) {
            $gID = (int) $group->getGroupID();
            $rows[] = ['id' => $gID, 'name' => (string) $group->getGroupName(), 'on' => !empty($groups[$gID]['on']), 'icon' => (string) ($groups[$gID]['icon'] ?? 'package')];
        }

        return $rows;
    }

    /**
     * From the block form's posted "groups" array.
     */
    public static function groupsFromPost($posted): string
    {
        $groups = [];
        foreach ((array) $posted as $gID => $group) {
            $icon = (string) ($group['icon'] ?? 'package');
            $groups[(int) $gID] = ['on' => !empty($group['on']), 'icon' => isset(self::icons()[$icon]) ? $icon : 'package'];
        }

        return json_encode($groups);
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
