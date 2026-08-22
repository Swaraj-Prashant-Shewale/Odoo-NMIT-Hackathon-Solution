<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Enrolments;
use App\Models\LessonProgress;
use App\Models\Lessons;
use Dayflow\Kernel\Support\Clock;

/**
 * Keeps watch progress and the derived enrolment state in step.
 *
 * A lesson counts as watched once the reported position reaches most of its
 * runtime; demanding the final second would leave every enrolment stranded at
 * ninety-something percent because players stop firing updates over the
 * closing credits.
 */
final class ProgressTracker
{
    /** Share of a lesson's runtime that counts as having watched it. */
    private const COMPLETION_RATIO = 0.9;

    private Lessons $lessons;
    private LessonProgress $progress;
    private Enrolments $enrolments;

    public function __construct()
    {
        $this->lessons = new Lessons();
        $this->progress = new LessonProgress();
        $this->enrolments = new Enrolments();
    }

    /**
     * Records a watch position and returns the refreshed enrolment.
     *
     * @param array<string, mixed> $enrolment
     * @param array<string, mixed> $lesson
     * @return array{enrolment: array<string, mixed>, lesson_progress: array<string, mixed>, lessons_completed: int, lessons_total: int}
     */
    public function record(array $enrolment, array $lesson, int $watchedSeconds): array
    {
        $enrolmentId = (string) $enrolment['id'];
        $lessonId = (string) $lesson['id'];
        $duration = (int) $lesson['duration_seconds'];

        $existing = $this->progress->forEnrolmentAndLesson($enrolmentId, $lessonId);

        // Watch position only ever moves forward, and never past the real
        // runtime, so a replayed or inflated client update cannot shrink a
        // record or manufacture training time.
        $cap = $duration > 0 ? $duration : PHP_INT_MAX;
        $position = min(max($watchedSeconds, (int) ($existing['watched_seconds'] ?? 0)), $cap);

        $reachedEnd = $duration <= 0
            ? $position > 0
            : $position >= (int) ceil($duration * self::COMPLETION_RATIO);

        $completedAt = $existing['completed_at'] ?? null;
        if ($completedAt === null && $reachedEnd) {
            $completedAt = Clock::iso();
        }

        if ($existing === null) {
            $record = $this->progress->create([
                'enrolment_id' => $enrolmentId,
                'lesson_id' => $lessonId,
                'employee_id' => (string) $enrolment['employee_id'],
                'watched_seconds' => $position,
                'completed_at' => $completedAt,
            ]);
        } else {
            $record = $this->progress->update((string) $existing['id'], [
                'watched_seconds' => $position,
                'completed_at' => $completedAt,
            ]) ?? $existing;
        }

        $refreshed = $this->recompute($enrolment, $lessonId);

        return [
            'enrolment' => $refreshed,
            'lesson_progress' => $record,
            'lessons_completed' => $this->progress->completedCount($enrolmentId),
            'lessons_total' => $this->lessons->countForCourse((string) $enrolment['course_id']),
        ];
    }

    /**
     * Recalculates progress_percent and status from the lesson_progress rows.
     *
     * @param array<string, mixed> $enrolment
     * @return array<string, mixed>
     */
    public function recompute(array $enrolment, ?string $lastLessonId = null): array
    {
        $enrolmentId = (string) $enrolment['id'];
        $total = $this->lessons->countForCourse((string) $enrolment['course_id']);
        $completed = $this->progress->completedCount($enrolmentId);

        $alreadyCompleted = ($enrolment['completed_at'] ?? null) !== null;

        // A finished course stays at 100 even if the catalogue later gains a
        // lesson: the achievement was already earned and possibly certified.
        $percent = $alreadyCompleted
            ? 100
            : ($total === 0 ? 0 : (int) round(($completed / $total) * 100));

        $changes = ['progress_percent' => min(100, max(0, $percent))];

        if ($lastLessonId !== null) {
            $changes['last_lesson_id'] = $lastLessonId;
        }

        if (!$alreadyCompleted) {
            $started = $enrolment['started_at'] ?? null;

            if ($completed > 0 || $percent > 0) {
                $changes['status'] = 'in_progress';
                if ($started === null) {
                    $changes['started_at'] = Clock::iso();
                }
            } else {
                $changes['status'] = $started === null ? 'not_started' : 'in_progress';
            }
        }

        return $this->enrolments->update($enrolmentId, $changes) ?? $enrolment;
    }

    /**
     * Brings every enrolment on a course back in line after its lessons change.
     *
     * Adding or removing a lesson silently changes the denominator behind each
     * learner's percentage, so the stored value has to be rebuilt.
     */
    public function recomputeCourse(string $courseId): void
    {
        foreach ($this->enrolments->forCourses([$courseId]) as $enrolment) {
            $this->recompute($enrolment);
        }
    }

    /** True when every lesson on the course has been completed. */
    public function allLessonsComplete(string $enrolmentId, string $courseId): bool
    {
        $total = $this->lessons->countForCourse($courseId);

        return $total > 0 && $this->progress->completedCount($enrolmentId) >= $total;
    }
}
