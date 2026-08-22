<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TaxSlabs;

/**
 * Income tax withheld at source, computed from the stored slab table.
 *
 * The engine is entirely table driven. Rates, bands and surcharge come from
 * tax_slabs for the financial year being paid, so a budget change is a data
 * change and a payslip from an earlier year still recomputes to the figure
 * that was actually deducted.
 */
final class TaxCalculator
{
    /** Flat deduction every salaried taxpayer receives (75,000 in minor units). */
    private const STANDARD_DEDUCTION_MINOR = 7_500_000;

    /** Taxable income at or below 12,00,000 attracts a full rebate, so tax is nil. */
    private const REBATE_CEILING_MINOR = 120_000_000;

    /** Health and education cess, charged on tax plus surcharge. */
    private const CESS_RATE = 4.0;

    /** @var array<string, list<array<string, mixed>>> */
    private array $bands = [];

    public function __construct(private readonly TaxSlabs $slabs)
    {
    }

    /**
     * Tax due for a whole year on a projected salary income.
     *
     * @param int $annualIncomeMinor Taxable earnings for the year, in paise.
     */
    public function annualTax(int $annualIncomeMinor, string $financialYear, string $regime = 'new'): int
    {
        $taxable = max(0, $annualIncomeMinor - self::STANDARD_DEDUCTION_MINOR);

        if ($taxable <= self::REBATE_CEILING_MINOR) {
            return 0;
        }

        $bands = $this->bandsFor($financialYear, $regime);

        if ($bands === []) {
            return 0;
        }

        $tax = 0.0;

        foreach ($bands as $band) {
            $lower = (int) $band['lower_minor'];

            if ($taxable <= $lower) {
                break;
            }

            $ceiling = $band['upper_minor'] === null ? $taxable : min($taxable, (int) $band['upper_minor']);
            $slice = $ceiling - $lower;

            if ($slice <= 0) {
                continue;
            }

            $bandTax = ($slice * (float) $band['rate']) / 100;

            // Surcharge is stored against the band it applies to, so it is
            // charged on the tax arising within that band rather than on the
            // whole liability.
            $tax += $bandTax + (($bandTax * (float) $band['surcharge']) / 100);
        }

        $tax += ($tax * self::CESS_RATE) / 100;

        return (int) round($tax);
    }

    /** Even monthly withholding: the annual liability spread across twelve months. */
    public function monthlyTax(int $annualIncomeMinor, string $financialYear, string $regime = 'new'): int
    {
        return intdiv($this->annualTax($annualIncomeMinor, $financialYear, $regime), 12);
    }

    /** @return list<array<string, mixed>> */
    private function bandsFor(string $financialYear, string $regime): array
    {
        $key = $regime . '|' . $financialYear;

        if (array_key_exists($key, $this->bands)) {
            return $this->bands[$key];
        }

        $bands = $this->slabs->forYear($financialYear, $regime);

        if ($bands === []) {
            // A year whose table has not been loaded yet must not quietly
            // deduct nothing: a payslip showing no income tax at all is a
            // mistake somebody has to repay later. The nearest published table
            // stands in, which is what a payroll office does while it waits
            // for the new rates.
            $nearest = $this->slabs->nearestYear($financialYear, $regime);
            $bands = $nearest === null ? [] : $this->slabs->forYear($nearest, $regime);
        }

        return $this->bands[$key] = $bands;
    }
}
