<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

/**
 * One employee's statement of pay for one month.
 *
 * A payslip stays invisible to the employee until the run is published, so
 * published_at doubles as the visibility flag and as the record of when the
 * statement was actually issued.
 */
final class Payslips extends Repository
{
    protected string $table = 'payslips';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'payroll_run_id', 'employee_id', 'period',
        'payable_days', 'present_days', 'leave_days', 'lop_days',
        'gross_minor', 'total_deductions_minor', 'net_minor', 'tax_minor',
        'published_at',
    ];

    protected array $casts = [
        'payable_days' => 'float',
        'present_days' => 'float',
        'leave_days' => 'float',
        'lop_days' => 'float',
        'gross_minor' => 'int',
        'total_deductions_minor' => 'int',
        'net_minor' => 'int',
        'tax_minor' => 'int',
    ];

    protected bool $softDeletes = false;

    /** @return list<array<string, mixed>> */
    public function forRun(string $runId): array
    {
        $rows = $this->query()
            ->where('payroll_run_id', '=', $runId)
            ->orderBy('net_minor', 'desc')
            ->get();

        return array_map([$this, 'present'], $rows);
    }

    /** Clears a run's output so processing it again is not additive. */
    public function deleteForRun(string $runId): int
    {
        return $this->execute(
            'DELETE FROM payslips WHERE payroll_run_id = :run_id',
            ['run_id' => $runId]
        );
    }

    /** Makes every payslip in a run visible to its employee. */
    public function publishRun(string $runId, string $publishedAt): int
    {
        return $this->execute(
            'UPDATE payslips
                SET published_at = :published_at, updated_at = :updated_at
              WHERE payroll_run_id = :run_id
                AND published_at IS NULL',
            ['published_at' => $publishedAt, 'updated_at' => Clock::iso(), 'run_id' => $runId]
        );
    }

    /** @return array{gross: int, deductions: int, net: int, count: int} */
    public function totalsForRun(string $runId): array
    {
        $row = $this->rawOne(
            'SELECT COUNT(*)                              AS employee_count,
                    COALESCE(SUM(gross_minor), 0)         AS gross,
                    COALESCE(SUM(total_deductions_minor), 0) AS deductions,
                    COALESCE(SUM(net_minor), 0)           AS net
               FROM payslips
              WHERE payroll_run_id = :run_id',
            ['run_id' => $runId]
        ) ?? [];

        return [
            'count' => (int) ($row['employee_count'] ?? 0),
            'gross' => (int) ($row['gross'] ?? 0),
            'deductions' => (int) ($row['deductions'] ?? 0),
            'net' => (int) ($row['net'] ?? 0),
        ];
    }
}
