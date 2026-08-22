<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\CourseCategories;
use App\Models\Courses;
use App\Models\Enrolments;
use App\Models\LessonProgress;
use App\Models\Lessons;
use App\Models\QuizQuestions;
use App\Models\Quizzes;
use App\Policies\EnrolmentPolicy;
use App\Policies\LessonAccess;
use App\Services\VideoLibrary;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Database\QueryBuilder;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;
use Dayflow\Kernel\Validation\Validator;

final class CourseController
{
    private Courses $courses;
    private CourseCategories $categories;
    private Lessons $lessons;
    private Enrolments $enrolments;
    private LessonProgress $progress;
    private Quizzes $quizzes;
    private QuizQuestions $questions;

    public function __construct()
    {
        $this->courses = new Courses();
        $this->categories = new CourseCategories();
        $this->lessons = new Lessons();
        $this->enrolments = new Enrolments();
        $this->progress = new LessonProgress();
        $this->quizzes = new Quizzes();
        $this->questions = new QuizQuestions();
    }

    /** The browsable catalogue, carrying the caller's own enrolment on each row. */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'search' => 'nullable|safe_text|max:120',
            'category_id' => 'nullable|uuid',
            'level' => 'nullable|in:beginner,intermediate,advanced',
            'mandatory' => 'nullable|boolean',
        ])->validated();

        $principal = $request->principal();

        $builder = $this->courses->query()
            ->select('courses.*')
            ->selectRaw('"course_categories"."name" AS category_name')
            ->selectRaw('"course_categories"."colour" AS category_colour')
            ->selectRaw('"course_categories"."icon" AS category_icon')
            ->join('course_categories', 'course_categories.id', '=', 'courses.category_id', 'LEFT');

        if (!EnrolmentPolicy::seesUnpublished($principal)) {
            $builder->where('courses.is_active', '=', true)->whereNotNull('courses.published_at');
        }

        if (($filters['search'] ?? null) !== null) {
            $builder->whereIn('courses.id', $this->courses->idsMatching((string) $filters['search']));
        }

        if (($filters['category_id'] ?? null) !== null) {
            $builder->where('courses.category_id', '=', $filters['category_id']);
        }

        if (($filters['level'] ?? null) !== null) {
            $builder->where('courses.level', '=', $filters['level']);
        }

        if (array_key_exists('mandatory', $filters) && $filters['mandatory'] !== null) {
            $builder->where('courses.is_mandatory', '=', (bool) $filters['mandatory']);
        }

        $builder->orderBy('courses.is_mandatory', 'desc')->orderBy('courses.title');

        $page = $this->courses->paginate($builder, $request->page(), $request->perPage());
        $page['data'] = $this->decorate($page['data'], $principal->employeeId);

        return Response::page($page);
    }

    /** One course with its lessons and the caller's progress through them. */
    public function show(Request $request): Response
    {
        $principal = $request->principal();
        $course = $this->requireCourse($request);

        $visible = (bool) $course['is_active'] && ($course['published_at'] ?? null) !== null;
        if (!$visible && !EnrolmentPolicy::seesUnpublished($principal)) {
            throw HttpException::notFound();
        }

        $enrolment = $principal->employeeId === null
            ? null
            : $this->enrolments->forCourseAndEmployee((string) $course['id'], $principal->employeeId);

        $lessons = $this->lessons->forCourse((string) $course['id']);

        $watched = [];
        if ($enrolment !== null) {
            foreach ($this->progress->forEnrolment((string) $enrolment['id']) as $row) {
                $watched[(string) $row['lesson_id']] = $row;
            }
        }

        $shapedLessons = [];
        foreach ($lessons as $lesson) {
            $shaped = VideoLibrary::presentLesson(
                $lesson,
                LessonAccess::revealsVideo($lesson, $enrolment, $principal)
            );

            $record = $watched[(string) $lesson['id']] ?? null;
            $shaped['watched_seconds'] = (int) ($record['watched_seconds'] ?? 0);
            $shaped['completed_at'] = $record['completed_at'] ?? null;
            $shaped['is_completed'] = ($record['completed_at'] ?? null) !== null;

            $shapedLessons[] = $shaped;
        }

        $quiz = $this->quizzes->activeForCourse((string) $course['id']);

        $course['category'] = $this->categories->find((string) $course['category_id']);
        $course['thumbnail_url'] = $this->courses->thumbnailFor($course, $lessons[0] ?? null);
        $course['lesson_count'] = count($lessons);
        $course['total_seconds'] = array_sum(array_map(
            static fn (array $lesson): int => (int) $lesson['duration_seconds'],
            $lessons
        ));
        $course['runtime_label'] = Str::duration((int) $course['total_seconds']);

        return Response::ok([
            'course' => $course,
            'lessons' => $shapedLessons,
            'enrolment' => $enrolment,
            'quiz' => $quiz === null ? null : [
                'id' => $quiz['id'],
                'title' => $quiz['title'],
                'pass_percent' => $quiz['pass_percent'],
                'max_attempts' => $quiz['max_attempts'],
                'question_count' => count($this->questions->forQuiz((string) $quiz['id'])),
            ],
        ]);
    }

    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'category_id' => 'required|uuid',
            'title' => 'required|safe_text|max:180',
            'slug' => 'nullable|slug|max:180',
            'summary' => 'nullable|safe_text|max:400',
            'description' => 'nullable|safe_text|max:6000',
            'thumbnail_url' => 'nullable|url|max:500',
            'level' => 'nullable|in:beginner,intermediate,advanced',
            'estimated_minutes' => 'nullable|int|between:0,10000',
            'is_mandatory' => 'nullable|boolean',
            'mandatory_for_department_id' => 'nullable|uuid',
            'mandatory_for_designation_id' => 'nullable|uuid',
            'passing_score' => 'nullable|int|between:0,100',
            'certificate_enabled' => 'nullable|boolean',
            'publish' => 'nullable|boolean',
        ])->validated();

        $this->requireCategory((string) $data['category_id']);
        $this->assertSecureThumbnail($data['thumbnail_url'] ?? null);

        $attributes = $this->attributesFrom($data);
        $attributes['title'] = (string) $data['title'];
        $attributes['slug'] = $this->uniqueSlug((string) ($data['slug'] ?? $data['title']));
        $attributes['category_id'] = $data['category_id'];
        $attributes['level'] = $data['level'] ?? 'beginner';
        $attributes['is_active'] = true;
        $attributes['created_by'] = $request->principal()->employeeId;
        $attributes['published_at'] = ($data['publish'] ?? false) === true ? Clock::iso() : null;

        $course = $this->courses->create($attributes);

        AuditLog::record($request, 'learning.course.created', 'course', (string) $course['id'], [], $course);

        return Response::created($course);
    }

    public function update(Request $request): Response
    {
        $course = $this->requireCourse($request);

        $data = Validator::make($request->all(), [
            'category_id' => 'nullable|uuid',
            'title' => 'nullable|safe_text|max:180',
            'slug' => 'nullable|slug|max:180',
            'summary' => 'nullable|safe_text|max:400',
            'description' => 'nullable|safe_text|max:6000',
            'thumbnail_url' => 'nullable|url|max:500',
            'level' => 'nullable|in:beginner,intermediate,advanced',
            'estimated_minutes' => 'nullable|int|between:0,10000',
            'is_mandatory' => 'nullable|boolean',
            'mandatory_for_department_id' => 'nullable|uuid',
            'mandatory_for_designation_id' => 'nullable|uuid',
            'passing_score' => 'nullable|int|between:0,100',
            'certificate_enabled' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'publish' => 'nullable|boolean',
        ])->validated();

        if (($data['category_id'] ?? null) !== null) {
            $this->requireCategory((string) $data['category_id']);
        }

        $this->assertSecureThumbnail($data['thumbnail_url'] ?? null);

        $attributes = $this->attributesFrom($data);

        foreach (['category_id', 'title', 'level', 'is_active'] as $field) {
            if (($data[$field] ?? null) !== null) {
                $attributes[$field] = $data[$field];
            }
        }

        if (($data['slug'] ?? null) !== null) {
            $attributes['slug'] = $this->uniqueSlug((string) $data['slug'], (string) $course['id']);
        }

        if (array_key_exists('publish', $data) && $data['publish'] !== null) {
            $attributes['published_at'] = $data['publish'] === true
                ? ($course['published_at'] ?? Clock::iso())
                : null;
        }

        $updated = $this->courses->update((string) $course['id'], $attributes);

        if ($updated === null) {
            throw HttpException::notFound();
        }

        AuditLog::record(
            $request,
            'learning.course.updated',
            'course',
            (string) $course['id'],
            $course,
            $updated,
            ['changed' => array_keys(AuditLog::diff($course, $updated))]
        );

        return Response::ok($updated);
    }

    /**
     * Removes a course, but only while nobody depends on it.
     *
     * Deleting a course with enrolments would take completed training records
     * and issued certificates with it, so an in-use course is deactivated
     * instead and disappears from the catalogue without losing its history.
     */
    public function destroy(Request $request): Response
    {
        $course = $this->requireCourse($request);
        $enrolled = $this->enrolments->countForCourse((string) $course['id']);

        if ($enrolled > 0) {
            throw HttpException::conflict(
                'This course cannot be deleted because people are enrolled on it. Deactivate it instead.',
                ['enrolments' => $enrolled]
            );
        }

        $this->courses->delete((string) $course['id']);

        AuditLog::record($request, 'learning.course.deleted', 'course', (string) $course['id'], $course, []);

        return Response::noContent();
    }

    /**
     * Attaches enrolment state, runtime and quiz presence to catalogue rows.
     *
     * Doing it here rather than per row keeps the listing to a fixed number of
     * queries however many courses come back.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function decorate(array $rows, ?string $employeeId): array
    {
        if ($rows === []) {
            return [];
        }

        $courseIds = array_map(static fn (array $row): string => (string) $row['id'], $rows);

        $totals = $this->courses->lessonTotals($courseIds);
        $withQuiz = array_flip($this->quizzes->courseIdsWithQuiz($courseIds));
        $mine = $employeeId === null ? [] : $this->enrolments->forEmployeeAcross($employeeId, $courseIds);

        $firstLessons = $this->firstLessons($courseIds);

        foreach ($rows as $index => $row) {
            $courseId = (string) $row['id'];
            $total = $totals[$courseId] ?? ['lesson_count' => 0, 'total_seconds' => 0];
            $enrolment = $mine[$courseId] ?? null;

            $rows[$index]['lesson_count'] = $total['lesson_count'];
            $rows[$index]['total_seconds'] = $total['total_seconds'];
            $rows[$index]['runtime_label'] = Str::duration($total['total_seconds']);
            $rows[$index]['has_quiz'] = isset($withQuiz[$courseId]);
            $rows[$index]['thumbnail_url'] = $this->courses->thumbnailFor($row, $firstLessons[$courseId] ?? null);
            $rows[$index]['enrolment_status'] = $enrolment['status'] ?? null;
            $rows[$index]['enrolment_id'] = $enrolment['id'] ?? null;
            $rows[$index]['progress_percent'] = (int) ($enrolment['progress_percent'] ?? 0);
            $rows[$index]['due_on'] = $enrolment['due_on'] ?? null;
            $rows[$index]['is_overdue'] = (bool) ($enrolment['is_overdue'] ?? false);
            $rows[$index]['is_enrolled'] = $enrolment !== null;
        }

        return $rows;
    }

    /**
     * The opening lesson of each course, used as a thumbnail fallback.
     *
     * @param list<string> $courseIds
     * @return array<string, array<string, mixed>>
     */
    private function firstLessons(array $courseIds): array
    {
        $rows = QueryBuilder::table('lessons')
            ->whereIn('course_id', $courseIds)
            ->orderBy('course_id')
            ->orderBy('sequence')
            ->get();

        $first = [];
        foreach ($rows as $row) {
            $courseId = (string) $row['course_id'];
            if (!isset($first[$courseId])) {
                $first[$courseId] = $row;
            }
        }

        return $first;
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

    private function requireCategory(string $categoryId): void
    {
        if ($this->categories->find($categoryId) === null) {
            throw HttpException::unprocessable(
                'That category does not exist.',
                ['category_id' => ['Choose a category from the catalogue.']]
            );
        }
    }

    /**
     * Keeps the optional cover image on a scheme a browser will load safely.
     *
     * The url rule alone would accept plain http, which would silently break
     * the image on a page served over https and downgrade the request.
     */
    private function assertSecureThumbnail(mixed $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        if (!str_starts_with(strtolower((string) $url), 'https://')) {
            throw HttpException::unprocessable(
                'The cover image address must use https.',
                ['thumbnail_url' => ['Only https image addresses are accepted.']]
            );
        }
    }

    /**
     * Fields that are copied straight through once validated.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributesFrom(array $data): array
    {
        $attributes = [];

        $passthrough = [
            'summary', 'description', 'thumbnail_url', 'estimated_minutes',
            'is_mandatory', 'mandatory_for_department_id', 'mandatory_for_designation_id',
            'passing_score', 'certificate_enabled',
        ];

        foreach ($passthrough as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        return $attributes;
    }

    /** Derives a catalogue-unique slug, numbering collisions rather than failing. */
    private function uniqueSlug(string $source, ?string $exceptCourseId = null): string
    {
        $base = Str::slug($source);
        if ($base === '') {
            $base = 'course';
        }

        $base = substr($base, 0, 160);
        $candidate = $base;

        for ($suffix = 2; $suffix < 100; $suffix++) {
            $existing = $this->courses->findBySlug($candidate);

            if ($existing === null || ($exceptCourseId !== null && (string) $existing['id'] === $exceptCourseId)) {
                return $candidate;
            }

            $candidate = $base . '-' . $suffix;
        }

        return $base . '-' . substr(str_replace('-', '', Str::uuid()), 0, 6);
    }
}
