<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;

/**
 * Who may see, run and export each saved report.
 *
 * The route permission on /reports only establishes that somebody may use the
 * reporting screen at all. What decides whether this caller may run this report
 * is the required_permission stored on the definition itself, checked on every
 * single request. A catalogue that hid a report but still ran it would be a
 * lock on the door of an open window.
 */
final class ReportPolicy
{
    /**
     * Permissions that are strictly wider than another, and therefore satisfy
     * it.
     *
     * Without this a Finance officer, who holds report.view.all, would be
     * refused a report marked report.view.team - a narrower entitlement they
     * already exceed. The map is deliberately explicit and one-directional:
     * only a permission that grants a superset of the other's reach appears
     * here, so nothing widens by accident.
     *
     * @var array<string, list<string>> Held permission => permissions it satisfies.
     */
    private const SATISFIES = [
        Permissions::REPORT_VIEW_ALL => [Permissions::REPORT_VIEW_TEAM],
        Permissions::ATTENDANCE_VIEW_ALL => [Permissions::ATTENDANCE_VIEW_TEAM],
        Permissions::LEAVE_VIEW_ALL => [Permissions::LEAVE_VIEW_TEAM],
        Permissions::TALENT_VIEW_ALL => [Permissions::TALENT_VIEW_TEAM],
        Permissions::PROFILE_VIEW_ALL => [Permissions::PROFILE_VIEW_TEAM],
    ];

    /** @param array<string, mixed> $definition */
    public static function mayRun(Principal $principal, array $definition): bool
    {
        $required = (string) ($definition['required_permission'] ?? '');

        // A definition with no permission recorded is treated as forbidden
        // rather than as unrestricted: failing closed is the only safe default.
        if ($required === '') {
            return false;
        }

        if ($principal->can($required)) {
            return true;
        }

        foreach (self::SATISFIES as $held => $covers) {
            if (in_array($required, $covers, true) && $principal->can($held)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $definition
     * @throws HttpException 403 when the caller may not run it.
     */
    public static function assertMayRun(Principal $principal, array $definition): void
    {
        if (!self::mayRun($principal, $definition)) {
            throw HttpException::forbidden('You do not have permission to run this report.');
        }
    }

    /**
     * The subset of a catalogue a caller may run.
     *
     * @param list<array<string, mixed>> $definitions
     * @return list<array<string, mixed>>
     */
    public static function visible(Principal $principal, array $definitions): array
    {
        return array_values(array_filter(
            $definitions,
            static fn (array $definition): bool => self::mayRun($principal, $definition)
        ));
    }

    /** True when the caller may take report data off the platform as a file. */
    public static function mayExport(Principal $principal): bool
    {
        return $principal->can(Permissions::REPORT_EXPORT);
    }

    /**
     * @throws HttpException 403 when the caller may not take data off the platform.
     */
    public static function assertMayExport(Principal $principal): void
    {
        if (!self::mayExport($principal)) {
            throw HttpException::forbidden('You do not have permission to export report data.');
        }
    }
}
