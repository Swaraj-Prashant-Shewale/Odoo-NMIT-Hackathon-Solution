<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Database\QueryBuilder;

/**
 * The individual amounts that make up a payslip.
 *
 * The component name and type are denormalised onto the line deliberately: a
 * payslip is a statement of what was paid on a particular day and must not
 * change its wording because the component catalogue was edited afterwards.
 */
final class PayslipLines extends Repository
{
    protected string $table = 'payslip_lines';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'payslip_id', 'pay_component_id', 'component_name',
        'component_type', 'amount_minor', 'display_order', 'created_at',
    ];

    protected array $casts = [
        'amount_minor' => 'int',
        'display_order' => 'int',
    ];

    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    /** @return list<array<string, mixed>> */
    public function forPayslip(string $payslipId): array
    {
        $rows = $this->query()
            ->where('payslip_id', '=', $payslipId)
            ->orderBy('display_order')
            ->orderBy('component_name')
            ->get();

        return array_map([$this, 'present'], $rows);
    }

    /**
     * Employer-borne cost per period, for the payroll cost trend.
     *
     * Employer contributions never touch an employee's net pay, so they are
     * excluded from the run totals and reported separately here.
     *
     * @param list<string> $periods
     * @return array<string, int> Period => employer cost in minor units.
     */
    public function employerCostByPeriod(array $periods): array
    {
        if ($periods === []) {
            return [];
        }

        $rows = QueryBuilder::table('payslip_lines')
            ->join('payslips', 'payslip_lines.payslip_id', '=', 'payslips.id')
            ->select('payslips.period')
            ->selectRaw('COALESCE(SUM("payslip_lines"."amount_minor"), 0) AS employer_cost_minor')
            ->where('payslip_lines.component_type', '=', 'employer_contribution')
            ->whereIn('payslips.period', $periods)
            ->groupBy('payslips.period')
            ->get();

        $costs = [];
        foreach ($rows as $row) {
            $costs[(string) $row['period']] = (int) $row['employer_cost_minor'];
        }

        return $costs;
    }
}
