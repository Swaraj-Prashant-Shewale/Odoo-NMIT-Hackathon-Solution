<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ApprovalDelegations;
use App\Models\LeaveApprovals;
use App\Models\LeaveBalances;
use App\Models\LeaveRequests;
use App\Models\LeaveTypes;
use App\Policies\ApprovalPolicy;
use App\Policies\LeaveScope;
use App\Services\ApproverResolver;
use App\Services\BalanceLedger;
use App\Services\EmployeeProfile;
use App\Services\LeaveRequestService;
use App\Services\RouteId;
use App\Services\WorkingDayCalculator;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

final class LeaveRequestController
{
    private LeaveRequests $requests;
    private LeaveTypes $types;
    private LeaveApprovals $approvals;
    private LeaveBalances $balances;
    private ApprovalDelegations $delegations;
    private ApprovalPolicy $approvalPolicy;

    public function __construct()
    {
        $this->requests = new LeaveRequests();
        $this->types = new LeaveTypes();
        $this->approvals = new LeaveApprovals();
        $this->balances = new LeaveBalances();
        $this->delegations = new ApprovalDelegations();
        $this->approvalPolicy = new ApprovalPolicy($this->delegations);
    }

    /** POST /leave/requests */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'leave_type_id' => 'required|uuid',
            'starts_on' => 'required|date',
            'ends_on' => 'required|date|after_or_equal:starts_on',
            'is_half_day' => 'nullable|boolean',
            'half_day_period' => 'nullable|in:first_half,second_half',
            'reason' => 'nullable|safe_text|max:1000',
            'contact_during_leave' => 'nullable|safe_text|max:120',
            'supporting_document_id' => 'nullable|uuid',
        ], [
            'leave_type_id' => 'Leave type',
            'starts_on' => 'First day',
            'ends_on' => 'Last day',
        ])->validated();

        $principal = $request->principal();

        // The applicant is always the caller. An employee_id in the body is
        // discarded by the validator and would be ignored even if it were not.
        $employeeId = LeaveScope::employeeId($principal);

        $record = $this->service($request)->submit($principal, $employeeId, $data);

        AuditLog::record($request, 'leave.request.submitted', 'leave_request', (string) $record['id'], [], $record);

        return Response::created($this->decorate([$record])[0]);
    }

    /** GET /leave/requests */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'status' => 'nullable|in:pending,approved,rejected,cancelled,withdrawn',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'employee_id' => 'nullable|uuid',
            'leave_type_id' => 'nullable|uuid',
        ])->validated();

        $principal = $request->principal();
        $scope = new LeaveScope($request->bearerToken());
        $builder = $this->requests->query();

        $target = $filters['employee_id'] ?? null;

        if ($target !== null) {
            if (!$scope->canView($principal, $target)) {
                throw HttpException::forbidden('You cannot see that person\'s leave.');
            }

            $builder->where('employee_id', '=', $target);
        } else {
            $visible = $scope->visibleEmployeeIds($principal);

            if ($visible !== null) {
                $builder->whereIn('employee_id', $visible);
            }
        }

        if (isset($filters['status'])) {
            $builder->where('status', '=', $filters['status']);
        }

        if (isset($filters['leave_type_id'])) {
            $builder->where('leave_type_id', '=', $filters['leave_type_id']);
        }

        // A request counts as inside the window when any of its days are, so
        // the filter compares the far end of the range against each bound.
        if (isset($filters['from'])) {
            $builder->where('ends_on', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $builder->where('starts_on', '<=', $filters['to']);
        }

        $page = $this->requests->paginate(
            $builder->orderBy('starts_on', 'desc')->orderBy('applied_at', 'desc'),
            $request->page(),
            $request->perPage()
        );

        $page['data'] = $this->decorate($page['data']);

        return Response::page($page);
    }

    /** GET /leave/requests/{id} */
    public function show(Request $request): Response
    {
        $record = $this->requests->find(RouteId::of($request));

        if ($record === null) {
            throw HttpException::notFound();
        }

        $principal = $request->principal();
        $scope = new LeaveScope($request->bearerToken());

        $visible = $scope->visibleEmployeeIds($principal);
        $teamIds = $visible ?? [];

        if (!$this->service($request)->mayView($principal, $record, $teamIds)) {
            throw HttpException::forbidden();
        }

        $decorated = $this->decorate([$record])[0];
        $decorated['approvals'] = $this->approvals->forRequest((string) $record['id']);

        return Response::ok($decorated);
    }

    /** POST /leave/requests/{id}/decide */
    public function decide(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'status' => 'required|in:approve,reject',
            'note' => 'nullable|safe_text|max:500',
        ], ['status' => 'Decision'])->validated();

        $principal = $request->principal();
        LeaveScope::employeeId($principal);

        $outcome = $this->service($request)->decide(
            $principal,
            RouteId::of($request),
            (string) $data['status'],
            $data['note'] ?? null
        );

        AuditLog::record(
            $request,
            'leave.request.decided',
            'leave_request',
            (string) $outcome['request']['id'],
            $outcome['before'],
            $outcome['request']
        );

        return Response::ok($this->decorate([$outcome['request']])[0]);
    }

    /** POST /leave/requests/{id}/cancel */
    public function cancel(Request $request): Response
    {
        $principal = $request->principal();
        LeaveScope::employeeId($principal);

        $outcome = $this->service($request)->cancel($principal, RouteId::of($request));

        AuditLog::record(
            $request,
            'leave.request.cancelled',
            'leave_request',
            (string) $outcome['request']['id'],
            $outcome['before'],
            $outcome['request']
        );

        return Response::ok($this->decorate([$outcome['request']])[0]);
    }

    /** GET /leave/pending-approvals */
    public function pendingApprovals(Request $request): Response
    {
        $principal = $request->principal();
        $approverId = LeaveScope::employeeId($principal);
        $today = Clock::today();

        $queue = $this->requests->queueFor(
            $approverId,
            $today,
            $principal->can(Permissions::LEAVE_VIEW_ALL)
        );

        $decorated = $this->decorate($queue);

        // Whoever is about to sign needs to know whether the employee can
        // actually afford the days, without opening a second screen.
        foreach ($decorated as $index => $row) {
            $balance = $this->balances->forEmployeeTypeYear(
                (string) $row['employee_id'],
                (string) $row['leave_type_id'],
                (int) substr((string) $row['starts_on'], 0, 4)
            );

            $decorated[$index]['available_days'] = $balance === null ? 0.0 : (float) $balance['available_days'];
            $decorated[$index]['delegated_from'] = $row['approver_id'] === $approverId ? null : $row['approver_id'];
        }

        return Response::ok($decorated, [
            'total' => count($decorated),
            'approver_id' => $approverId,
            // Naming the approvers being covered for explains why requests the
            // caller has never seen before are suddenly in their queue.
            'standing_in_for' => $this->delegations->delegatorsFor($approverId, $today),
        ]);
    }

    /**
     * Adds the leave type's display fields to a list of requests.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function decorate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $types = $this->types->keyedById();

        foreach ($rows as $index => $row) {
            $type = $types[(string) ($row['leave_type_id'] ?? '')] ?? null;

            $rows[$index]['leave_type'] = $type === null ? null : [
                'id' => $type['id'],
                'name' => $type['name'],
                'code' => $type['code'],
                'category' => $type['category'],
                'colour' => $type['colour'],
                'is_paid' => $type['is_paid'],
            ];
        }

        return array_values($rows);
    }

    private function service(Request $request): LeaveRequestService
    {
        $token = $request->bearerToken();

        return new LeaveRequestService(
            $this->requests,
            $this->types,
            $this->approvals,
            new BalanceLedger($this->balances),
            new WorkingDayCalculator($token),
            new ApproverResolver($token),
            $this->approvalPolicy,
            new EmployeeProfile($token)
        );
    }
}
