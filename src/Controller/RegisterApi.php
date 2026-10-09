<?php

namespace CommunityStoreQuickPos\Controller;

use CommunityStoreCredit\Service\CreditService;
use CommunityStoreQuickPos\Badge\BadgeService;
use CommunityStoreQuickPos\PosBlock;
use CommunityStoreQuickPos\Sale\EventTab;
use CommunityStoreQuickPos\Sale\SaleService;
use CommunityStoreQuickPos\Settings;
use Concrete\Core\Block\Block;
use Concrete\Core\Controller\Controller;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * JSON endpoints of the staff register (/ccm/quick_pos/register/…): staff only (view permission on Store › Quick
 * POS) and the "quick_pos_register" token. Customers are picked by badge or name; badge ids never leave the server.
 */
class RegisterApi extends Controller
{
    public const BLOCK = 'quick_pos_register';
    public const TOKEN = 'quick_pos_register';

    /**
     * A customer by badge ("tag") or user ID, with credit balance and event tab.
     */
    public function customer(): JsonResponse
    {
        [$block, $error] = $this->context();
        if ($error) {
            return $error;
        }
        $tag = (string) $this->request->request->get('tag', '');
        if ($tag !== '') {
            $match = $this->app->make(BadgeService::class)->find($tag);
            if (!$match->isOk()) {
                return $this->fail($match->message(), 404, $match->status);
            }
            $user = $match->user;
        } else {
            $user = $this->app->make(UserInfoRepository::class)->getByID((int) $this->request->request->get('uID'));
            if (!$user instanceof UserInfo || !$user->isActive()) {
                return $this->fail(t('Unknown customer.'), 404, 'unknown');
            }
        }

        return new JsonResponse(['ok' => true, 'customer' => $this->customerData($block, $user)]);
    }

    /**
     * Name search (at least 2 characters, 10 hits).
     */
    public function search(): JsonResponse
    {
        [, $error] = $this->context();
        if ($error) {
            return $error;
        }
        $q = trim((string) $this->request->request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return new JsonResponse(['ok' => true, 'results' => []]);
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $rows = $this->app->make(Connection::class)->fetchAllAssociative(
            'SELECT uID, uName, uEmail FROM Users WHERE uIsActive = 1 AND (uName LIKE ? OR uEmail LIKE ?) ORDER BY uName ASC LIMIT 10',
            [$like, $like]
        );

        return new JsonResponse(['ok' => true, 'results' => array_map(static function ($row) {
            return ['uID' => (int) $row['uID'], 'name' => (string) $row['uName'], 'email' => (string) $row['uEmail']];
        }, $rows)]);
    }

    public function sell(): JsonResponse
    {
        [$block, $error] = $this->context();
        if ($error) {
            return $error;
        }
        $controller = $block->getController();
        $method = (string) $this->request->request->get('method', '');
        if (!in_array($method, $controller->getMethods(), true)) {
            return $this->fail(t('This payment method is not offered here.'), 400, 'method');
        }
        $event = PosBlock::event((int) $controller->eventID);
        $catalog = PosBlock::catalog($event, $controller->groups);
        $items = json_decode((string) $this->request->request->get('items', '{}'), true);
        $uID = (int) $this->request->request->get('uID', 0);
        try {
            $receipt = $this->app->make(SaleService::class)->sell(
                (string) $this->request->request->get('uuid', ''),
                $catalog,
                is_array($items) ? $items : [],
                $method,
                $uID,
                ['source' => SaleService::SOURCE_REGISTER, 'bID' => (int) $block->getBlockID(), 'staffUID' => Settings::currentUserID(), 'event' => $event]
            );
        } catch (UserMessageException $e) {
            return $this->fail($e->getMessage(), 409, 'sale');
        }
        $user = $uID > 0 ? $this->app->make(UserInfoRepository::class)->getByID($uID) : null;

        return new JsonResponse([
            'ok' => true,
            'receipt' => $receipt,
            'stock' => $catalog->stockOf(array_column($receipt['lines'], 'pID')),
            'customer' => $user ? $this->customerData($block, $user) : null,
        ]);
    }

    public function topup(): JsonResponse
    {
        [$block, $error] = $this->context();
        if ($error) {
            return $error;
        }
        if (!$block->getController()->allowsTopUp()) {
            return $this->fail(t('Top-ups are not offered here.'), 400, 'method');
        }
        $uID = (int) $this->request->request->get('uID', 0);
        try {
            $receipt = $this->app->make(SaleService::class)->topUp(
                (string) $this->request->request->get('uuid', ''),
                $uID,
                (float) str_replace(',', '.', (string) $this->request->request->get('amount', '0')),
                (string) $this->request->request->get('method', ''),
                ['bID' => (int) $block->getBlockID(), 'staffUID' => Settings::currentUserID()]
            );
        } catch (UserMessageException $e) {
            return $this->fail($e->getMessage(), 409, 'topup');
        }

        return new JsonResponse(['ok' => true, 'receipt' => $receipt, 'customer' => $this->customerData($block, $this->app->make(UserInfoRepository::class)->getByID($uID))]);
    }

    /**
     * Gives the scanned badge to the customer.
     */
    public function assign(): JsonResponse
    {
        [$block, $error] = $this->context();
        if ($error) {
            return $error;
        }
        $uID = (int) $this->request->request->get('uID', 0);
        try {
            $this->app->make(BadgeService::class)->assign($uID, (string) $this->request->request->get('tag', ''), Settings::currentUserID());
        } catch (UserMessageException $e) {
            return $this->fail($e->getMessage(), 409, 'assign');
        }

        return new JsonResponse(['ok' => true, 'customer' => $this->customerData($block, $this->app->make(UserInfoRepository::class)->getByID($uID))]);
    }

    public function recent(): JsonResponse
    {
        [$block, $error] = $this->context();
        if ($error) {
            return $error;
        }

        return new JsonResponse(['ok' => true, 'sales' => $this->app->make(SaleService::class)->recent((int) $block->getBlockID())]);
    }

    protected function customerData(Block $block, UserInfo $user): array
    {
        $event = PosBlock::event((int) $block->getController()->eventID);
        $tab = $this->app->make(EventTab::class)->status($event, (int) $user->getUserID());
        $balance = SaleService::creditAvailable() ? $this->app->make(CreditService::class)->getBalance((int) $user->getUserID()) : null;

        return PosBlock::person($user) + [
            'email' => (string) $user->getUserEmail(),
            'badges' => $this->app->make(BadgeService::class)->countForUser((int) $user->getUserID()),
            'balance' => $balance,
            'tab' => [
                'state' => $tab['state'],
                'ok' => $tab['state'] === EventTab::OK,
                'message' => EventTab::message($tab['state']),
                'total' => $tab['total'],
                'seat' => $tab['seat'],
            ],
        ];
    }

    /**
     * @return array{0: Block|null, 1: JsonResponse|null}
     */
    protected function context(): array
    {
        Settings::useSiteLocale();
        if (!$this->app->make('token')->validate(self::TOKEN, (string) $this->request->request->get('ccm_token'))) {
            return [null, $this->fail(t('Your session expired. Please reload the page.'), 400, 'token')];
        }
        if (!Settings::isStaff()) {
            return [null, $this->fail(t('Only staff can use the register.'), 403, 'staff')];
        }
        $block = PosBlock::load((int) $this->request->request->get('bID'), self::BLOCK);
        if ($block === null) {
            return [null, $this->fail(t('This register was removed. Please reload the page.'), 404, 'block')];
        }

        return [$block, null];
    }

    protected function fail(string $message, int $status, string $code): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'code' => $code, 'error' => $message], $status);
    }
}
