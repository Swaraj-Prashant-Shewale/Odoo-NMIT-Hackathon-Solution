<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * One monthly payroll cycle.
 *
 * The run carries the workflow state and the recomputed totals; the money for
 * each individual lives on the payslips it owns.
 */
final class PayrollRuns extends Repository
{
    protected string $table = 'payroll_runs';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'period', 'run_label', 'status', 'employee_count',
        'total_gross_minor', 'total_deductions_minor', 'total_net_minor',
        'processed_by', 'processed_at', 'approved_by', 'approved_at',
        'paid_at', 'notes',
    ];

    protected array $casts = [
        'employee_count' => 'int',
        'total_gross_minor' => 'int',
        'total_deductions_minor' => 'int',
        'total_net_minor' => 'int',
    ];

    protected bool $softDeletes = false;

    public function forPeriod(string $period): ?array
    {
        return $this->findBy('period', $period);
    }

    /**
     * Reads a run and holds its row until the surrounding transaction ends.
     *
     * Every step of the cycle reads the current status, judges it, and writes
     * the next one. Processing in particular spends seconds calling other
     * services between the two, so the status is read again under a lock
     * before anything is written: otherwise a run approved in the meantime
     * could be quietly overwritten with a fresh set of payslips.
     */
    public function lockForUpdate(string $id): ?array
    {
        $row = $this->rawOne('SELECT * FROM payroll_runs WHERE id = :id FOR UPDATE', ['id' => $id]);

        return $row === null ? null : $this->present($row);
    }

    /**
     * The most recent settled runs, oldest first.
     *
     * Only approved and paid runs are included: a draft has no meaning as a
     * cost figure, and showing one on a dashboard would misstate the trend.
     *
     * @return list<array<string, mixed>>
     */
    public function costTrend(int $months): array
    {
        $rows = $this->query()
            ->whereIn('status', ['approved', 'paid'])
            ->orderBy('period', 'desc')
            ->limit(max(1, min($months, 36)))
            ->get();

        return array_reverse(array_map([$this, 'present'], $rows));
    }
}
