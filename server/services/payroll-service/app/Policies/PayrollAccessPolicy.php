<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;

/**
 * Who may look at whose pay.
 *
 * A payslip is the most sensitive record the platform holds, so scope is
 * decided here for every entry point rather than being re-derived, slightly
 * differently, in each controller.
 */
final class PayrollAccessPolicy
{
    private function __construct()
    {
    }

    public static function seesEveryone(Principal $principal): bool
    {
        return $principal->can(Permissions::PAYROLL_VIEW_ALL);
    }

    /** The employee whose records a request is about, given an optional filter. */
    public static function resolveSubject(Principal $principal, ?string $requestedEmployeeId): string
    {
        if ($requestedEmployeeId !== null && !self::seesEveryone($principal) && !$principal->owns($requestedEmployeeId)) {
            throw HttpException::forbidden('You may only view your own payroll records.');
        }

        $employeeId = $requestedEmployeeId ?? $principal->employeeId;

        if ($employeeId === null || $employeeId === '') {
            throw HttpException::forbidden('This account is not linked to an employee record.');
        }

        return $employeeId;
    }

    /**
     * Record-level check for a single payslip.
     *
     * Somebody else's payslip is answered with 404 rather than 403. A 403
     * would confirm that a payslip exists for that identifier, which is
     * already more than a caller is entitled to learn.
     *
     * @param array<string, mixed> $payslip
     */
    public static function assertMayView(Principal $principal, array $payslip): void
    {
        if (self::seesEveryone($principal)) {
            return;
        }

        if (!$principal->owns($payslip['employee_id'] ?? null)) {
            throw HttpException::notFound();
        }

        // A draft payslip is a working figure, not a statement of pay: it only
        // becomes visible to the employee once the run has been published.
        if (($payslip['published_at'] ?? null) === null) {
            throw HttpException::notFound();
        }
    }
}
