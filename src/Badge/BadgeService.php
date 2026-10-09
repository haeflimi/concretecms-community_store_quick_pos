<?php

namespace CommunityStoreQuickPos\Badge;

use CommunityStoreQuickPos\Settings;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Logging\Channels;
use Concrete\Core\Logging\LoggerFactory;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use DateTime;
use Psr\Log\LoggerInterface;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * RFID badges of users. Only HMACs of a badge id are stored. Because readers often cut characters off the start or
 * the end of the id, a badge also gets lookup keys for every fragment that is at most "max_missing" characters
 * shorter (and at least "min_length" long). A scan is hashed once and looked up: the badge with the fewest missing
 * characters wins, and if two people's badges match equally well the scan is refused instead of guessed.
 */
class BadgeService
{
    /** @var Connection */
    protected $db;

    /** @var UserInfoRepository */
    protected $users;

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $secret;

    public function __construct(Connection $db, UserInfoRepository $users, LoggerFactory $loggerFactory)
    {
        $this->db = $db;
        $this->users = $users;
        $this->logger = $loggerFactory->createLogger(Channels::CHANNEL_APPLICATION);
        $this->secret = (string) app('config/database')->get('community_store_quick_pos.badge_secret');
    }

    /**
     * Letters and digits only, upper case: readers send the id as keystrokes, and separators depend on the keyboard
     * layout of the device.
     */
    public static function normalize(string $tag): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($tag)));
    }

    public function hash(string $normalized): string
    {
        if ($this->secret === '') {
            throw new \RuntimeException('Quick POS: the badge secret is missing (reinstall or upgrade the package).');
        }

        return hash_hmac('sha256', $normalized, $this->secret);
    }

    /**
     * The user a scan belongs to.
     */
    public function find(string $tag): BadgeMatch
    {
        $normalized = self::normalize($tag);
        if (strlen($normalized) < $this->minLength()) {
            return new BadgeMatch(BadgeMatch::TOO_SHORT);
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT b.id, b.uID, k.missing FROM QuickPosBadgeKeys k'
            . ' INNER JOIN QuickPosBadges b ON b.id = k.badgeID'
            . ' INNER JOIN Users u ON u.uID = b.uID'
            . ' WHERE k.keyHash = ? AND b.revokedAt IS NULL AND u.uIsActive = 1'
            . ' ORDER BY k.missing ASC',
            [$this->hash($normalized)]
        );
        if ($rows === []) {
            return new BadgeMatch(BadgeMatch::UNKNOWN);
        }
        $best = (int) $rows[0]['missing'];
        $candidates = array_filter($rows, static function ($row) use ($best) {
            return (int) $row['missing'] === $best;
        });
        $userIDs = array_unique(array_map(static function ($row) {
            return (int) $row['uID'];
        }, $candidates));
        if (count($userIDs) > 1) {
            $this->logger->notice(sprintf('Quick POS: ambiguous badge read (%d characters) matches %d users.', strlen($normalized), count($userIDs)));

            return new BadgeMatch(BadgeMatch::AMBIGUOUS);
        }
        $user = $this->users->getByID((int) $rows[0]['uID']);
        if (!$user instanceof UserInfo) {
            return new BadgeMatch(BadgeMatch::UNKNOWN);
        }
        if ($best > 0) {
            // how often the reader cuts characters off: worth watching
            $this->logger->info(sprintf('Quick POS: badge of user %d recognised with %d character(s) missing.', $user->getUserID(), $best));
        }
        $this->db->executeStatement('UPDATE QuickPosBadges SET lastUsedAt = ? WHERE id = ?', [(new DateTime())->format('Y-m-d H:i:s'), (int) $rows[0]['id']]);

        return new BadgeMatch(BadgeMatch::OK, $user, (int) $rows[0]['id'], $best);
    }

    /**
     * Gives a badge to a user. A badge that belongs to somebody else is refused (revoke it there first); the same
     * badge again for the same user is a no-op.
     *
     * @throws UserMessageException
     */
    public function assign(int $uID, string $tag, int $byUID = 0): int
    {
        $normalized = self::normalize($tag);
        if (strlen($normalized) < $this->minLength()) {
            throw new UserMessageException(t('The badge id is too short (at least %s letters or digits).', $this->minLength()));
        }
        if (!$this->users->getByID($uID)) {
            throw new UserMessageException(t('Unknown user.'));
        }
        $hash = $this->hash($normalized);
        $existing = $this->db->fetchAssociative('SELECT id, uID FROM QuickPosBadges WHERE tagHash = ? AND revokedAt IS NULL', [$hash]);
        if ($existing) {
            if ((int) $existing['uID'] === $uID) {
                return (int) $existing['id'];
            }
            $owner = $this->users->getByID((int) $existing['uID']);
            throw new UserMessageException(t('This badge belongs to %s. Revoke it there first.', $owner ? $owner->getUserName() : '#' . $existing['uID']));
        }
        $this->db->transactional(function () use ($uID, $normalized, $hash, $byUID, &$badgeID) {
            $this->db->insert('QuickPosBadges', [
                'uID' => $uID,
                'tagHash' => $hash,
                'hint' => substr($normalized, -4),
                'createdAt' => (new DateTime())->format('Y-m-d H:i:s'),
                'createdBy' => $byUID ?: null,
            ]);
            $badgeID = (int) $this->db->lastInsertId();
            foreach ($this->fragments($normalized) as $fragment => $missing) {
                $this->db->insert('QuickPosBadgeKeys', ['badgeID' => $badgeID, 'keyHash' => $this->hash($fragment), 'missing' => $missing]);
            }
        });
        $this->logger->info(sprintf('Quick POS: badge …%s assigned to user %d by user %d.', substr($normalized, -4), $uID, $byUID));

        return (int) $badgeID;
    }

    public function revoke(int $badgeID): void
    {
        $this->db->executeStatement('UPDATE QuickPosBadges SET revokedAt = ? WHERE id = ? AND revokedAt IS NULL', [(new DateTime())->format('Y-m-d H:i:s'), $badgeID]);
    }

    /**
     * @return array<int, array{id: int, uID: int, userName: string|null, hint: string, createdAt: string, lastUsedAt: string|null, revokedAt: string|null}>
     */
    public function getList(string $keywords = '', bool $withRevoked = false, int $uID = 0): array
    {
        $sql = 'SELECT b.id, b.uID, u.uName AS userName, u.uEmail AS userEmail, b.hint, b.createdAt, b.lastUsedAt, b.revokedAt'
            . ' FROM QuickPosBadges b LEFT JOIN Users u ON u.uID = b.uID WHERE 1 = 1';
        $params = [];
        if (!$withRevoked) {
            $sql .= ' AND b.revokedAt IS NULL';
        }
        if ($uID > 0) {
            $sql .= ' AND b.uID = ?';
            $params[] = $uID;
        }
        if ($keywords !== '') {
            $sql .= ' AND (u.uName LIKE ? OR u.uEmail LIKE ? OR b.hint = ?)';
            $params[] = '%' . $keywords . '%';
            $params[] = '%' . $keywords . '%';
            $params[] = self::normalize($keywords);
        }

        return $this->db->fetchAllAssociative($sql . ' ORDER BY u.uName ASC, b.createdAt DESC LIMIT 500', $params);
    }

    public function countForUser(int $uID): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM QuickPosBadges WHERE uID = ? AND revokedAt IS NULL', [$uID]);
    }

    /**
     * Imports the "badge_id" user attribute of the old credit manager: exact ids, active users. Re-running it is safe;
     * badges imported before get their lookup keys rebuilt with the current settings.
     *
     * @return array{imported: int, refreshed: int, skipped: string[]}
     */
    public function importFromAttribute(string $handle = 'badge_id', int $byUID = 0): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT uav.uID, d.value FROM UserAttributeValues uav'
            . ' INNER JOIN AttributeKeys ak ON ak.akID = uav.akID'
            . ' INNER JOIN atDefault d ON d.avID = uav.avID'
            . ' INNER JOIN Users u ON u.uID = uav.uID'
            . ' WHERE ak.akHandle = ? AND u.uIsActive = 1',
            [$handle]
        );
        $result = ['imported' => 0, 'refreshed' => 0, 'skipped' => []];
        foreach ($rows as $row) {
            $normalized = self::normalize((string) $row['value']);
            if ($normalized === '') {
                continue;
            }
            $existing = $this->db->fetchAssociative('SELECT id, uID FROM QuickPosBadges WHERE tagHash = ? AND revokedAt IS NULL', [$this->hash($normalized)]);
            if ($existing && (int) $existing['uID'] === (int) $row['uID']) {
                $this->rebuildKeys((int) $existing['id'], $normalized);
                ++$result['refreshed'];
                continue;
            }
            try {
                $this->assign((int) $row['uID'], $normalized, $byUID);
                ++$result['imported'];
            } catch (UserMessageException $e) {
                $result['skipped'][] = sprintf('#%d: %s', $row['uID'], $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * The full id (0 missing) and every fragment with up to max_missing characters cut off the start and/or end,
     * never shorter than min_length. A 10 character id with the defaults gives 1 + 2 + 3 + 4 = 10 keys.
     *
     * @return array<string, int> fragment => characters missing
     */
    public function fragments(string $normalized): array
    {
        $length = strlen($normalized);
        $fragments = [];
        $maxMissing = min($this->maxMissing(), $length - $this->minLength());
        for ($missing = 0; $missing <= $maxMissing; ++$missing) {
            for ($start = 0; $start <= $missing; ++$start) {
                $fragment = substr($normalized, $start, $length - $missing);
                if (!isset($fragments[$fragment])) {
                    $fragments[$fragment] = $missing;
                }
            }
        }

        return $fragments;
    }

    protected function rebuildKeys(int $badgeID, string $normalized): void
    {
        $this->db->transactional(function () use ($badgeID, $normalized) {
            $this->db->delete('QuickPosBadgeKeys', ['badgeID' => $badgeID]);
            foreach ($this->fragments($normalized) as $fragment => $missing) {
                $this->db->insert('QuickPosBadgeKeys', ['badgeID' => $badgeID, 'keyHash' => $this->hash($fragment), 'missing' => $missing]);
            }
        });
    }

    protected function minLength(): int
    {
        return Settings::int('badge.min_length', 4, 32);
    }

    protected function maxMissing(): int
    {
        return Settings::int('badge.max_missing', 0, 8);
    }
}
