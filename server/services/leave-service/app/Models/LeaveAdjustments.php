<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class LeaveAdjustments extends Repository
{
    protected string $table = 'leave_adjustments';

    protected array $fillable = [
        'employee_id', 'leave_type_id', 'year', 'delta_days', 'reason', 'adjusted_by',
    ];

    protected array $casts = [
        'year' => 'int',
        'delta_days' => 'float',
    ];

    // Corrections are historical facts, so nothing here is ever updated.
    protected bool $timestamps = false;

    /** @return list<array<string, mixed>> */
    public function history(string $employeeId, int $year): array
    {
        $rows = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->where('year', '=', $year)
            ->orderBy('created_at', 'desc')
            ->get();

        return array_map([$this, 'present'], $rows);
    }
}
