<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Security\Roles;

/**
 * Guards the one operation that can rewrite the whole access model.
 *
 * Holding user.roles.manage lets somebody change what other people may do. If
 * that were the only check, an HR administrator could grant themselves
 * super_admin, or strip it from the person above them, and the hierarchy would
 * be decoration. Two rules keep it real:
 *
 *  1. Nobody edits their own roles. Escalation always needs a second person.
 *  2. Nobody hands out, or takes away, a role more senior than their own. The
 *     ceiling is the caller's most senior role, so authority can be delegated
 *     downwards and never manufactured upwards.
 *
 * Removals are checked as strictly as grants. Stripping the last super_admin
 * is as damaging as minting a new one, and an attacker who could only remove
 * roles could still take the platform apart.
 */
final class RolePolicy
{
    /**
     * Explains why a role change is not permitted, or null when it is.
     *
     * @param list<string> $currentRoles Roles the target holds now.
     * @param list<string> $desiredRoles Roles the target would hold afterwards.
     */
    public static function refusalReason(
        Principal $actor,
        string $targetUserId,
        array $currentRoles,
        array $desiredRoles,
    ): ?string {
        if (UserPolicy::isSelf($actor, $targetUserId)) {
            return 'You cannot change your own roles. Ask another administrator to make this change.';
        }

        if ($desiredRoles === []) {
            return 'An account must keep at least one role.';
        }

        $ceiling = $actor->primaryRole();

        foreach (self::affectedRoles($currentRoles, $desiredRoles) as $role) {
            if (!Roles::isValid($role)) {
                return sprintf('"%s" is not a recognised role.', $role);
            }

            if (!Roles::outranks($ceiling, $role)) {
                return sprintf(
                    'Your own role of %s does not allow you to grant or remove the %s role.',
                    Roles::label($ceiling),
                    Roles::label($role)
                );
            }
        }

        return null;
    }

    /**
     * Every role the change would add or take away.
     *
     * @param list<string> $currentRoles
     * @param list<string> $desiredRoles
     * @return list<string>
     */
    private static function affectedRoles(array $currentRoles, array $desiredRoles): array
    {
        return array_values(array_unique(array_merge(
            array_diff($desiredRoles, $currentRoles),
            array_diff($currentRoles, $desiredRoles)
        )));
    }
}
