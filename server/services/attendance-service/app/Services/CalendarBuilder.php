<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AttendanceRecords;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Support\Arr;
use Dayflow\Kernel\Support\Clock;

/**
 * Builds the day-by-day grid behind the weekly and monthly attendance views.
 *
 * The grid is the join of four sources — punched attendance, the shift
 * pattern, the holiday calendar and approved leave — so a day with no record
 * is explained rather than silently blank.
 */
final class CalendarBuilder
{
    private AttendanceRecords $records;
    private ShiftResolver $shifts;
    private HolidayCalendar $holidays;
    private LeaveDirectory $leave;

    public function __construct()
    {
        $this->records = new AttendanceRecords();
        $this->shifts = new ShiftResolver();
        $this->holidays = new HolidayCalendar();
        $this->leave = new LeaveDirectory();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function build(Request $request, string $employeeId, string $from, string $to, ?string $locationId): array
    {
        $records = Arr::keyBy($this->records->betweenDates($employeeId, $from, $to), 'work_date');
        $holidays = $this->holidays->map($from, $to, $locationId);
        $leaveDays = $this->leave->datesFor($request, $employeeId, $from, $to);
        $today = Clock::today();

        $days = [];

        foreach (Clock::dateRange($from, $to) as $date) {
            $shift = $this->shifts->resolve($employeeId, $date);
            $record = $records[$date] ?? null;
            $isWorkingDay = $this->shifts->isWorkingDay($shift, $date);
            $holiday = $this->holidays->describe($holidays, $date);
            $isClosure = $this->holidays->isClosure($holidays, $date);
            $onLeave = $leaveDays[$date] ?? null;

            $days[] = [
                'date' => $date,
                'weekday' => TimeFormat::weekdayKey($date),
                'status' => $this->statusFor($record, $date, $today, $isWorkingDay, $isClosure, $onLeave !== null),
                'is_working_day' => $isWorkingDay,
                'is_weekly_off' => !$isWorkingDay,
                'is_holiday' => $isClosure,
                'is_future' => $date > $today,
                'is_today' => $date === $today,
                'holiday' => $holiday,
                'leave' => $onLeave,
                'record_id' => $record['id'] ?? null,
                'check_in_at' => $record['check_in_at'] ?? null,
                'check_out_at' => $record['check_out_at'] ?? null,
                'check_in_time' => TimeFormat::timeOfDay($record['check_in_at'] ?? null),
                'check_out_time' => TimeFormat::timeOfDay($record['check_out_at'] ?? null),
                'worked_seconds' => (int) ($record['worked_seconds'] ?? 0),
                'worked_hours' => TimeFormat::hours((int) ($record['worked_seconds'] ?? 0)),
                'break_seconds' => (int) ($record['break_seconds'] ?? 0),
                'overtime_seconds' => (int) ($record['overtime_seconds'] ?? 0),
                'late_minutes' => (int) ($record['late_minutes'] ?? 0),
                'early_leave_minutes' => (int) ($record['early_leave_minutes'] ?? 0),
                'is_regularised' => (bool) ($record['is_regularised'] ?? false),
                'remarks' => $record['remarks'] ?? null,
                'shift' => $this->shifts->summarise($shift),
            ];
        }

        return $days;
    }

    /**
     * Totals for a grid.
     *
     * @param list<array<string, mixed>> $days
     * @return array<string, mixed>
     */
    public function summarise(array $days): array
    {
        $counts = [
            'present' => 0, 'absent' => 0, 'half_day' => 0, 'on_leave' => 0,
            'holiday' => 0, 'weekly_off' => 0, 'wfh' => 0,
        ];

        $workedSeconds = 0;
        $overtimeSeconds = 0;
        $lateMinutes = 0;
        $lateDays = 0;
        $checkIns = [];
        $checkOuts = [];

        foreach ($days as $day) {
            $status = $day['status'];

            if (is_string($status) && array_key_exists($status, $counts)) {
                $counts[$status]++;
            }

            $workedSeconds += (int) $day['worked_seconds'];
            $overtimeSeconds += (int) $day['overtime_seconds'];
            $lateMinutes += (int) $day['late_minutes'];

            if ((int) $day['late_minutes'] > 0) {
                $lateDays++;
            }

            if ($day['check_in_at'] !== null) {
                $checkIns[] = (string) $day['check_in_at'];
            }

            if ($day['check_out_at'] !== null) {
                $checkOuts[] = (string) $day['check_out_at'];
            }
        }

        $payableDays = $counts['present'] + $counts['wfh'] + ($counts['half_day'] / 2);

        return [
            'present_days' => $counts['present'],
            'absent_days' => $counts['absent'],
            'half_days' => $counts['half_day'],
            'leave_days' => $counts['on_leave'],
            'holidays' => $counts['holiday'],
            'weekly_offs' => $counts['weekly_off'],
            'wfh_days' => $counts['wfh'],
            'payable_days' => round($payableDays, 1),
            'late_days' => $lateDays,
            'late_minutes' => $lateMinutes,
            'total_worked_seconds' => $workedSeconds,
            'total_hours' => TimeFormat::hours($workedSeconds),
            'overtime_seconds' => $overtimeSeconds,
            'overtime_hours' => TimeFormat::hours($overtimeSeconds),
            'average_check_in' => TimeFormat::averageTimeOfDay($checkIns),
            'average_check_out' => TimeFormat::averageTimeOfDay($checkOuts),
        ];
    }

    /**
     * Chooses the status shown for one day.
     *
     * A stored status wins whenever punches back it up. Only an empty day is
     * explained by leave, a holiday or the weekly off, and only a working day
     * in the past is ever called an absence.
     */
    private function statusFor(
        ?array $record,
        string $date,
        string $today,
        bool $isWorkingDay,
        bool $isClosure,
        bool $onLeave,
    ): ?string {
        if ($record !== null && $record['check_in_at'] !== null) {
            return (string) $record['status'];
        }

        if ($onLeave) {
            return 'on_leave';
        }

        if ($isClosure) {
            return 'holiday';
        }

        if (!$isWorkingDay) {
            return 'weekly_off';
        }

        if ($date > $today) {
            return null;
        }

        // An explicitly stored status on an empty working day is a deliberate
        // statement by HR, so it survives.
        return $record === null ? 'absent' : (string) $record['status'];
    }
}
