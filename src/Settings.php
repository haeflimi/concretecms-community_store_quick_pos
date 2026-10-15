<?php

namespace CommunityStoreQuickPos;

use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\User;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Package settings (config/settings.php, overrides saved from the dashboard) and the staff check.
 */
class Settings
{
    public const DASHBOARD_PATH = '/dashboard/store/quick_pos';

    public static function get(string $key, $default = null)
    {
        return Application::getFacadeApplication()->make(Repository::class)->get('community_store_quick_pos::settings.' . $key, $default);
    }

    public static function save(string $key, $value): void
    {
        Application::getFacadeApplication()->make(Repository::class)->save('community_store_quick_pos::settings.' . $key, $value);
    }

    public static function int(string $key, int $min, int $max): int
    {
        return max($min, min($max, (int) self::get($key)));
    }

    /**
     * Staff = a signed-in user who may view the package's dashboard page (Store › Quick POS). The page permissions
     * decide, as for the event manager's check-in desk.
     */
    public static function isStaff(): bool
    {
        $user = Application::getFacadeApplication()->make(User::class);
        if (!$user->isRegistered()) {
            return false;
        }
        $page = Page::getByPath(self::DASHBOARD_PATH);

        return $page && !$page->isError() && (new Checker($page))->canViewPage();
    }

    /**
     * May the signed-in user edit this page? Editors use the kiosk without a badge (as themselves).
     */
    public static function canEditPage(?Page $page): bool
    {
        $user = Application::getFacadeApplication()->make(User::class);
        if (!$user->isRegistered() || !$page || $page->isError()) {
            return false;
        }

        return (new Checker($page))->canEditPageContents();
    }

    /**
     * API routes don't run in the page's locale: switch to the site's default locale so messages and names match the
     * page the POS runs on.
     */
    public static function useSiteLocale(): void
    {
        $site = Application::getFacadeApplication()->make('site')->getSite();
        $locale = $site && $site->getDefaultLocale() ? $site->getDefaultLocale()->getLocale() : null;
        if ($locale) {
            \Concrete\Core\Localization\Localization::changeLocale($locale);
        }
    }

    public static function currentUserID(): int
    {
        $user = Application::getFacadeApplication()->make(User::class);

        return $user->isRegistered() ? (int) $user->getUserID() : 0;
    }
}
