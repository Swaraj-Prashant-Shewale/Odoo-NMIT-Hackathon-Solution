<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class Shifts extends Repository
{
    protected string $table = 'shifts';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'name', 'code', 'starts_at', 'ends_at', 'break_minutes',
        'full_day_hours', 'half_day_hours', 'grace_minutes',
        'is_night_shift', 'working_days', 'is_active',
    ];

    protected array $casts = [
        'break_minutes' => 'int',
        'grace_minutes' => 'int',
        'full_day_hours' => 'float',
        'half_day_hours' => 'float',
        'is_night_shift' => 'bool',
        'is_active' => 'bool',
        'working_days' => 'json',
    ];

    protected bool $softDeletes = false;

    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        $rows = $this->query()->where('is_active', '=', true)->orderBy('starts_at', 'asc')->get();

        return array_map([$this, 'present'], $rows);
    }

    public function byCode(string $code): ?array
    {
        return $this->findBy('code', strtoupper($code));
    }

    /**
     * The shift applied when an employee has neither a roster nor an
     * assignment, so attendance can still be measured against something.
     */
    public function fallback(): ?array
    {
        $row = $this->query()
            ->where('is_active', '=', true)
            ->where('is_night_shift', '=', false)
            ->orderBy('created_at', 'asc')
            ->first();

        return $row === null ? null : $this->present($row);
    }

    /** True while any assignment, roster entry or attendance row still points at the shift. */
    public function isInUse(string $shiftId): bool
    {
        $row = $this->rawOne(
            'SELECT
                 (SELECT COUNT(*) FROM shift_assignments  WHERE shift_id = :a) AS assignments,
                 (SELECT COUNT(*) FROM rosters            WHERE shift_id = :b) AS rosters,
                 (SELECT COUNT(*) FROM attendance_records WHERE shift_id = :c) AS records',
            ['a' => $shiftId, 'b' => $shiftId, 'c' => $shiftId]
        );

        return ((int) ($row['assignments'] ?? 0) + (int) ($row['rosters'] ?? 0) + (int) ($row['records'] ?? 0)) > 0;
    }

    public function present(array $row): array
    {
        $row = parent::present($row);

        // TIME columns come back as HH:MM:SS; the platform renders times of day
        // as HH:MM everywhere, so the trimming happens once, here.
        foreach (['starts_at', 'ends_at'] as $column) {
            if (isset($row[$column]) && is_string($row[$column])) {
                $row[$column] = substr($row[$column], 0, 5);
            }
        }

        return $row;
    }
}
