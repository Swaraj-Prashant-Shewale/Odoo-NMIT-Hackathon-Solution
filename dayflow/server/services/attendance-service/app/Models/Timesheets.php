<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\TimeFormat;
use Dayflow\Kernel\Database\Repository;

final class Timesheets extends Repository
{
    protected string $table = 'timesheets';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'employee_id', 'work_date', 'project_code', 'task_description',
        'hours', 'billable', 'approved_by', 'approved_at', 'status',
    ];

    protected array $casts = [
        'hours' => 'float',
        'billable' => 'bool',
    ];

    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    /**
     * Records a decision, but only while the entry is still awaiting one.
     *
     * The expected status is part of the WHERE clause rather than only of a
     * preceding read, so two approvers deciding the same entry at the same
     * moment cannot both succeed: the loser matches no row and is told the
     * entry has already been settled.
     */
    public function decideIfSubmitted(string $id, string $status, string $approvedBy, string $approvedAt): ?array
    {
        $row = $this->rawOne(
            "UPDATE timesheets
             SET status = :status,
                 approved_by = CAST(:approved_by AS UUID),
                 approved_at = CAST(:approved_at AS TIMESTAMPTZ)
             WHERE id = CAST(:id AS UUID) AND status = 'submitted'
             RETURNING *",
            ['status' => $status, 'approved_by' => $approvedBy, 'approved_at' => $approvedAt, 'id' => $id]
        );

        return $row === null ? null : $this->present($row);
    }

    /** Hours already logged on a day, so a second entry cannot push it past 24. */
    public function hoursLoggedOn(string $employeeId, string $workDate, ?string $ignoreId = null): float
    {
        $row = $this->rawOne(
            'SELECT COALESCE(SUM(hours), 0) AS total
             FROM timesheets
             WHERE employee_id = :employee_id
               AND work_date = CAST(:work_date AS DATE)
               AND (CAST(:ignore_id AS UUID) IS NULL OR id <> CAST(:ignore_id2 AS UUID))',
            [
                'employee_id' => $employeeId,
                'work_date' => $workDate,
                'ignore_id' => $ignoreId,
                'ignore_id2' => $ignoreId,
            ]
        );

        return (float) ($row['total'] ?? 0);
    }

    /**
     * Logged effort grouped by project code.
     *
     * @param list<string>|null $employeeIds Null means every employee.
     * @return list<array<string, mixed>>
     */
    public function projectSummary(string $from, string $to, ?array $employeeIds): array
    {
        $sql = "SELECT project_code,
                       SUM(hours)                                       AS total_hours,
                       SUM(hours) FILTER (WHERE billable)               AS billable_hours,
                       SUM(hours) FILTER (WHERE status = 'approved')    AS approved_hours,
                       COUNT(DISTINCT employee_id)::INTEGER             AS contributors,
                       COUNT(*)::INTEGER                                AS entries
                FROM timesheets
                WHERE work_date BETWEEN CAST(:from_date AS DATE) AND CAST(:to_date AS DATE)";

        $bindings = ['from_date' => $from, 'to_date' => $to];

        if ($employeeIds !== null) {
            if ($employeeIds === []) {
                return [];
            }

            $placeholders = [];
            foreach (array_values($employeeIds) as $index => $employeeId) {
                $key = 'employee' . $index;
                $placeholders[] = ':' . $key;
                $bindings[$key] = $employeeId;
            }

            $sql .= ' AND employee_id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' GROUP BY project_code ORDER BY total_hours DESC';

        return array_map(
            static fn (array $row): array => [
                'project_code' => (string) $row['project_code'],
                'total_hours' => round((float) $row['total_hours'], 2),
                'billable_hours' => round((float) ($row['billable_hours'] ?? 0), 2),
                'approved_hours' => round((float) ($row['approved_hours'] ?? 0), 2),
                'contributors' => (int) $row['contributors'],
                'entries' => (int) $row['entries'],
            ],
            $this->raw($sql, $bindings)
        );
    }

    public function present(array $row): array
    {
        $row = parent::present($row);

        if (array_key_exists('approved_at', $row)) {
            $row['approved_at'] = TimeFormat::local($row['approved_at'] === null ? null : (string) $row['approved_at']);
        }

        return $row;
    }
}
