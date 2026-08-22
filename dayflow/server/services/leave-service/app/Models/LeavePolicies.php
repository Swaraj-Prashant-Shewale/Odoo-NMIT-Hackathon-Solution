<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class LeavePolicies extends Repository
{
    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract', 'intern', 'consultant'];

    protected string $table = 'leave_policies';

    protected array $fillable = [
        'leave_type_id', 'employment_type', 'applies_after_months',
        'quota_override_days', 'effective_from', 'effective_to',
    ];

    protected array $casts = [
        'applies_after_months' => 'int',
        'quota_override_days' => 'float',
    ];

    // Policies are dated rather than versioned, so there is nothing to update.
    protected bool $timestamps = false;

    /**
     * Every policy in force on a date, indexed by "type id|employment type".
     *
     * The accrual run needs the whole table at once; fetching it in one query
     * keeps a run over hundreds of employees to a single round trip.
     *
     * @return array<string, array<string, mixed>>
     */
    public function inForceIndex(string $onDate): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT ON (leave_type_id, employment_type) *
            FROM leave_policies
            WHERE effective_from <= CAST(:on_date AS date)
              AND (effective_to IS NULL OR effective_to >= CAST(:on_date_end AS date))
            ORDER BY leave_type_id, employment_type, effective_from DESC
        SQL;

        $index = [];

        foreach ($this->raw($sql, ['on_date' => $onDate, 'on_date_end' => $onDate]) as $row) {
            $policy = $this->present($row);
            $index[$policy['leave_type_id'] . '|' . $policy['employment_type']] = $policy;
        }

        return $index;
    }
}
