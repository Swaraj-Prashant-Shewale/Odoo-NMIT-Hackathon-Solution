<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\ServiceClient;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Security\Roles;

/**
 * Decides who signs off a request, following the platform's approval routing.
 *
 * 1. The employee's own manager, when the reporting line names one.
 * 2. Otherwise an approver in the same department.
 * 3. Otherwise HR.
 *
 * The result is written onto the request at submission and never recomputed,
 * so a reorganisation halfway through the week does not move requests out of
 * the queue of the person already looking at them.
 */
final class ApproverResolver
{
    public function __construct(private readonly ?string $bearerToken = null)
    {
    }

    /**
     * The approver for a request the principal is submitting for themselves.
     *
     * The manager is already in the verified token, so the common case costs
     * nothing. Only an employee with no reporting line triggers a lookup.
     */
    public function forSelf(Principal $principal): ?string
    {
        $employeeId = (string) $principal->employeeId;

        if ($principal->managerId !== null && $principal->managerId !== '' && $principal->managerId !== $employeeId) {
            return $principal->managerId;
        }

        return $this->fallbackApprover($employeeId, $principal->departmentId);
    }

    /**
     * An approver drawn from the department, then from HR.
     *
     * Which accounts hold a role is known only to the identity service, and
     * which people sit in a department only to the employee service, so the
     * "same department" rule needs both: the roster of managers is narrowed to
     * the department by intersecting the two lists. Sending department_id to
     * the identity service instead would achieve nothing — it does not filter
     * on a field it has never stored, and the first manager in the company
     * would be handed the request.
     *
     * Every lookup is optional: if a service cannot be reached the request is
     * still accepted with no approver recorded, and it surfaces in the queue of
     * anyone who can see all leave rather than disappearing.
     */
    private function fallbackApprover(string $employeeId, ?string $departmentId): ?string
    {
        if ($departmentId !== null && $departmentId !== '') {
            $managers = $this->employeeIdsWithRole(Roles::MANAGER, $employeeId);

            if ($managers !== []) {
                $department = $this->employeeIdsInDepartment($departmentId);

                foreach ($managers as $candidate) {
                    if (in_array($candidate, $department, true)) {
                        return $candidate;
                    }
                }
            }
        }

        return $this->employeeIdsWithRole(Roles::HR_OFFICER, $employeeId)[0] ?? null;
    }

    /**
     * The employee records behind every active account holding a role.
     *
     * @return list<string>
     */
    private function employeeIdsWithRole(string $role, string $excludeEmployeeId): array
    {
        $rows = ServiceClient::for('identity', $this->bearerToken)
            ->tryGet('/users', ['role' => $role, 'status' => 'active', 'per_page' => 100], []);

        if (!is_array($rows)) {
            return [];
        }

        $ids = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $candidate = $row['employee_id'] ?? null;

            if (!is_string($candidate) || $candidate === '' || $candidate === $excludeEmployeeId) {
                continue;
            }

            if (array_key_exists('is_active', $row) && $row['is_active'] === false) {
                continue;
            }

            $ids[] = $candidate;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Everyone the employee service places in one department.
     *
     * @return list<string>
     */
    private function employeeIdsInDepartment(string $departmentId): array
    {
        $rows = ServiceClient::for('employee', $this->bearerToken)
            ->tryGet('/employees', ['department_id' => $departmentId, 'per_page' => 100], []);

        if (!is_array($rows)) {
            return [];
        }

        $ids = [];

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id']) && is_string($row['id'])) {
                $ids[] = $row['id'];
            }
        }

        return array_values(array_unique($ids));
    }
}
