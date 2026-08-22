<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;

/**
 * Record-level decisions about accounts.
 *
 * Holding a permission answers "may this role manage accounts at all". It says
 * nothing about whether this particular record is theirs to touch, which is
 * the question every one of these methods exists to answer.
 */
final class UserPolicy
{
    public static function isSelf(Principal $principal, string $userId): bool
    {
        return $userId !== '' && hash_equals($principal->userId, $userId);
    }

    /** An administrator, or the person whose record it is. */
    public static function canView(Principal $principal, string $userId): bool
    {
        return $principal->can(Permissions::USER_MANAGE_ALL) || self::isSelf($principal, $userId);
    }

    /**
     * Nobody may switch off their own account.
     *
     * The last administrator disabling themselves would leave a deployment
     * with no way back in, and an attacker holding a stolen session has no
     * reason to be handed a way to lock the real owner out either.
     */
    public static function canDeactivate(Principal $principal, string $userId): bool
    {
        return !self::isSelf($principal, $userId);
    }
}
