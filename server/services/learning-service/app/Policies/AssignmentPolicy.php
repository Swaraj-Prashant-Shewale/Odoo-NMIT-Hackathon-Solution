<?php

declare(strict_types=1);

namespace App\Policies;

use App\Services\EmployeeDirectory;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;

/**
 * Decides who a caller may push training onto.
 *
 * Assignment is the one place in this service where somebody writes a record
 * that belongs to another person, so the scope check is strict: a manager
 * holding only learning.assign.team may reach their direct reports and nobody
 * else, and every target is confirmed against employee-service.
 */
final class AssignmentPolicy
{
    private function __construct()
    {
    }

    /**
     * Validates a whole assignment target list, or rejects the request.
     *
     * The check is all-or-nothing on purpose. Quietly assigning the reachable
     * half of a list would leave the manager believing the rest went out too.
     *
     * @param list<string> $employeeIds
     */
    /** Above this many targets, one paged directory read beats a call per person. */
    private const BULK_LOOKUP_THRESHOLD = 10;

    public static function assertMayAssign(
        Principal $principal,
        array $employeeIds,
        EmployeeDirectory $directory,
    ): void {
        $unrestricted = $principal->can(Permissions::LEARNING_ASSIGN_ANY);

        if (!$unrestricted && !$principal->can(Permissions::LEARNING_ASSIGN_TEAM)) {
            throw HttpException::forbidden('You do not have permission to assign training.');
        }

        $managerId = $unrestricted ? null : EnrolmentPolicy::requireEmployeeId($principal);

        if (count($employeeIds) > self::BULK_LOOKUP_THRESHOLD) {
            // Fills the directory's cache in a couple of requests; anyone it
            // could not reach still falls through to an individual lookup.
            $directory->everyone();
        }

        $unknown = [];
        $outOfScope = [];

        foreach ($employeeIds as $employeeId) {
            $employee = $directory->find($employeeId);

            if ($employee === null) {
                $unknown[] = $employeeId;
                continue;
            }

            if ((bool) ($employee['is_active'] ?? true) === false) {
                $outOfScope[] = $employeeId;
                continue;
            }

            if ($managerId !== null && !$directory->reportsTo($employeeId, $managerId)) {
                $outOfScope[] = $employeeId;
            }
        }

        if ($unknown !== []) {
            throw HttpException::unprocessable(
                'One or more of the selected people could not be found.',
                ['employee_ids' => array_values($unknown)]
            );
        }

        if ($outOfScope !== []) {
            throw HttpException::forbidden(
                'You may only assign training to people who report to you.'
            );
        }
    }
}
