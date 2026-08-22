<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Courses;
use App\Models\Lessons;
use App\Services\ProgressTracker;
use App\Services\VideoLibrary;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\Validator;

/**
 * Manages the video lessons inside a course.
 *
 * Every write validates the pasted address with the youtube rule and stores
 * the extracted id alongside it. Nothing else is ever accepted as embeddable
 * content.
 */
final class LessonController
{
    private Courses $courses;
    private Lessons $lessons;
    private ProgressTracker $tracker;

    public function __construct()
    {
        $this->courses = new Courses();
        $this->lessons = new Lessons();
        $this->tracker = new ProgressTracker();
    }

    public function store(Request $request): Response
    {
        $course = $this->requireCourse($request);

        $data = Validator::make($request->all(), [
            'title' => 'required|safe_text|max:180',
            'description' => 'nullable|safe_text|max:2000',
            'video_url' => 'required|youtube|max:500',
            'duration_seconds' => 'required|int|between:1,86400',
            'sequence' => 'nullable|int|between:1,999',
            'is_preview' => 'nullable|boolean',
        ])->validated();

        $courseId = (string) $course['id'];
        $sequence = $data['sequence'] ?? null;

        if ($sequence === null) {
            $sequence = $this->lessons->nextSequence($courseId);
        } elseif ($this->lessons->sequenceTaken($courseId, (int) $sequence)) {
            throw HttpException::conflict('Another lesson already occupies that position in the course.');
        }

        $lesson = $this->lessons->create([
            'course_id' => $courseId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'video_url' => $data['video_url'],
            'video_id' => VideoLibrary::videoId((string) $data['video_url']),
            'duration_seconds' => $data['duration_seconds'],
            'sequence' => (int) $sequence,
            'is_preview' => (bool) ($data['is_preview'] ?? false),
        ]);

        // The course just gained a lesson, so everybody's percentage of it
        // changed even though nobody watched anything.
        $this->tracker->recomputeCourse($courseId);

        AuditLog::record($request, 'learning.lesson.created', 'lesson', (string) $lesson['id'], [], $lesson);

        return Response::created(VideoLibrary::presentLesson($lesson, true));
    }

    public function update(Request $request): Response
    {
        $course = $this->requireCourse($request);
        $lesson = $this->requireLesson($request, (string) $course['id']);

        $data = Validator::make($request->all(), [
            'title' => 'nullable|safe_text|max:180',
            'description' => 'nullable|safe_text|max:2000',
            'video_url' => 'nullable|youtube|max:500',
            'duration_seconds' => 'nullable|int|between:1,86400',
            'sequence' => 'nullable|int|between:1,999',
            'is_preview' => 'nullable|boolean',
        ])->validated();

        $attributes = [];

        foreach (['title', 'description', 'duration_seconds', 'is_preview'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (($data['video_url'] ?? null) !== null) {
            $attributes['video_url'] = $data['video_url'];
            $attributes['video_id'] = VideoLibrary::videoId((string) $data['video_url']);
        }

        if (($data['sequence'] ?? null) !== null) {
            $sequence = (int) $data['sequence'];

            if ($this->lessons->sequenceTaken((string) $course['id'], $sequence, (string) $lesson['id'])) {
                throw HttpException::conflict('Another lesson already occupies that position in the course.');
            }

            $attributes['sequence'] = $sequence;
        }

        $updated = $this->lessons->update((string) $lesson['id'], $attributes);

        if ($updated === null) {
            throw HttpException::notFound();
        }

        // A shorter runtime moves the ninety percent line, so watch records
        // that now clear it have to be re-evaluated.
        $this->tracker->recomputeCourse((string) $course['id']);

        AuditLog::record(
            $request,
            'learning.lesson.updated',
            'lesson',
            (string) $lesson['id'],
            $lesson,
            $updated,
            ['changed' => array_keys(AuditLog::diff($lesson, $updated))]
        );

        return Response::ok(VideoLibrary::presentLesson($updated, true));
    }

    public function destroy(Request $request): Response
    {
        $course = $this->requireCourse($request);
        $lesson = $this->requireLesson($request, (string) $course['id']);

        $this->lessons->delete((string) $lesson['id']);
        $this->tracker->recomputeCourse((string) $course['id']);

        AuditLog::record($request, 'learning.lesson.deleted', 'lesson', (string) $lesson['id'], $lesson, []);

        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function requireCourse(Request $request): array
    {
        $parameters = Validator::make($request->routeParameters(), [
            'id' => 'required|uuid',
        ])->validated();

        $course = $this->courses->find((string) $parameters['id']);

        if ($course === null) {
            throw HttpException::notFound('That course does not exist.');
        }

        return $course;
    }

    /** @return array<string, mixed> */
    private function requireLesson(Request $request, string $courseId): array
    {
        $parameters = Validator::make($request->routeParameters(), [
            'lessonId' => 'required|uuid',
        ])->validated();

        $lesson = $this->lessons->find((string) $parameters['lessonId']);

        // The lesson must belong to the course in the path; otherwise the
        // course id would be decoration an attacker could ignore.
        if ($lesson === null || (string) $lesson['course_id'] !== $courseId) {
            throw HttpException::notFound('That lesson does not exist.');
        }

        return $lesson;
    }
}
