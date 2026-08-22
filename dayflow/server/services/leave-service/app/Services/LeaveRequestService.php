<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LeaveApprovals;
use App\Models\LeaveRequests;
use App\Models\LeaveTypes;
use App\Policies\ApprovalPolicy;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Support\Clock;

/**
 * The rules that govern the life of a leave request.
 *
 * Everything that must happen together happens inside one transaction: the
 * overlap test and the insert, the decision and the balance movement, the
 * cancellation and the release. Domain events are queued inside that same
 * transaction through the outbox, so a rolled-back change can never announce
 * itself to the rest of the platform.
 */
final class LeaveRequestService
{
    /** Tolerance for comparing balances held as NUMERIC against float arithmetic. */
    private const EPSILON = 0.001;

    public function __construct(
        private readonly LeaveRequests $requests,
        private readonly LeaveTypes $types,
        private readonly LeaveApprovals $approvals,
        private readonly BalanceLedger $ledger,
        private readonly WorkingDayCalculator $calculator,
        private readonly ApproverResolver $approvers,
        private readonly ApprovalPolicy $approvalPolicy,
        private readonly EmployeeProfile $employees,
    ) {
    }

    /**
     * Accepts a new request from the principal, for the principal.
     *
     * @param array<string, mixed> $data Already validated.
     * @return array<string, mixed>
     */
    public function submit(Principal $principal, string $employeeId, array $data): array
    {
        $type = $this->activeType((string) $data['leave_type_id']);

        $startsOn = (string) $data['starts_on'];
        $endsOn = (string) $data['ends_on'];

        if (strtotime($endsOn) < strtotime($startsOn)) {
            throw HttpException::unprocessable('The last day of leave cannot be before the first.');
        }

        // Balances are held per leave year, so a request straddling the turn of
        // the year has no single balance to charge. Splitting it is the
        // employee's decision, not something to guess at.
        if (substr($startsOn, 0, 4) !== substr($endsOn, 0, 4)) {
            throw HttpException::unprocessable(
                'A request must fall inside one leave year. Please apply separately for each year.'
            );
        }

        $counted = $this->calculator->count($startsOn, $endsOn);
        $dayCount = $counted['days'];

        $isHalfDay = (bool) ($data['is_half_day'] ?? false);
        $halfDayPeriod = $isHalfDay ? ($data['half_day_period'] ?? null) : null;

        if ($isHalfDay) {
            if ($startsOn !== $endsOn) {
                throw HttpException::unprocessable('A half day can only be taken on a single date.');
            }

            if ($type['allows_half_day'] !== true) {
                throw HttpException::unprocessable(sprintf('%s cannot be taken as a half day.', $type['name']));
            }

            if ($halfDayPeriod === null) {
                throw HttpException::unprocessable('Please say whether the half day is the first or second half.');
            }

            $dayCount = 0.5;
        }

        if ($dayCount <= 0) {
            throw HttpException::unprocessable(
                'Those dates contain no working days, so there is nothing to apply for.'
            );
        }

        $this->assertNotice($type, $startsOn);
        $this->assertLength($type, $dayCount);
        $this->assertSupportingDocument($type, $dayCount, $data['supporting_document_id'] ?? null);
        $this->assertGenderEligible($type, $employeeId);

        $approverId = $this->approvers->forSelf($principal);
        $year = (int) substr($startsOn, 0, 4);

        return Connection::transaction(function () use (
            $data, $type, $employeeId, $startsOn, $endsOn, $dayCount,
            $isHalfDay, $halfDayPeriod, $approverId, $year, $counted
        ): array {
            $this->requests->lockEmployee($employeeId);

            $clash = $this->requests->overlapping($employeeId, $startsOn, $endsOn);

            if ($clash !== null) {
                throw HttpException::conflict(
                    'You already have leave booked over some of those dates.',
                    [
                        'conflicting_request_id' => $clash['id'],
                        'starts_on' => $clash['starts_on'],
                        'ends_on' => $clash['ends_on'],
                        'status' => $clash['status'],
                    ]
                );
            }

            $balance = $this->ledger->ensure($employeeId, $type, $year);

            // Unpaid leave is a request for permission, not a draw on an
            // entitlement, so there is no balance to run out of.
            if ($type['is_paid'] === true) {
                $available = $this->ledger->availableForUpdate((string) $balance['id']);

                if ($dayCount > $available + self::EPSILON) {
                    throw HttpException::unprocessable(
                        sprintf(
                            'You have %s day(s) of %s available and this request needs %s.',
                            rtrim(rtrim(number_format($available, 2, '.', ''), '0'), '.') ?: '0',
                            $type['name'],
                            rtrim(rtrim(number_format($dayCount, 2, '.', ''), '0'), '.')
                        ),
                        ['available_days' => $available, 'requested_days' => $dayCount]
                    );
                }
            }

            $record = $this->requests->create([
                'employee_id' => $employeeId,
                'leave_type_id' => $type['id'],
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'day_count' => $dayCount,
                'is_half_day' => $isHalfDay,
                'half_day_period' => $halfDayPeriod,
                'reason' => $data['reason'] ?? null,
                'contact_during_leave' => $data['contact_during_leave'] ?? null,
                'supporting_document_id' => $data['supporting_document_id'] ?? null,
                'status' => 'pending',
                'approver_id' => $approverId,
                'holiday_calendar_applied' => $counted['holiday_calendar_applied'],
                'applied_at' => Clock::iso(),
            ]);

            if ($approverId !== null) {
                $this->approvals->create([
                    'leave_request_id' => $record['id'],
                    'level' => 1,
                    'approver_id' => $approverId,
                    'status' => 'pending',
                ]);
            }

            $this->ledger->reserve((string) $balance['id'], $dayCount);

            EventPublisher::publish('leave.request.submitted', [
                'employee_id' => $employeeId,
                'request_id' => $record['id'],
                'leave_type' => $type['name'],
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'approver_id' => $approverId,
            ]);

            return $record;
        });
    }

    /**
     * Approves or rejects a pending request.
     *
     * @return array{request: array<string, mixed>, before: array<string, mixed>}
     */
    public function decide(Principal $principal, string $requestId, string $action, ?string $note): array
    {
        $deciderId = (string) $principal->employeeId;

        return Connection::transaction(function () use ($principal, $requestId, $action, $note, $deciderId): array {
            $request = $this->requests->lockForUpdate($requestId);

            if ($request === null) {
                throw HttpException::notFound();
            }

            if ($request['status'] !== 'pending') {
                throw HttpException::conflict(
                    'This request has already been dealt with.',
                    ['status' => $request['status']]
                );
            }

            // Signing off your own absence defeats the point of an approval,
            // and holding leave.view.all does not change that.
            if ($principal->owns((string) $request['employee_id'])) {
                throw HttpException::forbidden('You cannot decide your own leave request.');
            }

            if (!$this->approvalPolicy->mayDecide($principal, $request)) {
                throw HttpException::forbidden('This request is not yours to decide.');
            }

            $level = $this->approvals->currentLevel($requestId);
            $levelStatus = $action === 'approve' ? 'approved' : 'rejected';

            if ($level !== null) {
                $this->approvals->closeLevel((string) $level['id'], $levelStatus, $note);
            }

            $nextLevel = $action === 'approve' && $level !== null
                ? $this->approvals->nextLevelAfter($requestId, (int) $level['level'])
                : null;

            // A chain with another signature outstanding stays pending, and
            // the request simply moves into the next approver's queue. Days
            // remain reserved until the last level has signed.
            if ($nextLevel !== null) {
                $updated = $this->requests->update($requestId, ['approver_id' => $nextLevel['approver_id']]);

                return ['request' => $updated ?? $request, 'before' => $request];
            }

            $now = Clock::iso();
            $status = $action === 'approve' ? 'approved' : 'rejected';

            $updated = $this->requests->update($requestId, [
                'status' => $status,
                'decided_by' => $deciderId,
                'decided_at' => $now,
                'decision_note' => $note,
            ]);

            if ($status === 'rejected') {
                $this->approvals->skipRemaining($requestId);
            }

            $balance = $this->ledger->ensure(
                (string) $request['employee_id'],
                $this->typeOrFail((string) $request['leave_type_id']),
                (int) substr((string) $request['starts_on'], 0, 4)
            );

            $days = (float) $request['day_count'];

            if ($status === 'approved') {
                $this->ledger->consumeReserved((string) $balance['id'], $days);
            } else {
                $this->ledger->releaseReserved((string) $balance['id'], $days);
            }

            EventPublisher::publish('leave.request.decided', [
                'employee_id' => $request['employee_id'],
                'request_id' => $requestId,
                'status' => $status,
                'decided_by' => $deciderId,
                'note' => $note,
            ]);

            return ['request' => $updated ?? $request, 'before' => $request];
        });
    }

    /**
     * Withdraws a request the employee owns.
     *
     * @return array{request: array<string, mixed>, before: array<string, mixed>}
     */
    public function cancel(Principal $principal, string $requestId): array
    {
        $employeeId = (string) $principal->employeeId;

        return Connection::transaction(function () use ($principal, $requestId, $employeeId): array {
            $request = $this->requests->lockForUpdate($requestId);

            if ($request === null) {
                throw HttpException::notFound();
            }

            if (!$principal->owns((string) $request['employee_id'])) {
                throw HttpException::forbidden('You can only cancel your own leave.');
            }

            $status = (string) $request['status'];

            if (!in_array($status, ['pending', 'approved'], true)) {
                throw HttpException::conflict(
                    'This request can no longer be cancelled.',
                    ['status' => $status]
                );
            }

            // Approved leave that has already started has been taken; taking it
            // back off the balance would misstate what the employee has used.
            if ($status === 'approved' && strtotime((string) $request['starts_on']) <= strtotime(Clock::today())) {
                throw HttpException::conflict(
                    'Approved leave can only be cancelled before it starts. Please speak to HR.',
                    ['starts_on' => $request['starts_on']]
                );
            }

            $updated = $this->requests->update($requestId, [
                'status' => 'cancelled',
                'cancelled_at' => Clock::iso(),
                'cancelled_by' => $employeeId,
            ]);

            $this->approvals->skipRemaining($requestId);

            $balance = $this->ledger->ensure(
                (string) $request['employee_id'],
                $this->typeOrFail((string) $request['leave_type_id']),
                (int) substr((string) $request['starts_on'], 0, 4)
            );

            $days = (float) $request['day_count'];

            if ($status === 'pending') {
                $this->ledger->releaseReserved((string) $balance['id'], $days);
            } else {
                $this->ledger->releaseUsed((string) $balance['id'], $days);
            }

            EventPublisher::publish('leave.request.cancelled', [
                'employee_id' => $request['employee_id'],
                'request_id' => $requestId,
            ]);

            return ['request' => $updated ?? $request, 'before' => $request];
        });
    }

    /**
     * True when the principal is allowed to read this particular request.
     *
     * @param array<string, mixed> $request
     * @param list<string>         $teamMemberIds
     */
    public function mayView(Principal $principal, array $request, array $teamMemberIds): bool
    {
        if ($principal->can(Permissions::LEAVE_VIEW_ALL)) {
            return true;
        }

        if ($principal->owns((string) $request['employee_id'])) {
            return true;
        }

        if (in_array((string) $request['employee_id'], $teamMemberIds, true)) {
            return true;
        }

        // Whoever has to decide a request must be able to read it, including a
        // delegate standing in for the recorded approver.
        return $this->approvalPolicy->mayDecide($principal, $request);
    }

    /** @return array<string, mixed> */
    private function activeType(string $leaveTypeId): array
    {
        $type = $this->types->find($leaveTypeId);

        if ($type === null || $type['is_active'] !== true) {
            throw HttpException::unprocessable('That leave type is not available.');
        }

        return $type;
    }

    /** @return array<string, mixed> */
    private function typeOrFail(string $leaveTypeId): array
    {
        $type = $this->types->find($leaveTypeId);

        if ($type === null) {
            throw HttpException::unprocessable('The leave type on this request no longer exists.');
        }

        return $type;
    }

    /** @param array<string, mixed> $type */
    private function assertNotice(array $type, string $startsOn): void
    {
        $notice = (int) ($type['min_notice_days'] ?? 0);

        // A notice period of zero means the type may be applied for after the
        // fact, which is exactly what sick leave needs.
        if ($notice <= 0) {
            return;
        }

        $earliest = Clock::now()->setTime(0, 0)->modify(sprintf('+%d days', $notice));

        if (Clock::parse($startsOn)->setTime(0, 0) < $earliest) {
            throw HttpException::unprocessable(
                sprintf('%s needs at least %d day(s) of notice.', $type['name'], $notice),
                ['earliest_start' => $earliest->format('Y-m-d')]
            );
        }
    }

    /** @param array<string, mixed> $type */
    private function assertLength(array $type, float $dayCount): void
    {
        $limit = $type['max_consecutive_days'] ?? null;

        if ($limit === null || (int) $limit <= 0) {
            return;
        }

        if ($dayCount > (float) $limit + self::EPSILON) {
            throw HttpException::unprocessable(
                sprintf('%s is limited to %d day(s) in one request.', $type['name'], (int) $limit),
                ['max_consecutive_days' => (int) $limit, 'requested_days' => $dayCount]
            );
        }
    }

    /**
     * Refuses a type that does not apply to this employee.
     *
     * Statutory parental leave is the reason this column exists: maternity
     * leave opens a balance of half a year, so leaving the restriction to the
     * interface would mean anybody willing to post the request could take it.
     *
     * Gender lives on the canonical employee record, not in the token, so it
     * is fetched — but only when the type is actually restricted, which keeps
     * an ordinary application free of the round trip. Only a recorded, definite
     * mismatch refuses: a blank, "other" or "undisclosed" record says nothing
     * about eligibility, and an unreachable employee service must not become a
     * reason somebody cannot apply for leave at all.
     *
     * @param array<string, mixed> $type
     */
    private function assertGenderEligible(array $type, string $employeeId): void
    {
        $restriction = (string) ($type['applies_to_gender'] ?? 'any');

        if ($restriction === 'any') {
            return;
        }

        $employee = $this->employees->find($employeeId);
        $gender = $employee === null ? null : ($employee['gender'] ?? null);

        if (!is_string($gender) || !in_array($gender, ['female', 'male'], true)) {
            return;
        }

        if ($gender === $restriction) {
            return;
        }

        throw HttpException::unprocessable(
            sprintf('%s is not available to you.', $type['name']),
            ['applies_to_gender' => $restriction]
        );
    }

    /** @param array<string, mixed> $type */
    private function assertSupportingDocument(array $type, float $dayCount, mixed $documentId): void
    {
        $threshold = $type['requires_document_after_days'] ?? null;

        if ($threshold === null) {
            return;
        }

        if ($dayCount <= (float) $threshold + self::EPSILON) {
            return;
        }

        if (is_string($documentId) && $documentId !== '') {
            return;
        }

        throw HttpException::unprocessable(
            sprintf(
                '%s longer than %d day(s) needs a supporting document. Please upload it first and attach it here.',
                $type['name'],
                (int) $threshold
            ),
            ['requires_document_after_days' => (int) $threshold]
        );
    }
}
