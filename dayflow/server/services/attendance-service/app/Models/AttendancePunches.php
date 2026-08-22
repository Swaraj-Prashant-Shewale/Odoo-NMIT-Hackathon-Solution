<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\TimeFormat;
use Dayflow\Kernel\Database\Repository;

/**
 * The punch trail.
 *
 * Rows are only ever inserted. A correction is expressed on the daily rollup
 * or as a regularisation, so the raw record of what the clock saw survives any
 * later amendment.
 */
final class AttendancePunches extends Repository
{
    protected string $table = 'attendance_punches';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'attendance_record_id', 'employee_id', 'punched_at', 'direction',
        'ip_address', 'user_agent', 'source', 'latitude', 'longitude',
    ];

    protected array $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    // The trail keeps the raw agent string for forensics, but it has no place
    // in an API payload that a colleague could read.
    protected array $hidden = ['user_agent'];

    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    /** @return list<array<string, mixed>> */
    public function forRecord(string $recordId): array
    {
        $rows = $this->query()
            ->where('attendance_record_id', '=', $recordId)
            ->orderBy('punched_at', 'asc')
            ->get();

        return array_map([$this, 'present'], $rows);
    }

    public function lastFor(string $employeeId): ?array
    {
        $row = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->orderBy('punched_at', 'desc')
            ->first();

        return $row === null ? null : $this->present($row);
    }

    public function present(array $row): array
    {
        $row = parent::present($row);

        if (array_key_exists('punched_at', $row)) {
            $row['punched_at'] = TimeFormat::local($row['punched_at'] === null ? null : (string) $row['punched_at']);
        }

        return $row;
    }
}
