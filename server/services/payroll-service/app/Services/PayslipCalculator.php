<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Turns a salary structure plus a month's attendance into payslip lines.
 *
 * Everything is integer arithmetic on minor units. A float would drift by a
 * paisa here and there and the run totals would stop reconciling against the
 * sum of the payslips, which is the one thing finance always checks.
 */
final class PayslipCalculator
{
    public function __construct(private readonly TaxCalculator $tax)
    {
    }

    /**
     * @param array<string, mixed>            $structure  A salary_structures row.
     * @param list<array<string, mixed>>      $lines      Structure lines joined to their component.
     * @param array{working_days: float, payable_days: float} $attendance
     *
     * @return array{
     *     gross_minor: int,
     *     total_deductions_minor: int,
     *     net_minor: int,
     *     tax_minor: int,
     *     employer_cost_minor: int,
     *     lines: list<array<string, mixed>>
     * }
     */
    public function calculate(array $structure, array $lines, array $attendance, string $financialYear): array
    {
        $basic = (int) $structure['basic_monthly_minor'];
        $ctcMonthly = intdiv((int) $structure['ctc_annual_minor'], 12);

        $workingDays = (float) $attendance['working_days'];
        $payableDays = max(0.0, min((float) $attendance['payable_days'], $workingDays));

        $proratedBasic = $this->proRate($basic, $payableDays, $workingDays);

        // Withholding is projected from the contractual salary rather than
        // from this month's pro-rated figure, otherwise a single unpaid day
        // would swing the employee's annual tax estimate for the whole year.
        $annualTaxable = 0;
        foreach ($lines as $line) {
            if ($line['component_type'] !== 'earning' || $line['is_taxable'] !== true) {
                continue;
            }

            $annualTaxable += $this->contractualAmount($line, $basic, $ctcMonthly) * 12;
        }

        $monthlyTax = $this->tax->monthlyTax($annualTaxable, $financialYear);

        $computed = [];
        $gross = 0;
        $deductions = 0;
        $employerCost = 0;
        $taxCharged = 0;

        foreach ($lines as $line) {
            $type = (string) $line['component_type'];
            $calculation = (string) $line['calculation'];

            if ($calculation === 'slab') {
                $amount = $monthlyTax;
            } elseif ($type === 'earning') {
                $amount = $this->proRate(
                    $this->contractualAmount($line, $basic, $ctcMonthly),
                    $payableDays,
                    $workingDays
                );
            } else {
                // Statutory contributions follow the basic that was actually
                // paid; flat charges such as professional tax are levied in
                // full for any month in which somebody was on the payroll.
                $amount = $this->contractualAmount($line, $proratedBasic, $ctcMonthly);
            }

            $amount = max(0, $amount);

            if ($type === 'earning') {
                $gross += $amount;
            } elseif ($type === 'deduction') {
                $deductions += $amount;
                if ($calculation === 'slab') {
                    $taxCharged += $amount;
                }
            } else {
                $employerCost += $amount;
            }

            $computed[] = [
                'pay_component_id' => (string) $line['pay_component_id'],
                'component_name' => (string) $line['component_name'],
                'component_type' => $type,
                'amount_minor' => $amount,
                'display_order' => (int) ($line['display_order'] ?? 0),
            ];
        }

        return [
            'gross_minor' => $gross,
            'total_deductions_minor' => min($deductions, $gross),
            'net_minor' => $gross - min($deductions, $gross),
            'tax_minor' => $taxCharged,
            'employer_cost_minor' => $employerCost,
            'lines' => $computed,
        ];
    }

    /**
     * The full-month value of a component before any pro-rating.
     *
     * @param array<string, mixed> $line
     * @param int $basis Basic pay the percentage is measured against.
     */
    private function contractualAmount(array $line, int $basis, int $ctcMonthly): int
    {
        $percentage = $line['percentage'] ?? $line['component_percentage'] ?? null;

        return match ((string) $line['calculation']) {
            'percent_of_basic' => (int) round(($basis * (float) $percentage) / 100),
            'percent_of_ctc' => (int) round(($ctcMonthly * (float) $percentage) / 100),
            'slab' => 0,
            default => (int) $line['amount_monthly_minor'],
        };
    }

    private function proRate(int $amount, float $payableDays, float $workingDays): int
    {
        if ($workingDays <= 0.0) {
            return 0;
        }

        if ($payableDays >= $workingDays) {
            return $amount;
        }

        return (int) round(($amount * $payableDays) / $workingDays);
    }
}
