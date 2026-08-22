<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Support\Clock;

/**
 * Who a given announcement is for.
 *
 * The listing endpoint narrows the same rules in SQL so a targeted notice is
 * never loaded at all. This class answers the question for a single row, which
 * is what the read-receipt endpoint needs: marking something read is itself a
 * signal that it exists, so it has to be refused for anything the caller was
 * not entitled to see in the first place.
 */
final class AnnouncementPolicy
{
    private function __construct()
    {
    }

    /** @param array<string, mixed> $announcement */
    public static function isVisibleTo(Principal $principal, array $announcement): bool
    {
        // Whoever maintains the notice board has to be able to open a retired
        // or scheduled notice in order to correct it.
        if ($principal->can(Permissions::ANNOUNCEMENT_PUBLISH)) {
            return true;
        }

        if (!filter_var($announcement['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $expiresOn = $announcement['expires_on'] ?? null;
        if (is_string($expiresOn) && $expiresOn !== '' && $expiresOn < Clock::today()) {
            return false;
        }

        $department = $announcement['target_department_id'] ?? null;
        if (is_string($department) && $department !== '' && $department !== $principal->departmentId) {
            return false;
        }

        $role = $announcement['target_role'] ?? null;
        if (is_string($role) && $role !== '' && !in_array($role, $principal->roles, true)) {
            return false;
        }

        return true;
    }
}
