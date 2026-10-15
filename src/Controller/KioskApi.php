<?php

namespace CommunityStoreQuickPos\Controller;

use CommunityStoreQuickPos\Badge\BadgeMatch;
use CommunityStoreQuickPos\Badge\BadgeService;
use CommunityStoreQuickPos\Device\DeviceService;
use CommunityStoreQuickPos\Kiosk;
use CommunityStoreQuickPos\PosBlock;
use CommunityStoreQuickPos\Sale\EventTab;
use CommunityStoreQuickPos\Sale\SaleService;
use CommunityStoreQuickPos\Settings;
use Concrete\Core\Controller\Controller;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Symfony\Component\HttpFoundation\JsonResponse;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * JSON endpoints of the self-service kiosk (/ccm/quick_pos/kiosk/…), each for a kiosk page ("cID"). Every request
 * needs an enrolled device (cookie, SameSite=Strict, so no CSRF token is needed and a tablet can stay open for
 * days). A scan identifies the customer for this device's session; the badge is never sent back. Paying needs a
 * second scan of the same person's badge. Signed-in users who may edit the kiosk page need no badge: "self" signs
 * them in as themselves (CSRF token from the page), and their checkout is confirmed with a button instead.
 */
class KioskApi extends Controller
{
    public const ENROLL_TOKEN = 'quick_pos_enroll';
    public const SELF_TOKEN = 'quick_pos_self';
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
        [$device, $page, $error] = $this->context();
        if ($error) {
            return $error;
        }
        $this->session()->remove(self::SESSION);
        $match = $this->match($device['id'], (string) $this->request->request->get('tag', ''));
        if ($match instanceof JsonResponse) {
            return $match;
        }

        return $this->signIn($device, $page, $match->user, false);
    }

    /**
     * No badge: a signed-in user who may edit the kiosk's page shops as themselves.
     */
    public function self(): JsonResponse
    {
        [$device, $page, $error] = $this->context();
        if ($error) {
            return $error;
        }
        $this->session()->remove(self::SESSION);
        if (!$this->app->make('token')->validate(self::SELF_TOKEN, (string) $this->request->request->get('ccm_token'))) {
            return $this->fail(t('Your session expired. Please reload the page.'), 400, 'token');
        }
        $user = $this->editor($page);
        if ($user === null) {
            return $this->fail(t('Only people who may edit this page can use the kiosk without a badge.'), 403, 'editor');
        }

        return $this->signIn($device, $page, $user, true);
    }

    /**
     * Starts the customer's kiosk session (after a badge match, or as the page editor: $self).
     */
    protected function signIn(array $device, Page $page, UserInfo $user, bool $self): JsonResponse
    {
        $event = Kiosk::event($page);
        $status = $this->app->make(EventTab::class)->status($event, (int) $user->getUserID());
        $sales = $this->app->make(SaleService::class);
        // amounts go out as numbers: the browser formats them like the page did (API routes may run in another locale)
        $customer = PosBlock::person($user) + ['seat' => $status['seat'], 'tabTotal' => $status['total']];
        if ($status['state'] !== EventTab::OK) {
            return new JsonResponse(['ok' => false, 'code' => $status['state'], 'error' => EventTab::message($status['state']), 'customer' => $customer], 403);
        }
        $this->session()->set(self::SESSION, ['uID' => (int) $user->getUserID(), 'deviceID' => $device['id'], 'cID' => (int) $page->getCollectionID(), 'self' => $self, 'startedAt' => time()]);
        $catalog = Kiosk::catalog($page, $event);
        $products = $catalog->toArray()['products'];

        return new JsonResponse([
            'ok' => true,
            'customer' => $customer,
            'favourites' => $sales->favourites((int) $user->getUserID(), array_column($products, 'id')),
            'stock' => array_column($products, 'stock', 'id'),
        ]);
    }

    /**
     * Pay: the cart goes on the customer's event tab, confirmed by scanning the badge again (page editors: by the
     * page's token, they are still signed in as the same user).
     */
    public function checkout(): JsonResponse
    {
        [$device, $page, $error] = $this->context();
        if ($error) {
            return $error;
        }
        $session = $this->session()->get(self::SESSION);
        if (!is_array($session) || (int) $session['deviceID'] !== $device['id'] || (int) ($session['cID'] ?? 0) !== (int) $page->getCollectionID()
            || (int) $session['startedAt'] < time() - Settings::int('kiosk.session_max', 60, 3600)) {
            $this->session()->remove(self::SESSION);

            return $this->fail(t('Your session ran out. Please scan your badge again.'), 401, 'session');
        }
        if (!empty($session['self'])) {
            if (!$this->app->make('token')->validate(self::SELF_TOKEN, (string) $this->request->request->get('ccm_token'))) {
                return $this->fail(t('Your session expired. Please reload the page.'), 400, 'token');
            }
            $user = $this->editor($page);
            if ($user === null || (int) $user->getUserID() !== (int) $session['uID']) {
                $this->session()->remove(self::SESSION);

                return $this->fail(t('Your session ran out. Please sign in again.'), 401, 'session');
            }
        } else {
            $match = $this->match($device['id'], (string) $this->request->request->get('tag', ''));
            if ($match instanceof JsonResponse) {
                return $match;
            }
            if ((int) $match->user->getUserID() !== (int) $session['uID']) {
                return $this->fail(t('This is not the badge you signed in with.'), 409, 'other_badge');
            }
        }
        $event = Kiosk::event($page);
        $catalog = Kiosk::catalog($page, $event);
        $settings = Kiosk::settings($page);
        $items = json_decode((string) $this->request->request->get('items', '{}'), true);
        try {
            $receipt = $this->app->make(SaleService::class)->sell(
                (string) $this->request->request->get('uuid', ''),
                $catalog,
                is_array($items) ? $items : [],
                SaleService::METHOD_TAB,
                (int) $session['uID'],
                ['source' => SaleService::SOURCE_KIOSK, 'cID' => (int) $page->getCollectionID(), 'deviceID' => $device['id'], 'event' => $event, 'tabLimit' => $settings['tabLimit']]
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
     * @return array{0: array|null, 1: Page|null, 2: JsonResponse|null} the device, the kiosk page, or the error to send
     */
    protected function context(): array
    {
        Settings::useSiteLocale();
        $device = $this->app->make(DeviceService::class)->current($this->request);
        if ($device === null) {
            return [null, null, $this->fail(t('This device is not registered as a kiosk.'), 403, 'device')];
        }
        $page = Kiosk::page((int) $this->request->request->get('cID'));
        if ($page === null) {
            return [null, null, $this->fail(t('This kiosk was removed. Please reload the page.'), 404, 'page')];
        }

        return [$device, $page, null];
    }

    /**
     * The signed-in user, if they may edit the kiosk page.
     */
    protected function editor(Page $page): ?UserInfo
    {
        if (!Settings::canEditPage($page)) {
            return null;
        }
        $user = $this->app->make(UserInfoRepository::class)->getByID(Settings::currentUserID());

        return $user instanceof UserInfo && $user->isActive() ? $user : null;
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
