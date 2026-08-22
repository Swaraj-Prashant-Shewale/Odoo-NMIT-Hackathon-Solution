<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\LeaveAdjustments;
use App\Models\LeaveBalances;
use App\Models\LeavePolicies;
use App\Models\LeaveTypes;
use App\Policies\LeaveScope;
use App\Services\AccrualRunner;
use App\Services\BalanceLedger;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

final class LeaveBalanceController
{
    /** Corrections beyond this are a data-entry slip, not a policy decision. */
    private const MAX_ADJUSTMENT_DAYS = 365;

    private LeaveBalances $balances;
    private LeaveTypes $types;
    private LeaveAdjustments $adjustments;
    private LeavePolicies $policies;
    private BalanceLedger $ledger;

    public function __construct()
    {
        $this->balances = new LeaveBalances();
        $this->types = new LeaveTypes();
        $this->adjustments = new LeaveAdjustments();
        $this->policies = new LeavePolicies();
        $this->ledger = new BalanceLedger($this->balances);
    }

    /** GET /leave-balances */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'employee_id' => 'nullable|uuid',
            'year' => 'nullable|int|between:2000,2200',
        ])->validated();

        $principal = $request->principal();
        $self = LeaveScope::employeeId($principal);
        $employeeId = $filters['employee_id'] ?? $self;

        if ($employeeId !== $self) {
            $scope = new LeaveScope($request->bearerToken());

            if (!$scope->canView($principal, $employeeId)) {
                throw HttpException::forbidden('You cannot see that person\'s leave balances.');
            }
        }

        $year = (int) ($filters['year'] ?? Clock::now()->format('Y'));
        $summary = $this->balances->summaryFor($employeeId, $year);

        return Response::ok($summary, [
            'employee_id' => $employeeId,
            'year' => $year,
            'total_available_days' => round(array_sum(array_column($summary, 'available_days')), 2),
        ]);
    }

    /** POST /leave-balances/adjust */
    public function adjust(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'employee_id' => 'required|uuid',
            'leave_type_id' => 'required|uuid',
            'delta_days' => 'required|numeric|between:-365,365',
            'reason' => 'required|safe_text|max:500',
            'year' => 'nullable|int|between:2000,2200',
        ], [
            'delta_days' => 'Adjustment',
            'leave_type_id' => 'Leave type',
        ])->validated();

        $principal = $request->principal();
        $actorId = LeaveScope::employeeId($principal);

        $delta = round((float) $data['delta_days'], 2);

        if (abs($delta) < 0.01) {
            throw HttpException::unprocessable('An adjustment of zero days would change nothing.');
        }

        if (abs($delta) > self::MAX_ADJUSTMENT_DAYS) {
            throw HttpException::unprocessable('That adjustment is larger than a full year of leave.');
        }

        $type = $this->types->find((string) $data['leave_type_id']);

        if ($type === null) {
            throw HttpException::unprocessable('That leave type does not exist.');
        }

        $year = (int) ($data['year'] ?? Clock::now()->format('Y'));
        $employeeId = (string) $data['employee_id'];

        (new LeaveScope($request->bearerToken()))->requireEmployee($employeeId);

        $result = Connection::transaction(function () use ($employeeId, $type, $year, $delta, $data, $actorId): array {
            $balance = $this->ledger->ensure($employeeId, $type, $year);
            $before = $this->balances->find((string) $balance['id']);

            // A negative correction that would drive the balance below what has
            // already been taken cannot be honoured: the days are spent.
            $available = $this->ledger->availableForUpdate((string) $balance['id']);

            if ($available + $delta < -0.001) {
                throw HttpException::unprocessable(
                    'That reduction would take the balance below zero.',
                    ['available_days' => $available, 'delta_days' => $delta]
                );
            }

            $this->ledger->adjust((string) $balance['id'], $delta);

            $adjustment = $this->adjustments->create([
                'employee_id' => $employeeId,
                'leave_type_id' => $type['id'],
                'year' => $year,
                'delta_days' => $delta,
                'reason' => $data['reason'],
                'adjusted_by' => $actorId,
            ]);

            EventPublisher::publish('leave.balance.adjusted', [
                'employee_id' => $employeeId,
                'leave_type_id' => $type['id'],
                'delta_days' => $delta,
                'reason' => $data['reason'],
            ]);

            return [
                'before' => $before ?? [],
                'adjustment' => $adjustment,
                'balance' => $this->balances->find((string) $balance['id']) ?? [],
            ];
        });

        AuditLog::record(
            $request,
            'leave.balance.adjusted',
            'leave_balance',
            (string) ($result['balance']['id'] ?? ''),
            $result['before'],
            $result['balance'],
            ['reason' => $data['reason'], 'delta_days' => $delta]
        );

        return Response::created([
            'adjustment' => $result['adjustment'],
            'balance' => $result['balance'],
        ]);
    }

    /** POST /leave-balances/accrue */
    public function accrue(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'period' => 'nullable|string|max:7',
        ], ['period' => 'Accrual period'])->validated();

        $period = (string) ($data['period'] ?? Clock::now()->format('Y-m'));

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw HttpException::unprocessable('The accrual period must be a month in YYYY-MM format.');
        }

        // Crediting a month that has not happened yet would hand out days the
        // company has not earned the obligation for.
        if ($period > Clock::now()->format('Y-m')) {
            throw HttpException::unprocessable('That accrual period is in the future.');
        }

        $runner = new AccrualRunner($this->types, $this->policies, $this->ledger);
        $summary = $runner->run($period, $request->bearerToken());

        AuditLog::record($request, 'leave.accrual.run', 'leave_balance', null, [], $summary);

        return Response::ok($summary);
    }

    /** GET /leave-balances/adjustments */
    public function adjustmentHistory(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'employee_id' => 'nullable|uuid',
            'year' => 'nullable|int|between:2000,2200',
        ])->validated();

        $principal = $request->principal();
        $self = LeaveScope::employeeId($principal);
        $employeeId = $filters['employee_id'] ?? $self;

        if ($employeeId !== $self) {
            $scope = new LeaveScope($request->bearerToken());

            if (!$scope->canView($principal, $employeeId)) {
                throw HttpException::forbidden('You cannot see that person\'s balance history.');
            }
        }

        $year = (int) ($filters['year'] ?? Clock::now()->format('Y'));

        return Response::ok(
            $this->adjustments->history($employeeId, $year),
            ['employee_id' => $employeeId, 'year' => $year]
        );
    }
}
