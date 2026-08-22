<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;

/**
 * Decides how wide an analytics answer may be.
 *
 * A permission says "may run reports"; it does not say "over whose data". The
 * routes that serve both a manager and an HR officer are therefore declared
 * ->authenticated() and resolve the width here, then pass it to the owning
 * service as an explicit scope. The owning service still applies its own rules
 * on top - this is what analytics asks for, not what it is trusted to receive.
 */
final class AnalyticsScope
{
    public const ORGANISATION = 'all';
    public const TEAM = 'team';
    public const SELF = 'self';

    /**
     * The widest scope a caller is entitled to, given the pair of permissions
     * that govern a domain.
     *
     * @throws HttpException 403 when the caller holds neither.
     */
    public static function resolve(Principal $principal, string $allPermission, string $teamPermission): string
    {
        if ($principal->can($allPermission)) {
            return self::ORGANISATION;
        }

        if ($principal->can($teamPermission)) {
            return self::TEAM;
        }

        throw HttpException::forbidden('You do not have permission to view this analysis.');
    }

    /** Scope for the attendance and leave analyses, which both serve managers and HR. */
    public static function forReporting(Principal $principal): string
    {
        return self::resolve($principal, Permissions::REPORT_VIEW_ALL, Permissions::REPORT_VIEW_TEAM);
    }

    /**
     * A manager may only ever narrow an analysis to their own reports.
     *
     * The manager id is taken from the verified token, never from the request,
     * so a manager cannot substitute somebody else's team by editing a query
     * string.
     *
     * @return array<string, string>
     */
    public static function filterFor(Principal $principal, string $scope): array
    {
        if ($scope === self::ORGANISATION) {
            return ['scope' => self::ORGANISATION];
        }

        if ($principal->employeeId === null) {
            throw HttpException::forbidden('This analysis needs an employee record to define a team.');
        }

        return ['scope' => self::TEAM, 'manager_id' => $principal->employeeId];
    }

    /**
     * Rejects a department filter a team-scoped caller is not entitled to widen
     * an answer with.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function applyDepartment(Principal $principal, string $scope, array $filters): array
    {
        $departmentId = $filters['department_id'] ?? null;

        if ($departmentId === null) {
            return $filters;
        }

        self::assertDepartment($principal, $scope, (string) $departmentId);

        return $filters;
    }

    /**
     * Rejects a department a team-scoped caller may not narrow - or widen - an
     * answer with.
     *
     * Organisation-wide callers may slice by any department. A manager may only
     * name their own, because any other department is by definition outside the
     * team they were granted sight of.
     *
     * The comparison is case-insensitive: the validator lowercases a UUID that
     * arrived in the query string, while the department id on the token is
     * whatever identity-service put there. A case difference between the two
     * would lock a manager out of their own department.
     *
     * @throws HttpException 403 when the department is outside the caller's scope.
     */
    public static function assertDepartment(Principal $principal, string $scope, string $departmentId): void
    {
        if ($scope === self::ORGANISATION) {
            return;
        }

        $own = $principal->departmentId;

        if ($own === null || strcasecmp($departmentId, $own) !== 0) {
            throw HttpException::forbidden('You may only analyse your own department.');
        }
    }
}
