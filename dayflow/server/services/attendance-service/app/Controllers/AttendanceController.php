<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AttendancePunches;
use App\Models\AttendanceRecords;
use App\Models\Shifts;
use App\Policies\AttendanceScope;
use App\Services\AttendanceCalculator;
use App\Services\CalendarBuilder;
use App\Services\HolidayCalendar;
use App\Services\LeaveDirectory;
use App\Services\PeopleDirectory;
use App\Services\RouteId;
use App\Services\ShiftResolver;
use App\Services\TimeFormat;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;
use Dayflow\Kernel\Validation\Validator;

final class AttendanceController
{
    /** PostgreSQL's unique-violation class, raised when two punches race. */
    private const UNIQUE_VIOLATION = '23505';

    private const STATUSES = 'present,absent,half_day,on_leave,holiday,weekly_off,wfh';

    private AttendanceRecords $records;
    private AttendancePunches $punches;
    private Shifts $shiftCatalogue;
    private ShiftResolver $shifts;
    private HolidayCalendar $holidays;
    private LeaveDirectory $leave;
    private PeopleDirectory $people;
    private CalendarBuilder $calendar;
    private AttendanceScope $scope;

    public function __construct()
    {
        $this->records = new AttendanceRecords();
        $this->punches = new AttendancePunches();
        $this->shiftCatalogue = new Shifts();
        $this->shifts = new ShiftResolver();
        $this->holidays = new HolidayCalendar();
        $this->leave = new LeaveDirectory();
        $this->people = new PeopleDirectory();
        $this->calendar = new CalendarBuilder();
        $this->scope = new AttendanceScope();
    }

    /**
     * Starts a working day.
     *
     * The employee is always taken from the verified token. Accepting one from
     * the body would let anyone punch in on a colleague's behalf, which is the
     * single most attractive thing to forge in an attendance system.
     */
    public function checkIn(Request $request): Response
    {
        $employeeId = $this->scope->selfId($request);
        $data = $this->punchPayload($request);

        $now = Clock::now();
        $punchedAt = $now->format(\DateTimeInterface::ATOM);
        $workDate = $this->shifts->workDateFor($employeeId, $now);
        $shift = $this->shifts->resolve($employeeId, $workDate);
        $existing = $this->records->forEmployeeOnDate($employeeId, $workDate);

        if ($existing !== null && $existing['check_in_at'] !== null && $existing['check_out_at'] === null) {
            throw HttpException::conflict(
                sprintf('You are already checked in for %s.', $workDate),
                ['work_date' => $workDate, 'checked_in_at' => $existing['check_in_at']]
            );
        }

        try {
            $result = Connection::transaction(function () use ($request, $employeeId, $workDate, $shift, $existing, $data, $punchedAt): array {
                if ($existing === null) {
                    $record = $this->records->create([
                        'employee_id' => $employeeId,
                        'work_date' => $workDate,
                        'shift_id' => $shift['id'],
                        'check_in_at' => $punchedAt,
                        'check_in_ip' => $request->clientIp,
                        'check_in_source' => $data['source'],
                        'late_minutes' => AttendanceCalculator::lateMinutes($shift, $workDate, $punchedAt),
                        // Provisional: the live board has to show somebody as at
                        // work the moment they arrive. Check-out re-derives it
                        // from the hours actually put in.
                        'status' => 'present',
                        'remarks' => $data['remarks'],
                    ]);
                } else {
                    // Returning after a completed day reopens it. The first
                    // arrival of the day, and the lateness it earned, stand.
                    $changes = [
                        'check_out_at' => null,
                        'check_out_ip' => null,
                        'early_leave_minutes' => 0,
                        'status' => 'present',
                    ];

                    if ($existing['check_in_at'] === null) {
                        // The day exists without an arrival on it — an approved
                        // regularisation that only restated a status, or an HR
                        // correction that cleared the time. This punch is
                        // therefore the day's first arrival, and leaving the
                        // column null would strand the employee: check-out
                        // looks for an open row and would never find one.
                        $changes['check_in_at'] = $punchedAt;
                        $changes['check_in_ip'] = $request->clientIp;
                        $changes['check_in_source'] = $data['source'];
                        $changes['late_minutes'] = AttendanceCalculator::lateMinutes($shift, $workDate, $punchedAt);
                    }

                    $record = $this->records->update((string) $existing['id'], $changes) ?? $existing;
                }

                $punch = $this->punches->create([
                    'attendance_record_id' => $record['id'],
                    'employee_id' => $employeeId,
                    'punched_at' => $punchedAt,
                    'direction' => 'in',
                    'ip_address' => $request->clientIp,
                    'user_agent' => $request->userAgent(),
                    'source' => $data['source'],
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                ]);

                EventPublisher::publish('attendance.checked_in', [
                    'employee_id' => $employeeId,
                    'date' => $workDate,
                    'time' => TimeFormat::timeOfDay($punchedAt),
                ]);

                return ['record' => $record, 'punch' => $punch];
            });
        } catch (\PDOException $exception) {
            if ($exception->getCode() === self::UNIQUE_VIOLATION) {
                throw HttpException::conflict('A check-in for this day was recorded a moment ago.');
            }

            throw $exception;
        }

        $record = $result['record'];

        AuditLog::record($request, 'attendance.checked_in', 'attendance_record', (string) $record['id'], [], [
            'work_date' => $workDate,
            'check_in_at' => $record['check_in_at'],
            'late_minutes' => $record['late_minutes'],
            'source' => $data['source'],
        ]);

        return Response::created([
            'record' => $record,
            'punch' => $result['punch'],
            'shift' => $this->shifts->summarise($shift),
            'is_late' => (int) $record['late_minutes'] > 0,
        ]);
    }

    /**
     * Closes the working day the employee currently has open.
     *
     * Worked time is recomputed from the whole punch trail rather than from the
     * first and last stamp, so a lunch break taken by punching out is honoured.
     */
    public function checkOut(Request $request): Response
    {
        $employeeId = $this->scope->selfId($request);
        $data = $this->punchPayload($request);

        $open = $this->records->openFor($employeeId);

        if ($open === null) {
            throw HttpException::conflict('You are not currently checked in.');
        }

        $now = Clock::now();
        $punchedAt = $now->format(\DateTimeInterface::ATOM);
        $workDate = (string) $open['work_date'];
        $shift = $this->shifts->forRecord($open, $employeeId);

        $result = Connection::transaction(function () use ($request, $employeeId, $open, $shift, $workDate, $data, $punchedAt): array {
            // Re-read the day under a row lock. The check above was made
            // outside the transaction, so without this two taps of the button
            // arriving together would each add an "out" punch and each publish
            // the day as finished.
            $locked = $this->records->lockById((string) $open['id']);

            if ($locked === null || $locked['check_in_at'] === null || $locked['check_out_at'] !== null) {
                throw HttpException::conflict('You are not currently checked in.');
            }

            $this->punches->create([
                'attendance_record_id' => $locked['id'],
                'employee_id' => $employeeId,
                'punched_at' => $punchedAt,
                'direction' => 'out',
                'ip_address' => $request->clientIp,
                'user_agent' => $request->userAgent(),
                'source' => $data['source'],
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
            ]);

            $settled = AttendanceCalculator::settle(
                $this->punches->forRecord((string) $locked['id']),
                (int) $shift['break_minutes']
            );

            $status = AttendanceCalculator::statusFor($shift, $settled['worked_seconds']);

            $record = $this->records->update((string) $locked['id'], [
                'check_out_at' => $punchedAt,
                'check_out_ip' => $request->clientIp,
                'worked_seconds' => $settled['worked_seconds'],
                'break_seconds' => $settled['break_seconds'],
                'overtime_seconds' => AttendanceCalculator::overtimeSeconds($shift, $settled['worked_seconds']),
                'early_leave_minutes' => AttendanceCalculator::earlyLeaveMinutes($shift, $workDate, $punchedAt),
                'status' => $status,
                'remarks' => $data['remarks'] ?? $locked['remarks'],
            ]) ?? $locked;

            EventPublisher::publish('attendance.checked_out', [
                'employee_id' => $employeeId,
                'date' => $workDate,
                'worked_seconds' => $settled['worked_seconds'],
            ]);

            if ($status === 'absent') {
                // Too little time on the clock for the day to count. Raising it
                // here gives the employee a chance to regularise the day while
                // they still remember it.
                EventPublisher::publish('attendance.absent_flagged', [
                    'employee_id' => $employeeId,
                    'date' => $workDate,
                ]);
            }

            return ['before' => $locked, 'record' => $record];
        });

        $before = $result['before'];
        $record = $result['record'];

        AuditLog::record($request, 'attendance.checked_out', 'attendance_record', (string) $record['id'], [
            'status' => $before['status'],
            'worked_seconds' => $before['worked_seconds'],
        ], [
            'status' => $record['status'],
            'worked_seconds' => $record['worked_seconds'],
            'overtime_seconds' => $record['overtime_seconds'],
        ]);

        return Response::ok([
            'record' => $record,
            'shift' => $this->shifts->summarise($shift),
            'worked_label' => Str::duration((int) $record['worked_seconds']),
        ]);
    }

    /** The caller's own state right now: the widget on the dashboard. */
    public function today(Request $request): Response
    {
        $employeeId = $this->scope->selfId($request);
        $now = Clock::now();
        $workDate = $this->shifts->workDateFor($employeeId, $now);
        $shift = $this->shifts->resolve($employeeId, $workDate);

        $record = $this->records->forEmployeeOnDate($employeeId, $workDate);
        $punches = $record === null ? [] : $this->punches->forRecord((string) $record['id']);

        $isOpen = $record !== null && $record['check_in_at'] !== null && $record['check_out_at'] === null;
        $elapsed = AttendanceCalculator::elapsedSeconds($punches, $now);

        $holidays = $this->holidays->map($workDate, $workDate, $this->people->locationOf($request, $employeeId));
        $leaveDays = $this->leave->datesFor($request, $employeeId, $workDate, $workDate);

        return Response::ok([
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'is_checked_in' => $isOpen,
            'can_check_in' => !$isOpen,
            'can_check_out' => $isOpen,
            'check_in_at' => $record['check_in_at'] ?? null,
            'check_out_at' => $record['check_out_at'] ?? null,
            'check_in_time' => TimeFormat::timeOfDay($record['check_in_at'] ?? null),
            'check_out_time' => TimeFormat::timeOfDay($record['check_out_at'] ?? null),
            'elapsed_seconds' => $elapsed,
            'elapsed_label' => Str::duration($elapsed),
            'worked_seconds' => (int) ($record['worked_seconds'] ?? 0),
            'overtime_seconds' => (int) ($record['overtime_seconds'] ?? 0),
            'late_minutes' => (int) ($record['late_minutes'] ?? 0),
            'status' => $record['status'] ?? null,
            'is_regularised' => (bool) ($record['is_regularised'] ?? false),
            'is_working_day' => $this->shifts->isWorkingDay($shift, $workDate),
            'is_holiday' => $this->holidays->isClosure($holidays, $workDate),
            'holiday' => $this->holidays->describe($holidays, $workDate),
            'on_leave' => $leaveDays[$workDate] ?? null,
            'shift' => $this->shifts->summarise($shift),
            'punches' => $punches,
            'server_time' => $now->format(\DateTimeInterface::ATOM),
        ]);
    }

    /** A paginated register, scoped to whoever the caller may see. */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'employee_id' => 'nullable|uuid',
            'status' => 'nullable|in:' . self::STATUSES,
        ])->validated();

        [$monthStart, $monthEnd] = Clock::monthBounds(Clock::today());
        $from = $filters['from'] ?? $monthStart;
        $to = $filters['to'] ?? $monthEnd;

        if ($from > $to) {
            throw HttpException::unprocessable('The start of the range is after its end.');
        }

        $builder = $this->records->query()
            ->whereBetween('work_date', $from, $to)
            ->orderBy('work_date', 'desc')
            ->orderBy('employee_id', 'asc');

        $requested = $filters['employee_id'] ?? null;

        if (is_string($requested) && $requested !== '') {
            $builder->where('employee_id', '=', $this->scope->resolveSubject($request, $requested));
        } else {
            $this->scope->apply($builder, $request);
        }

        if (isset($filters['status'])) {
            $builder->where('status', '=', $filters['status']);
        }

        $page = $this->records->paginate($builder, $request->page(), $request->perPage());
        $page['meta'] += ['from' => $from, 'to' => $to];

        return Response::page($page);
    }

    /** Seven days for one employee, the grid behind the weekly view. */
    public function weekly(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'week_of' => 'nullable|date',
            'employee_id' => 'nullable|uuid',
        ])->validated();

        $employeeId = $this->scope->resolveSubject($request, $filters['employee_id'] ?? null);
        [$from, $to] = Clock::weekBounds($filters['week_of'] ?? Clock::today());

        $days = $this->calendar->build(
            $request,
            $employeeId,
            $from,
            $to,
            $this->people->locationOf($request, $employeeId)
        );

        return Response::ok([
            'employee_id' => $employeeId,
            'employee' => $this->people->summarise($request, $employeeId),
            'week_start' => $from,
            'week_end' => $to,
            'days' => $days,
            'summary' => $this->calendar->summarise($days),
        ]);
    }

    /** A calendar month for one employee, plus the totals HR reads off it. */
    public function monthly(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'month' => 'nullable|string|max:7',
            'employee_id' => 'nullable|uuid',
        ])->validated();

        $month = $filters['month'] ?? Clock::now()->format('Y-m');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw HttpException::unprocessable('Month must be in YYYY-MM format.', ['month' => ['Month must be in YYYY-MM format.']]);
        }

        $employeeId = $this->scope->resolveSubject($request, $filters['employee_id'] ?? null);
        [$from, $to] = Clock::monthBounds($month . '-01');

        $days = $this->calendar->build(
            $request,
            $employeeId,
            $from,
            $to,
            $this->people->locationOf($request, $employeeId)
        );

        return Response::ok([
            'employee_id' => $employeeId,
            'employee' => $this->people->summarise($request, $employeeId),
            'month' => $month,
            'from' => $from,
            'to' => $to,
            // The first cell of the grid, so a client can pad the calendar to
            // start on a Monday without recomputing the weekday itself.
            'starts_on_weekday' => (int) Clock::parse($from)->format('N'),
            'days' => $days,
            'summary' => $this->calendar->summarise($days),
        ]);
    }

    /** The live board: who is in, who is late, who is away, right now. */
    public function teamToday(Request $request): Response
    {
        $date = Clock::today();
        $visible = $this->scope->visibleIds($request);

        $employeeIds = $visible ?? $this->people->allIds($request);

        if ($employeeIds === []) {
            // The directory could not be reached; fall back to whoever has
            // actually punched today so the board is never simply blank.
            $employeeIds = $this->records->employeeIdsOn($date);
        }

        $records = $this->records->forEmployeesOnDate($employeeIds, $date);
        $onLeave = $this->leave->everyoneOn($request, $date);
        $holidays = $this->holidays->map($date, $date, null);
        $isClosure = $this->holidays->isClosure($holidays, $date);

        $rows = [];
        $tally = [
            'checked_in' => 0, 'checked_out' => 0, 'not_in' => 0,
            'on_leave' => 0, 'holiday' => 0, 'weekly_off' => 0, 'late' => 0,
        ];

        foreach ($employeeIds as $employeeId) {
            $record = $records[$employeeId] ?? null;
            $leave = $onLeave[$employeeId] ?? null;
            $shift = $this->shifts->resolve($employeeId, $date);

            $isIn = $record !== null && $record['check_in_at'] !== null && $record['check_out_at'] === null;
            $isOut = $record !== null && $record['check_out_at'] !== null;
            $late = (int) ($record['late_minutes'] ?? 0);

            $presence = match (true) {
                $isIn => 'checked_in',
                $isOut => 'checked_out',
                $leave !== null => 'on_leave',
                $isClosure => 'holiday',
                !$this->shifts->isWorkingDay($shift, $date) => 'weekly_off',
                default => 'not_in',
            };

            $tally[$presence]++;

            if ($late > 0) {
                $tally['late']++;
            }

            $rows[] = $this->people->summarise($request, $employeeId) + [
                'presence' => $presence,
                'status' => $record['status'] ?? ($leave !== null ? 'on_leave' : null),
                'check_in_at' => $record['check_in_at'] ?? null,
                'check_out_at' => $record['check_out_at'] ?? null,
                'check_in_time' => TimeFormat::timeOfDay($record['check_in_at'] ?? null),
                'check_out_time' => TimeFormat::timeOfDay($record['check_out_at'] ?? null),
                'late_minutes' => $late,
                'worked_seconds' => (int) ($record['worked_seconds'] ?? 0),
                'leave' => $leave,
                'shift' => $this->shifts->summarise($shift),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($a['full_name'] ?? '') <=> ($b['full_name'] ?? ''));

        return Response::ok([
            'date' => $date,
            'is_holiday' => $isClosure,
            'holiday' => $this->holidays->describe($holidays, $date),
            'scope' => $visible === null ? 'organisation' : 'team',
            'headcount' => count($rows),
            'summary' => $tally,
            'people' => $rows,
        ]);
    }

    /**
     * An HR correction applied straight to the daily rollup.
     *
     * The punch trail is left alone: it is the evidence the correction is being
     * made against, and overwriting it would erase the reason for the change.
     */
    public function update(Request $request): Response
    {
        $record = $this->records->find(RouteId::of($request));

        if ($record === null) {
            throw HttpException::notFound();
        }

        // Separation of duties: holding the correction permission does not make
        // somebody the right person to rewrite their own attendance. They raise
        // a regularisation like everyone else and someone else decides it.
        if ($request->principal()->owns((string) $record['employee_id'])) {
            throw HttpException::forbidden('Correct your own attendance by raising a regularisation request.');
        }

        $data = Validator::make($request->all(), [
            'check_in_at' => 'nullable|datetime',
            'check_out_at' => 'nullable|datetime',
            'status' => 'nullable|in:' . self::STATUSES,
            'shift_id' => 'nullable|uuid',
            'remarks' => 'nullable|safe_text|max:1000',
        ])->validated();

        if ($data === []) {
            throw HttpException::unprocessable('No correction was supplied.');
        }

        $employeeId = (string) $record['employee_id'];
        $workDate = (string) $record['work_date'];

        $checkIn = array_key_exists('check_in_at', $data)
            ? TimeFormat::local($data['check_in_at'])
            : $record['check_in_at'];

        $checkOut = array_key_exists('check_out_at', $data)
            ? TimeFormat::local($data['check_out_at'])
            : $record['check_out_at'];

        if ($checkIn !== null && $checkOut !== null && Clock::parse($checkOut) < Clock::parse($checkIn)) {
            throw HttpException::unprocessable('The check-out time is earlier than the check-in time.');
        }

        $shift = $this->shifts->forRecord($record, $employeeId);

        if (($data['shift_id'] ?? null) !== null) {
            $chosen = $this->shiftCatalogue->find((string) $data['shift_id']);

            if ($chosen === null) {
                throw HttpException::unprocessable('That shift does not exist.', ['shift_id' => ['That shift does not exist.']]);
            }

            $shift = $chosen;
        }

        $settled = AttendanceCalculator::settlePair($checkIn, $checkOut, (int) $shift['break_minutes']);

        $changes = [
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
            'shift_id' => $shift['id'],
            'worked_seconds' => $settled['worked_seconds'],
            'break_seconds' => $settled['break_seconds'],
            'overtime_seconds' => AttendanceCalculator::overtimeSeconds($shift, $settled['worked_seconds']),
            'late_minutes' => $checkIn === null ? 0 : AttendanceCalculator::lateMinutes($shift, $workDate, $checkIn),
            'early_leave_minutes' => $checkOut === null ? 0 : AttendanceCalculator::earlyLeaveMinutes($shift, $workDate, $checkOut),
            // A corrected record no longer reflects the raw trail, and the grid
            // marks it so the difference is visible rather than silent.
            'is_regularised' => true,
        ];

        $changes['status'] = $data['status']
            ?? ($checkIn !== null && $checkOut !== null
                ? AttendanceCalculator::statusFor($shift, $settled['worked_seconds'])
                : (string) $record['status']);

        if (array_key_exists('remarks', $data)) {
            $changes['remarks'] = $data['remarks'];
        }

        $updated = $this->records->update((string) $record['id'], $changes);

        if ($updated === null) {
            throw HttpException::notFound();
        }

        AuditLog::record(
            $request,
            'attendance.record.corrected',
            'attendance_record',
            (string) $record['id'],
            $record,
            $updated,
            ['changed' => AuditLog::diff($record, $updated)]
        );

        return Response::ok(['record' => $updated, 'shift' => $this->shifts->summarise($shift)]);
    }

    /**
     * Shared validation for both punch endpoints.
     *
     * @return array{source: string, latitude: float|null, longitude: float|null, remarks: string|null}
     */
    private function punchPayload(Request $request): array
    {
        $data = Validator::make($request->all(), [
            'source' => 'nullable|in:web,mobile,biometric',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'remarks' => 'nullable|safe_text|max:500',
        ])->validated();

        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;

        // Half a coordinate locates nothing, so it is rejected rather than
        // stored as a misleading partial reading.
        if (($latitude === null) !== ($longitude === null)) {
            throw HttpException::unprocessable('A location needs both a latitude and a longitude.');
        }

        return [
            'source' => $data['source'] ?? 'web',
            'latitude' => $latitude === null ? null : (float) $latitude,
            'longitude' => $longitude === null ? null : (float) $longitude,
            'remarks' => $data['remarks'] ?? null,
        ];
    }
}
