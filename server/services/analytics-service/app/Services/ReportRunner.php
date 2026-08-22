<?php

declare(strict_types=1);

namespace App\Services;

use App\Policies\AnalyticsScope;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;

/**
 * Produces the rows behind each saved report.
 *
 * Unlike a dashboard card, a report has no useful degraded form: an export that
 * quietly omits half the organisation because one service was slow is worse
 * than no export at all, because nothing on the page says so. Every runner here
 * therefore treats its source as essential and surfaces a failure as 503.
 */
final class ReportRunner
{
    /** Ceiling on how many rows one report may return, whatever the filters say. */
    private const MAX_ROWS = 5000;

    /** Payroll runs one register may open, so a wide window stays bounded. */
    private const MAX_RUNS_READ = 24;

    /** People one statement may ask a per-person endpoint about. */
    private const MAX_PEOPLE_READ = 200;

    public function __construct(
        private readonly Downstream $downstream,
        private readonly EmployeeDirectory $directory,
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $filters Already validated and merged with the definition defaults.
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function run(array $definition, array $filters, Principal $principal): array
    {
        $slug = (string) ($definition['slug'] ?? '');

        $result = match ($slug) {
            'monthly-attendance-register' => $this->attendanceRegister($filters, $principal),
            'leave-balance-statement' => $this->leaveBalanceStatement($filters, $principal),
            'leave-utilisation-summary' => $this->leaveUtilisation($filters),
            'headcount-by-department' => $this->headcountByDepartment(),
            'new-joiners-and-exits' => $this->joinersAndExits($filters),
            'payroll-register' => $this->payrollRegister($filters),
            'salary-disbursement-summary' => $this->salaryDisbursement($filters),
            'expense-claim-summary' => $this->expenseClaims($filters),
            'training-compliance' => $this->trainingCompliance($filters, $principal),
            'document-expiry' => $this->documentExpiry($filters),
            'overtime-summary' => $this->overtimeSummary($filters, $principal),
            'performance-rating-distribution' => $this->ratingDistribution($filters),
            default => throw HttpException::notFound('This report has no runner and cannot be produced.'),
        };

        $result['rows'] = array_slice($result['rows'], 0, self::MAX_ROWS);
        $result['summary'] = ($result['summary'] ?? []) + ['row_count' => count($result['rows'])];

        return $result;
    }

    /**
     * Merges the caller's filters over the definition's defaults.
     *
     * A "period" of 2026-08 is expanded into a from/to pair here so that every
     * runner deals with one representation of a window rather than two.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $requested
     * @return array<string, mixed>
     */
    public function resolveFilters(array $definition, array $requested): array
    {
        $defaults = is_array($definition['default_filters'] ?? null) ? $definition['default_filters'] : [];
        $filters = array_merge($defaults, $requested);

        // The caller's period is validated before it reaches here, but a stored
        // definition's defaults are not - they are edited straight into the
        // database - so the shape is checked rather than handed to a date
        // parser that would fault on anything else.
        if (isset($filters['period']) && is_string($filters['period'])) {
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $filters['period']) !== 1) {
                throw HttpException::unprocessable('A period must be written as YYYY-MM.', [
                    'period' => ['A period must be written as YYYY-MM.'],
                ]);
            }

            $month = Period::month($filters['period'] . '-01');
            $filters['from'] ??= $month['from'];
            $filters['to'] ??= $month['to'];
        }

        if (($filters['range'] ?? null) === 'current_month') {
            [$from, $to] = Clock::monthBounds(Clock::today());
            $filters['from'] ??= $from;
            $filters['to'] ??= $to;
        }

        if (($filters['range'] ?? null) === 'last_12_months') {
            $months = Period::lastMonths(12);
            $filters['from'] ??= $months[0]['from'];
            $filters['to'] ??= $months[count($months) - 1]['to'];
        }

        unset($filters['range']);

        return $filters;
    }

    // -----------------------------------------------------------------------
    // Attendance
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function attendanceRegister(array $filters, Principal $principal): array
    {
        ['from' => $from, 'to' => $to] = Period::range($filters, 31);
        $scope = $this->scopeFor($principal, Permissions::REPORT_VIEW_ALL, Permissions::REPORT_VIEW_TEAM);

        $records = $this->mustHave(
            $this->downstream->collect('attendance', '/attendance', $this->window($principal, $scope, $from, $to, $filters), 100, 20),
            'attendance'
        );

        $people = $this->directory->index();
        $byEmployee = [];

        foreach ($records as $row) {
            $employeeId = Payload::text($row, ['employee_id'], '');
            if ($employeeId === '') {
                continue;
            }

            $byEmployee[$employeeId] ??= [
                'present' => 0, 'absent' => 0, 'half_day' => 0, 'on_leave' => 0,
                'wfh' => 0, 'holiday' => 0, 'weekly_off' => 0, 'seconds' => 0,
            ];

            $status = Payload::text($row, ['status'], '');
            if (isset($byEmployee[$employeeId][$status])) {
                $byEmployee[$employeeId][$status]++;
            }

            $byEmployee[$employeeId]['seconds'] += Payload::int($row, ['worked_seconds', 'work_seconds'])
                ?: (int) round(Payload::float($row, ['worked_hours', 'work_hours']) * 3600);
        }

        $rows = [];
        foreach ($byEmployee as $employeeId => $counts) {
            $person = $people[$employeeId] ?? [];
            $working = $counts['present'] + $counts['wfh'] + $counts['half_day'] + $counts['absent'] + $counts['on_leave'];

            $rows[] = [
                'employee_code' => Payload::text($person, ['employee_code'], ''),
                'employee' => Payload::text($person, ['full_name'], $employeeId),
                'department' => Payload::text($person, ['department_name', 'department'], ''),
                'present' => $counts['present'],
                'work_from_home' => $counts['wfh'],
                'half_days' => $counts['half_day'],
                'absent' => $counts['absent'],
                'on_leave' => $counts['on_leave'],
                'holidays' => $counts['holiday'],
                'worked_hours' => Payload::round($counts['seconds'] / 3600),
                'attendance_rate' => Payload::percent(
                    (float) ($counts['present'] + $counts['wfh'] + ($counts['half_day'] / 2)),
                    (float) $working
                ),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['employee'] <=> $b['employee']);

        return [
            'columns' => [
                ['key' => 'employee_code', 'label' => 'Code', 'type' => 'text'],
                ['key' => 'employee', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'present', 'label' => 'Present', 'type' => 'number'],
                ['key' => 'work_from_home', 'label' => 'WFH', 'type' => 'number'],
                ['key' => 'half_days', 'label' => 'Half days', 'type' => 'number'],
                ['key' => 'absent', 'label' => 'Absent', 'type' => 'number'],
                ['key' => 'on_leave', 'label' => 'On leave', 'type' => 'number'],
                ['key' => 'holidays', 'label' => 'Holidays', 'type' => 'number'],
                ['key' => 'worked_hours', 'label' => 'Hours', 'type' => 'number'],
                ['key' => 'attendance_rate', 'label' => 'Rate %', 'type' => 'number'],
            ],
            'rows' => $rows,
            'summary' => ['from' => $from, 'to' => $to, 'scope' => $scope, 'employees' => count($rows)],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function overtimeSummary(array $filters, Principal $principal): array
    {
        ['from' => $from, 'to' => $to] = Period::range($filters, 31);
        $scope = $this->scopeFor($principal, Permissions::REPORT_VIEW_ALL, Permissions::REPORT_VIEW_TEAM);

        // The overtime endpoint answers with one object holding a "months"
        // collection, not with a bare list, so it is read as a record and the
        // collection taken out of it. Treating the object itself as a row is
        // what silently produced an empty register.
        $summary = $this->downstream->record('attendance', '/overtime', $this->window($principal, $scope, $from, $to, $filters));

        if ($summary === null) {
            throw HttpException::serviceUnavailable(
                'The attendance service did not answer, so this report cannot be produced right now.'
            );
        }

        $records = Payload::rows($summary['months'] ?? $summary);

        $people = $this->directory->index();
        $byEmployee = [];

        foreach ($records as $row) {
            $employeeId = Payload::text($row, ['employee_id'], '');
            if ($employeeId === '') {
                continue;
            }

            $hours = Payload::float($row, ['overtime_hours', 'hours']);
            if ($hours === 0.0) {
                $hours = Payload::float($row, ['overtime_seconds', 'seconds']) / 3600;
            }

            $byEmployee[$employeeId] ??= ['hours' => 0.0, 'worked' => 0.0, 'days' => 0];
            $byEmployee[$employeeId]['hours'] += $hours;
            $byEmployee[$employeeId]['worked'] += Payload::float($row, ['worked_seconds']) / 3600;
            $byEmployee[$employeeId]['days'] += Payload::int($row, ['overtime_days'], 1);
        }

        $rows = [];
        foreach ($byEmployee as $employeeId => $totals) {
            $person = $people[$employeeId] ?? [];

            $rows[] = [
                'employee_code' => Payload::text($person, ['employee_code'], ''),
                'employee' => Payload::text($person, ['full_name'], $employeeId),
                'department' => Payload::text($person, ['department_name', 'department'], ''),
                'overtime_days' => $totals['days'],
                'overtime_hours' => Payload::round($totals['hours']),
                'worked_hours' => Payload::round($totals['worked']),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['overtime_hours'] <=> $a['overtime_hours']);

        return [
            'columns' => [
                ['key' => 'employee_code', 'label' => 'Code', 'type' => 'text'],
                ['key' => 'employee', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'overtime_days', 'label' => 'Days with overtime', 'type' => 'number'],
                ['key' => 'overtime_hours', 'label' => 'Overtime hours', 'type' => 'number'],
                ['key' => 'worked_hours', 'label' => 'Hours worked', 'type' => 'number'],
            ],
            'rows' => $rows,
            'summary' => ['from' => $from, 'to' => $to, 'scope' => $scope],
        ];
    }

    // -----------------------------------------------------------------------
    // Leave
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function leaveBalanceStatement(array $filters, Principal $principal): array
    {
        $scope = $this->scopeFor($principal, Permissions::REPORT_VIEW_ALL, Permissions::REPORT_VIEW_TEAM);

        $query = ['scope' => $scope] + $this->department($principal, $scope, $filters);

        $people = $this->directory->index();
        $balances = $this->balancesFor($people, $query);
        $rows = [];

        foreach ($balances as $balance) {
            $employeeId = Payload::text($balance, ['employee_id'], '');
            $person = $people[$employeeId] ?? [];

            $entitled = Payload::float($balance, ['entitled_days', 'allocated_days', 'opening_balance']);
            $used = Payload::float($balance, ['used_days', 'taken_days']);

            $rows[] = [
                'employee_code' => Payload::text($person, ['employee_code'], ''),
                'employee' => Payload::text($balance, ['employee_name'], Payload::text($person, ['full_name'], $employeeId)),
                'department' => Payload::text($person, ['department_name', 'department'], ''),
                'leave_type' => Payload::text($balance, ['leave_type_name', 'leave_type', 'name'], 'Leave'),
                'entitled_days' => Payload::round($entitled),
                'used_days' => Payload::round($used),
                'pending_days' => Payload::round(Payload::float($balance, ['pending_days', 'in_approval_days'])),
                'available_days' => Payload::round(Payload::float(
                    $balance,
                    ['available_days', 'balance_days', 'remaining_days'],
                    max(0.0, $entitled - $used)
                )),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['employee'], $a['leave_type']] <=> [$b['employee'], $b['leave_type']]);

        return [
            'columns' => [
                ['key' => 'employee_code', 'label' => 'Code', 'type' => 'text'],
                ['key' => 'employee', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'leave_type', 'label' => 'Leave type', 'type' => 'text'],
                ['key' => 'entitled_days', 'label' => 'Entitled', 'type' => 'number'],
                ['key' => 'used_days', 'label' => 'Used', 'type' => 'number'],
                ['key' => 'pending_days', 'label' => 'Pending', 'type' => 'number'],
                ['key' => 'available_days', 'label' => 'Available', 'type' => 'number'],
            ],
            'rows' => $rows,
            'summary' => ['scope' => $scope, 'as_of' => Clock::today(), 'employees' => count($people)],
        ];
    }

    /**
     * Leave balances for everyone in scope.
     *
     * The leave service answers for one person per call - a balance is a
     * personal figure, and its endpoint is built that way - so a statement
     * covering a team has to ask once per person. The roster is the set of
     * people the caller may already see, so this never reaches further than
     * they could reach themselves, and the number of calls is capped so a
     * report can never turn into an unbounded fan-out.
     *
     * @param array<string, array<string, mixed>> $people
     * @param array<string, mixed>                $query
     * @return list<array<string, mixed>>
     */
    private function balancesFor(array $people, array $query): array
    {
        if ($people === []) {
            // No roster: the only balances that can be asked for are the
            // caller's own, which is still an honest answer.
            return $this->mustHave($this->downstream->rows('leave', '/leave-balances', $query), 'leave');
        }

        $balances = [];
        $asked = 0;
        $answered = false;

        foreach (array_keys($people) as $employeeId) {
            if (++$asked > self::MAX_PEOPLE_READ) {
                break;
            }

            $rows = $this->downstream->rows('leave', '/leave-balances', ['employee_id' => $employeeId]);

            if ($rows === null) {
                continue;
            }

            $answered = true;

            foreach ($rows as $row) {
                $balances[] = $row + ['employee_id' => $employeeId];
            }
        }

        if (!$answered) {
            throw HttpException::serviceUnavailable(
                'The leave service did not answer, so this report cannot be produced right now.'
            );
        }

        return $balances;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function leaveUtilisation(array $filters): array
    {
        ['from' => $from, 'to' => $to] = Period::range($filters, 365);

        $requests = $this->mustHave(
            $this->downstream->collect('leave', '/leave/requests', [
                'scope' => AnalyticsScope::ORGANISATION,
                'from' => $from,
                'to' => $to,
            ], 100, 20),
            'leave'
        );

        $byType = [];
        foreach ($requests as $request) {
            $type = Payload::text($request, ['leave_type_name', 'leave_type'], 'Leave');
            $status = Payload::text($request, ['status'], '');
            $days = Payload::float($request, ['day_count', 'days']);

            $byType[$type] ??= [
                'leave_type' => $type,
                'category' => Payload::text($request, ['category', 'leave_category'], '')
                    ?: Payload::text($request['leave_type'] ?? null, ['category'], ''),
                'requests' => 0,
                'approved_requests' => 0,
                'days_taken' => 0.0,
                'employees' => [],
            ];

            $byType[$type]['requests']++;
            $byType[$type]['employees'][Payload::text($request, ['employee_id'], '')] = true;

            if ($status === 'approved') {
                $byType[$type]['approved_requests']++;
                $byType[$type]['days_taken'] += $days;
            }
        }

        $rows = array_values(array_map(
            static fn (array $row): array => [
                'leave_type' => $row['leave_type'],
                'category' => $row['category'],
                'requests' => $row['requests'],
                'approved_requests' => $row['approved_requests'],
                'employees' => count(array_filter(array_keys($row['employees']), static fn (string $id): bool => $id !== '')),
                'days_taken' => Payload::round((float) $row['days_taken']),
            ],
            $byType
        ));

        usort($rows, static fn (array $a, array $b): int => $b['days_taken'] <=> $a['days_taken']);

        return [
            'columns' => [
                ['key' => 'leave_type', 'label' => 'Leave type', 'type' => 'text'],
                ['key' => 'category', 'label' => 'Category', 'type' => 'text'],
                ['key' => 'requests', 'label' => 'Requests', 'type' => 'number'],
                ['key' => 'approved_requests', 'label' => 'Approved', 'type' => 'number'],
                ['key' => 'employees', 'label' => 'Employees', 'type' => 'number'],
                ['key' => 'days_taken', 'label' => 'Days taken', 'type' => 'number'],
            ],
            'rows' => $rows,
            'summary' => ['from' => $from, 'to' => $to],
        ];
    }

    // -----------------------------------------------------------------------
    // People
    // -----------------------------------------------------------------------

    /**
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function headcountByDepartment(): array
    {
        if (!$this->directory->available()) {
            throw HttpException::serviceUnavailable('The employee service did not answer, so this report cannot be produced.');
        }

        $headcount = $this->directory->headcount();
        $today = Clock::today();
        $tenureByDepartment = [];

        foreach ($this->directory->active() as $person) {
            $department = Payload::text($person, ['department_id'], 'Unassigned');
            $joined = Payload::text($person, ['joined_on', 'joining_date'], '');

            if ($joined !== '' && $joined <= $today) {
                $tenureByDepartment[$department][] = Clock::inclusiveDays($joined, $today) / 365.25;
            }
        }

        $rows = array_map(
            static function (array $department) use ($headcount, $tenureByDepartment): array {
                $tenures = $tenureByDepartment[$department['key']] ?? [];

                return [
                    'department' => $department['label'],
                    'headcount' => $department['value'],
                    'share_percent' => Payload::percent((float) $department['value'], (float) $headcount),
                    'average_tenure_years' => $tenures === [] ? 0.0 : Payload::round(array_sum($tenures) / count($tenures)),
                ];
            },
            $this->directory->byDepartment()
        );

        return [
            'columns' => [
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'headcount', 'label' => 'Headcount', 'type' => 'number'],
                ['key' => 'share_percent', 'label' => 'Share %', 'type' => 'number'],
                ['key' => 'average_tenure_years', 'label' => 'Avg tenure (yrs)', 'type' => 'number'],
            ],
            'rows' => $rows,
            'summary' => ['as_of' => $today, 'headcount' => $headcount],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function joinersAndExits(array $filters): array
    {
        ['from' => $from, 'to' => $to] = Period::range($filters, 365);

        if (!$this->directory->available()) {
            throw HttpException::serviceUnavailable('The employee service did not answer, so this report cannot be produced.');
        }

        $rows = [];

        foreach ($this->directory->joinersBetween($from, $to) as $person) {
            $rows[] = $this->movementRow($person, 'Joiner', Payload::text($person, ['joined_on', 'joining_date'], ''));
        }

        foreach ($this->directory->leaversBetween($from, $to) as $person) {
            $rows[] = $this->movementRow($person, 'Exit', Payload::text($person, ['exit_date', 'last_working_day'], ''));
        }

        usort($rows, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return [
            'columns' => [
                ['key' => 'movement', 'label' => 'Movement', 'type' => 'text'],
                ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'employee_code', 'label' => 'Code', 'type' => 'text'],
                ['key' => 'employee', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'designation', 'label' => 'Designation', 'type' => 'text'],
                ['key' => 'employment_type', 'label' => 'Type', 'type' => 'text'],
            ],
            'rows' => $rows,
            'summary' => [
                'from' => $from,
                'to' => $to,
                'joiners' => count($this->directory->joinersBetween($from, $to)),
                'leavers' => count($this->directory->leaversBetween($from, $to)),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function documentExpiry(array $filters): array
    {
        $days = isset($filters['days']) && is_numeric($filters['days']) ? (int) $filters['days'] : 60;
        $days = max(1, min($days, 365));

        $documents = $this->mustHave(
            $this->downstream->collect('employee', '/documents/expiring', ['days' => $days], 100, 10),
            'employee'
        );

        $people = $this->directory->available() ? $this->directory->index() : [];
        $today = Clock::today();
        $rows = [];

        foreach ($documents as $document) {
            $expiresOn = Payload::text($document, ['expires_on', 'expiry_date', 'valid_until'], '');
            if ($expiresOn === '') {
                continue;
            }

            $employeeId = Payload::text($document, ['employee_id'], '');
            $person = $people[$employeeId] ?? [];

            $rows[] = [
                'employee_code' => Payload::text($person, ['employee_code'], ''),
                'employee' => Payload::text($document, ['employee_name'], Payload::text($person, ['full_name'], $employeeId)),
                'department' => Payload::text($person, ['department_name', 'department'], ''),
                // employee_documents carries a title and a category; there is
                // no document_type column, so this used to print the literal
                // word "Document" for every row.
                'document_type' => Payload::text($document, ['title', 'document_type', 'type', 'name'], '')
                    ?: ucfirst(str_replace('_', ' ', Payload::text($document, ['category'], 'document'))),
                'status' => Payload::text($document, ['status'], ''),
                'expires_on' => $expiresOn,
                'days_remaining' => $expiresOn < $today ? 0 : Clock::inclusiveDays($today, $expiresOn) - 1,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['expires_on'] <=> $b['expires_on']);

        return [
            'columns' => [
                ['key' => 'employee_code', 'label' => 'Code', 'type' => 'text'],
                ['key' => 'employee', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'document_type', 'label' => 'Document', 'type' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
                ['key' => 'expires_on', 'label' => 'Expires on', 'type' => 'date'],
                ['key' => 'days_remaining', 'label' => 'Days left', 'type' => 'number'],
            ],
            'rows' => $rows,
            'summary' => ['window_days' => $days, 'as_of' => $today],
        ];
    }

    // -----------------------------------------------------------------------
    // Payroll and expenses
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function payrollRegister(array $filters): array
    {
        ['from' => $from, 'to' => $to] = Period::range($filters, 31);

        $payslips = $this->payslipsBetween($from, $to);
        $people = $this->directory->available() ? $this->directory->index() : [];
        $rows = [];

        foreach ($payslips as $payslip) {
            $employeeId = Payload::text($payslip, ['employee_id'], '');
            $person = $people[$employeeId] ?? [];

            $rows[] = [
                'employee_code' => Payload::text($person, ['employee_code'], ''),
                'employee' => Payload::text($payslip, ['employee_name'], Payload::text($person, ['full_name'], $employeeId)),
                'department' => Payload::text($person, ['department_name', 'department'], ''),
                'period' => Payload::text($payslip, ['period', 'pay_period'], ''),
                'gross' => $this->amount(Payload::int($payslip, ['gross_minor', 'total_earnings_minor'])),
                'deductions' => $this->amount(Payload::int($payslip, ['deductions_minor', 'total_deductions_minor'])),
                'net' => $this->amount(Payload::int($payslip, ['net_minor', 'net_pay_minor'])),
                'status' => Payload::text($payslip, ['status'], ''),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['period'], $a['employee']] <=> [$a['period'], $b['employee']]);

        return [
            'columns' => [
                ['key' => 'period', 'label' => 'Period', 'type' => 'text'],
                ['key' => 'employee_code', 'label' => 'Code', 'type' => 'text'],
                ['key' => 'employee', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'gross', 'label' => 'Gross', 'type' => 'money'],
                ['key' => 'deductions', 'label' => 'Deductions', 'type' => 'money'],
                ['key' => 'net', 'label' => 'Net', 'type' => 'money'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ],
            'rows' => $rows,
            'summary' => ['from' => $from, 'to' => $to, 'currency' => Env::get('CURRENCY_CODE', 'INR')],
        ];
    }

    /**
     * Every payslip issued in a window.
     *
     * The payslip collection answers for one person - "may read payslips" is
     * never a whole answer, so that endpoint scopes to the caller - which makes
     * it the wrong source for a register. A run does hold every payslip it
     * produced, and reading runs is already governed by payroll.view.all, which
     * this report requires. Reading the runs is therefore both the correct
     * source and one this caller was already entitled to.
     *
     * @return list<array<string, mixed>>
     */
    private function payslipsBetween(string $from, string $to): array
    {
        $runs = $this->mustHave(
            $this->downstream->collect('payroll', '/payroll/runs', [], 100, 5),
            'payroll'
        );

        $fromPeriod = substr($from, 0, 7);
        $toPeriod = substr($to, 0, 7);

        $payslips = [];
        $opened = 0;

        foreach ($runs as $run) {
            $period = substr(Payload::text($run, ['period', 'pay_period'], ''), 0, 7);

            if ($period === '' || $period < $fromPeriod || $period > $toPeriod) {
                continue;
            }

            if (Payload::text($run, ['status'], '') === 'cancelled') {
                continue;
            }

            $runId = Payload::text($run, ['id', 'run_id'], '');

            // The id came from another service and is about to become part of a
            // URL path, where it cannot be bound as a parameter, so its shape is
            // checked before it is used.
            if (!self::isUuid($runId)) {
                continue;
            }

            if (++$opened > self::MAX_RUNS_READ) {
                break;
            }

            $detail = $this->downstream->record('payroll', '/payroll/runs/' . $runId);

            foreach (Payload::rows($detail['payslips'] ?? []) as $payslip) {
                $payslips[] = $payslip + ['period' => $period, 'status' => Payload::text($run, ['status'], '')];
            }
        }

        return $payslips;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function salaryDisbursement(array $filters): array
    {
        ['from' => $from, 'to' => $to] = Period::range($filters, 365);

        $runs = $this->mustHave(
            $this->downstream->collect('payroll', '/payroll/runs', ['from' => $from, 'to' => $to], 100, 5),
            'payroll'
        );

        $rows = array_map(
            fn (array $run): array => [
                'period' => Payload::text($run, ['period', 'pay_period'], ''),
                'status' => Payload::text($run, ['status'], ''),
                'employee_count' => Payload::int($run, ['employee_count', 'employees']),
                'gross' => $this->amount(Payload::int($run, ['total_gross_minor', 'gross_minor'])),
                'deductions' => $this->amount(Payload::int($run, ['total_deductions_minor', 'deductions_minor'])),
                'net' => $this->amount(Payload::int($run, ['total_net_minor', 'net_minor'])),
                'paid_on' => Payload::text($run, ['paid_on', 'paid_at', 'processed_on'], ''),
            ],
            $runs
        );

        usort($rows, static fn (array $a, array $b): int => $b['period'] <=> $a['period']);

        return [
            'columns' => [
                ['key' => 'period', 'label' => 'Period', 'type' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
                ['key' => 'employee_count', 'label' => 'Employees', 'type' => 'number'],
                ['key' => 'gross', 'label' => 'Gross', 'type' => 'money'],
                ['key' => 'deductions', 'label' => 'Deductions', 'type' => 'money'],
                ['key' => 'net', 'label' => 'Net disbursed', 'type' => 'money'],
                ['key' => 'paid_on', 'label' => 'Paid on', 'type' => 'date'],
            ],
            'rows' => $rows,
            'summary' => ['from' => $from, 'to' => $to, 'currency' => Env::get('CURRENCY_CODE', 'INR')],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function expenseClaims(array $filters): array
    {
        ['from' => $from, 'to' => $to] = Period::range($filters, 90);

        $query = ['scope' => AnalyticsScope::ORGANISATION, 'from' => $from, 'to' => $to];
        if (isset($filters['status'])) {
            $query['status'] = $filters['status'];
        }

        $claims = $this->mustHave(
            $this->downstream->collect('payroll', '/expenses', $query, 100, 20),
            'payroll'
        );

        $people = $this->directory->available() ? $this->directory->index() : [];
        $rows = [];

        foreach ($claims as $claim) {
            $employeeId = Payload::text($claim, ['employee_id'], '');
            $person = $people[$employeeId] ?? [];

            $rows[] = [
                'employee_code' => Payload::text($person, ['employee_code'], ''),
                'employee' => Payload::text($claim, ['employee_name'], Payload::text($person, ['full_name'], $employeeId)),
                'department' => Payload::text($person, ['department_name', 'department'], ''),
                'category' => Payload::text($claim, ['category', 'expense_category', 'type'], ''),
                'title' => Payload::text($claim, ['title', 'description'], ''),
                'amount' => $this->amount(Payload::int($claim, ['amount_minor', 'claim_amount_minor', 'total_minor'])),
                'status' => Payload::text($claim, ['status'], ''),
                'submitted_on' => Payload::text($claim, ['submitted_on', 'submitted_at', 'created_at'], ''),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['submitted_on'] <=> $a['submitted_on']);

        return [
            'columns' => [
                ['key' => 'submitted_on', 'label' => 'Submitted', 'type' => 'date'],
                ['key' => 'employee_code', 'label' => 'Code', 'type' => 'text'],
                ['key' => 'employee', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'category', 'label' => 'Category', 'type' => 'text'],
                ['key' => 'title', 'label' => 'Description', 'type' => 'text'],
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ],
            'rows' => $rows,
            'summary' => ['from' => $from, 'to' => $to, 'currency' => Env::get('CURRENCY_CODE', 'INR')],
        ];
    }

    // -----------------------------------------------------------------------
    // Learning and performance
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function trainingCompliance(array $filters, Principal $principal): array
    {
        // Built from /learning/compliance rather than /enrolments. The
        // enrolment list is hard-scoped to the caller and discards "scope"
        // without saying so, which made this company-wide report return one
        // row: whoever ran it. The compliance endpoint already walks every
        // mandatory course against its real audience.
        $compliance = $this->mustHave(
            $this->downstream->record('learning', '/learning/compliance'),
            'learning'
        );

        $people = $this->directory->available() ? $this->directory->index() : [];
        $today = Payload::text($compliance, ['as_of'], Clock::today());
        $byEmployee = [];

        $courses = is_array($compliance['courses'] ?? null) ? $compliance['courses'] : [];

        foreach ($courses as $course) {
            foreach ((is_array($course['employees'] ?? null) ? $course['employees'] : []) as $row) {
                $employeeId = Payload::text($row, ['employee_id'], '');

                if ($employeeId === '') {
                    continue;
                }

                $byEmployee[$employeeId] ??= ['assigned' => 0, 'mandatory' => 0, 'completed' => 0, 'overdue' => 0];

                $status = Payload::text($row, ['status'], '');

                // Every course in this response is a mandatory one, so
                // everybody in its audience carries one obligation for it.
                $byEmployee[$employeeId]['mandatory']++;

                if ($status !== 'not_enrolled') {
                    $byEmployee[$employeeId]['assigned']++;
                }

                if ($status === 'completed') {
                    $byEmployee[$employeeId]['completed']++;
                }

                if (Payload::bool($row, ['is_overdue', 'overdue'])) {
                    $byEmployee[$employeeId]['overdue']++;
                }
            }
        }

        $rows = [];
        foreach ($byEmployee as $employeeId => $counts) {
            $person = $people[$employeeId] ?? [];

            $rows[] = [
                'employee_code' => Payload::text($person, ['employee_code'], ''),
                'employee' => Payload::text($person, ['full_name'], $employeeId),
                'department' => Payload::text($person, ['department_name', 'department'], ''),
                'assigned' => $counts['assigned'],
                'mandatory' => $counts['mandatory'],
                'mandatory_completed' => $counts['completed'],
                'overdue' => $counts['overdue'],
                'compliance_rate' => Payload::percent((float) $counts['completed'], (float) $counts['mandatory']),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['compliance_rate'], $a['employee']] <=> [$b['compliance_rate'], $b['employee']]);

        return [
            'columns' => [
                ['key' => 'employee_code', 'label' => 'Code', 'type' => 'text'],
                ['key' => 'employee', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'assigned', 'label' => 'Assigned', 'type' => 'number'],
                ['key' => 'mandatory', 'label' => 'Mandatory', 'type' => 'number'],
                ['key' => 'mandatory_completed', 'label' => 'Completed', 'type' => 'number'],
                ['key' => 'overdue', 'label' => 'Overdue', 'type' => 'number'],
                ['key' => 'compliance_rate', 'label' => 'Compliance %', 'type' => 'number'],
            ],
            'rows' => $rows,
            'summary' => ['as_of' => $today, 'employees' => count($rows)],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function ratingDistribution(array $filters): array
    {
        // The talent service validates both of these against a fixed list, and
        // neither "organisation" as a bare AnalyticsScope constant nor a status
        // of "closed" was on it - so this report answered 422, which the
        // wrapper below turned into a permanent 503 on the page.
        $query = ['scope' => 'organisation'];

        if (isset($filters['cycle_id'])) {
            $query['review_cycle_id'] = $filters['cycle_id'];
        }

        $reviews = $this->mustHave(
            $this->downstream->collect('talent', '/reviews', $query, 100, 20),
            'talent'
        );

        $buckets = [];
        $rated = 0;

        foreach ($reviews as $review) {
            $rating = Payload::value($review, ['rating', 'overall_rating', 'final_rating']);

            if (!is_numeric($rating)) {
                continue;
            }

            $rated++;
            $key = (string) Payload::round((float) $rating, 1);
            $buckets[$key] ??= ['rating' => $key, 'reviews' => 0];
            $buckets[$key]['reviews']++;
        }

        $rows = array_values(array_map(
            static fn (array $row): array => $row + ['share_percent' => Payload::percent((float) $row['reviews'], (float) $rated)],
            $buckets
        ));

        usort($rows, static fn (array $a, array $b): int => (float) $b['rating'] <=> (float) $a['rating']);

        return [
            'columns' => [
                ['key' => 'rating', 'label' => 'Rating', 'type' => 'text'],
                ['key' => 'reviews', 'label' => 'Reviews', 'type' => 'number'],
                ['key' => 'share_percent', 'label' => 'Share %', 'type' => 'number'],
            ],
            'rows' => $rows,
            'summary' => ['reviews_considered' => $rated, 'reviews_total' => count($reviews)],
        ];
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $person
     * @return array<string, mixed>
     */
    private function movementRow(array $person, string $movement, string $date): array
    {
        return [
            'movement' => $movement,
            'date' => $date,
            'employee_code' => Payload::text($person, ['employee_code'], ''),
            'employee' => Payload::text($person, ['full_name'], ''),
            'department' => Payload::text($person, ['department_name', 'department'], ''),
            'designation' => Payload::text($person, ['designation_name', 'designation'], ''),
            'employment_type' => ucwords(str_replace('_', ' ', Payload::text($person, ['employment_type'], ''))),
        ];
    }

    /**
     * The window a report asks a service for, narrowed to the caller's scope.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function window(Principal $principal, string $scope, string $from, string $to, array $filters): array
    {
        return ['scope' => $scope, 'from' => $from, 'to' => $to]
            + $this->department($principal, $scope, $filters);
    }

    /**
     * The department slice a caller asked for, once they have been shown to be
     * entitled to it.
     *
     * A report is exactly as capable of widening an answer as a chart is, so it
     * goes through the same check. Without this, a team-scoped caller could
     * name any department in the query string and the hint would be forwarded
     * to the owning service unexamined.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function department(Principal $principal, string $scope, array $filters): array
    {
        $departmentId = $filters['department_id'] ?? null;

        if ($departmentId === null) {
            return [];
        }

        AnalyticsScope::assertDepartment($principal, $scope, (string) $departmentId);

        return ['department_id' => $departmentId];
    }

    /** True when a value is shaped like the UUID it claims to be. */
    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function scopeFor(Principal $principal, string $allPermission, string $teamPermission): string
    {
        return AnalyticsScope::resolve($principal, $allPermission, $teamPermission);
    }

    /**
     * A report's data source is not optional.
     *
     * @param list<array<string, mixed>>|null $rows
     * @return list<array<string, mixed>>
     */
    private function mustHave(?array $rows, string $service): array
    {
        if ($rows === null) {
            throw HttpException::serviceUnavailable(sprintf(
                'The %s service did not answer, so this report cannot be produced right now.',
                $service
            ));
        }

        return $rows;
    }

    /** Formats minor units as a plain decimal so a spreadsheet can total the column. */
    private function amount(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }
}
