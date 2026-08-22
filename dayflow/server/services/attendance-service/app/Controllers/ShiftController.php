<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ShiftAssignments;
use App\Models\Shifts;
use App\Policies\AttendanceScope;
use App\Services\RouteId;
use App\Services\ShiftResolver;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/**
 * The shift catalogue and the standing assignments that apply it to people.
 */
final class ShiftController
{
    private const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private Shifts $shifts;
    private ShiftAssignments $assignments;
    private ShiftResolver $resolver;
    private AttendanceScope $scope;

    public function __construct()
    {
        $this->shifts = new Shifts();
        $this->assignments = new ShiftAssignments();
        $this->resolver = new ShiftResolver();
        $this->scope = new AttendanceScope();
    }

    public function index(Request $request): Response
    {
        $builder = $this->shifts->query()->orderBy('starts_at', 'asc');

        if ($request->has('active')) {
            $builder->where('is_active', '=', $request->bool('active'));
        }

        return Response::page($this->shifts->paginate($builder, $request->page(), $request->perPage()));
    }

    public function show(Request $request): Response
    {
        $shift = $this->shifts->find(RouteId::of($request));

        if ($shift === null) {
            throw HttpException::notFound();
        }

        return Response::ok($shift);
    }

    /** The shift an employee is on today, plus the history behind it. */
    public function mine(Request $request): Response
    {
        $filters = Validator::make($request->all(), ['employee_id' => 'nullable|uuid'])->validated();

        $employeeId = $this->scope->resolveSubject($request, $filters['employee_id'] ?? null);
        $today = Clock::today();
        $shift = $this->resolver->resolve($employeeId, $today);

        return Response::ok([
            'employee_id' => $employeeId,
            'date' => $today,
            'shift' => $this->resolver->summarise($shift),
            'is_working_day' => $this->resolver->isWorkingDay($shift, $today),
            'assignments' => $this->assignments->history($employeeId),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validatePayload($request, true);
        $data['code'] = strtoupper((string) $data['code']);

        if ($this->shifts->findBy('code', $data['code']) !== null) {
            throw HttpException::conflict('A shift with that code already exists.');
        }

        $shift = $this->shifts->create($data);

        AuditLog::record($request, 'attendance.shift.created', 'shift', (string) $shift['id'], [], $shift);

        return Response::created($shift);
    }

    public function update(Request $request): Response
    {
        $shift = $this->shifts->find(RouteId::of($request));

        if ($shift === null) {
            throw HttpException::notFound();
        }

        $data = $this->validatePayload($request, false);

        if ($data === []) {
            throw HttpException::unprocessable('No changes were supplied.');
        }

        if (isset($data['code'])) {
            $data['code'] = strtoupper((string) $data['code']);
            $clash = $this->shifts->findBy('code', $data['code']);

            if ($clash !== null && $clash['id'] !== $shift['id']) {
                throw HttpException::conflict('A shift with that code already exists.');
            }
        }

        $this->assertDayLengthsOrdered($data + $shift);

        $updated = $this->shifts->update((string) $shift['id'], $data) ?? $shift;

        AuditLog::record($request, 'attendance.shift.updated', 'shift', (string) $shift['id'], $shift, $updated);

        return Response::ok($updated);
    }

    /**
     * Removes a shift nobody depends on.
     *
     * A shift that has already measured somebody's day is never deleted:
     * dropping it would silently change what those records were judged against.
     */
    public function destroy(Request $request): Response
    {
        $shift = $this->shifts->find(RouteId::of($request));

        if ($shift === null) {
            throw HttpException::notFound();
        }

        if ($this->shifts->isInUse((string) $shift['id'])) {
            throw HttpException::conflict(
                'This shift is already in use. Deactivate it instead so past attendance keeps its meaning.'
            );
        }

        $this->shifts->delete((string) $shift['id']);

        AuditLog::record($request, 'attendance.shift.deleted', 'shift', (string) $shift['id'], $shift, []);

        return Response::noContent();
    }

    /** Standing assignments, optionally for one employee. */
    public function assignments(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'employee_id' => 'nullable|uuid',
            'shift_id' => 'nullable|uuid',
        ])->validated();

        $builder = $this->assignments->query()->orderBy('effective_from', 'desc');

        if (($filters['employee_id'] ?? null) !== null) {
            $builder->where('employee_id', '=', $filters['employee_id']);
        }

        if (($filters['shift_id'] ?? null) !== null) {
            $builder->where('shift_id', '=', $filters['shift_id']);
        }

        return Response::page($this->assignments->paginate($builder, $request->page(), $request->perPage()));
    }

    public function assign(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'employee_id' => 'required|uuid',
            'shift_id' => 'required|uuid',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
        ])->validated();

        if ($this->shifts->find((string) $data['shift_id']) === null) {
            throw HttpException::unprocessable('That shift does not exist.', ['shift_id' => ['That shift does not exist.']]);
        }

        $effectiveTo = $data['effective_to'] ?? null;

        if ($this->assignments->overlaps((string) $data['employee_id'], (string) $data['effective_from'], $effectiveTo)) {
            throw HttpException::conflict('This employee already has a shift assigned across part of that period.');
        }

        $assignment = $this->assignments->create($data);

        AuditLog::record(
            $request,
            'attendance.shift.assigned',
            'shift_assignment',
            (string) $assignment['id'],
            [],
            $assignment
        );

        return Response::created($assignment);
    }

    public function unassign(Request $request): Response
    {
        $assignment = $this->assignments->find(RouteId::of($request));

        if ($assignment === null) {
            throw HttpException::notFound();
        }

        $this->assignments->delete((string) $assignment['id']);

        AuditLog::record(
            $request,
            'attendance.shift.unassigned',
            'shift_assignment',
            (string) $assignment['id'],
            $assignment,
            []
        );

        return Response::noContent();
    }

    /**
     * @return array<string, mixed> Only the fields the request actually carried.
     */
    private function validatePayload(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'nullable';

        $data = Validator::make($request->all(), [
            'name' => $required . '|safe_text|max:80',
            'code' => $required . '|safe_text|max:20',
            'starts_at' => $required . '|time',
            'ends_at' => $required . '|time',
            'break_minutes' => 'nullable|int|between:0,480',
            'full_day_hours' => 'nullable|numeric|between:0.5,24',
            'half_day_hours' => 'nullable|numeric|between:0.5,24',
            'grace_minutes' => 'nullable|int|between:0,240',
            'is_night_shift' => 'nullable|boolean',
            'working_days' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ])->validated();

        if (isset($data['code']) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{1,19}$/', (string) $data['code']) !== 1) {
            throw HttpException::unprocessable(
                'A shift code is 2 to 20 letters, numbers, hyphens or underscores.',
                ['code' => ['A shift code is 2 to 20 letters, numbers, hyphens or underscores.']]
            );
        }

        if (isset($data['working_days'])) {
            $data['working_days'] = $this->cleanWorkingDays($data['working_days']);
        }

        // Every column on the table is NOT NULL, so an explicit null from a
        // client is a request to leave the field alone, not to blank it.
        $data = array_filter($data, static fn (mixed $value): bool => $value !== null);

        if ($creating) {
            $data['break_minutes'] = $data['break_minutes'] ?? 60;
            $data['full_day_hours'] = $data['full_day_hours'] ?? 8;
            $data['half_day_hours'] = $data['half_day_hours'] ?? 4;
            $data['grace_minutes'] = $data['grace_minutes'] ?? 15;
            $data['is_night_shift'] = $data['is_night_shift']
                ?? $this->crossesMidnight((string) $data['starts_at'], (string) $data['ends_at']);
            $data['working_days'] = $data['working_days'] ?? ['mon', 'tue', 'wed', 'thu', 'fri'];
            $data['is_active'] = $data['is_active'] ?? true;

            $this->assertDayLengthsOrdered($data);
        }

        return $data;
    }

    /** @param array<int|string, mixed> $days */
    private function cleanWorkingDays(array $days): array
    {
        $clean = [];

        foreach ($days as $day) {
            $key = is_string($day) ? strtolower(trim($day)) : '';

            if (in_array($key, self::WEEKDAYS, true) && !in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }

        if ($clean === []) {
            throw HttpException::unprocessable(
                'A shift needs at least one working day.',
                ['working_days' => ['Use three-letter day keys such as "mon".']]
            );
        }

        // Stored in week order so the calendar never has to sort it.
        return array_values(array_filter(self::WEEKDAYS, static fn (string $day): bool => in_array($day, $clean, true)));
    }

    /** @param array<string, mixed> $shift */
    private function assertDayLengthsOrdered(array $shift): void
    {
        if ((float) $shift['half_day_hours'] > (float) $shift['full_day_hours']) {
            throw HttpException::unprocessable(
                'A half day cannot be longer than a full day.',
                ['half_day_hours' => ['A half day cannot be longer than a full day.']]
            );
        }
    }

    private function crossesMidnight(string $startsAt, string $endsAt): bool
    {
        return substr($endsAt, 0, 5) <= substr($startsAt, 0, 5);
    }
}
