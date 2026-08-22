<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Api;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Support\Clock;

/**
 * Attendance: punching in and out, and every view of the resulting record.
 *
 * The attendance service owns all of the arithmetic. It decides which shift a
 * day is measured against, what counts as late, how a punch trail becomes a
 * number of worked seconds and whether a day was present, half or absent. This
 * controller fetches those answers and arranges them on a page; it never
 * recomputes one, because a second implementation of the same rule is a second
 * implementation to keep in step.
 *
 * Two habits run through the methods below. Query parameters are validated
 * before they are forwarded, so a hand-edited address produces the default view
 * rather than a 422 the guard would bounce off. And a section that is
 * decoration — the week strip, the month totals — degrades to an empty state
 * when its call fails, while the section the page exists for is guarded.
 */
final class AttendanceController extends Controller
{
    /** The statuses the attendance service accepts as a filter. */
    private const STATUSES = ['present', 'absent', 'half_day', 'on_leave', 'holiday', 'weekly_off', 'wfh'];

    /** The statuses an employee may claim on a correction request. */
    private const CLAIMABLE_STATUSES = ['present', 'half_day', 'wfh'];

    /** A month of daily rows fits on one page; 31 keeps paging out of the way. */
    private const DAYS_PER_PAGE = 31;

    /** The most timesheet rows one week can show; also the service's ceiling. */
    private const TIMESHEET_ROWS = 100;

    // -----------------------------------------------------------------------
    // My attendance
    // -----------------------------------------------------------------------

    public function index(): void
    {
        $todayResponse = Api::get('/attendance/today');
        $this->guard($todayResponse, '/');

        $today = is_array($todayResponse['data']) ? $todayResponse['data'] : [];

        $weekOf = $this->dateInput('week_of', Clock::today());
        $weekResponse = Api::get('/attendance/weekly', ['week_of' => $weekOf]);
        $week = $weekResponse['ok'] && is_array($weekResponse['data']) ? $weekResponse['data'] : [];

        $weekStart = (string) ($week['week_start'] ?? $weekOf);
        $nextWeek = Clock::parse($weekStart)->modify('+7 days')->format('Y-m-d');

        [$from, $to] = $this->range(
            Clock::parse(Clock::today())->modify('-29 days')->format('Y-m-d'),
            Clock::today()
        );

        $status = $this->statusInput();

        $query = ['from' => $from, 'to' => $to, 'page' => $this->page(), 'per_page' => self::DAYS_PER_PAGE];

        if ($status !== '') {
            $query['status'] = $status;
        }

        $recordsResponse = Api::get('/attendance', $query);
        $this->guard($recordsResponse, '/');

        // The totals belong to the month the filtered range ends in, so the
        // tiles move with the filter instead of always describing today.
        $month = Clock::parse($to)->format('Y-m');
        $monthResponse = Api::get('/attendance/monthly', ['month' => $month]);
        $summary = $monthResponse['ok'] && is_array($monthResponse['data'])
            ? (array) ($monthResponse['data']['summary'] ?? [])
            : [];

        View::render('attendance/index', [
            'pageTitle' => 'My attendance',
            'breadcrumbs' => [['Attendance', '']],
            'today' => $today,
            'week' => $week,
            'weekPrevious' => Clock::parse($weekStart)->modify('-7 days')->format('Y-m-d'),
            'weekNext' => $nextWeek,
            'weekNextReached' => $nextWeek <= Clock::today(),
            'records' => is_array($recordsResponse['data']) ? $recordsResponse['data'] : [],
            'meta' => $recordsResponse['meta'],
            'summary' => $summary,
            'month' => $month,
            'monthLabel' => Clock::parse($month . '-01')->format('F Y'),
            'shiftLabels' => $this->shiftLabels(),
            'filters' => ['from' => $from, 'to' => $to, 'status' => $status],
            'statuses' => self::STATUSES,
            'canPunch' => Session::can(Permissions::ATTENDANCE_PUNCH),
            'canRegularise' => Session::can(Permissions::ATTENDANCE_REQUEST_REGULARISATION),
            'canViewTeam' => Session::can(Permissions::ATTENDANCE_VIEW_TEAM),
            'canViewAll' => Session::can(Permissions::ATTENDANCE_VIEW_ALL),
        ]);
    }

    public function checkIn(): void
    {
        $response = Api::post('/attendance/check-in', ['source' => 'web']);

        if (!$response['ok']) {
            // The service knows exactly why it refused — already checked in,
            // no employee record behind the account — and says so far more
            // usefully than a generic "that did not work" ever could.
            Flash::error((string) ($response['error']['message'] ?? 'You could not be checked in just now.'));
            $this->back('/attendance');
        }

        $data = is_array($response['data']) ? $response['data'] : [];
        $record = is_array($data['record'] ?? null) ? $data['record'] : [];

        Flash::success('Checked in at ' . time_display($record['check_in_at'] ?? null, 'just now') . '.');

        $lateMinutes = (int) ($record['late_minutes'] ?? 0);

        if ($lateMinutes > 0) {
            Flash::warning(sprintf(
                'That is %d minute%s after your shift started. Raise a correction if the clock has it wrong.',
                $lateMinutes,
                $lateMinutes === 1 ? '' : 's'
            ));
        }

        $this->back('/attendance');
    }

    public function checkOut(): void
    {
        $response = Api::post('/attendance/check-out', ['source' => 'web']);

        if (!$response['ok']) {
            Flash::error((string) ($response['error']['message'] ?? 'You could not be checked out just now.'));
            $this->back('/attendance');
        }

        $data = is_array($response['data']) ? $response['data'] : [];
        $record = is_array($data['record'] ?? null) ? $data['record'] : [];

        Flash::success(sprintf(
            'Checked out at %s. %s on the clock today.',
            time_display($record['check_out_at'] ?? null, 'just now'),
            hours($record['worked_seconds'] ?? 0)
        ));

        if ((string) ($record['status'] ?? '') === 'absent') {
            Flash::warning('That is below the hours your shift needs, so the day is recorded as absent. A correction request can put it right.');
        }

        $this->back('/attendance');
    }

    // -----------------------------------------------------------------------
    // Month calendar
    // -----------------------------------------------------------------------

    public function monthly(): void
    {
        $month = $this->monthInput('month', Clock::now()->format('Y-m'));

        $response = Api::get('/attendance/monthly', ['month' => $month]);
        $this->guard($response, '/attendance');

        $data = is_array($response['data']) ? $response['data'] : [];
        $days = is_array($data['days'] ?? null) ? $data['days'] : [];

        $first = Clock::parse($month . '-01');
        $next = $first->modify('+1 month')->format('Y-m');

        View::render('attendance/monthly', [
            'pageTitle' => 'Attendance calendar',
            'breadcrumbs' => [['Attendance', '/attendance'], ['Calendar', '']],
            'month' => $month,
            'monthLabel' => $first->format('F Y'),
            'days' => $days,
            'summary' => is_array($data['summary'] ?? null) ? $data['summary'] : [],
            // The service reports the weekday the first of the month falls on
            // as 1-7 starting Monday, which is the order the grid is drawn in.
            'startsOnWeekday' => max(1, min(7, (int) ($data['starts_on_weekday'] ?? 1))),
            'previousMonth' => $first->modify('-1 month')->format('Y-m'),
            'nextMonth' => $next,
            'nextMonthReached' => $next <= Clock::now()->format('Y-m'),
            'hoursChart' => $this->hoursChart($days),
            'statuses' => self::STATUSES,
        ]);
    }

    // -----------------------------------------------------------------------
    // The team board
    // -----------------------------------------------------------------------

    public function team(): void
    {
        $response = Api::get('/attendance/team-today');
        $this->guard($response, '/attendance');

        $data = is_array($response['data']) ? $response['data'] : [];
        $people = is_array($data['people'] ?? null) ? $data['people'] : [];

        $groups = [
            'in' => ['label' => 'In', 'icon' => 'fa-user-check', 'tone' => 'success', 'people' => []],
            'not_in' => ['label' => 'Not in yet', 'icon' => 'fa-user-clock', 'tone' => 'warning', 'people' => []],
            'on_leave' => ['label' => 'On leave', 'icon' => 'fa-umbrella-beach', 'tone' => 'info', 'people' => []],
            'not_scheduled' => ['label' => 'Not scheduled today', 'icon' => 'fa-moon', 'tone' => 'secondary', 'people' => []],
        ];

        foreach ($people as $person) {
            if (!is_array($person)) {
                continue;
            }

            $group = match ((string) ($person['presence'] ?? '')) {
                'checked_in', 'checked_out' => 'in',
                'on_leave' => 'on_leave',
                'holiday', 'weekly_off' => 'not_scheduled',
                default => 'not_in',
            };

            $groups[$group]['people'][] = $person;
        }

        View::render('attendance/team', [
            'pageTitle' => 'Team attendance',
            'breadcrumbs' => [['Attendance', '/attendance'], ['Team', '']],
            'date' => (string) ($data['date'] ?? Clock::today()),
            'isHoliday' => (bool) ($data['is_holiday'] ?? false),
            'holiday' => is_array($data['holiday'] ?? null) ? $data['holiday'] : null,
            'scope' => (string) ($data['scope'] ?? 'team'),
            'headcount' => (int) ($data['headcount'] ?? count($people)),
            'summary' => is_array($data['summary'] ?? null) ? $data['summary'] : [],
            'groups' => $groups,
        ]);
    }

    // -----------------------------------------------------------------------
    // The company register
    // -----------------------------------------------------------------------

    public function register(): void
    {
        [$monthStart, $monthEnd] = Clock::monthBounds(Clock::today());
        [$from, $to] = $this->range($monthStart, $monthEnd);

        $status = $this->statusInput();
        $departmentId = $this->uuidInput('department_id');
        $employeeId = $this->uuidInput('employee_id');

        // Attendance stores an employee id and nothing else about a person, so
        // names and departments are read from the employee directory and joined
        // on for display. A failure there costs the column, not the page.
        $directory = Api::collection('/directory', ['per_page' => 100]);
        $peopleById = [];

        foreach ($directory as $person) {
            if (is_array($person) && isset($person['id'])) {
                $peopleById[(string) $person['id']] = $person;
            }
        }

        // The register is filtered by employee rather than by department,
        // because that is what the attendance service accepts. Choosing a
        // department narrows the list of people offered below it.
        $choices = $departmentId === ''
            ? $directory
            : Api::collection('/directory', ['department_id' => $departmentId, 'per_page' => 100]);

        // Switching department while a person from the previous one is still
        // chosen would leave the table filtered by somebody the select can no
        // longer show as selected: the page would read "Everyone" and mean one
        // person. The narrower filter is dropped rather than left invisible.
        // An empty list means the directory call failed, which is no reason to
        // discard a filter the service would have honoured.
        if ($employeeId !== '' && $choices !== [] && !$this->offers($choices, $employeeId)) {
            $employeeId = '';
        }

        $query = ['from' => $from, 'to' => $to, 'page' => $this->page(), 'per_page' => 50];

        if ($status !== '') {
            $query['status'] = $status;
        }

        if ($employeeId !== '') {
            $query['employee_id'] = $employeeId;
        }

        $response = Api::get('/attendance', $query);
        $this->guard($response, '/attendance');

        $exportQuery = ['format' => 'csv', 'from' => $from, 'to' => $to];

        if ($departmentId !== '') {
            $exportQuery['department_id'] = $departmentId;
        }

        View::render('attendance/register', [
            'pageTitle' => 'Attendance register',
            'breadcrumbs' => [['Attendance', '/attendance'], ['Register', '']],
            'records' => is_array($response['data']) ? $response['data'] : [],
            'meta' => $response['meta'],
            'peopleById' => $peopleById,
            'choices' => $choices,
            'departments' => Api::collection('/departments'),
            'filters' => [
                'from' => $from,
                'to' => $to,
                'status' => $status,
                'department_id' => $departmentId,
                'employee_id' => $employeeId,
            ],
            'statuses' => self::STATUSES,
            'canExport' => Session::can(Permissions::REPORT_EXPORT),
            'canViewTeam' => Session::can(Permissions::ATTENDANCE_VIEW_TEAM),
            'exportHref' => '/reports/monthly-attendance-register/export?' . http_build_query($exportQuery),
        ]);
    }

    // -----------------------------------------------------------------------
    // Correcting a day
    // -----------------------------------------------------------------------

    public function showRegularise(): void
    {
        $query = ['page' => $this->page(), 'per_page' => 20];
        $employeeId = Session::employeeId();

        // Naming the employee keeps the list to the caller's own requests. A
        // manager calling this endpoint without one would also get their team's.
        if ($employeeId !== null && $employeeId !== '') {
            $query['employee_id'] = $employeeId;
        }

        $response = Api::get('/regularisations', $query);
        $this->guard($response, '/attendance');

        $shift = Api::data('/shifts/mine', [], []);

        View::render('attendance/regularise', [
            'pageTitle' => 'Request a correction',
            'breadcrumbs' => [['Attendance', '/attendance'], ['Correction request', '']],
            'requests' => is_array($response['data']) ? $response['data'] : [],
            'meta' => $response['meta'],
            'shift' => is_array($shift) && is_array($shift['shift'] ?? null) ? $shift['shift'] : null,
            'defaultDate' => $this->dateInput('date', ''),
            'today' => Clock::today(),
            'claimableStatuses' => self::CLAIMABLE_STATUSES,
            'canRequest' => Session::can(Permissions::ATTENDANCE_REQUEST_REGULARISATION),
        ]);
    }

    public function regularise(): void
    {
        $workDate = $this->input('work_date');
        $checkIn = $this->input('requested_check_in');
        $checkOut = $this->input('requested_check_out');
        $claimed = $this->input('requested_status');

        $input = [
            'work_date' => $workDate,
            'requested_check_in' => $checkIn,
            'requested_check_out' => $checkOut,
            'requested_status' => $claimed,
            'reason' => $this->input('reason'),
        ];

        $payload = ['work_date' => $workDate, 'reason' => $input['reason']];

        // The form collects a date and two times of day; the service wants two
        // instants. They are only combined when the date is genuinely a date,
        // so a missing day produces one clear complaint rather than three.
        $isDate = $this->dateInput('work_date', '') !== '';

        if ($isDate && $checkIn !== '') {
            $payload['requested_check_in'] = $workDate . 'T' . $checkIn;
        }

        if ($isDate && $checkOut !== '') {
            $payload['requested_check_out'] = $workDate . 'T' . $checkOut;
        }

        if (in_array($claimed, self::CLAIMABLE_STATUSES, true)) {
            $payload['requested_status'] = $claimed;
        }

        $response = Api::post('/regularisations', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $input, '/attendance/regularise');
        }

        Flash::success(sprintf(
            'Your correction for %s has been sent to your approver.',
            date_display($workDate, 'that day')
        ));

        $this->redirect('/attendance/regularise');
    }

    // -----------------------------------------------------------------------
    // The holiday calendar
    // -----------------------------------------------------------------------

    public function holidays(): void
    {
        $currentYear = (int) Clock::now()->format('Y');
        $year = $this->inputInt('year', $currentYear);

        if ($year < 2000 || $year > 2100) {
            $year = $currentYear;
        }

        $response = Api::get('/holidays', ['year' => $year]);
        $this->guard($response, '/attendance');

        $holidays = is_array($response['data']) ? $response['data'] : [];
        $today = Clock::today();
        $byMonth = [];
        $counts = ['public' => 0, 'restricted' => 0, 'company' => 0];
        $nextFound = false;
        $nextHoliday = null;

        // The service returns the year in date order, so the first entry that
        // has not been reached yet is the next one coming up.
        foreach ($holidays as $holiday) {
            if (!is_array($holiday)) {
                continue;
            }

            $date = (string) ($holiday['holiday_date'] ?? '');

            if ($date === '') {
                continue;
            }

            $holiday['has_passed'] = $date < $today;
            $holiday['is_today'] = $date === $today;
            $holiday['is_next'] = !$nextFound && $date >= $today;

            if ($holiday['is_next']) {
                $nextFound = true;
                $nextHoliday = $holiday;
            }

            $type = (string) ($holiday['holiday_type'] ?? 'public');

            if (array_key_exists($type, $counts)) {
                $counts[$type]++;
            }

            $byMonth[Clock::parse($date)->format('Y-m')][] = $holiday;
        }

        // The picker normally offers a window around this year. A year reached
        // by a hand-edited address is added to it, so the control never claims
        // to be showing one year while the cards below it show another.
        $years = range($currentYear - 2, $currentYear + 1);

        if (!in_array($year, $years, true)) {
            $years[] = $year;
            sort($years);
        }

        View::render('attendance/holidays', [
            'pageTitle' => 'Holiday calendar',
            'breadcrumbs' => [['Attendance', '/attendance'], ['Holidays', '']],
            'year' => $year,
            'years' => $years,
            'byMonth' => $byMonth,
            'total' => count($holidays),
            'counts' => $counts,
            'nextHoliday' => $nextHoliday,
            'remaining' => $this->remainingHolidays($holidays, $today),
        ]);
    }

    // -----------------------------------------------------------------------
    // Timesheets
    // -----------------------------------------------------------------------

    public function timesheets(): void
    {
        $weekOf = $this->dateInput('week_of', Clock::today());
        [$weekStart, $weekEnd] = Clock::weekBounds($weekOf);

        // A week is grouped by day rather than paged, so the whole week has to
        // arrive in one call: paging it would split a day across two pages and
        // leave the totals and the chart describing part of a week.
        $query = ['from' => $weekStart, 'to' => $weekEnd, 'per_page' => self::TIMESHEET_ROWS];
        $employeeId = Session::employeeId();

        if ($employeeId !== null && $employeeId !== '') {
            $query['employee_id'] = $employeeId;
        }

        $response = Api::get('/timesheets', $query);
        $this->guard($response, '/attendance');

        $entries = is_array($response['data']) ? $response['data'] : [];
        $nextWeek = Clock::parse($weekStart)->modify('+7 days')->format('Y-m-d');

        // Every day of the week gets a slot whether or not anything was logged
        // against it, so an empty Thursday is visible rather than absent.
        $byDay = [];

        foreach (Clock::dateRange($weekStart, $weekEnd) as $date) {
            $byDay[$date] = ['entries' => [], 'hours' => 0.0];
        }

        $totals = ['hours' => 0.0, 'billable' => 0.0, 'approved' => 0.0, 'pending' => 0];
        $byProject = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $date = (string) ($entry['work_date'] ?? '');
            $entryHours = (float) ($entry['hours'] ?? 0);
            $entryStatus = (string) ($entry['status'] ?? 'draft');

            if (!isset($byDay[$date])) {
                $byDay[$date] = ['entries' => [], 'hours' => 0.0];
            }

            $byDay[$date]['entries'][] = $entry;
            $byDay[$date]['hours'] += $entryHours;

            $totals['hours'] += $entryHours;

            if (($entry['billable'] ?? false) === true) {
                $totals['billable'] += $entryHours;
            }

            if ($entryStatus === 'approved') {
                $totals['approved'] += $entryHours;
            }

            if ($entryStatus === 'draft' || $entryStatus === 'submitted') {
                $totals['pending']++;
            }

            $project = (string) ($entry['project_code'] ?? 'UNASSIGNED');
            $byProject[$project] = ($byProject[$project] ?? 0.0) + $entryHours;
        }

        arsort($byProject);

        View::render('attendance/timesheets', [
            'pageTitle' => 'Timesheets',
            'breadcrumbs' => [['Attendance', '/attendance'], ['Timesheets', '']],
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'weekPrevious' => Clock::parse($weekStart)->modify('-7 days')->format('Y-m-d'),
            'weekNext' => $nextWeek,
            'weekNextReached' => $nextWeek <= Clock::today(),
            'byDay' => $byDay,
            'entryCount' => count($entries),
            // Above the ceiling the totals and the chart would describe only
            // part of the week, so the page says so rather than quietly
            // under-reporting.
            'truncated' => (int) ($response['meta']['total'] ?? 0) > count($entries),
            'totals' => $totals,
            'today' => Clock::today(),
            'projectChart' => [
                'type' => 'donut',
                'format' => 'hours',
                'labels' => array_keys($byProject),
                'values' => array_map(
                    static fn (float $value): float => round($value, 2),
                    array_values($byProject)
                ),
            ],
        ]);
    }

    public function storeTimesheet(): void
    {
        // The form's approval checkbox is not called "submit": a control by
        // that name shadows form.submit() and quietly breaks the page's other
        // scripted behaviour.
        $sendForApproval = $this->inputBool('submit_for_approval');

        $input = [
            'work_date' => $this->input('work_date'),
            'project_code' => $this->input('project_code'),
            'task_description' => $this->input('task_description'),
            'hours' => $this->input('hours'),
            'billable' => $this->inputBool('billable'),
            'submit_for_approval' => $sendForApproval,
        ];

        $response = Api::post('/timesheets', [
            'work_date' => $input['work_date'],
            'project_code' => $input['project_code'],
            'task_description' => $input['task_description'],
            'hours' => $input['hours'],
            'billable' => $input['billable'],
            'submit' => $sendForApproval,
        ]);

        if (!$response['ok']) {
            $this->backWithErrors($response, $input, '/attendance/timesheets');
        }

        Flash::success($sendForApproval
            ? 'Your time was logged and sent for approval.'
            : 'Your time was logged. Send it for approval when the week is complete.');

        $this->back('/attendance/timesheets');
    }

    // -----------------------------------------------------------------------
    // Input handling
    //
    // Every query parameter is checked here before it reaches the API. An
    // address someone has edited by hand should fall back to the default view,
    // not bounce off a validation error on a page they only wanted to read.
    // -----------------------------------------------------------------------

    /** A submitted calendar date, or the default when it is not one. */
    private function dateInput(string $key, string $default): string
    {
        $value = $this->input($key);
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : $default;
    }

    private function monthInput(string $key, string $default): string
    {
        $value = $this->input($key);

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1 ? $value : $default;
    }

    private function statusInput(string $key = 'status'): string
    {
        $value = $this->input($key);

        return in_array($value, self::STATUSES, true) ? $value : '';
    }

    private function uuidInput(string $key): string
    {
        $value = $this->input($key);
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        return preg_match($pattern, $value) === 1 ? $value : '';
    }

    /**
     * True when a directory listing contains a given employee.
     *
     * @param list<mixed> $people
     */
    private function offers(array $people, string $employeeId): bool
    {
        foreach ($people as $person) {
            if (is_array($person) && (string) ($person['id'] ?? '') === $employeeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * A from/to pair taken from the query string.
     *
     * A range entered back to front is turned around rather than refused: the
     * two dates say plainly what was meant.
     *
     * @return array{0: string, 1: string}
     */
    private function range(string $defaultFrom, string $defaultTo): array
    {
        $from = $this->dateInput('from', $defaultFrom);
        $to = $this->dateInput('to', $defaultTo);

        return $from > $to ? [$to, $from] : [$from, $to];
    }

    // -----------------------------------------------------------------------
    // Shaping what the service returned
    // -----------------------------------------------------------------------

    /**
     * Shift names for the shift ids stamped on the caller's own records.
     *
     * Attendance records carry a shift id and no name, so the employee's
     * current shift and their assignment history supply the labels. Anything
     * still unmatched is simply left blank in the table.
     *
     * @return array<string, string>
     */
    private function shiftLabels(): array
    {
        $data = Api::data('/shifts/mine', [], []);

        if (!is_array($data)) {
            return [];
        }

        $labels = [];
        $shift = $data['shift'] ?? null;

        if (is_array($shift) && !empty($shift['id'])) {
            $labels[(string) $shift['id']] = (string) ($shift['name'] ?? 'Shift');
        }

        foreach ((array) ($data['assignments'] ?? []) as $assignment) {
            if (is_array($assignment) && !empty($assignment['shift_id'])) {
                $labels[(string) $assignment['shift_id']] = (string) ($assignment['shift_name'] ?? 'Shift');
            }
        }

        return $labels;
    }

    /**
     * The hours-per-day bar chart under the month calendar.
     *
     * @param list<mixed> $days
     * @return array<string, mixed>
     */
    private function hoursChart(array $days): array
    {
        $labels = [];
        $values = [];

        foreach ($days as $day) {
            if (!is_array($day)) {
                continue;
            }

            $labels[] = (string) (int) substr((string) ($day['date'] ?? '0000-00-00'), 8, 2);
            $values[] = (float) ($day['worked_hours'] ?? 0);
        }

        return ['type' => 'bar', 'format' => 'hours', 'labels' => $labels, 'values' => $values];
    }

    /**
     * How many holidays in the loaded year are still to come.
     *
     * @param list<mixed> $holidays
     */
    private function remainingHolidays(array $holidays, string $today): int
    {
        $remaining = 0;

        foreach ($holidays as $holiday) {
            if (is_array($holiday) && (string) ($holiday['holiday_date'] ?? '') >= $today) {
                $remaining++;
            }
        }

        return $remaining;
    }
}
