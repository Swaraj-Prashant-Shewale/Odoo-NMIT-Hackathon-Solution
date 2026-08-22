<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PayrollRuns;
use App\Models\PayslipLines;
use App\Models\Payslips;
use App\Models\SalaryStructureLines;
use App\Models\SalaryStructures;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Support\Clock;

/**
 * Calculates a whole month of payroll.
 *
 * Two phases. Everything the run needs from other services is collected
 * first, then every payslip is written inside a single transaction: if one
 * employee's figures cannot be stored, nobody's are, and the run is left
 * exactly as it was rather than half processed.
 *
 * The remote lookups deliberately sit outside that transaction. Holding row
 * locks open across a dozen HTTP calls would block every other payroll
 * statement for as long as the slowest peer takes to answer.
 */
final class PayrollProcessor
{
    public function __construct(
        private readonly PayrollRuns $runs,
        private readonly Payslips $payslips,
        private readonly PayslipLines $payslipLines,
        private readonly SalaryStructures $structures,
        private readonly SalaryStructureLines $structureLines,
        private readonly PayslipCalculator $calculator,
    ) {
    }

    /**
     * @param array<string, mixed> $run
     *
     * @return array{
     *     run: array<string, mixed>,
     *     processed: int,
     *     skipped: list<array{employee_id: string, reason: string}>,
     *     employer_cost_minor: int
     * }
     */
    public function process(
        array $run,
        EmployeeDirectory $directory,
        PayrollInputs $inputs,
        string $actorUserId,
    ): array {
        $period = (string) $run['period'];
        [$from, $to] = Period::bounds($period);

        $workingDays = CompanyCalendar::workingDaysIn($period);
        $financialYear = CompanyCalendar::financialYear($period);

        $employees = $directory->activeEmployees();

        if ($employees === []) {
            throw HttpException::unprocessable('There are no active employees to pay for this period.');
        }

        $prepared = [];
        $skipped = [];

        foreach ($employees as $employee) {
            $employeeId = (string) ($employee['id'] ?? '');

            if ($employeeId === '') {
                continue;
            }

            $structure = $this->structures->effectiveDuring($employeeId, $from, $to);

            if ($structure === null) {
                $skipped[] = ['employee_id' => $employeeId, 'reason' => 'no_salary_structure'];
                continue;
            }

            $lines = $this->structureLines->forStructure((string) $structure['id']);

            if ($lines === []) {
                $skipped[] = ['employee_id' => $employeeId, 'reason' => 'salary_structure_has_no_components'];
                continue;
            }

            $prepared[] = [
                'employee_id' => $employeeId,
                'structure' => $structure,
                'lines' => $lines,
                'attendance' => $inputs->forEmployee($employeeId, $period, $workingDays),
            ];
        }

        if ($prepared === []) {
            throw HttpException::unprocessable(
                'None of the active employees has a salary structure effective in this period.',
                ['skipped' => $skipped]
            );
        }

        return Connection::transaction(function () use ($run, $period, $prepared, $skipped, $financialYear, $actorUserId): array {
            $runId = (string) $run['id'];

            // The status was last read before a dozen HTTP calls were made, so
            // it is read again under a lock now that the writes are about to
            // happen. Without this a run approved while the figures were being
            // gathered would be silently replaced with a fresh set of payslips
            // that nobody had signed off.
            $locked = $this->runs->lockForUpdate($runId);

            if ($locked === null) {
                throw HttpException::notFound('That payroll run does not exist.');
            }

            if (!in_array((string) $locked['status'], ['draft', 'processing'], true)) {
                throw HttpException::conflict(
                    'Only a run that has not been approved can be processed.',
                    ['status' => $locked['status']]
                );
            }

            // Reprocessing replaces the previous output rather than adding to
            // it, so a correction can be made without cancelling the run.
            $this->payslips->deleteForRun($runId);

            $totalGross = 0;
            $totalDeductions = 0;
            $totalNet = 0;
            $employerCost = 0;

            foreach ($prepared as $item) {
                $result = $this->calculator->calculate(
                    $item['structure'],
                    $item['lines'],
                    $item['attendance'],
                    $financialYear
                );

                $payslip = $this->payslips->create([
                    'payroll_run_id' => $runId,
                    'employee_id' => $item['employee_id'],
                    'period' => $period,
                    'payable_days' => $item['attendance']['payable_days'],
                    'present_days' => $item['attendance']['present_days'],
                    'leave_days' => $item['attendance']['leave_days'],
                    'lop_days' => $item['attendance']['lop_days'],
                    'gross_minor' => $result['gross_minor'],
                    'total_deductions_minor' => $result['total_deductions_minor'],
                    'net_minor' => $result['net_minor'],
                    'tax_minor' => $result['tax_minor'],
                ]);

                foreach ($result['lines'] as $line) {
                    $this->payslipLines->create($line + [
                        'payslip_id' => (string) $payslip['id'],
                        'created_at' => Clock::iso(),
                    ]);
                }

                $totalGross += $result['gross_minor'];
                $totalDeductions += $result['total_deductions_minor'];
                $totalNet += $result['net_minor'];
                $employerCost += $result['employer_cost_minor'];
            }

            $updated = $this->runs->update($runId, [
                'status' => 'processing',
                'employee_count' => count($prepared),
                'total_gross_minor' => $totalGross,
                'total_deductions_minor' => $totalDeductions,
                'total_net_minor' => $totalNet,
                'processed_by' => $actorUserId,
                'processed_at' => Clock::iso(),
            ]);

            return [
                'run' => $updated ?? $run,
                'processed' => count($prepared),
                'skipped' => $skipped,
                'employer_cost_minor' => $employerCost,
            ];
        });
    }
}
