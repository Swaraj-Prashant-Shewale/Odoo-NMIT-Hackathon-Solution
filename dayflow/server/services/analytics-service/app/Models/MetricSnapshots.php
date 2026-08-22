<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;

/**
 * Derived figures kept so a trend survives after the owning service has moved
 * on (a payroll run is archived, an attendance month is closed).
 *
 * Nothing here is authoritative. Every value can be recomputed from the
 * services that own the underlying records, which is why writing a snapshot
 * overwrites rather than appends.
 */
final class MetricSnapshots extends Repository
{
    protected string $table = 'metric_snapshots';

    protected array $fillable = [
        'metric_key', 'dimension_key', 'dimension_value', 'period', 'value', 'captured_at', 'created_at',
    ];

    protected array $casts = ['value' => 'float'];

    // The table records when a figure was captured rather than when its row was
    // last touched, so the base class timestamp handling is not used.
    protected bool $timestamps = false;

    public function record(
        string $metricKey,
        string $period,
        float $value,
        string $dimensionKey = 'overall',
        string $dimensionValue = 'all',
    ): void {
        $sql = <<<'SQL'
            INSERT INTO metric_snapshots
                (id, metric_key, dimension_key, dimension_value, period, value, captured_at, created_at)
            VALUES
                (:id, :metric_key, :dimension_key, :dimension_value, :period, :value, :captured_at, :created_at)
            ON CONFLICT (metric_key, dimension_key, dimension_value, period)
            DO UPDATE SET value = EXCLUDED.value, captured_at = EXCLUDED.captured_at
            SQL;

        $now = Clock::iso();

        $this->execute($sql, [
            'id' => Str::uuid(),
            'metric_key' => $metricKey,
            'dimension_key' => $dimensionKey,
            'dimension_value' => $dimensionValue,
            'period' => $period,
            'value' => number_format($value, 4, '.', ''),
            'captured_at' => $now,
            'created_at' => $now,
        ]);
    }

    /**
     * Writes a batch of figures.
     *
     * @param list<array{metric_key: string, period: string, value: float, dimension_key?: string, dimension_value?: string}> $metrics
     */
    public function recordMany(array $metrics): void
    {
        foreach ($metrics as $metric) {
            $this->record(
                (string) $metric['metric_key'],
                (string) $metric['period'],
                (float) $metric['value'],
                (string) ($metric['dimension_key'] ?? 'overall'),
                (string) ($metric['dimension_value'] ?? 'all'),
            );
        }
    }

    /**
     * A metric's values over time, oldest first.
     *
     * @return list<array{period: string, dimension_value: string, value: float, captured_at: string}>
     */
    public function series(string $metricKey, string $dimensionKey = 'overall', int $limit = 24): array
    {
        $sql = <<<'SQL'
            SELECT period, dimension_value, value, captured_at
            FROM metric_snapshots
            WHERE metric_key = :metric_key AND dimension_key = :dimension_key
            ORDER BY period DESC
            LIMIT :limit
            SQL;

        $rows = $this->raw($sql, [
            'metric_key' => $metricKey,
            'dimension_key' => $dimensionKey,
            'limit' => max(1, min($limit, 120)),
        ]);

        return array_values(array_reverse(array_map(
            static fn (array $row): array => [
                'period' => (string) $row['period'],
                'dimension_value' => (string) $row['dimension_value'],
                'value' => (float) $row['value'],
                'captured_at' => (string) $row['captured_at'],
            ],
            $rows
        )));
    }
}
