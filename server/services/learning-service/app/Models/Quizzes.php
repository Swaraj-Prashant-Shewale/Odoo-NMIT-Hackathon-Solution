<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class Quizzes extends Repository
{
    protected string $table = 'quizzes';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'course_id', 'title', 'pass_percent', 'max_attempts', 'is_active', 'created_at',
    ];

    protected array $casts = [
        'pass_percent' => 'int',
        'max_attempts' => 'int',
        'is_active' => 'bool',
    ];

    // The table records only when a quiz was created; it is never edited in
    // place, so there is no updated_at for the base class to maintain.
    protected bool $timestamps = false;

    public function activeForCourse(string $courseId): ?array
    {
        $row = $this->query()
            ->where('course_id', '=', $courseId)
            ->where('is_active', '=', true)
            ->first();

        return $row === null ? null : $this->present($row);
    }

    /**
     * Which of the given courses currently present a quiz.
     *
     * @param list<string> $courseIds
     * @return list<string>
     */
    public function courseIdsWithQuiz(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $rows = $this->query()
            ->select('course_id')
            ->whereIn('course_id', $courseIds)
            ->where('is_active', '=', true)
            ->get();

        return array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['course_id'],
            $rows
        )));
    }
}
