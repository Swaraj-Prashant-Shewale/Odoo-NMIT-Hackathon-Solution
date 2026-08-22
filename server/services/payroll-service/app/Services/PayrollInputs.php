<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\ServiceClient;

/**
 * Gathers the attendance and leave facts a payslip depends on.
 *
 * Payroll owns none of this data. Attendance-service reports what was worked
 * and leave-service reports what was authorised, and this class reduces both
 * to the four day counts a payslip actually needs.
 *
 * The two shapes it reads are fixed by the services that publish them:
 * `GET /attendance/monthly` answers with a `summary` object carrying
 * present_days, wfh_days, absent_days and half_days, and `GET /leave/requests`
 * answers with rows carrying day_count, starts_on, ends_on and a nested
 * leave_type that holds the category and whether it is paid.
 *
 * When either service cannot be reached the employee is treated as fully
 * present. Docking somebody's pay because an internal HTTP call timed out
 * would be the worse of the two failures.
 */
final class PayrollInputs
{
    private const LEAVE_PAGE_SIZE = 100;

    /** Only leave in these categories reduces pay. */
    private const UNPAID_CATEGORIES = ['unpaid'];

    public function __construct(private readonly ?string $bearerToken)
    {
    }

    /**
     * @return array{
     *     working_days: float,
     *     payable_days: float,
     *     present_days: float,
     *     leave_days: float,
     *     lop_days: float
     * }
     */
    public function forEmployee(string $employeeId, string $period, int $calendarWorkingDays): array
    {
        [$from, $to] = Period::bounds($period);

        $summary = $this->attendanceSummary($employeeId, $period);
        $leave = $this->approvedLeave($employeeId, $from, $to);

        // The attendance summary counts what happened, not how long the month
        // was meant to be, so the denominator comes from the company calendar.
        // Both services read that from platform.settings, which is what keeps
        // a pro-rated salary and an attendance report describing the same
        // month rather than two slightly different ones.
        $workingDays = (float) $calendarWorkingDays;

        $absentDays = max(0.0, (float) ($summary['absent_days'] ?? 0));
        $halfDays = max(0.0, (float) ($summary['half_days'] ?? 0));

        // Half a day worked is half a day unpaid unless leave covered it.
        $unexcused = $absentDays + ($halfDays / 2);

        $lopDays = min($workingDays, $leave['unpaid'] + $unexcused);
        $paidLeaveDays = min($workingDays - $lopDays, $leave['paid']);
        $payableDays = max(0.0, $workingDays - $lopDays);

        $presentDays = $summary === []
            ? max(0.0, $workingDays - $lopDays - $paidLeaveDays)
            : min($workingDays, max(0.0, $this->attendedDays($summary)));

        return [
            'working_days' => round($workingDays, 2),
            'payable_days' => round($payableDays, 2),
            'present_days' => round($presentDays, 2),
            'leave_days' => round($paidLeaveDays, 2),
            'lop_days' => round($lopDays, 2),
        ];
    }

    /**
     * Days the employee was actually at work, counting a half day as half.
     *
     * Attendance counts working from home separately from being in the office,
     * but a payslip has no reason to distinguish the two.
     *
     * @param array<string, mixed> $summary
     */
    private function attendedDays(array $summary): float
    {
        return (float) ($summary['present_days'] ?? 0)
            + (float) ($summary['wfh_days'] ?? 0)
            + ((float) ($summary['half_days'] ?? 0) / 2);
    }

    /**
     * The month's attendance totals, or an empty array when unavailable.
     *
     * @return array<string, mixed>
     */
    private function attendanceSummary(string $employeeId, string $period): array
    {
        $monthly = ServiceClient::for('attendance', $this->bearerToken)->tryGet('/attendance/monthly', [
            'employee_id' => $employeeId,
            'month' => $period,
        ], []);

        if (!is_array($monthly)) {
            return [];
        }

        // A subject that came back different from the one asked about means
        // attendance narrowed the request to the caller instead of refusing
        // it. Those are somebody else's days and must not reach this payslip.
        if (isset($monthly['employee_id']) && (string) $monthly['employee_id'] !== $employeeId) {
            return [];
        }

        $summary = $monthly['summary'] ?? null;

        return is_array($summary) ? $summary : [];
    }

    /** @return array{paid: float, unpaid: float} Days falling inside the month. */
    private function approvedLeave(string $employeeId, string $from, string $to): array
    {
        $rows = ServiceClient::for('leave', $this->bearerToken)->tryGet('/leave/requests', [
            'employee_id' => $employeeId,
            'status' => 'approved',
            'from' => $from,
            'to' => $to,
            'per_page' => self::LEAVE_PAGE_SIZE,
        ], []);

        $paid = 0.0;
        $unpaid = 0.0;

        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Leave-service refuses a subject the caller may not see, but a
            // row for anybody else is discarded here as well rather than
            // trusted to have been filtered upstream.
            if (isset($row['employee_id']) && (string) $row['employee_id'] !== $employeeId) {
                continue;
            }

            $days = $this->daysInsideMonth($row, $from, $to);

            if ($days <= 0.0) {
                continue;
            }

            if ($this->isUnpaid($row)) {
                $unpaid += $days;
            } else {
                $paid += $days;
            }
        }

        return ['paid' => $paid, 'unpaid' => $unpaid];
    }

    /**
     * Whether a leave request costs the employee the day's pay.
     *
     * The category belongs to the leave type, not to the request, so it
     * arrives nested inside leave_type. Absent that, the day is treated as
     * paid: over-paying somebody because a peer changed its payload shape is
     * recoverable, and silently docking them is not.
     *
     * @param array<string, mixed> $row
     */
    private function isUnpaid(array $row): bool
    {
        $type = is_array($row['leave_type'] ?? null) ? $row['leave_type'] : [];

        if (array_key_exists('is_paid', $type)) {
            return $type['is_paid'] === false || $type['is_paid'] === 'false' || $type['is_paid'] === 0;
        }

        $category = strtolower((string) ($type['category'] ?? $row['category'] ?? 'paid'));

        return in_array($category, self::UNPAID_CATEGORIES, true);
    }

    /**
     * Attributes a leave request to the month being paid.
     *
     * A request can straddle a month boundary, so its total day count is
     * capped by the working days it actually overlaps in this period.
     *
     * @param array<string, mixed> $row
     */
    private function daysInsideMonth(array $row, string $from, string $to): float
    {
        $startsOn = (string) ($row['starts_on'] ?? $from);
        $endsOn = (string) ($row['ends_on'] ?? $to);

        $overlapStart = strcmp($startsOn, $from) > 0 ? $startsOn : $from;
        $overlapEnd = strcmp($endsOn, $to) < 0 ? $endsOn : $to;

        if (strcmp($overlapEnd, $overlapStart) < 0) {
            return 0.0;
        }

        $overlapWorkingDays = (float) CompanyCalendar::workingDaysBetween($overlapStart, $overlapEnd);
        $requestedDays = max(0.0, (float) ($row['day_count'] ?? 0));

        if ($requestedDays <= 0.0) {
            return $overlapWorkingDays;
        }

        return min($requestedDays, $overlapWorkingDays);
    }
}
