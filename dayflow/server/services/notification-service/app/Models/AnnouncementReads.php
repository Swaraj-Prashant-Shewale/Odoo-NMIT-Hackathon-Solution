<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;

/** Read receipts, one per announcement per person. */
final class AnnouncementReads extends Repository
{
    protected string $table = 'announcement_reads';

    protected string $primaryKey = 'id';

    protected array $fillable = ['id', 'announcement_id', 'employee_id', 'read_at'];

    // The receipt is the timestamp; there is nothing else about it to update.
    protected bool $timestamps = false;

    /**
     * Records that someone has read an announcement.
     *
     * Reading twice is normal behaviour rather than an error, so a repeat keeps
     * the original timestamp and reports the existing receipt.
     */
    public function record(string $announcementId, string $employeeId): array
    {
        $row = $this->rawOne(
            'INSERT INTO announcement_reads (id, announcement_id, employee_id, read_at)
             VALUES (:id, :announcement_id, :employee_id, :read_at)
             ON CONFLICT (announcement_id, employee_id) DO UPDATE
                SET read_at = announcement_reads.read_at
             RETURNING *',
            [
                'id' => Str::uuid(),
                'announcement_id' => $announcementId,
                'employee_id' => $employeeId,
                'read_at' => Clock::iso(),
            ]
        );

        return $row === null ? [] : $this->present($row);
    }
}
