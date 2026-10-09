<?php

namespace CommunityStoreQuickPos\Controller;

use CommunityStoreQuickPos\Badge\BadgeMatch;
use CommunityStoreQuickPos\Badge\BadgeService;
use CommunityStoreQuickPos\Device\DeviceService;
use CommunityStoreQuickPos\PosBlock;
use CommunityStoreQuickPos\Sale\EventTab;
use CommunityStoreQuickPos\Sale\SaleService;
use CommunityStoreQuickPos\Settings;
use Concrete\Core\Block\Block;
use Concrete\Core\Controller\Controller;
use Concrete\Core\Error\UserMessageException;
use Symfony\Component\HttpFoundation\JsonResponse;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * JSON endpoints of the self-service kiosk (/ccm/quick_pos/kiosk/…). Every request needs an enrolled device (cookie,
 * SameSite=Strict, so no CSRF token is needed and a tablet can stay open for days). A scan identifies the customer
 * for this device's session; the badge is never sent back. Paying needs a second scan of the same person's badge.
 */
class KioskApi extends Controller
{
    public const BLOCK = 'quick_pos_kiosk';
    public const ENROLL_TOKEN = 'quick_pos_enroll';
    public const SESSION = 'quick_pos.kiosk';

    /**
     * A signed-in staff member registers this browser as a kiosk.
     */
    public function enroll(): JsonResponse
    {
        Settings::useSiteLocale();
        if (!$this->app->make('token')->validate(self::ENROLL_TOKEN, (string) $this->request->request->get('ccm_token'))) {
            return $this->fail(t('Your session expired. Please reload the page.'), 400, 'token');
        }
        if (!Settings::isStaff()) {
            return $this->fail(t('Only staff can register a kiosk.'), 403, 'staff');
        }
        $response = new JsonResponse(['ok' => true]);
        $id = $this->app->make(DeviceService::class)->enroll((string) $this->request->request->get('name', ''), Settings::currentUserID(), $response, $this->request);
        $response->setData(['ok' => true, 'deviceID' => $id]);

        return $response;
    }

    /**
     * Badge at the reader on the start screen: who is it, and may they buy?
     */
    public function scan(): JsonResponse
    {
        [$device, $block, $error] = $this->context();
        if ($error) {
            return $error;
        }
        $this->session()->remove(self::SESSION);
        $match = $this->match($device['id'], (string) $this->request->request->get('tag', ''));
        if ($match instanceof JsonResponse) {
            return $match;
        }
        $controller = $block->getController();
        $event = PosBlock::event((int) $controller->eventID);
        $status = $this->app->make(EventTab::class)->status($event, (int) $match->user->getUserID());
        $sales = $this->app->make(SaleService::class);
        // amounts go out as numbers: the browser formats them like the page did (API routes may run in another locale)
        $customer = PosBlock::person($match->user) + ['seat' => $status['seat'], 'tabTotal' => $status['total']];
        if ($status['state'] !== EventTab::OK) {
            return new JsonResponse(['ok' => false, 'code' => $status['state'], 'error' => EventTab::message($status['state']), 'customer' => $customer], 403);
        }
        $this->session()->set(self::SESSION, ['uID' => (int) $match->user->getUserID(), 'deviceID' => $device['id'], 'bID' => (int) $block->getBlockID(), 'startedAt' => time()]);
        $catalog = PosBlock::catalog($event, $controller->groups);
        $products = $catalog->toArray()['products'];

        return new JsonResponse([
            'ok' => true,
            'customer' => $customer,
            'favourites' => $sales->favourites((int) $match->user->getUserID(), array_column($products, 'id')),
            'stock' => array_column($products, 'stock', 'id'),
        ]);
    }

    /**
     * Pay: the cart goes on the customer's event tab, confirmed by scanning the badge again.
     */
    public function checkout(): JsonResponse
    {
        [$device, $block, $error] = $this->context();
        if ($error) {
            return $error;
        }
        $session = $this->session()->get(self::SESSION);
        if (!is_array($session) || (int) $session['deviceID'] !== $device['id'] || (int) $session['bID'] !== (int) $block->getBlockID()
            || (int) $session['startedAt'] < time() - Settings::int('kiosk.session_max', 60, 3600)) {
            $this->session()->remove(self::SESSION);

            return $this->fail(t('Your session ran out. Please scan your badge again.'), 401, 'session');
        }
        $match = $this->match($device['id'], (string) $this->request->request->get('tag', ''));
        if ($match instanceof JsonResponse) {
            return $match;
        }
        if ((int) $match->user->getUserID() !== (int) $session['uID']) {
            return $this->fail(t('This is not the badge you signed in with.'), 409, 'other_badge');
        }
        $controller = $block->getController();
        $event = PosBlock::event((int) $controller->eventID);
        $catalog = PosBlock::catalog($event, $controller->groups);
        $items = json_decode((string) $this->request->request->get('items', '{}'), true);
        try {
            $receipt = $this->app->make(SaleService::class)->sell(
                (string) $this->request->request->get('uuid', ''),
                $catalog,
                is_array($items) ? $items : [],
                SaleService::METHOD_TAB,
                (int) $session['uID'],
                ['source' => SaleService::SOURCE_KIOSK, 'bID' => (int) $block->getBlockID(), 'deviceID' => $device['id'], 'event' => $event, 'tabLimit' => (float) $controller->tabLimit]
            );
        } catch (UserMessageException $e) {
            return $this->fail($e->getMessage(), 409, 'sale');
        }
        // keep shopping is allowed, the session goes on (until it runs out)
        $this->session()->set(self::SESSION, ['startedAt' => time()] + $session);

        return new JsonResponse(['ok' => true, 'receipt' => $receipt, 'stock' => $catalog->stockOf(array_column($receipt['lines'], 'pID'))]);
    }

    public function logout(): JsonResponse
    {
        $this->session()->remove(self::SESSION);

        return new JsonResponse(['ok' => true]);
    }

    /**
     * @return array{0: array|null, 1: Block|null, 2: JsonResponse|null}
     */
    protected function context(): array
    {
        Settings::useSiteLocale();
        $device = $this->app->make(DeviceService::class)->current($this->request);
        if ($device === null) {
            return [null, null, $this->fail(t('This device is not registered as a kiosk.'), 403, 'device')];
        }
        $block = PosBlock::load((int) $this->request->request->get('bID'), self::BLOCK);
        if ($block === null) {
            return [null, null, $this->fail(t('This kiosk was removed. Please reload the page.'), 404, 'block')];
        }

        return [$device, $block, null];
    }

    /**
     * @return BadgeMatch|JsonResponse the match, or the error to send
     */
    protected function match(int $deviceID, string $tag)
    {
        $devices = $this->app->make(DeviceService::class);
        $locked = $devices->lockedFor($deviceID);
        if ($locked > 0) {
            return $this->fail(t('Too many unknown badges. Please wait %s seconds.', $locked), 429, 'locked', ['seconds' => $locked]);
        }
        $match = $this->app->make(BadgeService::class)->find($tag);
        if (!$match->isOk()) {
            if ($devices->recordFailure($deviceID)) {
                $seconds = $devices->lockedFor($deviceID);

                return $this->fail(t('Too many unknown badges. Please wait %s seconds.', $seconds), 429, 'locked', ['seconds' => $seconds]);
            }

            return $this->fail($match->message(), $match->status === BadgeMatch::UNKNOWN ? 404 : 409, $match->status === BadgeMatch::UNKNOWN ? 'unknown' : 'reread');
        }
        $devices->resetFailures($deviceID);

        return $match;
    }

    protected function session()
    {
        return $this->app->make('session');
    }

    protected function fail(string $message, int $status, string $code, array $extra = []): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'code' => $code, 'error' => $message] + $extra, $status);
    }
}
