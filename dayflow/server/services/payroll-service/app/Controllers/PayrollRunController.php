<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PayrollRuns;
use App\Models\PayslipLines;
use App\Models\Payslips;
use App\Models\SalaryStructureLines;
use App\Models\SalaryStructures;
use App\Models\TaxSlabs;
use App\Services\CompanyCalendar;
use App\Services\EmployeeDirectory;
use App\Services\Money;
use App\Services\PayrollInputs;
use App\Services\PayrollProcessor;
use App\Services\PayslipCalculator;
use App\Services\Period;
use App\Services\RouteInput;
use App\Services\TaxCalculator;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/** The monthly payroll cycle: create, process, approve, publish. */
final class PayrollRunController
{
    /** How many months of history the dashboard trend covers. */
    private const TREND_MONTHS = 12;

    private PayrollRuns $runs;

    private Payslips $payslips;

    private PayslipLines $payslipLines;

    public function __construct()
    {
        $this->runs = new PayrollRuns();
        $this->payslips = new Payslips();
        $this->payslipLines = new PayslipLines();
    }

    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'status' => 'nullable|in:draft,processing,approved,paid,cancelled',
        ])->validated();

        $builder = $this->runs->query();

        if (isset($filters['status'])) {
            $builder->where('status', '=', $filters['status']);
        }

        $builder->orderBy('period', 'desc');

        return Response::page($this->runs->paginate($builder, $request->page(), $request->perPage()));
    }

    public function show(Request $request): Response
    {
        $run = $this->requireRun($request);

        return Response::ok($run + [
            'period_label' => Period::label((string) $run['period']),
            'currency' => Money::currencyCode(),
            'working_days' => CompanyCalendar::workingDaysIn((string) $run['period']),
            'payslips' => $this->payslips->forRun((string) $run['id']),
        ]);
    }

    /** Opens a draft run for a month. */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'period' => 'required|string|max:7',
            'run_label' => 'nullable|safe_text|max:120',
            'notes' => 'nullable|safe_text|max:1000',
        ])->validated();

        $period = Period::normalise((string) $data['period']);

        if (Period::isFuture($period)) {
            throw HttpException::unprocessable('A payroll run cannot be opened for a month that has not started.');
        }

        if ($this->runs->forPeriod($period) !== null) {
            throw HttpException::conflict(
                'A payroll run already exists for this period.',
                ['period' => $period]
            );
        }

        $run = $this->runs->create([
            'period' => $period,
            'run_label' => $data['run_label'] ?? sprintf('%s payroll', Period::label($period)),
            'status' => 'draft',
            'notes' => $data['notes'] ?? null,
        ]);

        AuditLog::record($request, 'payroll.run.created', 'payroll_run', (string) $run['id'], [], $run);

        return Response::created($run);
    }

    /** Calculates every payslip in the run. */
    public function process(Request $request): Response
    {
        $run = $this->requireRun($request);

        if (!in_array((string) $run['status'], ['draft', 'processing'], true)) {
            throw HttpException::conflict(
                'Only a run that has not been approved can be processed.',
                ['status' => $run['status']]
            );
        }

        $token = $request->bearerToken();

        $outcome = $this->processor()->process(
            $run,
            new EmployeeDirectory($token),
            new PayrollInputs($token),
            $request->principal()->userId
        );

        AuditLog::record(
            $request,
            'payroll.run.processed',
            'payroll_run',
            (string) $run['id'],
            $run,
            $outcome['run'],
            ['skipped' => $outcome['skipped']]
        );

        return Response::ok($outcome['run'] + [
            'processed_employees' => $outcome['processed'],
            'skipped' => $outcome['skipped'],
            'employer_cost_minor' => $outcome['employer_cost_minor'],
        ]);
    }

    /** Signs the run off. Never by the same person who processed it. */
    public function approve(Request $request): Response
    {
        $run = $this->requireRun($request);
        $principal = $request->principal();

        // Everything here is decided under a row lock. Two people signing
        // the same run off together would otherwise both pass the status check
        // and both be recorded as the approver, and the run would announce
        // itself twice.
        $outcome = Connection::transaction(function () use ($run, $principal): array {
            $current = $this->lockRun((string) $run['id']);

            if (!in_array((string) $current['status'], ['draft', 'processing'], true)) {
                throw HttpException::conflict(
                    'This run has already been approved.',
                    ['status' => $current['status']]
                );
            }

            $totals = $this->payslips->totalsForRun((string) $current['id']);

            if ($totals['count'] === 0) {
                throw HttpException::conflict('Process the run before approving it: it has no payslips.');
            }

            // Separation of duties. Whoever produced the figures does not also
            // get to authorise paying them out.
            if ($current['processed_by'] !== null && hash_equals((string) $current['processed_by'], $principal->userId)) {
                throw HttpException::forbidden('A payroll run must be approved by someone other than the person who processed it.');
            }

            $updated = $this->runs->update((string) $current['id'], [
                'status' => 'approved',
                'employee_count' => $totals['count'],
                'total_gross_minor' => $totals['gross'],
                'total_deductions_minor' => $totals['deductions'],
                'total_net_minor' => $totals['net'],
                'approved_by' => $principal->userId,
                'approved_at' => Clock::iso(),
            ]) ?? $current;

            return ['run' => $updated, 'totals' => $totals];
        });

        $updated = $outcome['run'];

        AuditLog::record($request, 'payroll.run.approved', 'payroll_run', (string) $run['id'], $run, $updated);

        EventPublisher::publish('payroll.run.approved', [
            'run_id' => (string) $run['id'],
            'period' => (string) $run['period'],
            'employee_count' => $outcome['totals']['count'],
            'total_net_minor' => $outcome['totals']['net'],
        ]);

        return Response::ok($updated);
    }

    /** Releases the payslips to employees and closes the run. */
    public function publish(Request $request): Response
    {
        $run = $this->requireRun($request);
        $publishedAt = Clock::iso();

        // Releasing the payslips and closing the run are one change, not two.
        // Split apart, a failure between them would leave every statement
        // visible while the run still claimed to be awaiting payment.
        $outcome = Connection::transaction(function () use ($run, $publishedAt): array {
            $current = $this->lockRun((string) $run['id']);

            if ((string) $current['status'] !== 'approved') {
                throw HttpException::conflict(
                    'Only an approved run can be published.',
                    ['status' => $current['status']]
                );
            }

            $this->payslips->publishRun((string) $current['id'], $publishedAt);

            $updated = $this->runs->update((string) $current['id'], [
                'status' => 'paid',
                'paid_at' => $publishedAt,
            ]) ?? $current;

            return ['run' => $updated, 'payslips' => $this->payslips->forRun((string) $current['id'])];
        });

        $updated = $outcome['run'];
        $payslips = $outcome['payslips'];

        foreach ($payslips as $payslip) {
            EventPublisher::publish('payroll.payslip.published', [
                'employee_id' => (string) $payslip['employee_id'],
                'payslip_id' => (string) $payslip['id'],
                'period' => (string) $payslip['period'],
            ]);
        }

        AuditLog::record(
            $request,
            'payroll.run.published',
            'payroll_run',
            (string) $run['id'],
            $run,
            $updated,
            ['payslips' => count($payslips)]
        );

        return Response::ok($updated + ['published_payslips' => count($payslips)]);
    }

    /** Monthly cost trend for the payroll dashboard. */
    public function summary(Request $request): Response
    {
        $trend = $this->runs->costTrend(self::TREND_MONTHS);

        $periods = array_map(static fn (array $run): string => (string) $run['period'], $trend);
        $employerCosts = $this->payslipLines->employerCostByPeriod($periods);

        $rows = [];
        $grossTotal = 0;
        $netTotal = 0;
        $employerTotal = 0;

        foreach ($trend as $run) {
            $period = (string) $run['period'];
            $employerCost = $employerCosts[$period] ?? 0;

            $grossTotal += (int) $run['total_gross_minor'];
            $netTotal += (int) $run['total_net_minor'];
            $employerTotal += $employerCost;

            $rows[] = [
                'period' => $period,
                'period_label' => Period::label($period),
                'status' => (string) $run['status'],
                'employee_count' => (int) $run['employee_count'],
                'total_gross_minor' => (int) $run['total_gross_minor'],
                'total_deductions_minor' => (int) $run['total_deductions_minor'],
                'total_net_minor' => (int) $run['total_net_minor'],
                'employer_cost_minor' => $employerCost,
                'total_cost_minor' => (int) $run['total_gross_minor'] + $employerCost,
            ];
        }

        $latest = $rows === [] ? null : $rows[count($rows) - 1];

        return Response::ok([
            'currency' => Money::currencyCode(),
            'trend' => $rows,
            'latest' => $latest,
            'totals' => [
                'months' => count($rows),
                'gross_minor' => $grossTotal,
                'net_minor' => $netTotal,
                'employer_cost_minor' => $employerTotal,
                'average_monthly_cost_minor' => $rows === []
                    ? 0
                    : intdiv($grossTotal + $employerTotal, count($rows)),
            ],
            'pipeline' => $this->pipeline(),
        ]);
    }

    /** @return array<string, mixed> */
    private function pipeline(): array
    {
        $open = $this->runs->query()
            ->whereIn('status', ['draft', 'processing', 'approved'])
            ->orderBy('period', 'desc')
            ->get();

        return [
            'open_runs' => array_map([$this->runs, 'present'], $open),
            'current_period' => Period::current(),
            'current_period_has_run' => $this->runs->forPeriod(Period::current()) !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function requireRun(Request $request): array
    {
        $run = $this->runs->find(RouteInput::uuid($request));

        if ($run === null) {
            throw HttpException::notFound('That payroll run does not exist.');
        }

        return $run;
    }

    /**
     * Re-reads a run under a row lock held for the rest of the transaction.
     *
     * @return array<string, mixed>
     */
    private function lockRun(string $id): array
    {
        $run = $this->runs->lockForUpdate($id);

        if ($run === null) {
            throw HttpException::notFound('That payroll run does not exist.');
        }

        return $run;
    }

    private function processor(): PayrollProcessor
    {
        return new PayrollProcessor(
            $this->runs,
            $this->payslips,
            $this->payslipLines,
            new SalaryStructures(),
            new SalaryStructureLines(),
            new PayslipCalculator(new TaxCalculator(new TaxSlabs()))
        );
    }
}
