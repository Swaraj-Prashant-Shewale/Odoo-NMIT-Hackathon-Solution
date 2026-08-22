<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

final class ReviewParticipants extends Repository
{
    protected string $table = 'review_participants';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'review_cycle_id', 'employee_id', 'manager_id', 'status', 'created_at',
    ];

    /** Enrolment is a fact recorded once; the row is never edited wholesale. */
    protected bool $timestamps = false;

    public function create(array $attributes): array
    {
        return parent::create($attributes + ['created_at' => Clock::iso()]);
    }

    public function isEnrolled(string $cycleId, string $employeeId): bool
    {
        return $this->query()
            ->where('review_cycle_id', '=', $cycleId)
            ->where('employee_id', '=', $employeeId)
            ->exists();
    }

    public function markStatus(string $cycleId, string $employeeId, string $status): void
    {
        $this->execute(
            'UPDATE review_participants
             SET status = :status
             WHERE review_cycle_id = :review_cycle_id AND employee_id = :employee_id',
            ['status' => $status, 'review_cycle_id' => $cycleId, 'employee_id' => $employeeId]
        );
    }

    public function countFor(string $cycleId): int
    {
        return $this->query()->where('review_cycle_id', '=', $cycleId)->count();
    }
}
