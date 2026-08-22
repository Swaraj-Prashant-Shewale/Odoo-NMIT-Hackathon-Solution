<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

final class LeaveApprovals extends Repository
{
    protected string $table = 'leave_approvals';

    protected array $fillable = [
        'leave_request_id', 'level', 'approver_id', 'status', 'note', 'decided_at',
    ];

    protected array $casts = ['level' => 'int'];

    // The chain is append-only once written; a row is closed, never revised.
    protected bool $timestamps = false;

    /** @return list<array<string, mixed>> */
    public function forRequest(string $requestId): array
    {
        $rows = $this->query()
            ->where('leave_request_id', '=', $requestId)
            ->orderBy('level')
            ->get();

        return array_map([$this, 'present'], $rows);
    }

    /** The level currently waiting on a signature, if any. */
    public function currentLevel(string $requestId): ?array
    {
        $row = $this->query()
            ->where('leave_request_id', '=', $requestId)
            ->where('status', '=', 'pending')
            ->orderBy('level')
            ->first();

        return $row === null ? null : $this->present($row);
    }

    /** The next level still waiting after the one just closed. */
    public function nextLevelAfter(string $requestId, int $level): ?array
    {
        $row = $this->query()
            ->where('leave_request_id', '=', $requestId)
            ->where('status', '=', 'pending')
            ->where('level', '>', $level)
            ->orderBy('level')
            ->first();

        return $row === null ? null : $this->present($row);
    }

    public function closeLevel(string $approvalId, string $status, ?string $note): void
    {
        $this->execute(
            'UPDATE leave_approvals SET status = :status, note = :note, decided_at = :decided_at WHERE id = :id',
            ['status' => $status, 'note' => $note, 'decided_at' => Clock::iso(), 'id' => $approvalId]
        );
    }

    /**
     * Closes every level still waiting.
     *
     * Used when a request is rejected or withdrawn: the levels above the one
     * that decided are never going to be asked, and leaving them pending would
     * keep the request sitting in their queue forever.
     */
    public function skipRemaining(string $requestId): int
    {
        return $this->execute(
            "UPDATE leave_approvals
                SET status = 'skipped', decided_at = :decided_at
              WHERE leave_request_id = :request_id AND status = 'pending'",
            ['decided_at' => Clock::iso(), 'request_id' => $requestId]
        );
    }
}
