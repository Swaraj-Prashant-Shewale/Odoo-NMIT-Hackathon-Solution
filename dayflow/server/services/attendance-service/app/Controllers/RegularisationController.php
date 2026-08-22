<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AttendanceRecords;
use App\Models\Regularisations;
use App\Policies\AttendanceScope;
use App\Services\ApproverResolver;
use App\Services\AttendanceCalculator;
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
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/**
 * Requests to correct a day the clock got wrong, and the decisions on them.
 */
final class RegularisationController
{
    /** PostgreSQL's unique-violation class, raised by the one-pending-per-day index. */
    private const UNIQUE_VIOLATION = '23505';

    private Regularisations $requests;
    private AttendanceRecords $records;
    private ShiftResolver $shifts;
    private ApproverResolver $approvers;
    private PeopleDirectory $people;
    private AttendanceScope $scope;

    public function __construct()
    {
        $this->requests = new Regularisations();
        $this->records = new AttendanceRecords();
        $this->shifts = new ShiftResolver();
        $this->approvers = new ApproverResolver();
        $this->people = new PeopleDirectory();
        $this->scope = new AttendanceScope();
    }

    /** Raises a request against one of the caller's own days. */
    public function store(Request $request): Response
    {
        $employeeId = $this->scope->selfId($request);

        $data = Validator::make($request->all(), [
            'work_date' => 'required|date|before_or_equal:today',
            'requested_check_in' => 'nullable|datetime',
            'requested_check_out' => 'nullable|datetime',
            'requested_status' => 'nullable|in:present,half_day,wfh',
            'reason' => 'required|safe_text|min:10|max:1000',
        ])->validated();

        $checkIn = TimeFormat::local($data['requested_check_in'] ?? null);
        $checkOut = TimeFormat::local($data['requested_check_out'] ?? null);
        $status = $data['requested_status'] ?? null;

        if ($checkIn === null && $checkOut === null && $status === null) {
            throw HttpException::unprocessable('Say what the day should have been: a time, or a status.');
        }

        if ($checkIn !== null && $checkOut !== null && Clock::parse($checkOut) < Clock::parse($checkIn)) {
            throw HttpException::unprocessable('The requested check-out is earlier than the requested check-in.');
        }

        $this->assertTimesFallOnDay((string) $data['work_date'], $checkIn, $checkOut);

        if ($this->requests->pendingFor($employeeId, (string) $data['work_date']) !== null) {
            throw HttpException::conflict('A request for this day is already waiting for a decision.');
        }

        $approverId = $this->approvers->forEmployee($request, $employeeId);

        try {
            $record = $this->requests->create([
                'employee_id' => $employeeId,
                'work_date' => $data['work_date'],
                'requested_check_in' => $checkIn,
                'requested_check_out' => $checkOut,
                'requested_status' => $status,
                'reason' => $data['reason'],
                'status' => 'pending',
                'approver_id' => $approverId,
            ]);
        } catch (\PDOException $exception) {
            if ($exception->getCode() === self::UNIQUE_VIOLATION) {
                throw HttpException::conflict('A request for this day is already waiting for a decision.');
            }

            throw $exception;
        }

        AuditLog::record($request, 'attendance.regularisation.raised', 'regularisation', (string) $record['id'], [], $record);

        EventPublisher::publish('attendance.regularisation.raised', [
            'employee_id' => $employeeId,
            'request_id' => $record['id'],
            'date' => $record['work_date'],
            'approver_id' => $approverId,
        ]);

        return Response::created($record);
    }

    /** The caller's own requests, their team's, or everybody's. */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'status' => 'nullable|in:pending,approved,rejected',
            'employee_id' => 'nullable|uuid',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'assigned_to_me' => 'nullable|boolean',
        ])->validated();

        $builder = $this->requests->query()->orderBy('created_at', 'desc');

        if (($filters['assigned_to_me'] ?? false) === true) {
            // The approval queue is the requests stamped with this approver,
            // which is not the same set as the people who report to them.
            $builder->where('approver_id', '=', $this->scope->selfId($request));
        } elseif (($filters['employee_id'] ?? null) !== null) {
            $builder->where('employee_id', '=', $this->scope->resolveSubject($request, (string) $filters['employee_id']));
        } else {
            $this->scope->apply($builder, $request);
        }

        if (isset($filters['status'])) {
            $builder->where('status', '=', $filters['status']);
        }

        if (isset($filters['from'])) {
            $builder->where('work_date', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $builder->where('work_date', '<=', $filters['to']);
        }

        return Response::page($this->requests->paginate($builder, $request->page(), $request->perPage()));
    }

    public function show(Request $request): Response
    {
        $record = $this->requests->find(RouteId::of($request));

        if ($record === null) {
            throw HttpException::notFound();
        }

        $employeeId = (string) $record['employee_id'];
        $isApprover = $record['approver_id'] !== null
            && $request->principal()->owns((string) $record['approver_id']);

        if (!$isApprover && !$this->scope->canView($request, $employeeId)) {
            throw HttpException::forbidden('You may not view this request.');
        }

        return Response::ok([
            'request' => $record,
            'employee' => $this->people->summarise($request, $employeeId),
            'attendance' => $this->records->forEmployeeOnDate($employeeId, (string) $record['work_date']),
        ]);
    }

    /**
     * Approves or rejects a request.
     *
     * On approval the attendance record is written in the same transaction as
     * the decision, so a decision can never be recorded without the correction
     * it authorises actually landing.
     */
    public function decide(Request $request): Response
    {
        $record = $this->requests->find(RouteId::of($request));

        if ($record === null) {
            throw HttpException::notFound();
        }

        $principal = $request->principal();
        $employeeId = (string) $record['employee_id'];

        // Nobody signs off their own request, whatever permissions they hold.
        if ($principal->owns($employeeId)) {
            throw HttpException::forbidden('You may not decide your own regularisation request.');
        }

        if ($record['status'] !== 'pending') {
            throw HttpException::conflict(sprintf('This request was already %s.', $record['status']));
        }

        $this->assertMayDecide($request, $record);

        $data = Validator::make($request->all(), [
            'decision' => 'required|in:approve,reject',
            'note' => 'nullable|safe_text|max:1000',
        ])->validated();

        $status = $data['decision'] === 'approve' ? 'approved' : 'rejected';
        $decidedBy = $this->scope->selfId($request);
        $decidedAt = Clock::iso();
        $note = $data['note'] ?? null;

        if ($status === 'rejected' && ($note === null || trim($note) === '')) {
            throw HttpException::unprocessable('A rejection needs a note explaining it.');
        }

        $outcome = Connection::transaction(function () use ($record, $employeeId, $status, $decidedBy, $decidedAt, $note): array {
            // The pending check above was made outside the transaction. Reading
            // the request again under a row lock is what stops two approvers
            // deciding it at the same moment and both applying it to the day.
            $locked = $this->requests->lockById((string) $record['id']);

            if ($locked === null) {
                throw HttpException::notFound();
            }

            if ($locked['status'] !== 'pending') {
                throw HttpException::conflict(sprintf('This request was already %s.', $locked['status']));
            }

            $attendance = $status === 'approved' ? $this->applyToAttendance($locked, $employeeId) : null;

            $decided = $this->requests->update((string) $locked['id'], [
                'status' => $status,
                'decided_by' => $decidedBy,
                'decided_at' => $decidedAt,
                'decision_note' => $note,
            ]) ?? $locked;

            return ['request' => $decided, 'attendance' => $attendance];
        });

        AuditLog::record(
            $request,
            'attendance.regularisation.decided',
            'regularisation',
            (string) $record['id'],
            $record,
            $outcome['request']
        );

        EventPublisher::publish('attendance.regularisation.decided', [
            'employee_id' => $employeeId,
            'request_id' => $record['id'],
            'status' => $status,
        ]);

        return Response::ok($outcome);
    }

    /**
     * Writes an approved request onto the daily rollup.
     *
     * The punch trail is deliberately untouched. It is the evidence the
     * correction was made against, and rewriting it would destroy the reason
     * the record and the clock disagree.
     *
     * @return array<string, mixed>
     */
    private function applyToAttendance(array $record, string $employeeId): array
    {
        $workDate = (string) $record['work_date'];
        $existing = $this->records->forEmployeeOnDate($employeeId, $workDate);

        $shift = $existing === null
            ? $this->shifts->resolve($employeeId, $workDate)
            : $this->shifts->forRecord($existing, $employeeId);

        $checkIn = $record['requested_check_in'] ?? ($existing['check_in_at'] ?? null);
        $checkOut = $record['requested_check_out'] ?? ($existing['check_out_at'] ?? null);

        // A request that names only one side of the pair is completed from the
        // stored day, and the two can disagree: asking for an evening arrival
        // against a morning departure already on the record inverts the day.
        // The table refuses that outright, so it is caught here and answered as
        // an unprocessable request rather than as a failed write.
        if ($checkIn !== null && $checkOut !== null && Clock::parse((string) $checkOut) < Clock::parse((string) $checkIn)) {
            throw HttpException::unprocessable(
                'Approving this would leave the day checked out before it was checked in. The request needs both times.',
                ['requested_check_out' => ['This would fall before the check-in recorded for ' . $workDate . '.']]
            );
        }

        $settled = AttendanceCalculator::settlePair(
            $checkIn === null ? null : (string) $checkIn,
            $checkOut === null ? null : (string) $checkOut,
            (int) $shift['break_minutes']
        );

        $status = $record['requested_status']
            ?? ($checkIn !== null && $checkOut !== null
                ? AttendanceCalculator::statusFor($shift, $settled['worked_seconds'])
                : ($existing['status'] ?? 'absent'));

        $attributes = [
            'shift_id' => $shift['id'],
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
            'worked_seconds' => $settled['worked_seconds'],
            'break_seconds' => $settled['break_seconds'],
            'overtime_seconds' => AttendanceCalculator::overtimeSeconds($shift, $settled['worked_seconds']),
            'late_minutes' => $checkIn === null ? 0 : AttendanceCalculator::lateMinutes($shift, $workDate, (string) $checkIn),
            'early_leave_minutes' => $checkOut === null ? 0 : AttendanceCalculator::earlyLeaveMinutes($shift, $workDate, (string) $checkOut),
            'status' => $status,
            'is_regularised' => true,
            'remarks' => 'Regularised: ' . $record['reason'],
        ];

        if ($existing === null) {
            return $this->records->create($attributes + ['employee_id' => $employeeId, 'work_date' => $workDate]);
        }

        return $this->records->update((string) $existing['id'], $attributes) ?? $existing;
    }

    /**
     * Confirms this approver is entitled to decide this particular request.
     *
     * The route permission says the caller approves regularisations in general;
     * it says nothing about whose.
     */
    private function assertMayDecide(Request $request, array $record): void
    {
        $principal = $request->principal();

        if ($principal->can(Permissions::ATTENDANCE_VIEW_ALL)) {
            return;
        }

        $approverId = $record['approver_id'];

        if ($approverId !== null && $principal->owns((string) $approverId)) {
            return;
        }

        if (in_array((string) $record['employee_id'], $this->scope->reportIds($request), true)) {
            return;
        }

        throw HttpException::forbidden('This request is waiting on somebody else.');
    }

    /**
     * Keeps a requested time on the day it claims to describe.
     *
     * Without this a request could quietly move attendance onto an entirely
     * different date while appearing to correct one.
     */
    private function assertTimesFallOnDay(string $workDate, ?string $checkIn, ?string $checkOut): void
    {
        $windowStart = Clock::parse($workDate)->setTime(0, 0);
        // A night shift finishes on the following morning, so the window a
        // requested time may fall inside runs to the end of the next day.
        $windowEnd = $windowStart->modify('+2 days');

        foreach (['requested_check_in' => $checkIn, 'requested_check_out' => $checkOut] as $field => $value) {
            if ($value === null) {
                continue;
            }

            $moment = Clock::parse($value);

            if ($moment < $windowStart || $moment >= $windowEnd) {
                throw HttpException::unprocessable(
                    'The times requested do not fall on the day being corrected.',
                    [$field => ['This time is not on ' . $workDate . '.']]
                );
            }
        }
    }
}
