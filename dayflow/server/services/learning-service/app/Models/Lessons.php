<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class Lessons extends Repository
{
    protected string $table = 'lessons';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'course_id', 'title', 'description', 'video_url', 'video_id',
        'duration_seconds', 'sequence', 'is_preview',
    ];

    protected array $casts = [
        'duration_seconds' => 'int',
        'sequence' => 'int',
        'is_preview' => 'bool',
    ];

    /** @return list<array<string, mixed>> */
    public function forCourse(string $courseId): array
    {
        $rows = $this->query()
            ->where('course_id', '=', $courseId)
            ->orderBy('sequence')
            ->get();

        return array_map([$this, 'present'], $rows);
    }

    public function countForCourse(string $courseId): int
    {
        return $this->query()->where('course_id', '=', $courseId)->count();
    }

    /** The next free position, so a new lesson lands at the end of the course. */
    public function nextSequence(string $courseId): int
    {
        $row = $this->rawOne(
            'SELECT COALESCE(MAX(sequence), 0) + 1 AS next FROM lessons WHERE course_id = :course_id',
            ['course_id' => $courseId]
        );

        return (int) ($row['next'] ?? 1);
    }

    public function sequenceTaken(string $courseId, int $sequence, ?string $exceptLessonId = null): bool
    {
        $builder = $this->query()
            ->where('course_id', '=', $courseId)
            ->where('sequence', '=', $sequence);

        if ($exceptLessonId !== null) {
            $builder->where('id', '!=', $exceptLessonId);
        }

        return $builder->exists();
    }
}
