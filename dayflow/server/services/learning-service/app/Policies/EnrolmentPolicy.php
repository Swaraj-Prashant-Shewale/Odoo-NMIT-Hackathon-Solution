<?php

declare(strict_types=1);

namespace App\Policies;

use App\Services\EmployeeDirectory;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;

/**
 * Who may look at, or act on, a particular enrolment.
 *
 * A permission answers "may this role manage training at all". It cannot
 * answer "is this that person's own record", which is the question that keeps
 * one employee out of another's progress, quiz attempts and certificates.
 */
final class EnrolmentPolicy
{
    private function __construct()
    {
    }

    /** The caller's employee record, or 403 when the token carries none. */
    public static function requireEmployeeId(Principal $principal): string
    {
        if ($principal->employeeId === null || $principal->employeeId === '') {
            throw HttpException::forbidden('This account is not linked to an employee record.');
        }

        return $principal->employeeId;
    }

    /**
     * Only the learner may record progress, sit the quiz or take the document.
     *
     * @param array<string, mixed> $record Any row carrying an employee_id.
     */
    public static function assertOwn(Principal $principal, array $record): void
    {
        if (!$principal->owns(isset($record['employee_id']) ? (string) $record['employee_id'] : null)) {
            // Deliberately "not found": confirming that somebody else's
            // enrolment exists at this address is itself a disclosure.
            throw HttpException::notFound();
        }
    }

    /**
     * Whether the caller may read another person's enrolment list.
     *
     * HR sees everyone; a manager sees only the people who report to them, and
     * that reporting line is verified against employee-service rather than
     * assumed from the request.
     */
    public static function assertMayViewEmployee(
        Principal $principal,
        string $employeeId,
        EmployeeDirectory $directory,
    ): void {
        if ($principal->owns($employeeId)) {
            return;
        }

        if ($principal->can(Permissions::LEARNING_ASSIGN_ANY)) {
            return;
        }

        if ($principal->can(Permissions::LEARNING_ASSIGN_TEAM)) {
            $managerId = self::requireEmployeeId($principal);

            if ($directory->reportsTo($employeeId, $managerId)) {
                return;
            }
        }

        throw HttpException::forbidden('You may only view learning records for your own team.');
    }

    /** Catalogue managers see the whole library, including unpublished drafts. */
    public static function seesUnpublished(Principal $principal): bool
    {
        return $principal->can(Permissions::LEARNING_MANAGE_CATALOGUE);
    }
}
