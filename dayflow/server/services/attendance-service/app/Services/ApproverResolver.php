<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\ServiceClient;
use Dayflow\Kernel\Security\Roles;
use Dayflow\Kernel\Support\Logger;

/**
 * Works out who decides a request, following the platform's single routing
 * rule: the line manager, then an approver in the same department, then HR.
 *
 * The answer is stamped onto the request when it is raised so the queue stays
 * stable even if the reporting line changes before a decision is made.
 */
final class ApproverResolver
{
    private PeopleDirectory $people;

    public function __construct()
    {
        $this->people = new PeopleDirectory();
    }

    /** The employee id of the approver, or null when nobody could be resolved. */
    public function forEmployee(Request $request, string $employeeId): ?string
    {
        $principal = $request->principal();

        // The token already carries the caller's own manager, which covers the
        // ordinary case of somebody raising a request for themselves.
        if ($principal->owns($employeeId) && $principal->managerId !== null && $principal->managerId !== '') {
            return $principal->managerId;
        }

        $employee = $this->people->employee($request, $employeeId);
        $managerId = $employee['manager_id'] ?? null;

        if (is_string($managerId) && $managerId !== '' && $managerId !== $employeeId) {
            return $managerId;
        }

        $departmentId = $employee['department_id'] ?? ($principal->owns($employeeId) ? $principal->departmentId : null);

        if (is_string($departmentId) && $departmentId !== '') {
            $departmental = $this->firstHolder($request, ['department_id' => $departmentId], $employeeId);

            if ($departmental !== null) {
                return $departmental;
            }
        }

        return $this->firstHolder($request, [], $employeeId);
    }

    /**
     * Finds an HR officer, optionally narrowed to one department.
     *
     * Roles live with the identity service, so the lookup happens there. It is
     * best effort: a request with no resolvable approver is still valid and is
     * picked up from the queue by anyone holding the approval permission.
     *
     * @param array<string, mixed> $filters
     */
    private function firstHolder(Request $request, array $filters, string $excludeEmployeeId): ?string
    {
        try {
            $rows = ServiceClient::for('identity', $request->bearerToken())
                ->tryGet('/users', $filters + ['role' => Roles::HR_OFFICER, 'per_page' => 25], []);
        } catch (\Throwable $exception) {
            Logger::warning('Approver lookup unavailable', ['error' => $exception->getMessage()]);

            return null;
        }

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $candidate = $row['employee_id'] ?? null;

            // Nobody may end up as the approver of their own request.
            if (is_string($candidate) && $candidate !== '' && $candidate !== $excludeEmployeeId) {
                return $candidate;
            }
        }

        return null;
    }
}
