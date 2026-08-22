<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\MetricSnapshots;
use App\Policies\AnalyticsScope;
use App\Services\Downstream;
use App\Services\EmployeeDirectory;
use App\Services\Insights;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\Validator;

/**
 * Charts and trends.
 *
 * The attendance and leave analyses serve two different audiences - a manager
 * looking at their team and an HR officer looking at the organisation - so
 * their routes are ->authenticated() and the width of the answer is resolved
 * here from the caller's permissions. The three organisation-only analyses are
 * governed by a single permission each and say so on the route.
 */
final class AnalyticsController
{
    private MetricSnapshots $snapshots;

    public function __construct()
    {
        $this->snapshots = new MetricSnapshots();
    }

    public function attendance(Request $request): Response
    {
        $principal = $request->principal();
        $filters = $this->filters($request);

        $scope = AnalyticsScope::forReporting($principal);
        $filters = AnalyticsScope::applyDepartment($principal, $scope, $filters);

        $groupBy = (string) ($filters['group_by'] ?? 'day');

        return Response::ok($this->insights($request)->attendance(
            $filters,
            AnalyticsScope::filterFor($principal, $scope),
            $groupBy
        ));
    }

    public function leave(Request $request): Response
    {
        $principal = $request->principal();
        $filters = $this->filters($request);

        $scope = AnalyticsScope::forReporting($principal);
        $filters = AnalyticsScope::applyDepartment($principal, $scope, $filters);

        return Response::ok($this->insights($request)->leave(
            $filters,
            AnalyticsScope::filterFor($principal, $scope)
        ));
    }

    public function headcount(Request $request): Response
    {
        return Response::ok($this->insights($request)->headcount($this->filters($request)));
    }

    /**
     * Payroll cost trends.
     *
     * The route requires payroll.view.all, and the analysis itself only ever
     * asks for run-level totals, so no individual salary can appear here even
     * if the payroll service were to return one.
     */
    public function payroll(Request $request): Response
    {
        return Response::ok($this->insights($request)->payroll($this->filters($request)));
    }

    public function learning(Request $request): Response
    {
        return Response::ok($this->insights($request)->learning($this->filters($request)));
    }

    /**
     * The validated query filters every analysis accepts.
     *
     * Nothing reaches a downstream call or a date calculation without passing
     * through here first, and anything not declared is discarded.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $data = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'department_id' => 'nullable|uuid',
            'group_by' => 'nullable|in:day,week,month,department',
        ], [
            'from' => 'Start date',
            'to' => 'End date',
            'group_by' => 'Grouping',
        ])->validated();

        return array_filter($data, static fn (mixed $value): bool => $value !== null);
    }

    private function insights(Request $request): Insights
    {
        $downstream = new Downstream($request->bearerToken());

        return new Insights(
            $downstream,
            new EmployeeDirectory($downstream),
            $this->snapshots,
            $request->principal()
        );
    }
}
