<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\LeaveRequests;
use App\Policies\LeaveScope;
use App\Services\WorkingDayCalculator;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/**
 * Who is away, day by day, for the people the caller works with.
 */
final class LeaveCalendarController
{
    private LeaveRequests $requests;

    public function __construct()
    {
        $this->requests = new LeaveRequests();
    }

    /** GET /leave/calendar */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'month' => 'nullable|string|max:7',
        ], ['month' => 'Month'])->validated();

        $month = (string) ($filters['month'] ?? Clock::now()->format('Y-m'));

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw HttpException::unprocessable('The month must be given as YYYY-MM.');
        }

        [$from, $to] = Clock::monthBounds($month . '-01');

        $principal = $request->principal();
        $scope = new LeaveScope($request->bearerToken());
        $employeeIds = $scope->calendarEmployeeIds($principal);

        $rows = $this->requests->daysAwayBetween($from, $to, $employeeIds);

        // A range covering a weekend or a public holiday should not paint those
        // days as absences: the employee was not expected in anyway.
        $workingDays = (new WorkingDayCalculator($request->bearerToken()))->workingDateSet($from, $to);

        $days = [];

        foreach ($rows as $row) {
            $date = (string) $row['on_date'];

            if (!isset($workingDays[$date])) {
                continue;
            }

            $entry = [
                'request_id' => $row['id'],
                'employee_id' => $row['employee_id'],
                'leave_type_id' => $row['leave_type_id'],
                'leave_type_name' => $row['leave_type_name'],
                'leave_type_code' => $row['leave_type_code'],
                'colour' => $row['colour'],
                'status' => $row['status'],
                'is_half_day' => filter_var($row['is_half_day'], FILTER_VALIDATE_BOOLEAN),
                'half_day_period' => $row['half_day_period'],
                'starts_on' => $row['starts_on'],
                'ends_on' => $row['ends_on'],
            ];

            // Why somebody is off is their business. Colleagues get the dates
            // and the type so they can plan; the reason stays private.
            if ($principal->owns((string) $row['employee_id'])) {
                $entry['reason'] = $row['reason'];
            }

            $days[$date][] = $entry;
        }

        ksort($days);

        return Response::ok($days, [
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'scope' => $employeeIds === null ? 'organisation' : 'team',
            'employees_in_scope' => $employeeIds === null ? null : count($employeeIds),
        ]);
    }
}
