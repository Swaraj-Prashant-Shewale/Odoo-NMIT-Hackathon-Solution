<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employees;
use App\Services\RecordId;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;

/**
 * Who may see which person record.
 *
 * A permission answers "may this role read people records at all". It cannot
 * answer "may this person read *this* record", so every scoped endpoint asks
 * here as well.
 *
 * The reporting line is always read from the stored record rather than from
 * the manager_id claim in the token: the claim is a convenience for the client
 * and is stale the moment a reporting line changes.
 */
final class EmployeeAccessPolicy
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_TEAM = 'team';
    public const SCOPE_SELF = 'self';

    public function __construct(private readonly Employees $employees)
    {
    }

    public function scopeFor(Principal $principal): string
    {
        if ($principal->can(Permissions::PROFILE_VIEW_ALL)) {
            return self::SCOPE_ALL;
        }

        if ($principal->can(Permissions::PROFILE_VIEW_TEAM)) {
            return self::SCOPE_TEAM;
        }

        return self::SCOPE_SELF;
    }

    /**
     * The identifiers a team-scoped or self-scoped caller may read.
     *
     * @return list<string>
     */
    public function visibleEmployeeIds(Principal $principal): array
    {
        $self = RecordId::orNull($principal->employeeId);

        if ($self === null) {
            // An account with no usable person record attached can see nobody;
            // an empty list makes the query match nothing rather than
            // everything, and keeps a blank claim away from a uuid comparison.
            return [];
        }

        if ($this->scopeFor($principal) === self::SCOPE_TEAM) {
            return array_values(array_unique(array_merge([$self], $this->employees->directReportIds($self))));
        }

        return [$self];
    }

    /** @param array<string, mixed> $employee */
    public function canView(Principal $principal, array $employee): bool
    {
        if ($principal->can(Permissions::PROFILE_VIEW_ALL)) {
            return true;
        }

        $targetId = isset($employee['id']) ? (string) $employee['id'] : null;

        if ($principal->owns($targetId)) {
            return true;
        }

        if (!$principal->can(Permissions::PROFILE_VIEW_TEAM)) {
            return false;
        }

        $managerId = isset($employee['manager_id']) ? (string) $employee['manager_id'] : null;

        return $principal->owns($managerId);
    }

    /** @param array<string, mixed> $employee */
    public function assertCanView(Principal $principal, array $employee): void
    {
        if (!$this->canView($principal, $employee)) {
            // Deliberately "forbidden" rather than "not found": the caller has
            // already been told the record exists by asking for it by id, and
            // a 404 here would only make the failure harder to diagnose.
            throw HttpException::forbidden('You may not view this person record.');
        }
    }

    /**
     * May the caller see the direct reports of this person?
     *
     * Managers may inspect their own team, and their own manager may inspect
     * it too, which is what makes a skip-level view work.
     *
     * @param array<string, mixed> $employee
     */
    public function assertCanViewTeamOf(Principal $principal, array $employee): void
    {
        if ($this->canView($principal, $employee)) {
            return;
        }

        throw HttpException::forbidden('You may not view this team.');
    }
}
