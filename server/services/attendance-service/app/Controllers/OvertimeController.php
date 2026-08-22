<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AttendanceRecords;
use App\Policies\AttendanceScope;
use App\Services\PeopleDirectory;
use App\Services\TimeFormat;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/**
 * Overtime totals, rolled up by employee and calendar month.
 *
 * The numbers are derived from attendance rather than stored separately, so
 * they cannot drift away from the records that produced them.
 */
final class OvertimeController
{
    private AttendanceRecords $records;
    private PeopleDirectory $people;
    private AttendanceScope $scope;

    public function __construct()
    {
        $this->records = new AttendanceRecords();
        $this->people = new PeopleDirectory();
        $this->scope = new AttendanceScope();
    }

    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'month' => 'nullable|string|max:7',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'employee_id' => 'nullable|uuid',
        ])->validated();

        [$from, $to] = $this->window($filters);

        $employeeIds = ($filters['employee_id'] ?? null) !== null
            ? [$this->scope->resolveSubject($request, (string) $filters['employee_id'])]
            : $this->scope->visibleIds($request);

        $rows = $this->records->overtimeSummary($from, $to, $employeeIds);

        $totalSeconds = 0;
        $byEmployee = [];

        foreach ($rows as $index => $row) {
            $totalSeconds += $row['overtime_seconds'];
            $rows[$index] = $this->people->summarise($request, $row['employee_id']) + $row;
            $byEmployee[$row['employee_id']] = ($byEmployee[$row['employee_id']] ?? 0) + $row['overtime_seconds'];
        }

        arsort($byEmployee);

        return Response::ok([
            'from' => $from,
            'to' => $to,
            'months' => $rows,
            'total_overtime_seconds' => $totalSeconds,
            'total_overtime_hours' => TimeFormat::hours($totalSeconds),
            'top_employees' => array_map(
                fn (string $employeeId): array => $this->people->summarise($request, $employeeId) + [
                    'overtime_seconds' => $byEmployee[$employeeId],
                    'overtime_hours' => TimeFormat::hours($byEmployee[$employeeId]),
                ],
                array_slice(array_keys($byEmployee), 0, 5)
            ),
        ]);
    }

    /**
     * Resolves the reporting window.
     *
     * A month is the usual unit, but an explicit range is accepted so a payroll
     * period that does not line up with the calendar can still be totalled.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: string}
     */
    private function window(array $filters): array
    {
        if (($filters['from'] ?? null) !== null || ($filters['to'] ?? null) !== null) {
            [$monthStart, $monthEnd] = Clock::monthBounds(Clock::today());
            $from = (string) ($filters['from'] ?? $monthStart);
            $to = (string) ($filters['to'] ?? $monthEnd);

            if ($from > $to) {
                throw HttpException::unprocessable('The start of the range is after its end.');
            }

            return [$from, $to];
        }

        $month = (string) ($filters['month'] ?? Clock::now()->format('Y-m'));

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw HttpException::unprocessable(
                'Month must be in YYYY-MM format.',
                ['month' => ['Month must be in YYYY-MM format.']]
            );
        }

        return Clock::monthBounds($month . '-01');
    }
}
