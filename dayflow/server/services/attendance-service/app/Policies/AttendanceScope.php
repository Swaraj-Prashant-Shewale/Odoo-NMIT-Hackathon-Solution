<?php

declare(strict_types=1);

namespace App\Policies;

use App\Services\PeopleDirectory;
use Dayflow\Kernel\Database\QueryBuilder;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Security\Permissions;

/**
 * Decides whose attendance a caller may see.
 *
 * A permission answers "may this role look at attendance at all". It does not
 * answer "whose", which is the question every list and every grid in this
 * service has to settle before it touches the database.
 */
final class AttendanceScope
{
    private PeopleDirectory $people;

    public function __construct()
    {
        $this->people = new PeopleDirectory();
    }

    /** The caller's own employee id, refusing accounts with no person record. */
    public function selfId(Request $request): string
    {
        $employeeId = $request->principal()->employeeId;

        if ($employeeId === null || $employeeId === '') {
            throw HttpException::forbidden('This account is not linked to an employee record.');
        }

        return $employeeId;
    }

    /**
     * The employee ids in view, or null when the caller may see everyone.
     *
     * A manager's scope includes themselves: the team board is worthless if the
     * person reading it is the one row missing from it.
     *
     * @return list<string>|null
     */
    public function visibleIds(Request $request): ?array
    {
        $principal = $request->principal();

        if ($principal->can(Permissions::ATTENDANCE_VIEW_ALL)) {
            return null;
        }

        $selfId = $this->selfId($request);

        if ($principal->can(Permissions::ATTENDANCE_VIEW_TEAM)) {
            $ids = $this->people->directReportIds($request, $selfId);
            $ids[] = $selfId;

            return array_values(array_unique($ids));
        }

        return [$selfId];
    }

    /** @return list<string> The team, without the caller. */
    public function reportIds(Request $request): array
    {
        return $this->people->directReportIds($request, $this->selfId($request));
    }

    public function canView(Request $request, string $employeeId): bool
    {
        $visible = $this->visibleIds($request);

        return $visible === null || in_array($employeeId, $visible, true);
    }

    /** Resolves the employee a single-subject endpoint is being asked about. */
    public function resolveSubject(Request $request, ?string $requested): string
    {
        $selfId = $this->selfId($request);

        if ($requested === null || $requested === '' || $requested === $selfId) {
            return $selfId;
        }

        if (!$this->canView($request, $requested)) {
            // Deliberately the same answer as an unknown employee, so the
            // endpoint cannot be used to probe who exists.
            throw HttpException::forbidden('You may not view attendance for this employee.');
        }

        return $requested;
    }

    /** Narrows a list query to the rows the caller is entitled to. */
    public function apply(QueryBuilder $builder, Request $request, string $column = 'employee_id'): void
    {
        $visible = $this->visibleIds($request);

        if ($visible !== null) {
            $builder->whereIn($column, $visible);
        }
    }
}
