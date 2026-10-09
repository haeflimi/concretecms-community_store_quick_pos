<?php

namespace CommunityStoreQuickPos\Badge;

use Concrete\Core\User\UserInfo;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Result of a badge lookup.
 */
class BadgeMatch
{
    public const OK = 'ok';
    public const UNKNOWN = 'unknown';
    public const AMBIGUOUS = 'ambiguous';
    public const TOO_SHORT = 'short';

    /** @var string */
    public $status;

    /** @var UserInfo|null */
    public $user;

    /** @var int */
    public $badgeID;

    /** @var int characters the reader cut off */
    public $missing;

    public function __construct(string $status, ?UserInfo $user = null, int $badgeID = 0, int $missing = 0)
    {
        $this->status = $status;
        $this->user = $user;
        $this->badgeID = $badgeID;
        $this->missing = $missing;
    }

    public function isOk(): bool
    {
        return $this->status === self::OK && $this->user !== null;
    }

    /**
     * Message for the person at the reader.
     */
    public function message(): string
    {
        switch ($this->status) {
            case self::AMBIGUOUS:
            case self::TOO_SHORT:
                return t('The badge could not be read completely. Please scan it again.');
            case self::UNKNOWN:
                return t('This badge is not registered. Ask at the counter.');
        }

        return '';
    }
}
