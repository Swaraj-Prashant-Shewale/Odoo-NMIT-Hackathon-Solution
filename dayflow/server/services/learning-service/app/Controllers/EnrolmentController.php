<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Courses;
use App\Models\Enrolments;
use App\Models\LessonProgress;
use App\Models\Lessons;
use App\Models\Quizzes;
use App\Policies\AssignmentPolicy;
use App\Policies\EnrolmentPolicy;
use App\Services\CourseCompletion;
use App\Services\EmployeeDirectory;
use App\Services\ProgressTracker;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

final class EnrolmentController
{
    /** A single assignment call may not fan out further than this. */
    private const MAX_ASSIGNMENT_TARGETS = 200;

    private Courses $courses;
    private Enrolments $enrolments;
    private Lessons $lessons;
    private LessonProgress $progress;
    private Quizzes $quizzes;
    private ProgressTracker $tracker;
    private CourseCompletion $completion;

    public function __construct()
    {
        $this->courses = new Courses();
        $this->enrolments = new Enrolments();
        $this->lessons = new Lessons();
        $this->progress = new LessonProgress();
        $this->quizzes = new Quizzes();
        $this->tracker = new ProgressTracker();
        $this->completion = new CourseCompletion();
    }

    /**
     * Self-enrolment.
     *
     * The learner is always the caller. An employee_id in the body is ignored
     * outright, which is what stops this endpoint being used to enrol somebody
     * else and inherit their record.
     */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'course_id' => 'required|uuid',
        ])->validated();

        $principal = $request->principal();
        $employeeId = EnrolmentPolicy::requireEmployeeId($principal);
        $course = $this->requirePublishedCourse((string) $data['course_id']);

        if ($this->enrolments->forCourseAndEmployee((string) $course['id'], $employeeId) !== null) {
            throw HttpException::conflict('You are already enrolled on this course.');
        }

        $enrolment = $this->enrolments->create([
            'course_id' => (string) $course['id'],
            'employee_id' => $employeeId,
            'status' => 'not_started',
            'progress_percent' => 0,
        ]);

        AuditLog::record(
            $request,
            'learning.enrolment.created',
            'enrolment',
            (string) $enrolment['id'],
            [],
            $enrolment
        );

        return Response::created($enrolment);
    }

    /**
     * Assigns a course to one or many people with a due date.
     *
     * The scope check runs over the whole target list before anything is
     * written, so a manager reaching outside their team gets a clean refusal
     * rather than a half-completed assignment.
     */
    public function assign(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'course_id' => 'required|uuid',
            'employee_ids' => 'required|array',
            'due_on' => 'nullable|date|after_or_equal:today',
        ])->validated();

        $principal = $request->principal();
        $employeeIds = $this->normaliseEmployeeIds($data['employee_ids']);
        $directory = new EmployeeDirectory($request->bearerToken());

        AssignmentPolicy::assertMayAssign($principal, $employeeIds, $directory);

        $course = $this->requirePublishedCourse((string) $data['course_id']);
        $courseId = (string) $course['id'];
        $assignedBy = $principal->employeeId;
        $assignedAt = Clock::iso();
        $dueOn = $data['due_on'] ?? null;

        $created = [];
        $updated = [];

        foreach ($employeeIds as $employeeId) {
            $existing = $this->enrolments->forCourseAndEmployee($courseId, $employeeId);

            if ($existing === null) {
                $created[] = $this->enrolments->create([
                    'course_id' => $courseId,
                    'employee_id' => $employeeId,
                    'assigned_by' => $assignedBy,
                    'assigned_at' => $assignedAt,
                    'due_on' => $dueOn,
                    'status' => 'not_started',
                    'progress_percent' => 0,
                ]);
            } else {
                // Re-assigning an existing enrolment renews its deadline and
                // ownership without discarding work already done on it.
                $updated[] = $this->enrolments->update((string) $existing['id'], [
                    'assigned_by' => $assignedBy,
                    'assigned_at' => $assignedAt,
                    'due_on' => $dueOn ?? $existing['due_on'],
                ]) ?? $existing;
            }

            EventPublisher::publish('learning.course.assigned', [
                'employee_id' => $employeeId,
                'course_id' => $courseId,
                'course_title' => (string) $course['title'],
                'due_on' => $dueOn,
            ]);
        }

        AuditLog::record(
            $request,
            'learning.course.assigned',
            'course',
            $courseId,
            [],
            ['employee_ids' => $employeeIds, 'due_on' => $dueOn],
            ['created' => count($created), 'renewed' => count($updated)]
        );

        return Response::created([
            'course_id' => $courseId,
            'course_title' => $course['title'],
            'due_on' => $dueOn,
            'assigned' => count($created),
            'renewed' => count($updated),
            'enrolments' => array_merge($created, $updated),
        ]);
    }

    /** The caller's own enrolments, or a team member's for a manager or HR. */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'employee_id' => 'nullable|uuid',
            'status' => 'nullable|in:not_started,in_progress,completed,expired',
            'course_id' => 'nullable|uuid',
            'overdue' => 'nullable|boolean',
        ])->validated();

        $principal = $request->principal();
        $employeeId = EnrolmentPolicy::requireEmployeeId($principal);

        if (($filters['employee_id'] ?? null) !== null) {
            $employeeId = (string) $filters['employee_id'];
            EnrolmentPolicy::assertMayViewEmployee(
                $principal,
                $employeeId,
                new EmployeeDirectory($request->bearerToken())
            );
        }

        $builder = $this->enrolments->query()
            ->select('enrolments.*')
            ->selectRaw('"courses"."title" AS course_title')
            ->selectRaw('"courses"."slug" AS course_slug')
            ->selectRaw('"courses"."level" AS course_level')
            ->selectRaw('"courses"."thumbnail_url" AS course_thumbnail_url')
            ->selectRaw('"courses"."estimated_minutes" AS course_estimated_minutes')
            ->selectRaw('"courses"."is_mandatory" AS course_is_mandatory')
            ->join('courses', 'courses.id', '=', 'enrolments.course_id')
            ->where('enrolments.employee_id', '=', $employeeId);

        if (($filters['status'] ?? null) !== null) {
            $builder->where('enrolments.status', '=', $filters['status']);
        }

        if (($filters['course_id'] ?? null) !== null) {
            $builder->where('enrolments.course_id', '=', $filters['course_id']);
        }

        if (($filters['overdue'] ?? null) === true) {
            $builder->where('enrolments.due_on', '<', Clock::today())
                ->where('enrolments.status', '!=', 'completed');
        }

        $builder->orderBy('enrolments.due_on')->orderBy('enrolments.created_at', 'desc');

        $page = $this->enrolments->paginate($builder, $request->page(), $request->perPage());
        $page['data'] = $this->withLessonCounts($page['data']);

        return Response::page($page);
    }

    /**
     * Records how far through a lesson the learner has watched.
     *
     * A lesson counts as done once the reported position reaches most of its
     * runtime; the enrolment percentage is then rebuilt from the completed
     * lessons rather than trusted from the client.
     */
    public function progress(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'lesson_id' => 'required|uuid',
            'watched_seconds' => 'required|int|between:0,86400',
        ])->validated();

        $principal = $request->principal();
        $enrolment = $this->requireEnrolment($request);
        EnrolmentPolicy::assertOwn($principal, $enrolment);

        $lesson = $this->lessons->find((string) $data['lesson_id']);

        if ($lesson === null || (string) $lesson['course_id'] !== (string) $enrolment['course_id']) {
            throw HttpException::unprocessable(
                'That lesson is not part of this course.',
                ['lesson_id' => ['Choose a lesson from the course you are enrolled on.']]
            );
        }

        $result = $this->tracker->record($enrolment, $lesson, (int) $data['watched_seconds']);

        $course = $this->courses->find((string) $enrolment['course_id']);
        $quiz = $this->quizzes->activeForCourse((string) $enrolment['course_id']);
        $certificate = null;
        $justCompleted = false;

        $everythingWatched = $this->tracker->allLessonsComplete(
            (string) $enrolment['id'],
            (string) $enrolment['course_id']
        );

        // A course with a quiz is only finished once the quiz is passed, so
        // watching the last lesson leaves it in progress on purpose.
        if ($everythingWatched && $quiz === null && $course !== null) {
            $outcome = $this->completion->complete($result['enrolment'], $course, 100);
            $result['enrolment'] = $outcome['enrolment'];
            $certificate = $outcome['certificate'];
            $justCompleted = $outcome['newly_completed'];
        }

        AuditLog::record(
            $request,
            'learning.lesson.progressed',
            'enrolment',
            (string) $enrolment['id'],
            ['progress_percent' => $enrolment['progress_percent'], 'status' => $enrolment['status']],
            ['progress_percent' => $result['enrolment']['progress_percent'], 'status' => $result['enrolment']['status']],
            ['lesson_id' => (string) $lesson['id']]
        );

        return Response::ok([
            'enrolment' => $result['enrolment'],
            'lesson_progress' => $result['lesson_progress'],
            'lessons_completed' => $result['lessons_completed'],
            'lessons_total' => $result['lessons_total'],
            'quiz_required' => $everythingWatched && $quiz !== null,
            'course_completed' => $justCompleted,
            'certificate' => $certificate,
        ]);
    }

    /**
     * @param array<string, mixed>|mixed $raw
     * @return list<string>
     */
    private function normaliseEmployeeIds(mixed $raw): array
    {
        $ids = [];
        $invalid = [];

        foreach ((array) $raw as $candidate) {
            if (!is_string($candidate)) {
                $invalid[] = $candidate;
                continue;
            }

            $value = strtolower(trim($candidate));

            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) !== 1) {
                $invalid[] = $candidate;
                continue;
            }

            $ids[$value] = $value;
        }

        if ($invalid !== []) {
            throw HttpException::unprocessable(
                'The list of people contains an entry that is not a valid reference.',
                ['employee_ids' => ['Every entry must be an employee identifier.']]
            );
        }

        $ids = array_values($ids);

        if ($ids === []) {
            throw HttpException::unprocessable(
                'Choose at least one person to assign this course to.',
                ['employee_ids' => ['Select at least one person.']]
            );
        }

        if (count($ids) > self::MAX_ASSIGNMENT_TARGETS) {
            throw HttpException::unprocessable(
                sprintf('A single assignment may cover at most %d people.', self::MAX_ASSIGNMENT_TARGETS),
                ['employee_ids' => ['Split this into smaller groups.']]
            );
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withLessonCounts(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $courseIds = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['course_id'],
            $rows
        )));
        $enrolmentIds = array_map(static fn (array $row): string => (string) $row['id'], $rows);

        $totals = $this->courses->lessonTotals($courseIds);
        $completed = $this->progress->completedCounts($enrolmentIds);

        foreach ($rows as $index => $row) {
            $total = $totals[(string) $row['course_id']] ?? ['lesson_count' => 0, 'total_seconds' => 0];

            $rows[$index]['lesson_count'] = $total['lesson_count'];
            $rows[$index]['lessons_completed'] = $completed[(string) $row['id']] ?? 0;
            $rows[$index]['total_seconds'] = $total['total_seconds'];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function requireEnrolment(Request $request): array
    {
        $parameters = Validator::make($request->routeParameters(), [
            'id' => 'required|uuid',
        ])->validated();

        $enrolment = $this->enrolments->find((string) $parameters['id']);

        if ($enrolment === null) {
            throw HttpException::notFound('That enrolment does not exist.');
        }

        return $enrolment;
    }

    /** @return array<string, mixed> */
    private function requirePublishedCourse(string $courseId): array
    {
        $course = $this->courses->find($courseId);

        if ($course === null || !(bool) $course['is_active'] || ($course['published_at'] ?? null) === null) {
            throw HttpException::unprocessable(
                'That course is not available.',
                ['course_id' => ['Choose a published course from the catalogue.']]
            );
        }

        return $course;
    }
}
