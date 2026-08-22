<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

final class Enrolments extends Repository
{
    protected string $table = 'enrolments';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'course_id', 'employee_id', 'assigned_by', 'assigned_at', 'due_on',
        'started_at', 'completed_at', 'status', 'progress_percent', 'last_lesson_id',
    ];

    protected array $casts = [
        'progress_percent' => 'int',
    ];

    public function present(array $row): array
    {
        $row = parent::present($row);

        // Overdue is a question the client asks about almost every enrolment,
        // and answering it here keeps the comparison in one place.
        if (array_key_exists('due_on', $row) && array_key_exists('status', $row)) {
            $row['is_overdue'] = $row['due_on'] !== null
                && $row['status'] !== 'completed'
                && (string) $row['due_on'] < Clock::today();
        }

        return $row;
    }

    public function forCourseAndEmployee(string $courseId, string $employeeId): ?array
    {
        $row = $this->query()
            ->where('course_id', '=', $courseId)
            ->where('employee_id', '=', $employeeId)
            ->first();

        return $row === null ? null : $this->present($row);
    }

    /**
     * Enrolment rows for one employee across a set of courses, keyed by course.
     *
     * @param list<string> $courseIds
     * @return array<string, array<string, mixed>>
     */
    public function forEmployeeAcross(string $employeeId, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $rows = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->whereIn('course_id', $courseIds)
            ->get();

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(string) $row['course_id']] = $this->present($row);
        }

        return $keyed;
    }

    /** @return list<array<string, mixed>> */
    public function forEmployee(string $employeeId): array
    {
        $rows = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->orderBy('created_at', 'desc')
            ->get();

        return array_map([$this, 'present'], $rows);
    }

    /** @return list<array<string, mixed>> */
    public function forCourses(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $rows = $this->query()->whereIn('course_id', $courseIds)->get();

        return array_map([$this, 'present'], $rows);
    }

    public function countForCourse(string $courseId): int
    {
        return $this->query()->where('course_id', '=', $courseId)->count();
    }

    /**
     * Learning time recorded by one employee inside a date window.
     *
     * Watched time is capped at each lesson's real runtime so a client that
     * reports an inflated position cannot manufacture training hours.
     */
    public function watchedSecondsBetween(string $employeeId, string $from, string $to): int
    {
        $row = $this->rawOne(
            'SELECT COALESCE(SUM(LEAST(lesson_progress.watched_seconds, lessons.duration_seconds)), 0) AS seconds
             FROM lesson_progress
             JOIN lessons ON lessons.id = lesson_progress.lesson_id
             WHERE lesson_progress.employee_id = :employee_id
               AND lesson_progress.updated_at >= :from
               AND lesson_progress.updated_at < :to',
            ['employee_id' => $employeeId, 'from' => $from, 'to' => $to]
        );

        return (int) ($row['seconds'] ?? 0);
    }
}
