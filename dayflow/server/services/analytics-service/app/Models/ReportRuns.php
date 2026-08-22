<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

/**
 * A record of every report execution and export.
 *
 * An export turns a screen full of personal data into a file that leaves the
 * platform, so who took what, with which filters, and how many rows came back
 * is recorded here as well as in the audit trail.
 */
final class ReportRuns extends Repository
{
    protected string $table = 'report_runs';

    protected array $fillable = [
        'report_definition_id', 'run_by', 'filters', 'row_count', 'format', 'duration_ms', 'created_at',
    ];

    protected array $casts = [
        'filters' => 'json',
        'row_count' => 'int',
        'duration_ms' => 'int',
    ];

    // A run happens once and is never edited, so the table has no updated_at
    // column and the base class timestamp handling is not used.
    protected bool $timestamps = false;

    /** @param array<string, mixed> $filters */
    public function record(
        string $definitionId,
        string $userId,
        array $filters,
        int $rowCount,
        string $format,
        int $durationMs,
    ): array {
        return $this->create([
            'report_definition_id' => $definitionId,
            'run_by' => $userId,
            'filters' => $filters,
            'row_count' => max(0, $rowCount),
            'format' => $format,
            'duration_ms' => max(0, $durationMs),
            'created_at' => Clock::iso(),
        ]);
    }
}
