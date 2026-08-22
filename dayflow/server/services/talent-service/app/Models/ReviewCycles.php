<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class ReviewCycles extends Repository
{
    protected string $table = 'review_cycles';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'name', 'period_start', 'period_end', 'self_review_due_on',
        'manager_review_due_on', 'status', 'rating_scale_max', 'instructions', 'created_by',
    ];

    protected array $casts = ['rating_scale_max' => 'int'];

    /**
     * Cycles one person is entitled to see.
     *
     * A cycle becomes visible when the caller is enrolled in it or has been
     * asked to review somebody in it, and only once it has actually been
     * opened. A draft cycle is HR's working copy and stays out of sight.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleTo(string $employeeId): array
    {
        $rows = $this->raw(
            'SELECT c.*
             FROM review_cycles c
             WHERE c.status <> :draft
               AND (
                    EXISTS (
                        SELECT 1 FROM review_participants p
                        WHERE p.review_cycle_id = c.id AND p.employee_id = :participant_id
                    )
                    OR EXISTS (
                        SELECT 1 FROM reviews r
                        WHERE r.review_cycle_id = c.id
                          AND (r.reviewer_id = :reviewer_id OR r.employee_id = :subject_id)
                    )
               )
             ORDER BY c.period_start DESC, c.name',
            [
                'draft' => 'draft',
                'participant_id' => $employeeId,
                'reviewer_id' => $employeeId,
                'subject_id' => $employeeId,
            ]
        );

        return array_map([$this, 'present'], $rows);
    }
}
