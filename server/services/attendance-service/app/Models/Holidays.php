<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class Holidays extends Repository
{
    protected string $table = 'holidays';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'name', 'holiday_date', 'holiday_type', 'location_id', 'description', 'year', 'is_active',
    ];

    protected array $casts = [
        'year' => 'int',
        'is_active' => 'bool',
    ];

    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    /**
     * Active holidays inside a date window that apply at a location.
     *
     * A NULL location_id on the row means "every office", so it is always
     * included regardless of where the employee sits.
     *
     * @return list<array<string, mixed>>
     */
    public function inRange(string $from, string $to, ?string $locationId = null): array
    {
        return array_map([$this, 'present'], $this->raw(
            'SELECT * FROM holidays
             WHERE is_active = TRUE
               AND holiday_date BETWEEN CAST(:from_date AS DATE) AND CAST(:to_date AS DATE)
               AND (location_id IS NULL OR location_id = CAST(:location_id AS UUID))
             ORDER BY holiday_date ASC, holiday_type ASC',
            ['from_date' => $from, 'to_date' => $to, 'location_id' => $locationId]
        ));
    }

    /** @return list<array<string, mixed>> */
    public function upcoming(string $fromDate, int $limit, ?string $locationId = null): array
    {
        return array_map([$this, 'present'], $this->raw(
            'SELECT * FROM holidays
             WHERE is_active = TRUE
               AND holiday_date >= CAST(:from_date AS DATE)
               AND (location_id IS NULL OR location_id = CAST(:location_id AS UUID))
             ORDER BY holiday_date ASC
             LIMIT :row_limit',
            ['from_date' => $fromDate, 'location_id' => $locationId, 'row_limit' => $limit]
        ));
    }

    public function existsOn(string $date, string $name, ?string $locationId): bool
    {
        $row = $this->rawOne(
            'SELECT 1 FROM holidays
             WHERE holiday_date = CAST(:holiday_date AS DATE)
               AND lower(name) = lower(:name)
               AND COALESCE(location_id, CAST(:sentinel AS UUID))
                   = COALESCE(CAST(:location_id AS UUID), CAST(:sentinel2 AS UUID))
             LIMIT 1',
            [
                'holiday_date' => $date,
                'name' => $name,
                'location_id' => $locationId,
                'sentinel' => '00000000-0000-0000-0000-000000000000',
                'sentinel2' => '00000000-0000-0000-0000-000000000000',
            ]
        );

        return $row !== null;
    }
}
