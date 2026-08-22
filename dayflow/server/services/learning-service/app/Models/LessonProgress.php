<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class LessonProgress extends Repository
{
    protected string $table = 'lesson_progress';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'enrolment_id', 'lesson_id', 'employee_id', 'watched_seconds', 'completed_at',
    ];

    protected array $casts = [
        'watched_seconds' => 'int',
    ];

    /** @return list<array<string, mixed>> */
    public function forEnrolment(string $enrolmentId): array
    {
        $rows = $this->query()->where('enrolment_id', '=', $enrolmentId)->get();

        return array_map([$this, 'present'], $rows);
    }

    public function forEnrolmentAndLesson(string $enrolmentId, string $lessonId): ?array
    {
        $row = $this->query()
            ->where('enrolment_id', '=', $enrolmentId)
            ->where('lesson_id', '=', $lessonId)
            ->first();

        return $row === null ? null : $this->present($row);
    }

    public function completedCount(string $enrolmentId): int
    {
        return $this->query()
            ->where('enrolment_id', '=', $enrolmentId)
            ->whereNotNull('completed_at')
            ->count();
    }

    /**
     * Completed-lesson counts for many enrolments at once.
     *
     * @param list<string> $enrolmentIds
     * @return array<string, int>
     */
    public function completedCounts(array $enrolmentIds): array
    {
        if ($enrolmentIds === []) {
            return [];
        }

        $placeholders = [];
        $bindings = [];
        foreach (array_values($enrolmentIds) as $index => $id) {
            $key = 'e' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $id;
        }

        $sql = sprintf(
            'SELECT enrolment_id, COUNT(*) AS completed
             FROM lesson_progress
             WHERE completed_at IS NOT NULL AND enrolment_id IN (%s)
             GROUP BY enrolment_id',
            implode(', ', $placeholders)
        );

        $counts = [];
        foreach ($this->raw($sql, $bindings) as $row) {
            $counts[(string) $row['enrolment_id']] = (int) $row['completed'];
        }

        return $counts;
    }
}
