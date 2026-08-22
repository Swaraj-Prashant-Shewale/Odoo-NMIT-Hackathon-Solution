<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MetricSnapshots;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;
use Dayflow\Kernel\Support\Str;

/**
 * Builds one card of the home screen at a time.
 *
 * Every method here returns either the card's data or null. Null means "the
 * service that owns this figure did not answer", which the assembler turns
 * into "available": false so the client can draw a placeholder instead of a
 * zero. Showing a confident 0 for a figure nobody could fetch is worse than
 * showing nothing, because a reader cannot tell the two apart.
 */
final class DashboardSections
{
    /** Statuses the attendance domain recognises, used as the counting buckets. */
    private const ATTENDANCE_STATUSES = ['present', 'absent', 'half_day', 'on_leave', 'holiday', 'weekly_off', 'wfh'];

    /**
     * Fields that prove a summary endpoint really answered with counters,
     * rather than with an unrelated payload that happens to be an object.
     */
    private const SUMMARY_PROBE = ['attendance_rate', 'rate', 'present', 'present_days', 'absent', 'absent_days'];

    /** Pages of the daily register one card may pull when deriving a summary. */
    private const REGISTER_PAGE_CAP = 10;

    public function __construct(
        private readonly Downstream $downstream,
        private readonly EmployeeDirectory $directory,
        private readonly MetricSnapshots $snapshots,
        private readonly Principal $principal,
    ) {
    }

    // -----------------------------------------------------------------------
    // The person's own day
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function attendanceToday(): ?array
    {
        $today = Clock::today();

        $record = $this->downstream->record('attendance', '/attendance/today');

        if ($record === null) {
            $rows = $this->downstream->rows('attendance', '/attendance', ['from' => $today, 'to' => $today]);

            if ($rows === null) {
                return null;
            }

            // No row for today is a real answer - nobody has punched in yet.
            $record = $rows[0] ?? [];
        }

        $checkedInAt = Payload::text($record, ['checked_in_at', 'check_in_at', 'first_in_at']);
        $checkedOutAt = Payload::text($record, ['checked_out_at', 'check_out_at', 'last_out_at']);
        $seconds = $this->workedSeconds($record);

        return [
            'date' => $today,
            'status' => Payload::text($record, ['status'], $checkedInAt === '' ? 'absent' : 'present'),
            'checked_in' => $checkedInAt !== '' && $checkedOutAt === '',
            'checked_in_at' => $checkedInAt === '' ? null : $checkedInAt,
            'checked_out_at' => $checkedOutAt === '' ? null : $checkedOutAt,
            'worked_seconds' => $seconds,
            'worked_hours' => Payload::round($seconds / 3600),
            'worked_label' => Str::duration($seconds),
            'is_late' => self::isLate($record),
            'late_minutes' => Payload::int($record, ['late_minutes', 'late_by_minutes']),
            'shift' => [
                'name' => Payload::text($record, ['shift_name', 'shift'], 'General'),
                'starts_at' => Payload::text($record, ['shift_start', 'shift_starts_at', 'scheduled_start'], ''),
                'ends_at' => Payload::text($record, ['shift_end', 'shift_ends_at', 'scheduled_end'], ''),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function attendanceThisMonth(): ?array
    {
        [$from, $to] = Clock::monthBounds(Clock::today());

        $rows = $this->downstream->rows('attendance', '/attendance', ['from' => $from, 'to' => $to, 'per_page' => 100]);

        if ($rows !== null) {
            return $this->summariseDays($rows) + ['period' => ['from' => $from, 'to' => $to]];
        }

        $summary = $this->downstream->record('attendance', '/attendance/summary', ['from' => $from, 'to' => $to]);

        if ($summary === null) {
            return null;
        }

        return $this->readSummaryCounts($summary) + ['period' => ['from' => $from, 'to' => $to]];
    }

    /**
     * The seven-day strip on the weekly view.
     *
     * Every one of the seven days is emitted even when attendance has no record
     * for it, so the strip keeps its shape and a gap reads as a gap.
     *
     * @return list<array<string, mixed>>|null
     */
    public function attendanceWeek(): ?array
    {
        $to = Clock::today();
        $from = Clock::parse($to)->modify('-6 days')->format('Y-m-d');

        $rows = $this->downstream->rows('attendance', '/attendance', ['from' => $from, 'to' => $to, 'per_page' => 50]);

        if ($rows === null) {
            return null;
        }

        $byDate = [];
        foreach ($rows as $row) {
            $date = Payload::text($row, ['date', 'attendance_date', 'work_date']);
            if ($date !== '') {
                $byDate[substr($date, 0, 10)] = $row;
            }
        }

        $strip = [];
        foreach (Clock::dateRange($from, $to) as $date) {
            $row = $byDate[$date] ?? [];
            $seconds = $this->workedSeconds($row);

            $strip[] = [
                'date' => $date,
                'weekday' => Clock::parse($date)->format('D'),
                'status' => Payload::text($row, ['status'], Clock::isWeekend($date) ? 'weekly_off' : 'absent'),
                'worked_hours' => Payload::round($seconds / 3600),
                'checked_in_at' => Payload::text($row, ['checked_in_at', 'check_in_at'], '') ?: null,
                'checked_out_at' => Payload::text($row, ['checked_out_at', 'check_out_at'], '') ?: null,
            ];
        }

        return $strip;
    }

    /** @return array<string, mixed>|null */
    public function leaveBalances(): ?array
    {
        $rows = $this->downstream->rows('leave', '/leave-balances')
            ?? $this->downstream->rows('leave', '/leave/balances');

        if ($rows === null) {
            return null;
        }

        $types = [];
        $totalAvailable = 0.0;

        foreach ($rows as $row) {
            $entitled = Payload::float($row, ['entitled_days', 'allocated_days', 'opening_balance']);
            $used = Payload::float($row, ['used_days', 'taken_days', 'consumed_days']);
            $available = Payload::float(
                $row,
                ['available_days', 'balance_days', 'remaining_days', 'closing_balance'],
                max(0.0, $entitled - $used)
            );

            $totalAvailable += $available;

            $types[] = [
                'leave_type_id' => Payload::text($row, ['leave_type_id', 'id'], ''),
                'leave_type' => Payload::text($row, ['leave_type_name', 'leave_type', 'name'], 'Leave'),
                'category' => Payload::text($row, ['category', 'leave_category'], ''),
                'entitled_days' => Payload::round($entitled),
                'used_days' => Payload::round($used),
                'available_days' => Payload::round($available),
                'pending_days' => Payload::round(Payload::float($row, ['pending_days', 'in_approval_days'])),
            ];
        }

        return [
            'types' => $types,
            'total_available_days' => Payload::round($totalAvailable),
        ];
    }

    /** @return array<string, mixed>|null */
    public function pendingLeaveRequests(): ?array
    {
        $query = ['status' => 'pending', 'per_page' => 5, 'page' => 1];

        $total = $this->downstream->total('leave', '/leave/requests', $query);

        if ($total === null) {
            return null;
        }

        $rows = $this->downstream->rows('leave', '/leave/requests', $query) ?? [];

        return [
            'count' => $total,
            'requests' => array_map(
                static fn (array $row): array => [
                    'id' => Payload::text($row, ['id', 'request_id'], ''),
                    'leave_type' => Payload::text($row, ['leave_type_name', 'leave_type'], 'Leave'),
                    'starts_on' => Payload::text($row, ['starts_on', 'start_date'], ''),
                    'ends_on' => Payload::text($row, ['ends_on', 'end_date'], ''),
                    'day_count' => Payload::round(Payload::float($row, ['day_count', 'days'])),
                ],
                $rows
            ),
        ];
    }

    /**
     * The most recent published payslip, reduced to a headline.
     *
     * Only the period and the net figure are carried. A home screen never needs
     * the component breakdown, and the less salary detail crosses a service
     * boundary the smaller the consequence of anything going wrong downstream.
     *
     * @return array<string, mixed>|null
     */
    public function latestPayslip(): ?array
    {
        $rows = $this->downstream->rows('payroll', '/payslips', ['per_page' => 1, 'page' => 1, 'status' => 'published']);

        if ($rows === null) {
            $rows = $this->downstream->rows('payroll', '/payslips', ['per_page' => 1, 'page' => 1]);
        }

        if ($rows === null) {
            return null;
        }

        $row = $rows[0] ?? null;

        if ($row === null) {
            return ['payslip' => null];
        }

        $period = Payload::text($row, ['period', 'pay_period', 'period_key'], '');
        $net = Payload::int($row, ['net_minor', 'net_pay_minor', 'net_payable_minor']);

        return [
            'payslip' => [
                'id' => Payload::text($row, ['id', 'payslip_id'], ''),
                'period' => $period,
                'period_label' => $period === '' ? '' : Period::label($period),
                'net_minor' => $net,
                'net_display' => Str::money($net, Env::get('CURRENCY_SYMBOL', '')),
                'published_on' => Payload::text($row, ['published_on', 'published_at', 'paid_on'], '') ?: null,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function learning(): ?array
    {
        $rows = $this->downstream->rows('learning', '/enrolments', ['per_page' => 100])
            ?? $this->downstream->rows('learning', '/learning/enrolments', ['per_page' => 100]);

        if ($rows === null) {
            return null;
        }

        $today = Clock::today();
        $counts = ['not_started' => 0, 'in_progress' => 0, 'completed' => 0, 'expired' => 0];
        $overdue = 0;
        $outstanding = [];

        foreach ($rows as $row) {
            $status = Payload::text($row, ['status', 'enrolment_status'], 'not_started');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }

            if ($status === 'completed') {
                continue;
            }

            $dueOn = Payload::text($row, ['due_on', 'due_date'], '');
            $isOverdue = $dueOn !== '' && $dueOn < $today;

            if ($isOverdue) {
                $overdue++;
            }

            $outstanding[] = [
                'course_id' => Payload::text($row, ['course_id', 'id'], ''),
                'title' => Payload::text($row, ['course_title', 'title', 'course_name'], 'Course'),
                'status' => $status,
                'due_on' => $dueOn === '' ? null : $dueOn,
                'progress' => Payload::round(Payload::float($row, ['progress', 'progress_percent', 'completion'])),
                'is_overdue' => $isOverdue,
                'is_mandatory' => Payload::bool($row, ['is_mandatory', 'mandatory']),
            ];
        }

        // Anything with a deadline is shown before anything without one.
        usort($outstanding, static function (array $a, array $b): int {
            return [$a['due_on'] === null, (string) $a['due_on']] <=> [$b['due_on'] === null, (string) $b['due_on']];
        });

        return [
            'assigned' => $counts['not_started'] + $counts['in_progress'],
            'not_started' => $counts['not_started'],
            'in_progress' => $counts['in_progress'],
            'completed' => $counts['completed'],
            'expired' => $counts['expired'],
            'overdue' => $overdue,
            'courses' => array_slice($outstanding, 0, 5),
        ];
    }

    /** @return array<string, mixed>|null */
    public function goals(): ?array
    {
        $rows = $this->downstream->rows('talent', '/goals', ['status' => 'active', 'per_page' => 50])
            ?? $this->downstream->rows('talent', '/talent/goals', ['status' => 'active', 'per_page' => 50]);

        if ($rows === null) {
            return null;
        }

        $goals = [];
        $progressTotal = 0.0;

        foreach ($rows as $row) {
            $progress = Payload::float($row, ['progress', 'progress_percent', 'completion']);
            $progressTotal += $progress;

            $goals[] = [
                'id' => Payload::text($row, ['id', 'goal_id'], ''),
                'title' => Payload::text($row, ['title', 'name'], 'Goal'),
                'status' => Payload::text($row, ['status'], 'active'),
                'progress' => Payload::round($progress),
                'due_on' => Payload::text($row, ['due_on', 'target_date', 'ends_on'], '') ?: null,
            ];
        }

        return [
            'active_count' => count($goals),
            'average_progress' => $goals === [] ? 0.0 : Payload::round($progressTotal / count($goals)),
            'goals' => array_slice($goals, 0, 5),
        ];
    }

    /** @return array<string, mixed>|null */
    public function inbox(): ?array
    {
        $unread = $this->downstream->total('notification', '/notifications', ['status' => 'unread', 'per_page' => 1, 'page' => 1]);
        $announcements = $this->downstream->rows('notification', '/announcements', ['per_page' => 1, 'page' => 1]);

        if ($unread === null && $announcements === null) {
            return null;
        }

        $latest = $announcements[0] ?? null;

        return [
            'unread_count' => $unread ?? 0,
            'unread_available' => $unread !== null,
            'latest_announcement' => $latest === null ? null : [
                'id' => Payload::text($latest, ['id'], ''),
                'title' => Payload::text($latest, ['title', 'subject'], ''),
                'excerpt' => mb_substr(Payload::text($latest, ['summary', 'excerpt', 'body', 'message'], ''), 0, 200),
                'published_at' => Payload::text($latest, ['published_at', 'created_at'], '') ?: null,
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // The manager's team
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function teamToday(?string $managerId): ?array
    {
        $today = Clock::today();

        $board = $this->downstream->record('attendance', '/attendance/team-today');

        if ($board !== null && isset($board['people']) && is_array($board['people'])) {
            return $this->teamBoard($board, $managerId);
        }

        // The daily register filters on a from/to window, not on a single
        // "date" key. Asking for one day has to be expressed as a window of
        // one day, or the answer silently widens to the whole month and a
        // month of records gets counted as today.
        $rows = $this->downstream->rows('attendance', '/attendance', ['from' => $today, 'to' => $today, 'scope' => 'team', 'per_page' => 100]);

        if ($rows === null) {
            return null;
        }

        $counts = array_fill_keys(self::ATTENDANCE_STATUSES, 0);
        $lateArrivals = 0;

        foreach ($rows as $row) {
            $status = Payload::text($row, ['status'], '');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }

            if (self::isLate($row)) {
                $lateArrivals++;
            }
        }

        // The roster gives the true team size; without it, only the people
        // attendance knew about can be counted.
        $teamSize = $managerId !== null && $this->directory->available()
            ? count($this->directory->reportsTo($managerId))
            : count($rows);

        // Somebody with no record at all today is absent just as surely as
        // somebody explicitly marked so, and only the roster reveals them.
        $unrecorded = max(0, $teamSize - count($rows));

        // Holidays and weekly offs are taken out of the denominator: a team is
        // not 0% present because today is a Sunday.
        $expectedToWork = max(0, $teamSize - $counts['holiday'] - $counts['weekly_off']);

        return [
            'team_size' => $teamSize,
            'expected_to_work' => $expectedToWork,
            'present' => $counts['present'] + $counts['wfh'],
            'working_from_home' => $counts['wfh'],
            'half_day' => $counts['half_day'],
            'on_leave' => $counts['on_leave'],
            'absent' => $counts['absent'] + $unrecorded,
            'late_arrivals' => $lateArrivals,
            'present_rate' => Payload::percent((float) ($counts['present'] + $counts['wfh']), (float) $expectedToWork),
        ];
    }

    /** @return array<string, mixed>|null */
    public function pendingApprovals(bool $canApproveLeave, bool $canApproveRegularisation, bool $canApproveExpense): ?array
    {
        $queues = [];

        if ($canApproveLeave) {
            $leave = $this->downstream->total('leave', '/leave/pending-approvals')
                ?? $this->downstream->total('leave', '/leave/requests', ['status' => 'pending', 'scope' => 'team', 'per_page' => 1, 'page' => 1]);

            if ($leave !== null) {
                $queues['leave_requests'] = $leave;
            }
        }

        if ($canApproveRegularisation) {
            $regularisations = $this->downstream->total('attendance', '/regularisations', ['status' => 'pending', 'per_page' => 1, 'page' => 1]);

            if ($regularisations !== null) {
                $queues['regularisations'] = $regularisations;
            }
        }

        if ($canApproveExpense) {
            $expenses = $this->downstream->total('payroll', '/expenses', ['status' => 'submitted', 'per_page' => 1, 'page' => 1]);

            if ($expenses !== null) {
                $queues['expense_claims'] = $expenses;
            }
        }

        if ($queues === []) {
            return null;
        }

        return [
            'total' => array_sum($queues),
            'queues' => $queues,
        ];
    }

    /**
     * Who on the team is away over the next fortnight.
     *
     * @return array<string, mixed>|null
     */
    public function teamLeaveCalendar(int $days = 14): ?array
    {
        $from = Clock::today();
        $to = Clock::parse($from)->modify(sprintf('+%d days', max(1, $days) - 1))->format('Y-m-d');

        $query = ['status' => 'approved', 'scope' => 'team', 'from' => $from, 'to' => $to, 'per_page' => 100];

        $rows = $this->downstream->rows('leave', '/leave/requests', $query)
            ?? $this->downstream->rows('leave', '/leave/calendar', ['from' => $from, 'to' => $to]);

        if ($rows === null) {
            return null;
        }

        $names = $this->directory->available() ? $this->directory->nameIndex() : [];
        $calendar = array_fill_keys(Clock::dateRange($from, $to), []);

        foreach ($rows as $row) {
            $startsOn = max(Payload::text($row, ['starts_on', 'start_date'], $from), $from);
            $endsOn = min(Payload::text($row, ['ends_on', 'end_date'], $to), $to);

            if ($startsOn > $endsOn) {
                continue;
            }

            $employeeId = Payload::text($row, ['employee_id'], '');
            $entry = [
                'employee_id' => $employeeId,
                'employee_name' => Payload::text($row, ['employee_name', 'full_name'], $names[$employeeId] ?? 'Team member'),
                'leave_type' => Payload::text($row, ['leave_type_name', 'leave_type'], 'Leave'),
            ];

            foreach (Clock::dateRange($startsOn, $endsOn) as $date) {
                if (array_key_exists($date, $calendar)) {
                    $calendar[$date][] = $entry;
                }
            }
        }

        $out = [];
        foreach ($calendar as $date => $entries) {
            $out[] = [
                'date' => $date,
                'weekday' => Clock::parse($date)->format('D'),
                'away_count' => count($entries),
                'people' => $entries,
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'days' => $out,
            'total_away' => count($rows),
        ];
    }

    /** @return array<string, mixed>|null */
    public function teamGoals(): ?array
    {
        $rows = $this->downstream->rows('talent', '/goals', ['scope' => 'team', 'per_page' => 100]);

        if ($rows === null) {
            return null;
        }

        $achieved = 0;
        $active = 0;

        foreach ($rows as $row) {
            $status = Payload::text($row, ['status'], '');
            if ($status === 'achieved') {
                $achieved++;
            } elseif ($status === 'active') {
                $active++;
            }
        }

        // Draft and cancelled goals are excluded from the denominator: neither
        // represents work the team was actually asked to complete.
        $counted = $achieved + $active;

        return [
            'total' => count($rows),
            'active' => $active,
            'achieved' => $achieved,
            'completion_rate' => Payload::percent((float) $achieved, (float) $counted),
        ];
    }

    // -----------------------------------------------------------------------
    // The organisation
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function headcount(): ?array
    {
        if (!$this->directory->available()) {
            return null;
        }

        [$from, $to] = Clock::monthBounds(Clock::today());

        $joiners = $this->directory->joinersBetween($from, $to);
        $leavers = $this->directory->leaversBetween($from, $to);
        $headcount = $this->directory->headcount();

        $this->remember(Permissions::PROFILE_VIEW_ALL, [
            ['metric_key' => 'headcount.total', 'period' => substr($to, 0, 7), 'value' => (float) $headcount],
            ['metric_key' => 'headcount.joiners', 'period' => substr($to, 0, 7), 'value' => (float) count($joiners)],
            ['metric_key' => 'headcount.leavers', 'period' => substr($to, 0, 7), 'value' => (float) count($leavers)],
        ]);

        return [
            'headcount' => $headcount,
            'joiners_this_month' => count($joiners),
            'leavers_this_month' => count($leavers),
            'net_change' => count($joiners) - count($leavers),
            'average_tenure_years' => $this->directory->averageTenureYears(),
            'period' => ['from' => $from, 'to' => $to],
        ];
    }

    /** @return array<string, mixed>|null */
    public function workforceComposition(): ?array
    {
        if (!$this->directory->available()) {
            return null;
        }

        return [
            'by_department' => $this->directory->byDepartment(),
            'by_employment_type' => $this->directory->byEmploymentType(),
        ];
    }

    /** @return list<array<string, mixed>>|null */
    public function headcountTrend(int $months = 12): ?array
    {
        if (!$this->directory->available()) {
            return null;
        }

        return $this->directory->headcountTrend($months);
    }

    /**
     * Attendance rate for each of the last six months.
     *
     * @return array<string, mixed>|null
     */
    public function attendanceRateTrend(int $months = 6): ?array
    {
        $series = [];
        $answered = false;

        foreach (Period::lastMonths($months) as $month) {
            $counters = $this->attendanceCounters($month['from'], $month['to'], ['scope' => 'all']);

            if ($counters === null) {
                $series[] = ['period' => $month['period'], 'label' => $month['label'], 'rate' => null];
                continue;
            }

            $answered = true;
            $rate = (float) $counters['attendance_rate'];

            $series[] = ['period' => $month['period'], 'label' => $month['label'], 'rate' => $rate];

            $this->remember(Permissions::ATTENDANCE_VIEW_ALL, [
                ['metric_key' => 'attendance.rate', 'period' => $month['period'], 'value' => $rate],
            ]);
        }

        return $answered ? ['months' => $series] : null;
    }

    /**
     * The five departments losing the most days to absence this month.
     *
     * @return array<string, mixed>|null
     */
    public function absenceByDepartment(int $limit = 5): ?array
    {
        [$from, $to] = Clock::monthBounds(Clock::today());

        $rows = $this->downstream->rows('attendance', '/attendance/summary', [
            'from' => $from,
            'to' => $to,
            'group_by' => 'department',
            'scope' => 'all',
        ]);

        // Not every attendance service publishes a pre-aggregated summary. The
        // same answer is derivable from the daily register and the roster, and
        // deriving it is what keeps this card from being permanently dark.
        if ($rows === null || $rows === []) {
            $rows = $this->registerByDepartment($from, $to);
        }

        if ($rows === null || $rows === []) {
            return null;
        }

        $departments = [];

        foreach ($rows as $row) {
            $absent = Payload::float($row, ['absent', 'absent_days', 'absences']);
            $present = Payload::float($row, ['present', 'present_days']);
            $half = Payload::float($row, ['half_day', 'half_days']);

            $departments[] = [
                'department_id' => Payload::text($row, ['department_id'], ''),
                'department' => Payload::text($row, ['department_name', 'department', 'label'], 'Unassigned'),
                'absent_days' => Payload::round($absent),
                'absence_rate' => Payload::percent($absent, $absent + $present + $half),
            ];
        }

        usort($departments, static fn (array $a, array $b): int => $b['absent_days'] <=> $a['absent_days']);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'departments' => array_slice($departments, 0, max(1, $limit)),
        ];
    }

    /**
     * Days taken per leave type across the year so far.
     *
     * @return array<string, mixed>|null
     */
    public function leaveUtilisation(): ?array
    {
        $to = Clock::today();
        $from = Clock::parse($to)->format('Y-01-01');

        $summary = $this->downstream->rows('leave', '/leave/summary', [
            'from' => $from,
            'to' => $to,
            'group_by' => 'type',
            'scope' => 'all',
        ]);

        if ($summary !== null && $summary !== []) {
            return [
                'period' => ['from' => $from, 'to' => $to],
                'types' => array_map(
                    static fn (array $row): array => [
                        'leave_type' => Payload::text($row, ['leave_type_name', 'leave_type', 'label', 'name'], 'Leave'),
                        'days_taken' => Payload::round(Payload::float($row, ['days_taken', 'day_count', 'days', 'total_days'])),
                        'request_count' => Payload::int($row, ['request_count', 'requests', 'count']),
                    ],
                    $summary
                ),
            ];
        }

        $requests = $this->downstream->collect('leave', '/leave/requests', [
            'status' => 'approved',
            'scope' => 'all',
            'from' => $from,
            'to' => $to,
        ], 100, 10);

        if ($requests === null) {
            return null;
        }

        $byType = [];
        foreach ($requests as $row) {
            $type = Payload::text($row, ['leave_type_name', 'leave_type'], 'Leave');
            $byType[$type] ??= ['leave_type' => $type, 'days_taken' => 0.0, 'request_count' => 0];
            $byType[$type]['days_taken'] += Payload::float($row, ['day_count', 'days']);
            $byType[$type]['request_count']++;
        }

        $types = array_values($byType);
        usort($types, static fn (array $a, array $b): int => $b['days_taken'] <=> $a['days_taken']);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'types' => array_map(
                static fn (array $row): array => $row + ['days_taken' => Payload::round((float) $row['days_taken'])],
                $types
            ),
        ];
    }

    /** @return array<string, mixed>|null */
    public function onboarding(): ?array
    {
        $inProgress = $this->downstream->total('employee', '/onboarding', ['status' => 'in_progress', 'per_page' => 1, 'page' => 1]);

        if ($inProgress === null) {
            return null;
        }

        return [
            'in_progress' => $inProgress,
            'not_started' => $this->downstream->total('employee', '/onboarding', ['status' => 'pending', 'per_page' => 1, 'page' => 1]) ?? 0,
        ];
    }

    /** @return array<string, mixed>|null */
    public function documentsExpiring(int $days = 30): ?array
    {
        $rows = $this->downstream->rows('employee', '/documents/expiring', ['days' => $days, 'per_page' => 50])
            ?? $this->downstream->rows('employee', '/documents', ['expiring_within_days' => $days, 'per_page' => 50]);

        if ($rows === null) {
            return null;
        }

        $names = $this->directory->available() ? $this->directory->nameIndex() : [];
        $horizon = Clock::parse(Clock::today())->modify(sprintf('+%d days', $days))->format('Y-m-d');

        $documents = [];
        foreach ($rows as $row) {
            $expiresOn = Payload::text($row, ['expires_on', 'expiry_date', 'valid_until'], '');

            // A collection endpoint may not have applied the horizon itself, so
            // it is applied here rather than assumed.
            if ($expiresOn === '' || $expiresOn > $horizon) {
                continue;
            }

            $employeeId = Payload::text($row, ['employee_id'], '');
            $documents[] = [
                'id' => Payload::text($row, ['id', 'document_id'], ''),
                'employee_id' => $employeeId,
                'employee_name' => Payload::text($row, ['employee_name'], $names[$employeeId] ?? ''),
                'document_type' => Payload::text($row, ['document_type', 'type', 'name'], 'Document'),
                'expires_on' => $expiresOn,
            ];
        }

        usort($documents, static fn (array $a, array $b): int => $a['expires_on'] <=> $b['expires_on']);

        return [
            'window_days' => $days,
            'count' => count($documents),
            'documents' => array_slice($documents, 0, 5),
        ];
    }

    /** @return array<string, mixed>|null */
    public function trainingCompliance(): ?array
    {
        $summary = $this->downstream->record('learning', '/learning/compliance');

        if ($summary !== null && Payload::value($summary, ['compliance_rate', 'rate']) !== null) {
            return [
                'compliance_rate' => Payload::round(Payload::float($summary, ['compliance_rate', 'rate'])),
                'compliant' => Payload::int($summary, ['compliant', 'completed']),
                'total' => Payload::int($summary, ['total', 'required']),
            ];
        }

        $enrolments = $this->downstream->collect('learning', '/enrolments', [
            'scope' => 'all',
            'mandatory' => 'true',
        ], 100, 10);

        if ($enrolments === null) {
            return null;
        }

        $mandatory = array_values(array_filter(
            $enrolments,
            static fn (array $row): bool => Payload::bool($row, ['is_mandatory', 'mandatory'], true)
        ));

        $completed = count(array_filter(
            $mandatory,
            static fn (array $row): bool => Payload::text($row, ['status'], '') === 'completed'
        ));

        return [
            'compliance_rate' => Payload::percent((float) $completed, (float) count($mandatory)),
            'compliant' => $completed,
            'total' => count($mandatory),
        ];
    }

    // -----------------------------------------------------------------------
    // Finance
    // -----------------------------------------------------------------------

    /**
     * Payroll cost for each of the last six months.
     *
     * @return array<string, mixed>|null
     */
    public function payrollCostTrend(int $months = 6): ?array
    {
        $window = Period::lastMonths($months);
        $first = $window[0];
        $last = $window[count($window) - 1];

        $runs = $this->downstream->collect('payroll', '/payroll/runs', [
            'from' => $first['from'],
            'to' => $last['to'],
        ], 100, 5);

        if ($runs === null) {
            return null;
        }

        $totals = [];
        foreach ($runs as $run) {
            // A cancelled run never became a cost, so it is left out entirely.
            if (Payload::text($run, ['status'], '') === 'cancelled') {
                continue;
            }

            $period = substr(Payload::text($run, ['period', 'pay_period', 'period_key'], ''), 0, 7);
            if ($period === '') {
                continue;
            }

            $totals[$period] ??= ['gross' => 0, 'net' => 0, 'deductions' => 0, 'employees' => 0];
            $totals[$period]['gross'] += Payload::int($run, ['total_gross_minor', 'gross_minor']);
            $totals[$period]['net'] += Payload::int($run, ['total_net_minor', 'net_minor']);
            $totals[$period]['deductions'] += Payload::int($run, ['total_deductions_minor', 'deductions_minor']);
            $totals[$period]['employees'] += Payload::int($run, ['employee_count', 'employees']);
        }

        $symbol = Env::get('CURRENCY_SYMBOL', '');
        $series = [];

        foreach ($window as $month) {
            $figures = $totals[$month['period']] ?? ['gross' => 0, 'net' => 0, 'deductions' => 0, 'employees' => 0];

            $series[] = [
                'period' => $month['period'],
                'label' => $month['label'],
                'gross_minor' => $figures['gross'],
                'net_minor' => $figures['net'],
                'deductions_minor' => $figures['deductions'],
                'employee_count' => $figures['employees'],
                'net_display' => Str::money($figures['net'], $symbol),
            ];

            $this->remember(Permissions::PAYROLL_VIEW_ALL, [
                ['metric_key' => 'payroll.net_minor', 'period' => $month['period'], 'value' => (float) $figures['net']],
            ]);
        }

        return [
            'months' => $series,
            'latest_period' => $series === [] ? null : $series[count($series) - 1]['period'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function payrollCostByDepartment(): ?array
    {
        $runs = $this->downstream->collect('payroll', '/payroll/runs', ['per_page' => 12], 12, 1);

        if ($runs === null || $runs === []) {
            return null;
        }

        $latest = null;
        foreach ($runs as $run) {
            if (in_array(Payload::text($run, ['status'], ''), ['approved', 'paid'], true)) {
                $period = Payload::text($run, ['period', 'pay_period'], '');
                if ($latest === null || $period > Payload::text($latest, ['period', 'pay_period'], '')) {
                    $latest = $run;
                }
            }
        }

        if ($latest === null) {
            return null;
        }

        $runId = Payload::text($latest, ['id', 'run_id'], '');

        // The id came from another service, and it is about to become part of a
        // URL path where it cannot be bound as a parameter, so its shape is
        // checked before it is used.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $runId) !== 1) {
            return null;
        }

        $rows = $this->downstream->rows('payroll', '/payroll/runs/' . $runId . '/cost-by-department')
            ?: PayrollCost::byDepartment(
                $this->downstream->record('payroll', '/payroll/runs/' . $runId),
                $this->directory
            );

        if ($rows === null) {
            return null;
        }

        $symbol = Env::get('CURRENCY_SYMBOL', '');

        return [
            'period' => Payload::text($latest, ['period', 'pay_period'], ''),
            'run_id' => $runId,
            'departments' => array_map(
                static function (array $row) use ($symbol): array {
                    $net = Payload::int($row, ['net_minor', 'total_net_minor', 'cost_minor']);

                    return [
                        'department_id' => Payload::text($row, ['department_id'], ''),
                        'department' => Payload::text($row, ['department_name', 'department', 'label'], 'Unassigned'),
                        'employee_count' => Payload::int($row, ['employee_count', 'employees', 'headcount']),
                        'net_minor' => $net,
                        'net_display' => Str::money($net, $symbol),
                    ];
                },
                $rows
            ),
        ];
    }

    /** @return array<string, mixed>|null */
    public function expenseClaims(): ?array
    {
        $query = ['status' => 'submitted', 'per_page' => 100, 'page' => 1];

        $rows = $this->downstream->rows('payroll', '/expenses', $query);

        if ($rows === null) {
            return null;
        }

        $total = $this->downstream->total('payroll', '/expenses', $query) ?? count($rows);
        $value = 0;

        foreach ($rows as $row) {
            $value += Payload::int($row, ['amount_minor', 'claim_amount_minor', 'total_minor']);
        }

        return [
            'pending_count' => $total,
            'pending_value_minor' => $value,
            'pending_value_display' => Str::money($value, Env::get('CURRENCY_SYMBOL', '')),
        ];
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    /**
     * Turns a list of daily attendance records into the month's counters.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function summariseDays(array $rows): array
    {
        $counts = array_fill_keys(self::ATTENDANCE_STATUSES, 0);
        $seconds = 0;

        foreach ($rows as $row) {
            $status = Payload::text($row, ['status'], '');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }

            $seconds += $this->workedSeconds($row);
        }

        // Half days count as half a day present, which is what a person expects
        // to see when they add the figures up themselves.
        $workingDays = $counts['present'] + $counts['wfh'] + $counts['half_day'] + $counts['absent'] + $counts['on_leave'];
        $credited = $counts['present'] + $counts['wfh'] + ($counts['half_day'] / 2);

        return [
            'present' => $counts['present'],
            'work_from_home' => $counts['wfh'],
            'absent' => $counts['absent'],
            'half_days' => $counts['half_day'],
            'leave_taken' => $counts['on_leave'],
            'holidays' => $counts['holiday'],
            'weekly_offs' => $counts['weekly_off'],
            'worked_hours' => Payload::round($seconds / 3600),
            'attendance_rate' => Payload::percent((float) $credited, (float) $workingDays),
        ];
    }

    /**
     * Reads the same counters out of a pre-aggregated summary.
     *
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function readSummaryCounts(array $summary): array
    {
        $present = Payload::int($summary, ['present', 'present_days']);
        $wfh = Payload::int($summary, ['wfh', 'work_from_home_days']);
        $absent = Payload::int($summary, ['absent', 'absent_days']);
        $half = Payload::int($summary, ['half_day', 'half_days']);
        $leave = Payload::int($summary, ['on_leave', 'leave_days', 'leave_taken']);

        return [
            'present' => $present,
            'work_from_home' => $wfh,
            'absent' => $absent,
            'half_days' => $half,
            'leave_taken' => $leave,
            'holidays' => Payload::int($summary, ['holiday', 'holidays']),
            'weekly_offs' => Payload::int($summary, ['weekly_off', 'weekly_offs']),
            'worked_hours' => Payload::round(Payload::float($summary, ['worked_hours', 'total_hours'])),
            'attendance_rate' => $this->attendanceRateFrom($summary),
        ];
    }

    /** @param array<string, mixed> $summary */
    private function attendanceRateFrom(array $summary): float
    {
        $stated = Payload::value($summary, ['attendance_rate', 'rate']);

        if (is_numeric($stated)) {
            return Payload::round((float) $stated);
        }

        $present = Payload::float($summary, ['present', 'present_days']);
        $wfh = Payload::float($summary, ['wfh', 'work_from_home_days']);
        $half = Payload::float($summary, ['half_day', 'half_days']);
        $absent = Payload::float($summary, ['absent', 'absent_days']);
        $leave = Payload::float($summary, ['on_leave', 'leave_days', 'leave_taken']);

        return Payload::percent($present + $wfh + ($half / 2), $present + $wfh + $half + $absent + $leave);
    }

    /**
     * Folds the attendance service's live team board into this card's shape.
     *
     * @param array<string, mixed> $board
     * @return array<string, mixed>
     */
    private function teamBoard(array $board, ?string $managerId): array
    {
        $people = Payload::rows($board['people'] ?? []);

        $present = 0;
        $wfh = 0;
        $halfDay = 0;
        $onLeave = 0;
        $absent = 0;
        $offToday = 0;

        foreach ($people as $person) {
            $presence = Payload::text($person, ['presence'], '');
            $status = Payload::text($person, ['status'], '');

            if ($status === 'wfh') {
                $wfh++;
            }

            if ($status === 'half_day') {
                $halfDay++;
            }

            if ($presence === 'checked_in' || $presence === 'checked_out') {
                $present++;
            } elseif ($presence === 'on_leave') {
                $onLeave++;
            } elseif ($presence === 'holiday' || $presence === 'weekly_off') {
                $offToday++;
            } else {
                $absent++;
            }
        }

        // The board already carries everyone the caller may see. The roster is
        // only consulted when it came back empty, so a team is never reported
        // as having nobody in it just because attendance had no rows.
        $teamSize = $people === [] && $managerId !== null && $this->directory->available()
            ? count($this->directory->reportsTo($managerId))
            : count($people);

        // Holidays and weekly offs are taken out of the denominator: a team is
        // not 0% present because today is a Sunday.
        $expectedToWork = max(0, $teamSize - $offToday);

        return [
            'team_size' => $teamSize,
            'expected_to_work' => $expectedToWork,
            'present' => $present,
            'working_from_home' => $wfh,
            'half_day' => $halfDay,
            'on_leave' => $onLeave,
            'absent' => $absent,
            'late_arrivals' => Payload::int($board['summary'] ?? [], ['late']),
            'present_rate' => Payload::percent((float) $present, (float) $expectedToWork),
        ];
    }

    /**
     * Attendance counters for a window, however the owning service can supply
     * them.
     *
     * A purpose-built summary endpoint is asked for first, because it answers a
     * whole month in one call. When there is not one, the same counters are
     * derived from the daily register, which every attendance service
     * publishes. A card that only works against an optional endpoint is a card
     * that stays dark forever.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    private function attendanceCounters(string $from, string $to, array $query = []): ?array
    {
        $summary = $this->downstream->record('attendance', '/attendance/summary', $query + ['from' => $from, 'to' => $to]);

        if ($summary !== null && Payload::value($summary, self::SUMMARY_PROBE) !== null) {
            return $this->readSummaryCounts($summary);
        }

        $rows = $this->downstream->collect(
            'attendance',
            '/attendance',
            $query + ['from' => $from, 'to' => $to],
            100,
            self::REGISTER_PAGE_CAP
        );

        return $rows === null ? null : $this->summariseDays($rows);
    }

    /**
     * The register for a window, folded into one row per department.
     *
     * Attendance stores an employee id and nothing else about a person, so the
     * department has to come from the roster. Without the roster there is no
     * honest answer, and null is how this service says so.
     *
     * @return list<array<string, mixed>>|null
     */
    private function registerByDepartment(string $from, string $to): ?array
    {
        if (!$this->directory->available()) {
            return null;
        }

        $rows = $this->downstream->collect(
            'attendance',
            '/attendance',
            ['from' => $from, 'to' => $to, 'scope' => 'all'],
            100,
            self::REGISTER_PAGE_CAP
        );

        if ($rows === null) {
            return null;
        }

        $people = $this->directory->index();
        $departments = [];

        foreach ($rows as $row) {
            $person = $people[Payload::text($row, ['employee_id'], '')] ?? [];
            $key = Payload::text($person, ['department_id'], 'unassigned');

            $departments[$key] ??= [
                'department_id' => $key === 'unassigned' ? '' : $key,
                'department_name' => Payload::text($person, ['department_name', 'department'], 'Unassigned'),
            ] + array_fill_keys(self::ATTENDANCE_STATUSES, 0);

            $status = Payload::text($row, ['status'], '');
            if (isset($departments[$key][$status])) {
                $departments[$key][$status]++;
            }
        }

        return array_values($departments);
    }

    /**
     * Whether a day's record counts as a late arrival.
     *
     * The register records how many minutes late somebody was rather than a
     * flag, so reading only a boolean field would report nobody as ever late.
     * Both shapes are accepted, because the figure is the point and not the
     * spelling.
     *
     * @param array<string, mixed> $row
     */
    private static function isLate(array $row): bool
    {
        if (Payload::int($row, ['late_minutes', 'late_by_minutes']) > 0) {
            return true;
        }

        return Payload::bool($row, ['is_late', 'late']);
    }

    /** @param array<string, mixed> $row */
    private function workedSeconds(array $row): int
    {
        $stated = Payload::int($row, ['worked_seconds', 'work_seconds', 'duration_seconds']);

        if ($stated > 0) {
            return $stated;
        }

        $hours = Payload::float($row, ['worked_hours', 'work_hours']);
        if ($hours > 0) {
            return (int) round($hours * 3600);
        }

        $in = Payload::text($row, ['checked_in_at', 'check_in_at', 'first_in_at']);
        if ($in === '') {
            return 0;
        }

        $out = Payload::text($row, ['checked_out_at', 'check_out_at', 'last_out_at']);
        $start = strtotime($in);
        $end = $out === '' ? Clock::timestamp() : strtotime($out);

        if ($start === false || $end === false) {
            return 0;
        }

        return max(0, $end - $start);
    }

    /**
     * Stores computed figures so a trend survives the archiving of its source.
     *
     * A snapshot is a convenience, never a dependency: if the write fails the
     * dashboard is unaffected, because every number on it was just computed
     * from the owning service anyway.
     *
     * A snapshot row is shared by everybody, but the figure that produced it
     * was computed from whatever the owning service was willing to show THIS
     * caller. Writing a manager's slice of attendance under the organisation's
     * key would publish one person's partial view as the company's number, so a
     * figure is only kept when the caller holds the organisation-wide
     * permission for the domain it came from.
     *
     * @param list<array{metric_key: string, period: string, value: float}> $metrics
     */
    private function remember(string $organisationPermission, array $metrics): void
    {
        if (!$this->principal->can($organisationPermission)) {
            return;
        }

        try {
            $this->snapshots->recordMany($metrics);
        } catch (\Throwable $exception) {
            Logger::warning('Metric snapshot write failed', ['error' => $exception->getMessage()]);
        }
    }
}
