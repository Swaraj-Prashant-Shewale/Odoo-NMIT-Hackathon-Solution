<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Rosters;
use App\Models\Shifts;
use App\Policies\AttendanceScope;
use App\Services\RouteId;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/**
 * Day-level shift overrides: who is on which shift on which date.
 */
final class RosterController
{
    private const UNIQUE_VIOLATION = '23505';

    private Rosters $rosters;
    private Shifts $shifts;
    private AttendanceScope $scope;

    public function __construct()
    {
        $this->rosters = new Rosters();
        $this->shifts = new Shifts();
        $this->scope = new AttendanceScope();
    }

    /** The planner grid for a date range. */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'employee_id' => 'nullable|uuid',
        ])->validated();

        [$weekStart, $weekEnd] = Clock::weekBounds(Clock::today());
        $from = $filters['from'] ?? $weekStart;
        $to = $filters['to'] ?? $weekEnd;

        if ($from > $to) {
            throw HttpException::unprocessable('The start of the range is after its end.');
        }

        $employeeIds = ($filters['employee_id'] ?? null) !== null ? [(string) $filters['employee_id']] : null;

        return Response::ok([
            'from' => $from,
            'to' => $to,
            'entries' => $this->rosters->inRange($from, $to, $employeeIds),
        ]);
    }

    /** The caller's own rostered days, from today onwards. */
    public function mine(Request $request): Response
    {
        $filters = Validator::make($request->all(), ['employee_id' => 'nullable|uuid'])->validated();

        $employeeId = $this->scope->resolveSubject($request, $filters['employee_id'] ?? null);

        return Response::ok([
            'employee_id' => $employeeId,
            'from' => Clock::today(),
            'entries' => $this->rosters->upcomingFor($employeeId, Clock::today(), 60),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'employee_id' => 'required|uuid',
            'shift_id' => 'required|uuid',
            'roster_date' => 'required|date',
            'notes' => 'nullable|safe_text|max:500',
        ])->validated();

        if ($this->shifts->find((string) $data['shift_id']) === null) {
            throw HttpException::unprocessable('That shift does not exist.', ['shift_id' => ['That shift does not exist.']]);
        }

        $existing = $this->rosters->forEmployeeOnDate((string) $data['employee_id'], (string) $data['roster_date']);

        if ($existing !== null) {
            throw HttpException::conflict(
                'This employee is already rostered on that date.',
                ['roster_id' => $existing['id']]
            );
        }

        try {
            $entry = $this->rosters->create($data + ['created_by' => $request->principal()->employeeId]);
        } catch (\PDOException $exception) {
            if ($exception->getCode() === self::UNIQUE_VIOLATION) {
                throw HttpException::conflict('This employee is already rostered on that date.');
            }

            throw $exception;
        }

        AuditLog::record($request, 'attendance.roster.created', 'roster', (string) $entry['id'], [], $entry);

        return Response::created($entry);
    }

    public function update(Request $request): Response
    {
        $entry = $this->rosters->find(RouteId::of($request));

        if ($entry === null) {
            throw HttpException::notFound();
        }

        $data = Validator::make($request->all(), [
            'shift_id' => 'nullable|uuid',
            'notes' => 'nullable|safe_text|max:500',
        ])->validated();

        if ($data === []) {
            throw HttpException::unprocessable('No changes were supplied.');
        }

        if (($data['shift_id'] ?? null) !== null && $this->shifts->find((string) $data['shift_id']) === null) {
            throw HttpException::unprocessable('That shift does not exist.', ['shift_id' => ['That shift does not exist.']]);
        }

        $updated = $this->rosters->update((string) $entry['id'], $data) ?? $entry;

        AuditLog::record($request, 'attendance.roster.updated', 'roster', (string) $entry['id'], $entry, $updated);

        return Response::ok($updated);
    }

    public function destroy(Request $request): Response
    {
        $entry = $this->rosters->find(RouteId::of($request));

        if ($entry === null) {
            throw HttpException::notFound();
        }

        $this->rosters->delete((string) $entry['id']);

        AuditLog::record($request, 'attendance.roster.deleted', 'roster', (string) $entry['id'], $entry, []);

        return Response::noContent();
    }
}
