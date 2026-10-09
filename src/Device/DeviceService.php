<?php

namespace CommunityStoreQuickPos\Device;

use CommunityStoreQuickPos\Settings;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use DateTime;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Kiosk devices. A staff member enrolls a device once: it gets a random token in an httpOnly, SameSite=Strict
 * cookie, the database keeps the token's hash. Kiosk requests without a known device are refused, so the kiosk
 * works for guests on that tablet only, never from any other browser. Because the cookie is SameSite=Strict, other
 * sites can't make the tablet send kiosk requests either.
 */
class DeviceService
{
    public const COOKIE = 'qpos_device';

    /** @var Connection */
    protected $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * The enrolled device of this request, null if none (or revoked).
     *
     * @return array{id: int, name: string}|null
     */
    public function current(Request $request): ?array
    {
        $token = (string) $request->cookies->get(self::COOKIE, '');
        if (strlen($token) !== 64) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT id, name, lastSeenAt FROM QuickPosDevices WHERE tokenHash = ? AND revokedAt IS NULL', [hash('sha256', $token)]);
        if (!$row) {
            return null;
        }
        // one write a minute is enough to see which tablets are alive
        if ($row['lastSeenAt'] === null || strtotime((string) $row['lastSeenAt']) < time() - 60) {
            $this->db->executeStatement('UPDATE QuickPosDevices SET lastSeenAt = ? WHERE id = ?', [(new DateTime())->format('Y-m-d H:i:s'), (int) $row['id']]);
        }

        return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    /**
     * Registers this browser as a kiosk and puts the token cookie on the response.
     */
    public function enroll(string $name, int $byUID, Response $response, Request $request): int
    {
        $token = bin2hex(random_bytes(32));
        $this->db->insert('QuickPosDevices', [
            'name' => mb_substr(trim($name) !== '' ? trim($name) : t('Kiosk'), 0, 100),
            'tokenHash' => hash('sha256', $token),
            'createdAt' => (new DateTime())->format('Y-m-d H:i:s'),
            'createdBy' => $byUID ?: null,
        ]);
        $id = (int) $this->db->lastInsertId();
        $response->headers->setCookie(Cookie::create(self::COOKIE, $token, new DateTime('+400 days'), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_STRICT));

        return $id;
    }

    public function revoke(int $id): void
    {
        $this->db->executeStatement('UPDATE QuickPosDevices SET revokedAt = ? WHERE id = ? AND revokedAt IS NULL', [(new DateTime())->format('Y-m-d H:i:s'), $id]);
    }

    public function rename(int $id, string $name): void
    {
        if (trim($name) !== '') {
            $this->db->update('QuickPosDevices', ['name' => mb_substr(trim($name), 0, 100)], ['id' => $id]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getList(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT d.id, d.name, d.createdAt, d.lastSeenAt, d.revokedAt, u.uName AS createdByName FROM QuickPosDevices d'
            . ' LEFT JOIN Users u ON u.uID = d.createdBy ORDER BY d.revokedAt IS NULL DESC, d.name ASC'
        );
    }

    // ---- rate limit: unknown badges per device

    /**
     * Seconds the device is still locked, 0 if it may scan.
     */
    public function lockedFor(int $deviceID): int
    {
        return max(0, (int) $this->db->fetchOne('SELECT lockedUntil FROM QuickPosDevices WHERE id = ?', [$deviceID]) - time());
    }

    /**
     * Counts an unknown or unreadable badge; locks the device when there are too many within a minute.
     *
     * @return bool true if the device is now locked
     */
    public function recordFailure(int $deviceID): bool
    {
        $row = $this->db->fetchAssociative('SELECT failCount, failSince FROM QuickPosDevices WHERE id = ?', [$deviceID]);
        $count = $row && (int) $row['failSince'] >= time() - 60 ? (int) $row['failCount'] + 1 : 1;
        $since = $count === 1 ? time() : (int) $row['failSince'];
        if ($count >= Settings::int('kiosk.max_failures', 1, 100)) {
            $this->db->update('QuickPosDevices', ['failCount' => 0, 'failSince' => 0, 'lockedUntil' => time() + Settings::int('kiosk.lock_seconds', 5, 3600)], ['id' => $deviceID]);

            return true;
        }
        $this->db->update('QuickPosDevices', ['failCount' => $count, 'failSince' => $since], ['id' => $deviceID]);

        return false;
    }

    public function resetFailures(int $deviceID): void
    {
        $this->db->update('QuickPosDevices', ['failCount' => 0, 'failSince' => 0], ['id' => $deviceID]);
    }
}
