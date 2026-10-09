<?php

namespace Concrete\Package\CommunityStoreQuickPos\Controller\SinglePage\Dashboard\Store;

use CommunityStoreQuickPos\Badge\BadgeService;
use CommunityStoreQuickPos\Device\DeviceService;
use CommunityStoreQuickPos\Settings;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\User\UserInfoRepository;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Store › Quick POS: kiosk devices, badges and settings. Who may view this page counts as POS staff.
 */
class QuickPos extends DashboardPageController
{
    public function view()
    {
        $badges = $this->app->make(BadgeService::class);
        $keywords = trim((string) $this->request->query->get('keywords', ''));
        $this->set('tab', (string) $this->request->query->get('tab', 'badges'));
        $this->set('keywords', $keywords);
        $this->set('badges', $badges->getList($keywords, (bool) $this->request->query->get('revoked')));
        $this->set('devices', $this->app->make(DeviceService::class)->getList());
        $this->set('settings', [
            'min_length' => Settings::int('badge.min_length', 4, 32),
            'max_missing' => Settings::int('badge.max_missing', 0, 8),
            'session_max' => Settings::int('kiosk.session_max', 60, 3600),
            'max_failures' => Settings::int('kiosk.max_failures', 1, 100),
            'lock_seconds' => Settings::int('kiosk.lock_seconds', 5, 3600),
        ]);
        $this->set('attributeBadges', (int) $this->app->make('database')->connection()->fetchOne(
            "SELECT COUNT(*) FROM UserAttributeValues uav INNER JOIN AttributeKeys ak ON ak.akID = uav.akID WHERE ak.akHandle = 'badge_id'"
        ));
    }

    public function assign_badge()
    {
        if ($this->check('assign_badge')) {
            $user = $this->app->make(UserInfoRepository::class)->getByName(trim((string) $this->request->request->get('user')))
                ?: $this->app->make(UserInfoRepository::class)->getByEmail(trim((string) $this->request->request->get('user')));
            try {
                if (!$user) {
                    throw new UserMessageException(t('No user with this name or e-mail.'));
                }
                $this->app->make(BadgeService::class)->assign((int) $user->getUserID(), (string) $this->request->request->get('tag'), Settings::currentUserID());
                $this->flash('success', t('Badge assigned to %s.', $user->getUserName()));
            } catch (UserMessageException $e) {
                $this->flash('error', $e->getMessage());
            }
        }

        return $this->buildRedirect($this->action() . '?tab=badges');
    }

    public function revoke_badge($id = null)
    {
        if ($this->check('revoke_badge')) {
            $this->app->make(BadgeService::class)->revoke((int) $id);
            $this->flash('success', t('Badge revoked.'));
        }

        return $this->buildRedirect($this->action() . '?tab=badges');
    }

    public function import_badges()
    {
        if ($this->check('import_badges')) {
            $result = $this->app->make(BadgeService::class)->importFromAttribute('badge_id', Settings::currentUserID());
            $message = t('%s badges imported, %s refreshed.', $result['imported'], $result['refreshed']);
            if ($result['skipped']) {
                $message .= ' ' . t('Skipped: %s', implode('; ', $result['skipped']));
            }
            $this->flash('success', $message);
        }

        return $this->buildRedirect($this->action() . '?tab=badges');
    }

    public function rename_device($id = null)
    {
        if ($this->check('rename_device')) {
            $this->app->make(DeviceService::class)->rename((int) $id, (string) $this->request->request->get('name'));
            $this->flash('success', t('Device renamed.'));
        }

        return $this->buildRedirect($this->action() . '?tab=devices');
    }

    public function revoke_device($id = null)
    {
        if ($this->check('revoke_device')) {
            $this->app->make(DeviceService::class)->revoke((int) $id);
            $this->flash('success', t('Device revoked: it no longer works as a kiosk.'));
        }

        return $this->buildRedirect($this->action() . '?tab=devices');
    }

    public function save_settings()
    {
        if ($this->check('save_settings')) {
            $post = $this->request->request;
            Settings::save('badge.min_length', max(4, min(32, (int) $post->get('min_length'))));
            Settings::save('badge.max_missing', max(0, min(8, (int) $post->get('max_missing'))));
            Settings::save('kiosk.session_max', max(60, min(3600, (int) $post->get('session_max'))));
            Settings::save('kiosk.max_failures', max(1, min(100, (int) $post->get('max_failures'))));
            Settings::save('kiosk.lock_seconds', max(5, min(3600, (int) $post->get('lock_seconds'))));
            $this->flash('success', t('Settings saved.'));
        }

        return $this->buildRedirect($this->action() . '?tab=settings');
    }

    protected function check(string $action): bool
    {
        if (!$this->request->isMethod('POST') || !$this->token->validate($action)) {
            $this->flash('error', $this->token->getErrorMessage());

            return false;
        }

        return true;
    }
}
