<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

final class GoalUpdates extends Repository
{
    protected string $table = 'goal_updates';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'goal_id', 'employee_id', 'progress_percent', 'note', 'created_by', 'created_at',
    ];

    protected array $casts = ['progress_percent' => 'float'];

    /** The table is an append-only log, so it carries created_at and nothing else. */
    protected bool $timestamps = false;

    public function create(array $attributes): array
    {
        return parent::create($attributes + ['created_at' => Clock::iso()]);
    }

    /** @return list<array<string, mixed>> */
    public function history(string $goalId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));

        // The limit is an integer bounded above, never a caller's string, so
        // it is placed into the statement the same way the kernel's query
        // builder does it.
        $rows = $this->raw(
            sprintf('SELECT * FROM goal_updates WHERE goal_id = :goal_id ORDER BY created_at DESC LIMIT %d', $limit),
            ['goal_id' => $goalId]
        );

        return array_map([$this, 'present'], $rows);
    }
}
