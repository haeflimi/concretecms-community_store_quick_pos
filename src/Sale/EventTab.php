<?php

namespace CommunityStoreQuickPos\Sale;

use CommunityEventManager\Entity\Event;
use CommunityEventManager\Entity\Participant;
use CommunityEventManager\Event\EventService;
use CommunityEventManager\Participation\ParticipationService;
use CommunityEventManager\Tab\TabService;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Error\UserMessageException;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Bridge to the Community Event Manager (optional): the event a POS block sells for, its shop groups and the
 * participant's event tab, where kiosk sales (and register sales "on the tab") are put.
 */
class EventTab
{
    public const OK = 'ok';
    public const NO_EVENT = 'no_event';
    public const NO_TAB = 'no_tab';
    public const NOT_PARTICIPANT = 'not_participant';
    public const NOT_CHECKED_IN = 'not_checked_in';
    public const CLOSED = 'closed';

    public static function isAvailable(): bool
    {
        return class_exists(EventService::class) && class_exists(TabService::class);
    }

    /**
     * @param int $eventID 0 = the check-in event that is on now
     */
    public function resolveEvent(int $eventID): ?Event
    {
        if (!self::isAvailable()) {
            return null;
        }
        $events = app(EventService::class);
        if ($eventID > 0) {
            return $events->getByID($eventID);
        }

        return method_exists($events, 'getCurrentCheckinEvent') ? $events->getCurrentCheckinEvent() : null;
    }

    /**
     * The store groups the event sells at its tab (shop groups), empty if the event has no shop.
     *
     * @return int[]
     */
    public function shopGroupIDs(?Event $event): array
    {
        return $event !== null && $event->isShopEnabled() ? $event->getShopGroupIDs() : [];
    }

    /**
     * Whether a user can buy on their tab at this event, with the current tab total and the seat.
     *
     * @return array{state: string, participant: Participant|null, total: float, seat: string|null}
     */
    public function status(?Event $event, int $uID): array
    {
        $result = ['state' => self::NO_EVENT, 'participant' => null, 'total' => 0.0, 'seat' => null];
        if ($event === null) {
            return $result;
        }
        if (!TabService::isAvailable($event)) {
            $result['state'] = self::NO_TAB;

            return $result;
        }
        $participant = app(ParticipationService::class)->getParticipant($event, $uID);
        if ($participant === null || !$participant->isRegistered()) {
            $result['state'] = self::NOT_PARTICIPANT;

            return $result;
        }
        $result['participant'] = $participant;
        $result['seat'] = $participant->getSeatID();
        if ($event->isCheckinEnabled() && !$participant->isPresent()) {
            $result['state'] = self::NOT_CHECKED_IN;

            return $result;
        }
        $order = app(TabService::class)->getOrder($participant);
        if ($participant->isTabSettled() || ($order !== null && $order->getPaid())) {
            $result['state'] = self::CLOSED;

            return $result;
        }
        $result['state'] = self::OK;
        $result['total'] = $order !== null ? $this->orderTotal($order) : 0.0;

        return $result;
    }

    public static function message(string $state): string
    {
        switch ($state) {
            case self::NO_EVENT:
                return t('There is no event running right now.');
            case self::NO_TAB:
                return t('This event has no tab.');
            case self::NOT_PARTICIPANT:
                return t('You are not signed up for this event.');
            case self::NOT_CHECKED_IN:
                return t('You are not checked in yet. Please check in at the entrance first.');
            case self::CLOSED:
                return t('Your tab is already settled.');
        }

        return '';
    }

    /**
     * Puts the lines on the tab; returns the tab order and its new total.
     *
     * @param array<int, array{product: mixed, qty: int, price: float}> $lines
     *
     * @return array{order: Order, total: float}
     *
     * @throws UserMessageException
     */
    public function add(Event $event, int $uID, array $lines, int $byUID, float $limit = 0.0): array
    {
        $status = $this->status($event, $uID);
        if ($status['state'] !== self::OK) {
            throw new UserMessageException(self::message($status['state']));
        }
        $sum = 0.0;
        foreach ($lines as $line) {
            $sum += $line['price'] * $line['qty'];
        }
        if ($limit > 0 && round($status['total'] + $sum, 2) > $limit) {
            throw new UserMessageException(t('This would take your tab over the limit of %s. Please settle it at the counter first.', number_format($limit, 2)));
        }
        $items = array_map(static function ($line) {
            return ['product' => $line['product'], 'qty' => $line['qty']];
        }, $lines);
        $order = app(TabService::class)->openOrder($status['participant'], $items, $byUID);

        return ['order' => $order, 'total' => $this->orderTotal($order)];
    }

    /**
     * Summed in the database: items added in this request are not in the order's item collection yet.
     */
    public function orderTotal(Order $order): float
    {
        return round((float) app(Connection::class)->fetchOne('SELECT COALESCE(SUM(oiPricePaid * oiQty), 0) FROM CommunityStoreOrderItems WHERE oID = ?', [(int) $order->getOrderID()]), 2);
    }

    /**
     * Events a block can be bound to: published events with a tab, newest first.
     *
     * @return array<int, string>
     */
    public function eventOptions(): array
    {
        if (!self::isAvailable()) {
            return [];
        }
        $events = array_filter(app(EventService::class)->getList(['status' => [Event::STATUS_PUBLISHED, Event::STATUS_ARCHIVED]]), static function (Event $event) {
            return $event->isTabEnabled();
        });
        usort($events, static function (Event $a, Event $b) {
            return ($b->getStartsAt() ? $b->getStartsAt()->getTimestamp() : 0) <=> ($a->getStartsAt() ? $a->getStartsAt()->getTimestamp() : 0);
        });
        $options = [];
        foreach ($events as $event) {
            $options[$event->getID()] = $event->getName() . ($event->getStartsAt() ? ' (' . $event->getStartsAt()->format('d.m.Y') . ')' : '');
        }

        return $options;
    }
}
