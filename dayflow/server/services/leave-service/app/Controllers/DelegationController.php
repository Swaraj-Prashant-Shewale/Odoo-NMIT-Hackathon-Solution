<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ApprovalDelegations;
use App\Policies\ApprovalPolicy;
use App\Policies\LeaveScope;
use App\Services\RouteId;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/**
 * Standing arrangements for someone else to work an approval queue.
 */
final class DelegationController
{
    private ApprovalDelegations $delegations;
    private ApprovalPolicy $policy;

    public function __construct()
    {
        $this->delegations = new ApprovalDelegations();
        $this->policy = new ApprovalPolicy($this->delegations);
    }

    /** GET /approvals/delegations */
    public function index(Request $request): Response
    {
        $principal = $request->principal();
        $employeeId = LeaveScope::employeeId($principal);

        if ($principal->can(Permissions::LEAVE_VIEW_ALL)) {
            $rows = $this->delegations->all('starts_on', 'desc');
        } else {
            $rows = $this->delegations->involving($employeeId);
        }

        $today = Clock::today();

        foreach ($rows as $index => $row) {
            $rows[$index]['is_in_effect'] = $row['is_active'] === true
                && $row['starts_on'] <= $today
                && $row['ends_on'] >= $today;
        }

        return Response::ok($rows, ['total' => count($rows)]);
    }

    /** POST /approvals/delegations */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'delegate_id' => 'required|uuid',
            'starts_on' => 'required|date',
            'ends_on' => 'required|date|after_or_equal:starts_on',
            'reason' => 'nullable|safe_text|max:300',
            'delegator_id' => 'nullable|uuid',
        ], [
            'delegate_id' => 'Delegate',
            'starts_on' => 'First day',
            'ends_on' => 'Last day',
        ])->validated();

        $principal = $request->principal();
        $self = LeaveScope::employeeId($principal);

        // A manager delegates their own queue. Naming someone else as the
        // delegator is handing away authority that is not yours to hand away,
        // so only an administrator may do it.
        $delegatorId = $data['delegator_id'] ?? $self;

        if ($delegatorId !== $self && !$principal->can(Permissions::LEAVE_VIEW_ALL)) {
            throw HttpException::forbidden('You can only delegate your own approvals.');
        }

        if ($delegatorId === $data['delegate_id']) {
            throw HttpException::unprocessable('An approver cannot delegate to themselves.');
        }

        $scope = new LeaveScope($request->bearerToken());
        $scope->requireEmployee((string) $data['delegate_id']);

        if ($delegatorId !== $self) {
            $scope->requireEmployee($delegatorId);
        }

        $clash = $this->delegations->overlapping($delegatorId, (string) $data['starts_on'], (string) $data['ends_on']);

        if ($clash !== null) {
            throw HttpException::conflict(
                'An active delegation already covers part of that period.',
                ['conflicting_delegation_id' => $clash['id']]
            );
        }

        $record = $this->delegations->create([
            'delegator_id' => $delegatorId,
            'delegate_id' => $data['delegate_id'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'reason' => $data['reason'] ?? null,
            'is_active' => true,
        ]);

        AuditLog::record($request, 'leave.delegation.created', 'approval_delegation', (string) $record['id'], [], $record);

        return Response::created($record);
    }

    /**
     * DELETE /approvals/delegations/{id}
     *
     * Revoked rather than erased: who was allowed to approve on whose behalf,
     * and when, is exactly the sort of question an audit asks later.
     */
    public function destroy(Request $request): Response
    {
        $existing = $this->delegations->find(RouteId::of($request));

        if ($existing === null) {
            throw HttpException::notFound();
        }

        $principal = $request->principal();
        LeaveScope::employeeId($principal);

        if (!$principal->owns((string) $existing['delegator_id']) && !$principal->can(Permissions::LEAVE_VIEW_ALL)) {
            throw HttpException::forbidden('Only the approver who granted this delegation can withdraw it.');
        }

        if ($existing['is_active'] !== true) {
            throw HttpException::conflict('This delegation has already been withdrawn.');
        }

        $record = $this->delegations->update((string) $existing['id'], ['is_active' => false]);

        AuditLog::record(
            $request,
            'leave.delegation.revoked',
            'approval_delegation',
            (string) $existing['id'],
            $existing,
            $record ?? []
        );

        return Response::noContent();
    }

    /** GET /approvals/delegations/{id} */
    public function show(Request $request): Response
    {
        $record = $this->delegations->find(RouteId::of($request));

        if ($record === null) {
            throw HttpException::notFound();
        }

        if (!$this->policy->mayManageDelegation($request->principal(), $record)) {
            throw HttpException::forbidden();
        }

        return Response::ok($record);
    }
}
