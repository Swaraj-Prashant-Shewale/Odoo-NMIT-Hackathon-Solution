<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class Reviews extends Repository
{
    protected string $table = 'reviews';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'review_cycle_id', 'employee_id', 'reviewer_id', 'review_type',
        'overall_rating', 'strengths', 'improvements', 'achievements',
        'manager_comments', 'employee_comments', 'is_anonymous', 'status',
        'submitted_at', 'acknowledged_at',
    ];

    protected array $casts = [
        'overall_rating' => 'float',
        'is_anonymous' => 'bool',
    ];

    /**
     * Every review one person is entitled to read.
     *
     * Two disjoint sets, and nothing else ever qualifies:
     *   - reviews the caller is writing, at any stage, and
     *   - reviews written about the caller by somebody else, but only once the
     *     reviewer has submitted them.
     *
     * A self review is only reachable through the first branch, which is what
     * keeps it private to its author.
     *
     * @param array{review_cycle_id?: string|null, scope?: string|null, status?: string|null} $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function visibleTo(string $employeeId, array $filters, int $page, int $perPage): array
    {
        $conditions = [];
        $bindings = [];

        $received = '(employee_id = :subject_id AND submitted_at IS NOT NULL AND review_type <> :not_self)';
        $scope = $filters['scope'] ?? 'all';

        if ($scope === 'to_complete') {
            $conditions[] = 'reviewer_id = :reviewer_id';
            $conditions[] = 'status = :draft_status';
            $bindings['reviewer_id'] = $employeeId;
            $bindings['draft_status'] = 'draft';
        } elseif ($scope === 'received') {
            $conditions[] = $received;
            $bindings['subject_id'] = $employeeId;
            $bindings['not_self'] = 'self';
        } else {
            $conditions[] = '(reviewer_id = :reviewer_id OR ' . $received . ')';
            $bindings['reviewer_id'] = $employeeId;
            $bindings['subject_id'] = $employeeId;
            $bindings['not_self'] = 'self';
        }

        if (($filters['review_cycle_id'] ?? null) !== null) {
            $conditions[] = 'review_cycle_id = :review_cycle_id';
            $bindings['review_cycle_id'] = $filters['review_cycle_id'];
        }

        if (($filters['status'] ?? null) !== null) {
            $conditions[] = 'status = :status_filter';
            $bindings['status_filter'] = $filters['status'];
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $total = (int) ($this->rawOne('SELECT COUNT(*) AS aggregate FROM reviews ' . $where, $bindings)['aggregate'] ?? 0);

        // Both numbers are integers this class computed, never caller strings,
        // so they go into the statement the way the kernel's builder does it.
        $rows = $this->raw(
            sprintf(
                'SELECT * FROM reviews %s ORDER BY COALESCE(submitted_at, updated_at) DESC LIMIT %d OFFSET %d',
                $where,
                $perPage,
                $offset
            ),
            $bindings
        );

        return [
            'data' => array_map([$this, 'present'], $rows),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /** The most recent review about someone that they are allowed to read. */
    public function latestVisibleAbout(string $employeeId): ?array
    {
        $row = $this->rawOne(
            'SELECT * FROM reviews
             WHERE employee_id = :subject_id
               AND submitted_at IS NOT NULL
               AND (review_type <> :not_self OR reviewer_id = :reviewer_id)
             ORDER BY submitted_at DESC
             LIMIT 1',
            ['subject_id' => $employeeId, 'not_self' => 'self', 'reviewer_id' => $employeeId]
        );

        return $row === null ? null : $this->present($row);
    }

    public function outstandingFor(string $cycleId, string $employeeId): int
    {
        return $this->query()
            ->where('review_cycle_id', '=', $cycleId)
            ->where('employee_id', '=', $employeeId)
            ->where('status', '=', 'draft')
            ->count();
    }

    public function existsFor(string $cycleId, string $employeeId, string $reviewerId, string $type): bool
    {
        return $this->query()
            ->where('review_cycle_id', '=', $cycleId)
            ->where('employee_id', '=', $employeeId)
            ->where('reviewer_id', '=', $reviewerId)
            ->where('review_type', '=', $type)
            ->exists();
    }

    /**
     * Review counts for one cycle, grouped by type and status.
     *
     * @return list<array<string, mixed>>
     */
    public function progressBreakdown(string $cycleId): array
    {
        return $this->raw(
            'SELECT review_type, status, COUNT(*) AS total
             FROM reviews
             WHERE review_cycle_id = :review_cycle_id
             GROUP BY review_type, status
             ORDER BY review_type, status',
            ['review_cycle_id' => $cycleId]
        );
    }

    /**
     * Whole-number rating bands for one cycle. Counts only, never a row that
     * could be traced back to one person.
     *
     * @return list<array<string, mixed>>
     */
    public function ratingBands(string $cycleId): array
    {
        return $this->raw(
            'SELECT FLOOR(overall_rating)::int AS band, COUNT(*) AS total
             FROM reviews
             WHERE review_cycle_id = :review_cycle_id
               AND submitted_at IS NOT NULL
               AND overall_rating IS NOT NULL
             GROUP BY band
             ORDER BY band',
            ['review_cycle_id' => $cycleId]
        );
    }

    /** @return array{rated: int, average: float|null, lowest: float|null, highest: float|null} */
    public function ratingAggregate(string $cycleId): array
    {
        $row = $this->rawOne(
            'SELECT COUNT(*) AS rated,
                    ROUND(AVG(overall_rating), 2) AS average,
                    MIN(overall_rating) AS lowest,
                    MAX(overall_rating) AS highest
             FROM reviews
             WHERE review_cycle_id = :review_cycle_id
               AND submitted_at IS NOT NULL
               AND overall_rating IS NOT NULL',
            ['review_cycle_id' => $cycleId]
        ) ?? [];

        $rated = (int) ($row['rated'] ?? 0);

        return [
            'rated' => $rated,
            'average' => $rated === 0 ? null : (float) $row['average'],
            'lowest' => $rated === 0 ? null : (float) $row['lowest'],
            'highest' => $rated === 0 ? null : (float) $row['highest'],
        ];
    }
}
