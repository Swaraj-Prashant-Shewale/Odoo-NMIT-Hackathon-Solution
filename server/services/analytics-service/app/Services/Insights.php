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
 * The analyses behind the /analytics endpoints.
 *
 * Each one asks the owning service for the underlying records within the
 * caller's scope and does the grouping here, because the grouping a chart needs
 * (a bucket for every week in the range, including the empty ones) is a
 * presentation concern rather than something the owning service should know
 * about.
 */
final class Insights
{
    private const ATTENDANCE_STATUSES = ['present', 'absent', 'half_day', 'on_leave', 'holiday', 'weekly_off', 'wfh'];

    private const LEAVE_STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'withdrawn'];

    /** Pages of a downstream collection one analysis may pull. */
    private const PAGE_CAP = 20;

    public function __construct(
        private readonly Downstream $downstream,
        private readonly EmployeeDirectory $directory,
        private readonly MetricSnapshots $snapshots,
        private readonly Principal $principal,
    ) {
    }

    /**
     * Attendance trends over a window.
     *
     * @param array<string, mixed> $filters Validated query filters.
     * @param array<string, mixed> $scopeFilter Scope resolved from the caller's permissions.
     * @return array<string, mixed>
     */
    public function attendance(array $filters, array $scopeFilter, string $groupBy): array
    {
        ['from' => $from, 'to' => $to] = Period::range($filters, 30);

        $query = $scopeFilter + ['from' => $from, 'to' => $to];
        if (isset($filters['department_id'])) {
            $query['department_id'] = $filters['department_id'];
        }

        if ($groupBy === 'department') {
            return $this->attendanceByDepartment($query, $from, $to);
        }

        $records = $this->downstream->collect('attendance', '/attendance', $query, 100, 20);

        if ($records === null) {
            return $this->emptyAttendance($from, $to, $groupBy, $query);
        }

        $buckets = [];
        foreach (Period::buckets($from, $to, $groupBy) as $bucket) {
            $buckets[$bucket['key']] = [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'from' => $bucket['from'],
                'to' => $bucket['to'],
            ] + array_fill_keys(self::ATTENDANCE_STATUSES, 0) + ['records' => 0, 'worked_hours' => 0.0];
        }

        $totals = array_fill_keys(self::ATTENDANCE_STATUSES, 0);
        $totalSeconds = 0;
        $lateCount = 0;

        foreach ($records as $row) {
            $date = substr(Payload::text($row, ['date', 'attendance_date', 'work_date'], ''), 0, 10);
            if ($date === '') {
                continue;
            }

            $status = Payload::text($row, ['status'], '');
            $seconds = $this->workedSeconds($row);
            $totalSeconds += $seconds;

            if (isset($totals[$status])) {
                $totals[$status]++;
            }

            if (Payload::int($row, ['late_minutes', 'late_by_minutes']) > 0 || Payload::bool($row, ['is_late', 'late'])) {
                $lateCount++;
            }

            $key = Period::bucketFor($date, $groupBy);
            if (!isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['records']++;
            $buckets[$key]['worked_hours'] += $seconds / 3600;

            if (isset($buckets[$key][$status])) {
                $buckets[$key][$status]++;
            }
        }

        $series = [];
        foreach ($buckets as $bucket) {
            $bucket['worked_hours'] = Payload::round($bucket['worked_hours']);
            $bucket['attendance_rate'] = $this->rateOf($bucket);
            $series[] = $bucket;
        }

        return [
            'available' => true,
            'scope' => $scopeFilter['scope'] ?? 'all',
            'group_by' => $groupBy,
            'period' => ['from' => $from, 'to' => $to],
            'totals' => $totals + [
                'records' => count($records),
                'late_arrivals' => $lateCount,
                'worked_hours' => Payload::round($totalSeconds / 3600),
                'attendance_rate' => $this->rateOf($totals),
            ],
            'series' => $series,
        ];
    }

    /**
     * Leave trends over a window.
     *
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $scopeFilter
     * @return array<string, mixed>
     */
    public function leave(array $filters, array $scopeFilter): array
    {
        ['from' => $from, 'to' => $to] = Period::range($filters, 180);

        $query = $scopeFilter + ['from' => $from, 'to' => $to];
        if (isset($filters['department_id'])) {
            $query['department_id'] = $filters['department_id'];
        }

        $requests = $this->downstream->collect('leave', '/leave/requests', $query, 100, 20);

        if ($requests === null) {
            return [
                'available' => false,
                'scope' => $scopeFilter['scope'] ?? 'all',
                'period' => ['from' => $from, 'to' => $to],
                'totals' => [],
                'by_type' => [],
                'by_status' => [],
                'by_month' => [],
                'unavailable_services' => $this->downstream->unavailableServices(),
            ];
        }

        $byStatus = array_fill_keys(self::LEAVE_STATUSES, 0);
        $byType = [];
        $byMonth = [];
        $approvedDays = 0.0;
        $pendingDays = 0.0;

        foreach (Period::buckets($from, $to, 'month') as $bucket) {
            $byMonth[$bucket['key']] = [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'requests' => 0,
                'days' => 0.0,
            ];
        }

        foreach ($requests as $row) {
            $status = Payload::text($row, ['status'], '');
            $days = Payload::float($row, ['day_count', 'days']);
            $startsOn = substr(Payload::text($row, ['starts_on', 'start_date'], ''), 0, 10);

            if (isset($byStatus[$status])) {
                $byStatus[$status]++;
            }

            if ($status === 'approved') {
                $approvedDays += $days;
            } elseif ($status === 'pending') {
                $pendingDays += $days;
            }

            $type = Payload::text($row, ['leave_type_name', 'leave_type'], 'Leave');
            $byType[$type] ??= [
                'leave_type' => $type,
                'category' => Payload::text($row, ['category', 'leave_category'], ''),
                'requests' => 0,
                'days' => 0.0,
                'approved_days' => 0.0,
            ];
            $byType[$type]['requests']++;
            $byType[$type]['days'] += $days;
            if ($status === 'approved') {
                $byType[$type]['approved_days'] += $days;
            }

            $monthKey = $startsOn === '' ? '' : Period::bucketFor($startsOn, 'month');
            if (isset($byMonth[$monthKey])) {
                $byMonth[$monthKey]['requests']++;
                $byMonth[$monthKey]['days'] += $days;
            }
        }

        $types = array_values($byType);
        usort($types, static fn (array $a, array $b): int => $b['days'] <=> $a['days']);

        return [
            'available' => true,
            'scope' => $scopeFilter['scope'] ?? 'all',
            'period' => ['from' => $from, 'to' => $to],
            'totals' => [
                'requests' => count($requests),
                'approved_days' => Payload::round($approvedDays),
                'pending_days' => Payload::round($pendingDays),
                'approval_rate' => Payload::percent(
                    (float) $byStatus['approved'],
                    (float) ($byStatus['approved'] + $byStatus['rejected'])
                ),
            ],
            'by_status' => $byStatus,
            'by_type' => array_map(
                static fn (array $row): array => [
                    'leave_type' => $row['leave_type'],
                    'category' => $row['category'],
                    'requests' => $row['requests'],
                    'days' => Payload::round((float) $row['days']),
                    'approved_days' => Payload::round((float) $row['approved_days']),
                ],
                $types
            ),
            'by_month' => array_values(array_map(
                static fn (array $row): array => $row + ['days' => Payload::round((float) $row['days'])],
                $byMonth
            )),
        ];
    }

    /**
     * Workforce movement, attrition and tenure.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function headcount(array $filters): array
    {
        ['from' => $from, 'to' => $to] = Period::range($filters, 365);

        if (!$this->directory->available()) {
            return [
                'available' => false,
                'period' => ['from' => $from, 'to' => $to],
                'unavailable_services' => $this->downstream->unavailableServices(),
            ];
        }

        $joiners = $this->directory->joinersBetween($from, $to);
        $leavers = $this->directory->leaversBetween($from, $to);
        $headcount = $this->directory->headcount();
        $attrition = $this->directory->attritionRate($from, $to);

        $this->remember(Permissions::PROFILE_VIEW_ALL, [
            ['metric_key' => 'headcount.total', 'period' => substr(Clock::today(), 0, 7), 'value' => (float) $headcount],
            ['metric_key' => 'headcount.attrition_rate', 'period' => substr($to, 0, 7), 'value' => $attrition],
        ]);

        $names = $this->directory->nameIndex();

        return [
            'available' => true,
            'as_of' => Clock::today(),
            'period' => ['from' => $from, 'to' => $to],
            'headcount' => $headcount,
            'opening_headcount' => $this->directory->headcountOn($from),
            'closing_headcount' => $this->directory->headcountOn($to),
            'movement' => [
                'joiners' => count($joiners),
                'leavers' => count($leavers),
                'net_change' => count($joiners) - count($leavers),
            ],
            'attrition_rate' => $attrition,
            'trend' => $this->directory->headcountTrend(12),
            'by_department' => $this->directory->byDepartment(),
            'by_employment_type' => $this->directory->byEmploymentType(),
            'tenure' => [
                'bands' => $this->directory->tenureBands(),
                'average_years' => $this->directory->averageTenureYears(),
            ],
            'joiners' => $this->movementRows($joiners, $names, 'joined_on'),
            'leavers' => $this->movementRows($leavers, $names, 'exit_date'),
            'history' => $this->history('headcount.total'),
        ];
    }

    /**
     * Payroll cost trends.
     *
     * Only run-level totals are requested and only run-level totals are
     * returned. An individual salary never enters this analysis, so a reporting
     * screen can be opened in a meeting without exposing one.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function payroll(array $filters): array
    {
        ['from' => $from, 'to' => $to] = Period::range($filters, 180);

        $runs = $this->downstream->collect('payroll', '/payroll/runs', ['from' => $from, 'to' => $to], 100, 5);

        if ($runs === null) {
            return [
                'available' => false,
                'period' => ['from' => $from, 'to' => $to],
                'unavailable_services' => $this->downstream->unavailableServices(),
            ];
        }

        $symbol = Env::get('CURRENCY_SYMBOL', '');
        $months = [];

        foreach (Period::buckets($from, $to, 'month') as $bucket) {
            $months[$bucket['key']] = [
                'period' => $bucket['key'],
                'label' => $bucket['label'],
                'gross_minor' => 0,
                'net_minor' => 0,
                'deductions_minor' => 0,
                'employer_contribution_minor' => 0,
                'employee_count' => 0,
                'run_count' => 0,
            ];
        }

        foreach ($runs as $run) {
            if (Payload::text($run, ['status'], '') === 'cancelled') {
                continue;
            }

            $period = substr(Payload::text($run, ['period', 'pay_period', 'period_key'], ''), 0, 7);
            if (!isset($months[$period])) {
                continue;
            }

            $months[$period]['gross_minor'] += Payload::int($run, ['total_gross_minor', 'gross_minor']);
            $months[$period]['net_minor'] += Payload::int($run, ['total_net_minor', 'net_minor']);
            $months[$period]['deductions_minor'] += Payload::int($run, ['total_deductions_minor', 'deductions_minor']);
            $months[$period]['employer_contribution_minor'] += Payload::int($run, ['total_employer_contribution_minor', 'employer_contribution_minor']);
            $months[$period]['employee_count'] += Payload::int($run, ['employee_count', 'employees']);
            $months[$period]['run_count']++;
        }

        $series = [];
        $totalNet = 0;
        $totalGross = 0;

        $totalDeductions = 0;

        foreach ($months as $month) {
            $totalNet += $month['net_minor'];
            $totalGross += $month['gross_minor'];
            $totalDeductions += $month['deductions_minor'];

            $month['net_display'] = Str::money($month['net_minor'], $symbol);
            $month['average_cost_per_employee_minor'] = $month['employee_count'] > 0
                ? (int) round($month['net_minor'] / $month['employee_count'])
                : 0;

            $series[] = $month;

            $this->remember(Permissions::PAYROLL_VIEW_ALL, [
                ['metric_key' => 'payroll.net_minor', 'period' => $month['period'], 'value' => (float) $month['net_minor']],
            ]);
        }

        return [
            'available' => true,
            'period' => ['from' => $from, 'to' => $to],
            'totals' => [
                'gross_minor' => $totalGross,
                'net_minor' => $totalNet,
                'deductions_minor' => $totalDeductions,
                'gross_display' => Str::money($totalGross, $symbol),
                'net_display' => Str::money($totalNet, $symbol),
                'deductions_display' => Str::money($totalDeductions, $symbol),
                'run_count' => count($runs),
            ],
            'months' => $series,
            'by_department' => $this->payrollByDepartment($runs),
            'history' => $this->history('payroll.net_minor'),
        ];
    }

    /**
     * Learning completion and compliance.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function learning(array $filters): array
    {
        ['from' => $from, 'to' => $to] = Period::range($filters, 365);

        $query = ['scope' => 'all', 'from' => $from, 'to' => $to];
        if (isset($filters['department_id'])) {
            $query['department_id'] = $filters['department_id'];
        }

        $enrolments = $this->downstream->collect('learning', '/enrolments', $query, 100, 20);

        if ($enrolments === null) {
            return [
                'available' => false,
                'period' => ['from' => $from, 'to' => $to],
                'unavailable_services' => $this->downstream->unavailableServices(),
            ];
        }

        $today = Clock::today();
        $byStatus = ['not_started' => 0, 'in_progress' => 0, 'completed' => 0, 'expired' => 0];
        $byCourse = [];
        $mandatoryTotal = 0;
        $mandatoryCompleted = 0;
        $overdue = 0;
        $scores = [];

        foreach ($enrolments as $row) {
            $status = Payload::text($row, ['status', 'enrolment_status'], 'not_started');
            if (isset($byStatus[$status])) {
                $byStatus[$status]++;
            }

            $mandatory = Payload::bool($row, ['course_is_mandatory', 'is_mandatory', 'mandatory']);
            if ($mandatory) {
                $mandatoryTotal++;
                if ($status === 'completed') {
                    $mandatoryCompleted++;
                }
            }

            $dueOn = Payload::text($row, ['due_on', 'due_date'], '');
            if ($status !== 'completed' && $dueOn !== '' && $dueOn < $today) {
                $overdue++;
            }

            $score = Payload::value($row, ['score', 'final_score']);
            if (is_numeric($score)) {
                $scores[] = (float) $score;
            }

            $title = Payload::text($row, ['course_title', 'course_name', 'title'], 'Course');
            $byCourse[$title] ??= ['course' => $title, 'enrolled' => 0, 'completed' => 0, 'is_mandatory' => $mandatory];
            $byCourse[$title]['enrolled']++;
            if ($status === 'completed') {
                $byCourse[$title]['completed']++;
            }
        }

        $courses = array_values(array_map(
            static fn (array $row): array => $row + [
                'completion_rate' => Payload::percent((float) $row['completed'], (float) $row['enrolled']),
            ],
            $byCourse
        ));

        usort($courses, static fn (array $a, array $b): int => $b['enrolled'] <=> $a['enrolled']);

        $complianceRate = Payload::percent((float) $mandatoryCompleted, (float) $mandatoryTotal);

        $this->remember(Permissions::LEARNING_ASSIGN_ANY, [
            ['metric_key' => 'learning.compliance_rate', 'period' => substr($to, 0, 7), 'value' => $complianceRate],
        ]);

        return [
            'available' => true,
            'period' => ['from' => $from, 'to' => $to],
            'totals' => [
                'enrolments' => count($enrolments),
                'completed' => $byStatus['completed'],
                'overdue' => $overdue,
                'completion_rate' => Payload::percent((float) $byStatus['completed'], (float) count($enrolments)),
                'average_score' => $scores === [] ? null : Payload::round(array_sum($scores) / count($scores)),
            ],
            'compliance' => [
                'mandatory_enrolments' => $mandatoryTotal,
                'mandatory_completed' => $mandatoryCompleted,
                'compliance_rate' => $complianceRate,
            ],
            'by_status' => $byStatus,
            'by_course' => array_slice($courses, 0, 20),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function attendanceByDepartment(array $query, string $from, string $to): array
    {
        $rows = $this->downstream->rows('attendance', '/attendance/summary', $query + ['group_by' => 'department']);

        // Not every attendance service publishes a pre-aggregated summary. The
        // register plus the roster produces the same answer, and deriving it is
        // what keeps this grouping from being permanently empty.
        if ($rows === null || $rows === []) {
            $rows = $this->registerByDepartment($query, $from, $to);
        }

        if ($rows === null) {
            return $this->emptyAttendance($from, $to, 'department', $query);
        }

        $series = [];
        $totals = array_fill_keys(self::ATTENDANCE_STATUSES, 0);

        foreach ($rows as $row) {
            $bucket = [
                'key' => Payload::text($row, ['department_id'], ''),
                'label' => Payload::text($row, ['department_name', 'department', 'label'], 'Unassigned'),
                'from' => $from,
                'to' => $to,
            ];

            foreach (self::ATTENDANCE_STATUSES as $status) {
                $count = Payload::int($row, [$status, $status . '_days', $status . 's']);
                $bucket[$status] = $count;
                $totals[$status] += $count;
            }

            $bucket['records'] = array_sum(array_map(
                static fn (string $status): int => $bucket[$status],
                self::ATTENDANCE_STATUSES
            ));
            $bucket['worked_hours'] = Payload::round(Payload::float($row, ['worked_hours', 'total_hours']));
            $bucket['attendance_rate'] = $this->rateOf($bucket);

            $series[] = $bucket;
        }

        usort($series, static fn (array $a, array $b): int => $b['records'] <=> $a['records']);

        return [
            'available' => true,
            'scope' => $query['scope'] ?? 'all',
            'group_by' => 'department',
            'period' => ['from' => $from, 'to' => $to],
            'totals' => $totals + [
                'records' => array_sum($totals),
                'late_arrivals' => 0,
                'worked_hours' => Payload::round(Payload::sum($series, ['worked_hours'])),
                'attendance_rate' => $this->rateOf($totals),
            ],
            'series' => $series,
        ];
    }

    /**
     * Department split for the most recent completed run.
     *
     * @param list<array<string, mixed>> $runs
     * @return list<array<string, mixed>>
     */
    private function payrollByDepartment(array $runs): array
    {
        $latest = null;

        foreach ($runs as $run) {
            if (!in_array(Payload::text($run, ['status'], ''), ['approved', 'paid'], true)) {
                continue;
            }

            if ($latest === null || Payload::text($run, ['period'], '') > Payload::text($latest, ['period'], '')) {
                $latest = $run;
            }
        }

        if ($latest === null) {
            return [];
        }

        $runId = Payload::text($latest, ['id', 'run_id'], '');

        // The identifier arrived from another service and is about to be part
        // of a URL path, where it cannot be bound as a parameter. Its shape is
        // therefore checked rather than assumed.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $runId) !== 1) {
            return [];
        }

        $rows = $this->downstream->rows('payroll', '/payroll/runs/' . $runId . '/cost-by-department')
            ?: PayrollCost::byDepartment(
                $this->downstream->record('payroll', '/payroll/runs/' . $runId),
                $this->directory
            )
            ?? [];

        $symbol = Env::get('CURRENCY_SYMBOL', '');

        return array_map(
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
        );
    }

    /**
     * The register for a window, folded into one row per department.
     *
     * Attendance carries an employee id and nothing else about a person, so the
     * department has to come from the roster.
     *
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>|null
     */
    private function registerByDepartment(array $query, string $from, string $to): ?array
    {
        if (!$this->directory->available()) {
            return null;
        }

        $rows = $this->downstream->collect('attendance', '/attendance', $query, 100, self::PAGE_CAP);

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
                'worked_hours' => 0.0,
            ] + array_fill_keys(self::ATTENDANCE_STATUSES, 0);

            $status = Payload::text($row, ['status'], '');
            if (isset($departments[$key][$status])) {
                $departments[$key][$status]++;
            }

            $departments[$key]['worked_hours'] += $this->workedSeconds($row) / 3600;
        }

        return array_values($departments);
    }

    /**
     * A stored metric's history, oldest first.
     *
     * The figures on this page are computed live, so the stored series is only
     * ever an extra: it is what lets a chart keep a month that the owning
     * service has since archived.
     *
     * @return list<array<string, mixed>>
     */
    private function history(string $metricKey, int $months = 24): array
    {
        try {
            return $this->snapshots->series($metricKey, 'overall', $months);
        } catch (\Throwable $exception) {
            Logger::warning('Metric history unavailable', ['metric' => $metricKey, 'error' => $exception->getMessage()]);

            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $people
     * @param array<string, string>      $names
     * @return list<array<string, mixed>>
     */
    private function movementRows(array $people, array $names, string $dateField): array
    {
        $rows = array_map(
            static function (array $person) use ($names, $dateField): array {
                $id = Payload::text($person, ['id', 'employee_id'], '');

                return [
                    'employee_id' => $id,
                    'employee_code' => Payload::text($person, ['employee_code'], ''),
                    'name' => Payload::text($person, ['full_name'], $names[$id] ?? ''),
                    'department' => Payload::text($person, ['department_name', 'department'], ''),
                    'designation' => Payload::text($person, ['designation_name', 'designation'], ''),
                    'date' => Payload::text($person, [$dateField], ''),
                ];
            },
            $people
        );

        usort($rows, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return $rows;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function emptyAttendance(string $from, string $to, string $groupBy, array $query): array
    {
        return [
            'available' => false,
            'scope' => $query['scope'] ?? 'all',
            'group_by' => $groupBy,
            'period' => ['from' => $from, 'to' => $to],
            'totals' => [],
            'series' => [],
            'unavailable_services' => $this->downstream->unavailableServices(),
        ];
    }

    /**
     * Attendance rate for a set of counters.
     *
     * Half days count as half a day present, and holidays and weekly offs are
     * excluded from the denominator: a company is not less punctual because a
     * month contained a public holiday.
     *
     * @param array<string, mixed> $counts
     */
    private function rateOf(array $counts): float
    {
        $present = (float) ($counts['present'] ?? 0);
        $wfh = (float) ($counts['wfh'] ?? 0);
        $half = (float) ($counts['half_day'] ?? 0);
        $absent = (float) ($counts['absent'] ?? 0);
        $leave = (float) ($counts['on_leave'] ?? 0);

        return Payload::percent($present + $wfh + ($half / 2), $present + $wfh + $half + $absent + $leave);
    }

    /** @param array<string, mixed> $row */
    private function workedSeconds(array $row): int
    {
        $stated = Payload::int($row, ['worked_seconds', 'work_seconds', 'duration_seconds']);
        if ($stated > 0) {
            return $stated;
        }

        $hours = Payload::float($row, ['worked_hours', 'work_hours']);

        return $hours > 0 ? (int) round($hours * 3600) : 0;
    }

    /**
     * Keeps a computed figure so a trend outlives its source records.
     *
     * A snapshot row is shared by everybody, but the figure that produced it
     * was computed from whatever the owning service was willing to show THIS
     * caller. A figure is therefore only kept when the caller holds the
     * organisation-wide permission for the domain it came from - otherwise one
     * person's partial view would be published as the company's number.
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
