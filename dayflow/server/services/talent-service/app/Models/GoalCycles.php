<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class GoalCycles extends Repository
{
    protected string $table = 'goal_cycles';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'name', 'period_start', 'period_end', 'status', 'created_by',
    ];

    /**
     * The cycle a new goal should default to: the open cycle covering today,
     * falling back to the most recent open one.
     */
    public function current(string $today): ?array
    {
        // Each placeholder is named once: PDO's named-parameter rewriting is
        // only dependable when a name appears a single time in the statement.
        $row = $this->rawOne(
            'SELECT * FROM goal_cycles
             WHERE status = :status
             ORDER BY (period_start <= :on_or_before AND period_end >= :on_or_after) DESC,
                      period_start DESC
             LIMIT 1',
            ['status' => 'open', 'on_or_before' => $today, 'on_or_after' => $today]
        );

        return $row === null ? null : $this->present($row);
    }
}
