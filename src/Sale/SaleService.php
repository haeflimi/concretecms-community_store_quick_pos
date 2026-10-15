<?php

namespace CommunityStoreQuickPos\Sale;

use CommunityEventManager\Entity\Event;
use CommunityStoreCredit\Service\CreditService;
use CommunityStoreCredit\Service\InsufficientCreditException;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Logging\Channels;
use Concrete\Core\Logging\LoggerFactory;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderItem;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderStatus\OrderStatus;
use Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method as PaymentMethod;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price;
use DateTime;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Books POS sales. One request = one sale, identified by a uuid the client makes per cart: repeating the request
 * (double tap, retry after a timeout) returns the first result instead of booking again. Orders are built by hand and
 * never "completed" through the store, so no receipt or staff notification mails go out; stock is taken off here.
 */
class SaleService
{
    public const METHOD_TAB = 'tab';
    public const METHOD_CREDIT = 'credit';
    public const METHOD_CASH = 'cash';
    public const METHOD_CARD = 'card';
    public const METHOD_TWINT = 'twint';

    public const SOURCE_KIOSK = 'kiosk';
    public const SOURCE_REGISTER = 'register';
    public const SOURCE_TOPUP = 'topup';

    /** store payment method handle per POS method (credit belongs to community_store_credit) */
    public const PAYMENT_HANDLES = [
        self::METHOD_CREDIT => 'community_store_credit',
        self::METHOD_CASH => 'quick_pos_cash',
        self::METHOD_CARD => 'quick_pos_card',
        self::METHOD_TWINT => 'quick_pos_twint',
    ];

    /** @var Connection */
    protected $db;

    /** @var LoggerInterface */
    protected $logger;

    public function __construct(Connection $db, LoggerFactory $loggerFactory)
    {
        $this->db = $db;
        $this->logger = $loggerFactory->createLogger(Channels::CHANNEL_APPLICATION);
    }

    public static function methodNames(): array
    {
        return [
            self::METHOD_CASH => t('Cash'),
            self::METHOD_CARD => t('Card'),
            self::METHOD_TWINT => t('TWINT'),
            self::METHOD_CREDIT => t('Store credit'),
            self::METHOD_TAB => t('Event tab'),
        ];
    }

    public static function creditAvailable(): bool
    {
        return class_exists(CreditService::class) && PaymentMethod::getByHandle(self::PAYMENT_HANDLES[self::METHOD_CREDIT]) !== null;
    }

    /**
     * @param array<int|string, int|string> $items pID => qty
     * @param array{source: string, bID?: int, cID?: int, deviceID?: int, staffUID?: int, event?: Event|null, tabLimit?: float} $context bID = the register block, cID = the kiosk page
     *
     * @return array receipt, see receipt()
     *
     * @throws UserMessageException
     */
    public function sell(string $uuid, Catalog $catalog, array $items, string $method, int $uID, array $context): array
    {
        $uuid = $this->checkUuid($uuid);
        if (!isset(self::methodNames()[$method])) {
            throw new UserMessageException(t('Unknown payment method.'));
        }
        if (($method === self::METHOD_TAB || $method === self::METHOD_CREDIT) && $uID <= 0) {
            throw new UserMessageException(t('Choose the customer first.'));
        }
        $existing = $this->findByUuid($uuid);
        if ($existing !== null) {
            return $existing;
        }
        $lines = $catalog->lines($items);
        $total = 0.0;
        foreach ($lines as $line) {
            $total += $line['price'] * $line['qty'];
        }
        $total = round($total, 2);
        $staffUID = (int) ($context['staffUID'] ?? 0);
        $extra = [];

        $this->db->beginTransaction();
        try {
            // reserve the uuid first: a concurrent duplicate waits on the unique index and then fails
            $saleID = $this->insertSale($uuid, $context['source'], $method, $uID, $context);
            $note = t('Quick POS sale #%s', $saleID);
            switch ($method) {
                case self::METHOD_TAB:
                    $event = $context['event'] ?? null;
                    if (!$event instanceof Event) {
                        throw new UserMessageException(EventTab::message(EventTab::NO_EVENT));
                    }
                    $tab = app(EventTab::class)->add($event, $uID, $lines, $staffUID ?: $uID, (float) ($context['tabLimit'] ?? 0));
                    $order = $tab['order'];
                    $extra['tabTotal'] = $tab['total'];
                    break;
                case self::METHOD_CREDIT:
                    if (!self::creditAvailable()) {
                        throw new UserMessageException(t('Store credit is not available.'));
                    }
                    // checked up front: a failed charge inside the credit ledger's transactional() closes the
                    // entity manager, so that path is left to true races only
                    $balance = app(CreditService::class)->getBalance($uID);
                    if (round($balance - $total, 2) < 0) {
                        throw new UserMessageException(t('The store credit (%s) does not cover %s.', $this->money($balance), $this->money($total)));
                    }
                    $order = $this->createOrder($uID, $method, $lines, $note);
                    try {
                        $entry = app(CreditService::class)->charge($uID, $total, $note, 'pos:' . $uuid, $staffUID ?: $uID);
                    } catch (InsufficientCreditException $e) {
                        throw new UserMessageException(t('The store credit (%s) does not cover %s.', $this->money($e->getBalance()), $this->money($total)));
                    }
                    // the credit package's listener ties the entry to the order and refunds it if the order is cancelled
                    $order->setTransactionReference('credit:' . $entry->getID());
                    $this->markPaid($order, $staffUID);
                    $extra['balance'] = app(CreditService::class)->getBalance($uID);
                    break;
                default:
                    $order = $this->createOrder($uID, $method, $lines, $note);
                    $this->markPaid($order, $staffUID);
            }
            $this->takeStock($lines);
            $receiptLines = array_map(function ($line) {
                return ['pID' => $line['pID'], 'name' => $line['name'], 'qty' => $line['qty'], 'price' => $line['price'], 'lineTotal' => $this->money($line['price'] * $line['qty'])];
            }, $lines);
            $this->db->update('QuickPosSales', [
                'oID' => (int) $order->getOrderID(),
                'total' => $total,
                'items' => json_encode(['lines' => $receiptLines, 'extra' => $extra]),
            ], ['id' => $saleID]);
            $this->db->commit();
        } catch (UniqueConstraintViolationException $e) {
            $this->db->rollBack();
            $existing = $this->findByUuid($uuid);
            if ($existing !== null) {
                return $existing;
            }
            throw new UserMessageException(t('The sale could not be booked, please try again.'));
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            if (!$e instanceof UserMessageException) {
                $this->logger->error('Quick POS: sale failed: ' . $e->getMessage(), ['exception' => $e]);
                throw new UserMessageException(t('The sale could not be booked, please try again.'));
            }
            throw $e;
        }
        $this->logger->info(sprintf('Quick POS: sale #%d (%s, %s, user %d, staff %d, order %d): %s.', $saleID, $context['source'], $method, $uID, $staffUID, $order->getOrderID(), $total));

        return $this->findByUuid($uuid);
    }

    /**
     * Loads credit onto a customer's account at the register (no order: a top-up is not revenue).
     *
     * @return array receipt
     *
     * @throws UserMessageException
     */
    public function topUp(string $uuid, int $uID, float $amount, string $method, array $context): array
    {
        $uuid = $this->checkUuid($uuid);
        if (!self::creditAvailable()) {
            throw new UserMessageException(t('Store credit is not available.'));
        }
        if (!in_array($method, [self::METHOD_CASH, self::METHOD_CARD, self::METHOD_TWINT], true)) {
            throw new UserMessageException(t('Unknown payment method.'));
        }
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > 1000) {
            throw new UserMessageException(t('Enter an amount between 0.05 and 1000.'));
        }
        if ($uID <= 0 || !app(UserInfoRepository::class)->getByID($uID)) {
            throw new UserMessageException(t('Choose the customer first.'));
        }
        $existing = $this->findByUuid($uuid);
        if ($existing !== null) {
            return $existing;
        }
        $staffUID = (int) ($context['staffUID'] ?? 0);
        $this->db->beginTransaction();
        try {
            $saleID = $this->insertSale($uuid, self::SOURCE_TOPUP, $method, $uID, $context);
            $comment = t('Top-up at the POS (%s), #%s', self::methodNames()[$method], $saleID);
            app(CreditService::class)->add($uID, $amount, $comment, CreditService::SOURCE_MANUAL, 'pos-topup:' . $uuid, $staffUID ?: null);
            $this->db->update('QuickPosSales', [
                'total' => $amount,
                'items' => json_encode(['lines' => [['pID' => 0, 'name' => t('Store credit top-up'), 'qty' => 1, 'price' => $amount, 'lineTotal' => $this->money($amount)]], 'extra' => ['balance' => app(CreditService::class)->getBalance($uID)]]),
            ], ['id' => $saleID]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $existing = $this->findByUuid($uuid);
            if ($existing !== null) {
                return $existing;
            }
            $this->logger->error('Quick POS: top-up failed: ' . $e->getMessage(), ['exception' => $e]);
            throw new UserMessageException(t('The top-up could not be booked, please try again.'));
        }
        $this->logger->info(sprintf('Quick POS: top-up #%d of %s for user %d by staff %d (%s).', $saleID, $amount, $uID, $staffUID, $method));

        return $this->findByUuid($uuid);
    }

    /**
     * The receipt of a booked sale.
     */
    public function findByUuid(string $uuid): ?array
    {
        // rows only exist once committed (the reservation is rolled back with a failed sale)
        $row = $this->db->fetchAssociative('SELECT * FROM QuickPosSales WHERE uuid = ?', [$uuid]);

        return $row ? $this->receipt($row) : null;
    }

    /**
     * Last sales of a register block (newest first).
     */
    public function recent(int $bID, int $limit = 10): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM QuickPosSales WHERE bID = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)), [$bID]);

        return array_map([$this, 'receipt'], $rows);
    }

    /**
     * The user's favourites: the products they bought most often at the POS (among the given ones).
     *
     * @param int[] $pIDs
     *
     * @return int[]
     */
    public function favourites(int $uID, array $pIDs, int $limit = 6): array
    {
        if ($uID <= 0 || $pIDs === []) {
            return [];
        }
        $rows = $this->db->fetchFirstColumn(
            'SELECT oi.pID FROM CommunityStoreOrderItems oi INNER JOIN QuickPosSales s ON s.oID = oi.oID'
            . ' WHERE s.uID = ? AND oi.pID IN (' . implode(',', array_map('intval', $pIDs)) . ')'
            . ' GROUP BY oi.pID ORDER BY SUM(oi.oiQty) DESC LIMIT ' . max(1, $limit),
            [$uID]
        );

        return array_map('intval', $rows);
    }

    public function money(float $amount): string
    {
        return strip_tags((string) Price::format($amount));
    }

    protected function receipt(array $row): array
    {
        $data = json_decode((string) $row['items'], true) ?: [];
        $user = (int) $row['uID'] > 0 ? app(UserInfoRepository::class)->getByID((int) $row['uID']) : null;

        return [
            'id' => (int) $row['id'],
            'number' => str_pad((string) $row['id'], 3, '0', STR_PAD_LEFT),
            'uuid' => (string) $row['uuid'],
            'source' => (string) $row['source'],
            'method' => (string) $row['method'],
            'methodName' => self::methodNames()[$row['method']] ?? (string) $row['method'],
            'oID' => $row['oID'] !== null ? (int) $row['oID'] : null,
            'uID' => (int) $row['uID'],
            'customer' => $user ? (string) $user->getUserName() : null,
            'total' => (float) $row['total'],
            'totalLabel' => $this->money((float) $row['total']),
            'lines' => $data['lines'] ?? [],
            'tabTotal' => $data['extra']['tabTotal'] ?? null,
            'balance' => $data['extra']['balance'] ?? null,
            'time' => (new DateTime((string) $row['createdAt']))->format('H:i'),
            'createdAt' => (string) $row['createdAt'],
        ];
    }

    protected function insertSale(string $uuid, string $source, string $method, int $uID, array $context): int
    {
        $this->db->insert('QuickPosSales', [
            'uuid' => $uuid,
            'source' => $source,
            'method' => $method,
            'uID' => max(0, $uID),
            'deviceID' => !empty($context['deviceID']) ? (int) $context['deviceID'] : null,
            'staffUID' => !empty($context['staffUID']) ? (int) $context['staffUID'] : null,
            'bID' => !empty($context['bID']) ? (int) $context['bID'] : null,
            'cID' => !empty($context['cID']) ? (int) $context['cID'] : null,
            'createdAt' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * A paid-at-once store order with the sale's items (cash, card, TWINT, credit).
     */
    protected function createOrder(int $uID, string $method, array $lines, string $note): Order
    {
        $pm = PaymentMethod::getByHandle(self::PAYMENT_HANDLES[$method]);
        if (!$pm) {
            throw new UserMessageException(t('The payment method %s is not installed.', self::methodNames()[$method]));
        }
        $order = new Order();
        $order->setTemporaryRecordCreated(false);
        $order->setExternalPaymentRequested(null);
        $order->setCustomerID(max(0, $uID));
        $order->setDate(new DateTime());
        $order->setPaymentMethodID((int) $pm->getID());
        $order->setPaymentMethodName(self::methodNames()[$method]);
        $order->setShippingMethodName(null);
        $order->setShippingTotal(0);
        $order->setTaxTotal('');
        $order->setTaxIncluded('');
        $order->setTaxLabels('');
        $order->setTotal(0);
        $order->setNotes($note);
        $order->save();
        $status = OrderStatus::getByHandle('delivered') ?: OrderStatus::getStartingStatus();
        $order->updateStatus($status ? $status->getHandle() : null, $note);
        if ($uID > 0 && ($user = app(UserInfoRepository::class)->getByID($uID))) {
            $order->setAttribute('email', $user->getUserEmail());
        }
        $oID = (int) $order->getOrderID();
        foreach ($lines as $line) {
            OrderItem::add(['product' => ['object' => $line['product'], 'pID' => $line['pID'], 'qty' => $line['qty'], 'customerPrice' => $line['price']], 'productAttributes' => []], $oID);
        }
        $order->setTotal(round((float) $this->db->fetchOne('SELECT COALESCE(SUM(oiPricePaid * oiQty), 0) FROM CommunityStoreOrderItems WHERE oID = ?', [$oID]), 2));
        $order->save();

        return $order;
    }

    /**
     * The store's own "paid" steps, with the order's customer (never the staff member: sameRequest = false).
     */
    protected function markPaid(Order $order, int $staffUID): void
    {
        $order->completePayment(false);
        $order->setPaidByUID($staffUID ?: null);
        $order->save();
    }

    /**
     * Same as Order::completeOrder(), which we don't call because it also sends the mails.
     */
    protected function takeStock(array $lines): void
    {
        foreach ($lines as $line) {
            $product = Product::getByID($line['pID']);
            if ($product && !$product->isUnlimited()) {
                $product->setStockLevel((float) $product->getStockLevel() - $line['qty']);
            }
        }
    }

    protected function checkUuid(string $uuid): string
    {
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $uuid)) {
            throw new UserMessageException(t('Invalid request, please start again.'));
        }

        return $uuid;
    }
}
