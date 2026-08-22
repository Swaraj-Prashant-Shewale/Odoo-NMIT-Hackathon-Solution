<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class Goals extends Repository
{
    protected string $table = 'goals';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'employee_id', 'goal_cycle_id', 'title', 'description', 'category',
        'metric', 'target_value', 'current_value', 'unit', 'weight_percent',
        'status', 'progress_percent', 'starts_on', 'due_on', 'completed_at', 'created_by',
    ];

    protected array $casts = [
        'target_value' => 'float',
        'current_value' => 'float',
        'weight_percent' => 'float',
        'progress_percent' => 'float',
    ];

    /**
     * Weight already committed by an employee inside one cycle.
     *
     * An achieved or missed goal keeps its weight, because the weight is what
     * its result counts for at appraisal time. Only cancelling a goal returns
     * its share of the 100% available.
     */
    public function committedWeight(string $employeeId, string $cycleId, ?string $excludeGoalId = null): float
    {
        $sql = 'SELECT COALESCE(SUM(weight_percent), 0) AS total
                FROM goals
                WHERE employee_id = :employee_id
                  AND goal_cycle_id = :goal_cycle_id
                  AND status <> :cancelled';

        $bindings = [
            'employee_id' => $employeeId,
            'goal_cycle_id' => $cycleId,
            'cancelled' => 'cancelled',
        ];

        if ($excludeGoalId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $bindings['exclude_id'] = $excludeGoalId;
        }

        return (float) ($this->rawOne($sql, $bindings)['total'] ?? 0);
    }

    /**
     * A compact progress roll-up for one employee, optionally within a cycle.
     *
     * @return array{total: int, active: int, achieved: int, at_risk: int, average_progress: float, weight_percent: float}
     */
    public function summaryFor(string $employeeId, ?string $cycleId, string $today): array
    {
        $sql = 'SELECT
                    COUNT(*)                                                        AS total,
                    COUNT(*) FILTER (WHERE status = :active)                         AS active,
                    COUNT(*) FILTER (WHERE status = :achieved)                       AS achieved,
                    COUNT(*) FILTER (WHERE status = :at_risk_status
                                       AND due_on IS NOT NULL
                                       AND due_on < :today
                                       AND progress_percent < 100)                   AS at_risk,
                    COALESCE(ROUND(AVG(progress_percent), 2), 0)                      AS average_progress,
                    COALESCE(SUM(weight_percent) FILTER (WHERE status <> :cancelled), 0) AS weight_percent
                FROM goals
                WHERE employee_id = :employee_id';

        $bindings = [
            'active' => 'active',
            'achieved' => 'achieved',
            'at_risk_status' => 'active',
            'today' => $today,
            'cancelled' => 'cancelled',
            'employee_id' => $employeeId,
        ];

        if ($cycleId !== null) {
            $sql .= ' AND goal_cycle_id = :goal_cycle_id';
            $bindings['goal_cycle_id'] = $cycleId;
        }

        $row = $this->rawOne($sql, $bindings) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'achieved' => (int) ($row['achieved'] ?? 0),
            'at_risk' => (int) ($row['at_risk'] ?? 0),
            'average_progress' => (float) ($row['average_progress'] ?? 0),
            'weight_percent' => (float) ($row['weight_percent'] ?? 0),
        ];
    }
}
