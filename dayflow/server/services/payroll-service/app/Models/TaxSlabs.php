<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * Income tax bands for a regime and financial year.
 *
 * Keeping the bands in a table rather than in code means a budget change is a
 * data change: last year's payslips continue to recompute against last year's
 * bands, which is what makes a historical payslip reproducible.
 */
final class TaxSlabs extends Repository
{
    protected string $table = 'tax_slabs';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'regime', 'financial_year', 'lower_minor', 'upper_minor',
        'rate', 'surcharge', 'created_at',
    ];

    protected array $casts = [
        'lower_minor' => 'int',
        'upper_minor' => 'int',
        'rate' => 'float',
        'surcharge' => 'float',
    ];

    // The row is immutable once written, so there is no updated_at to maintain.
    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    /** @return list<array<string, mixed>> Bands in ascending order of income. */
    public function forYear(string $financialYear, string $regime = 'new'): array
    {
        $rows = $this->query()
            ->where('financial_year', '=', $financialYear)
            ->where('regime', '=', $regime)
            ->orderBy('lower_minor')
            ->get();

        return array_map([$this, 'present'], $rows);
    }

    /**
     * The closest published year to one that has no table of its own.
     *
     * The most recent earlier year is preferred, because that is the table a
     * payroll office keeps charging against until the new budget is loaded.
     * Only when nothing earlier exists does the earliest later year stand in.
     */
    public function nearestYear(string $financialYear, string $regime = 'new'): ?string
    {
        $earlier = $this->rawOne(
            'SELECT financial_year FROM tax_slabs
              WHERE regime = :regime AND financial_year <= :year
              ORDER BY financial_year DESC
              LIMIT 1',
            ['regime' => $regime, 'year' => $financialYear]
        );

        if ($earlier !== null) {
            return (string) $earlier['financial_year'];
        }

        $later = $this->rawOne(
            'SELECT financial_year FROM tax_slabs
              WHERE regime = :regime AND financial_year > :year
              ORDER BY financial_year ASC
              LIMIT 1',
            ['regime' => $regime, 'year' => $financialYear]
        );

        return $later === null ? null : (string) $later['financial_year'];
    }
}
