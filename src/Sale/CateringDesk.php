<?php

namespace CommunityStoreQuickPos\Sale;

use CommunityEventManager\Catering\CateringService;
use CommunityEventManager\Entity\Event;
use Concrete\Core\Error\UserMessageException;

/**
 * The "Catering" view of the register: the delivery list per catering slot of the register's event
 * (community_event_manager), with the store order status of each order advanced in bulk. Soft dependency: without the
 * event manager (or catering on the event) the view is not offered.
 */
class CateringDesk
{
    public static function isAvailable(): bool
    {
        return class_exists(CateringService::class);
    }

    public static function offers(?Event $event): bool
    {
        return $event !== null && self::isAvailable() && $event->isCateringEnabled();
    }

    /**
     * The slots with their order counts per status, and the orders of one slot (default: the next delivery).
     *
     * @return array{slots: array, slotID: int, orders: array, totals: array, flow: array}
     */
    public function overview(Event $event, int $slotID = 0): array
    {
        $catering = app(CateringService::class);
        $names = $catering->getStatusNames();
        $date = app('date');
        $all = $catering->getOrders($event);
        $slots = [];
        $next = 0;
        $now = new \DateTime();
        foreach ($catering->getSlots($event) as $slot) {
            $counts = array_fill_keys(array_keys($names), 0);
            foreach ($all as $order) {
                if ($order['slotID'] === $slot->getID() && isset($counts[$order['status']])) {
                    ++$counts[$order['status']];
                }
            }
            if ($next === 0 && $slot->getListedUntil() >= $now) {
                $next = $slot->getID();
            }
            $slots[] = [
                'id' => $slot->getID(),
                'title' => $slot->getTitle(),
                'when' => $date->formatCustom('D H:i', $slot->getDeliveryStart()),
                'open' => $slot->isOpen($now),
                'counts' => $counts,
                'total' => array_sum($counts),
            ];
        }
        $ids = array_column($slots, 'id');
        if (!in_array($slotID, $ids, true)) {
            $slotID = $next ?: (int) ($ids[0] ?? 0);
        }
        $orders = $slotID ? array_values(array_filter($all, static fn ($o) => $o['slotID'] === $slotID)) : [];
        usort($orders, static fn ($a, $b) => [$a['seat'] ?? 'zzz', $a['name']] <=> [$b['seat'] ?? 'zzz', $b['name']]);

        return [
            'slots' => $slots,
            'slotID' => $slotID,
            'orders' => array_map(static function (array $o) use ($catering) {
                return [
                    'oID' => $o['oID'], 'name' => $o['name'], 'seat' => $o['seat'], 'paid' => $o['paid'], 'status' => $o['status'],
                    'next' => $catering->nextStatus($o['status']),
                    'items' => array_map(static fn ($i) => ['name' => $i['name'], 'qty' => $i['qty']], $o['items']),
                ];
            }, $orders),
            'totals' => $catering->totals($orders),
            'flow' => array_map(static fn ($h) => ['id' => $h, 'name' => $names[$h]], array_keys($names)),
        ];
    }

    /**
     * @param int[] $oIDs
     *
     * @throws UserMessageException
     */
    public function setStatus(Event $event, array $oIDs, string $status, int $staffUID): int
    {
        return app(CateringService::class)->setStatus($event, $oIDs, $status, $staffUID);
    }
}
