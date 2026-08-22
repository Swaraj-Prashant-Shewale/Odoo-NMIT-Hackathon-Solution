<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Api;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;

/**
 * Learning and development: the course catalogue, the player and compliance.
 *
 * Every course is a set of embedded training videos. The learning service
 * never hands this client a video address; it returns a validated eleven
 * character video id and the templates build the iframe themselves against a
 * fixed, privacy enhanced host. That is the whole reason a pasted link cannot
 * become an iframe src, so nothing here ever passes a service supplied URL
 * into a frame.
 *
 * The other rule that shapes this file is that the answer key stays on the
 * server. The quiz endpoint strips it before the questions travel, and the
 * worked review only exists in a response to a graded submission, so a page
 * rendered before an attempt has nothing to reveal.
 */
final class LearningController extends Controller
{
    /** Courses on one page of the catalogue: three rows of four. */
    private const CATALOGUE_PER_PAGE = 12;

    /** Courses on one page of the management table. */
    private const MANAGE_PER_PAGE = 50;

    /** As many people as one request may offer in an assignment picker. */
    private const PICKER_LIMIT = 100;

    private const LEVELS = ['beginner', 'intermediate', 'advanced'];

    /**
     * Where a graded attempt waits between its POST and the redirect that
     * displays it. A result rendered straight from the POST would be
     * resubmitted by a refresh, which would burn a second attempt.
     */
    private const QUIZ_RESULT_KEY = '_learning_quiz_result';

    // -----------------------------------------------------------------------
    // The learner's own screens
    // -----------------------------------------------------------------------

    public function index(): void
    {
        $response = Api::get('/learning/my-dashboard');

        // The dashboard is built around an employee record, and an account
        // without one has no enrolments to show. The catalogue still works for
        // them, so that is a better landing place than a refusal.
        if (!$response['ok'] && $response['status'] === 403) {
            Flash::info('Your account is not linked to an employee record yet, so nothing is being tracked. The catalogue is still open to you.');
            $this->redirect('/learning/catalogue');
        }

        $this->guard($response);

        $dashboard = is_array($response['data']) ? $response['data'] : [];

        $inProgress = $this->listOf($dashboard, 'in_progress');
        $assigned = $this->listOf($dashboard, 'assigned');
        $completed = $this->listOf($dashboard, 'recently_completed');

        // Assigned work and company-required training belong in one list: the
        // learner does not care which of the two put a course on their plate,
        // only which one is late.
        //
        // The service's "assigned" bucket holds only what has not been started,
        // so anything under way that somebody else put there, or that the
        // company requires, has to be picked back out of "in progress" —
        // otherwise opening an assigned course would drop it out of this list
        // and take its deadline with it.
        $owed = array_filter(
            $inProgress,
            static fn (array $row): bool => (bool) ($row['course_is_mandatory'] ?? false)
                || ($row['assigned_by'] ?? null) !== null
        );

        $obligations = $this->byUrgency(array_merge($assigned, array_values($owed)));
        $continue = $this->continueWith($inProgress);

        // Whatever the learner picked up themselves still has to appear
        // somewhere, but not twice: a course already carried by the continue
        // card or the obligations list is left out of the remainder.
        $placed = [];
        foreach ($obligations as $row) {
            $placed[(string) ($row['course_id'] ?? '')] = true;
        }

        if ($continue !== null) {
            $placed[(string) ($continue['course']['id'] ?? '')] = true;
        }

        View::render('learning/index', [
            'pageTitle' => 'Learning',
            'breadcrumbs' => [['Learning', '']],
            'summary' => is_array($dashboard['summary'] ?? null) ? $dashboard['summary'] : [],
            'obligations' => $obligations,
            'others' => array_values(array_filter(
                $inProgress,
                static fn (array $row): bool => !isset($placed[(string) ($row['course_id'] ?? '')])
            )),
            'completed' => $completed,
            'certificates' => $this->keyBy($this->listOf($dashboard, 'certificates'), 'course_id'),
            'continue' => $continue,
            'assignment' => $this->assignmentOptions(),
        ]);
    }

    public function catalogue(): void
    {
        $filters = [
            'search' => $this->input('search'),
            'category_id' => $this->input('category_id'),
            'level' => $this->input('level'),
            'mandatory' => $this->inputBool('mandatory'),
        ];

        $query = ['page' => $this->page(), 'per_page' => self::CATALOGUE_PER_PAGE];

        if ($filters['search'] !== '') {
            $query['search'] = $filters['search'];
        }

        if ($filters['category_id'] !== '') {
            $query['category_id'] = $filters['category_id'];
        }

        if (in_array($filters['level'], self::LEVELS, true)) {
            $query['level'] = $filters['level'];
        }

        // Sent only when the toggle is on. "mandatory=0" is a real filter to
        // the service and would hide every required course rather than none.
        if ($filters['mandatory']) {
            $query['mandatory'] = 1;
        }

        $response = Api::get('/courses', $query);
        $this->guard($response, '/learning');

        View::render('learning/catalogue', [
            'pageTitle' => 'Course catalogue',
            'breadcrumbs' => [['Learning', '/learning'], ['Catalogue', '']],
            'courses' => is_array($response['data']) ? $response['data'] : [],
            'meta' => $response['meta'],
            'categories' => Api::collection('/learning/categories'),
            'filters' => $filters,
            'levels' => self::LEVELS,
        ]);
    }

    /** @param array<string, string> $parameters */
    public function course(array $parameters): void
    {
        $courseId = (string) ($parameters['id'] ?? '');

        $response = Api::get('/courses/' . rawurlencode($courseId));
        $this->guard($response, '/learning/catalogue');

        $data = is_array($response['data']) ? $response['data'] : [];
        $course = is_array($data['course'] ?? null) ? $data['course'] : [];
        $lessons = $this->listOf($data, 'lessons');
        $enrolment = is_array($data['enrolment'] ?? null) ? $data['enrolment'] : null;
        $quiz = is_array($data['quiz'] ?? null) ? $data['quiz'] : null;

        // Attempt counts live behind the quiz endpoint, and that endpoint
        // refuses anyone without an enrolment, so it is only worth asking for
        // once there is one.
        $attempts = null;
        if ($quiz !== null && $enrolment !== null) {
            $attempts = Api::data('/courses/' . rawurlencode($courseId) . '/quiz');
        }

        View::render('learning/course', [
            'pageTitle' => (string) ($course['title'] ?? 'Course'),
            'breadcrumbs' => [
                ['Learning', '/learning'],
                ['Catalogue', '/learning/catalogue'],
                [(string) ($course['title'] ?? 'Course'), ''],
            ],
            'course' => $course,
            'lessons' => $lessons,
            'enrolment' => $enrolment,
            'quiz' => $quiz,
            'attempts' => is_array($attempts) ? $attempts : null,
            'currentLessonId' => $this->currentLessonId($lessons, $enrolment),
            'lessonsCompleted' => $this->completedCount($lessons),
        ]);
    }

    /** @param array<string, string> $parameters */
    public function lesson(array $parameters): void
    {
        $courseId = (string) ($parameters['id'] ?? '');
        $lessonId = (string) ($parameters['lessonId'] ?? '');
        $coursePath = '/learning/courses/' . rawurlencode($courseId);

        $response = Api::get('/courses/' . rawurlencode($courseId));
        $this->guard($response, '/learning/catalogue');

        $data = is_array($response['data']) ? $response['data'] : [];
        $course = is_array($data['course'] ?? null) ? $data['course'] : [];
        $lessons = $this->listOf($data, 'lessons');
        $enrolment = is_array($data['enrolment'] ?? null) ? $data['enrolment'] : null;

        $position = null;
        foreach ($lessons as $index => $candidate) {
            if ((string) ($candidate['id'] ?? '') === $lessonId) {
                $position = $index;
                break;
            }
        }

        if ($position === null) {
            Flash::error('That lesson is not part of this course.');
            $this->redirect($coursePath);
        }

        $lesson = $lessons[$position];

        // A locked lesson comes back without a video id at all, so there is
        // nothing to play even if this page tried to render it.
        if (!empty($lesson['is_locked'])) {
            Flash::warning('Enrol on this course to watch this lesson.');
            $this->redirect($coursePath);
        }

        View::render('learning/lesson', [
            'pageTitle' => (string) ($lesson['title'] ?? 'Lesson'),
            'breadcrumbs' => [
                ['Learning', '/learning'],
                [(string) ($course['title'] ?? 'Course'), $coursePath],
                [(string) ($lesson['title'] ?? 'Lesson'), ''],
            ],
            'course' => $course,
            'lesson' => $lesson,
            'lessons' => $lessons,
            'enrolment' => $enrolment,
            'position' => $position + 1,
            'previous' => $lessons[$position - 1] ?? null,
            'next' => $lessons[$position + 1] ?? null,
            'lessonsCompleted' => $this->completedCount($lessons),
        ]);
    }

    /** @param array<string, string> $parameters */
    public function enrol(array $parameters): void
    {
        $courseId = (string) ($parameters['id'] ?? '');
        $coursePath = '/learning/courses/' . rawurlencode($courseId);

        $response = Api::post('/enrolments', ['course_id' => $courseId]);

        if (!$response['ok']) {
            $this->backWithErrors($response, [], $coursePath);
        }

        Flash::success('You are enrolled. The whole course is open to you now.');
        $this->redirect($coursePath);
    }

    /**
     * Records that a lesson has been watched.
     *
     * The enrolment is read back from the course endpoint rather than taken
     * from the form, so the identifier a progress record is written against
     * is never one the browser chose.
     *
     * @param array<string, string> $parameters
     */
    public function recordProgress(array $parameters): void
    {
        $courseId = (string) ($parameters['id'] ?? '');
        $lessonId = $this->input('lesson_id');
        $coursePath = '/learning/courses/' . rawurlencode($courseId);

        $detail = Api::data('/courses/' . rawurlencode($courseId));
        $enrolment = is_array($detail) && is_array($detail['enrolment'] ?? null) ? $detail['enrolment'] : null;

        if ($enrolment === null) {
            Flash::warning('Enrol on this course before recording progress.');
            $this->redirect($coursePath);
        }

        $lessons = is_array($detail) ? $this->listOf($detail, 'lessons') : [];

        $response = Api::post(
            '/enrolments/' . rawurlencode((string) $enrolment['id']) . '/progress',
            [
                'lesson_id' => $lessonId,
                // The reported position is capped at the lesson's real runtime
                // by the service, so claiming a large number gains nothing.
                'watched_seconds' => $this->inputInt('watched_seconds'),
            ]
        );

        if (!$response['ok']) {
            $this->backWithErrors($response, [], $coursePath);
        }

        $result = is_array($response['data']) ? $response['data'] : [];

        if (!empty($result['course_completed'])) {
            Flash::success(is_array($result['certificate'] ?? null)
                ? 'Course complete. Your certificate is ready to download.'
                : 'Course complete. Every lesson is done.');
            $this->redirect($coursePath);
        }

        if (!empty($result['quiz_required'])) {
            Flash::info('Every lesson is watched. Pass the quiz to finish the course.');
            $this->redirect($coursePath . '/quiz');
        }

        Flash::success('Lesson marked as complete.');

        $next = $this->lessonAfter($lessons, $lessonId);

        $this->redirect($next === null
            ? $coursePath
            : $coursePath . '/lessons/' . rawurlencode((string) $next['id']));
    }

    // -----------------------------------------------------------------------
    // Assessment
    // -----------------------------------------------------------------------

    /** @param array<string, string> $parameters */
    public function quiz(array $parameters): void
    {
        $courseId = (string) ($parameters['id'] ?? '');
        $coursePath = '/learning/courses/' . rawurlencode($courseId);

        $response = Api::get('/courses/' . rawurlencode($courseId) . '/quiz');

        // Sitting a quiz depends on holding an enrolment. That is a step the
        // learner can take, so it is a redirect with an explanation rather
        // than the blank refusal an access failure would produce.
        if (!$response['ok'] && $response['status'] === 403) {
            Flash::warning('Enrol on this course before taking its quiz.');
            $this->redirect($coursePath);
        }

        $this->guard($response, $coursePath);

        $data = is_array($response['data']) ? $response['data'] : [];

        View::render('learning/quiz', [
            'pageTitle' => 'Quiz',
            'breadcrumbs' => [
                ['Learning', '/learning'],
                [(string) ($data['course_title'] ?? 'Course'), $coursePath],
                ['Quiz', ''],
            ],
            'courseId' => $courseId,
            'courseTitle' => (string) ($data['course_title'] ?? 'Course'),
            'quiz' => is_array($data['quiz'] ?? null) ? $data['quiz'] : [],
            'questions' => $this->listOf($data, 'questions'),
            'attemptsUsed' => (int) ($data['attempts_used'] ?? 0),
            'attemptsRemaining' => (int) ($data['attempts_remaining'] ?? 0),
            'bestScore' => $data['best_score_percent'] ?? null,
            'result' => $this->takeQuizResult($courseId),
        ]);
    }

    /** @param array<string, string> $parameters */
    public function submitQuiz(array $parameters): void
    {
        $courseId = (string) ($parameters['id'] ?? '');
        $quizPath = '/learning/courses/' . rawurlencode($courseId) . '/quiz';

        $answers = [];

        // Each question is its own radio group, and each radio carries
        // "question id:option index" as a single value, so the question a
        // choice belongs to travels with it and an unanswered question simply
        // contributes nothing.
        foreach ($this->inputArray('answers') as $entry) {
            [$questionId, $selected] = array_pad(explode(':', $entry, 2), 2, null);

            if (!is_string($selected) || !is_numeric($selected)) {
                continue;
            }

            $answers[] = ['question_id' => $questionId, 'selected_index' => (int) $selected];
        }

        if ($answers === []) {
            Flash::error('Answer at least one question before submitting.');
            $this->redirect($quizPath);
        }

        $response = Api::post('/courses/' . rawurlencode($courseId) . '/quiz/submit', ['answers' => $answers]);

        if (!$response['ok']) {
            $this->backWithErrors($response, [], $quizPath);
        }

        $result = is_array($response['data']) ? $response['data'] : [];

        Session::put(self::QUIZ_RESULT_KEY, [$courseId => $result]);

        if (!empty($result['passed'])) {
            Flash::success(sprintf('Passed with %d%%.', (int) ($result['score_percent'] ?? 0)));
        } else {
            Flash::warning(sprintf(
                'Scored %d%%, and %d%% is needed to pass.',
                (int) ($result['score_percent'] ?? 0),
                (int) ($result['pass_percent'] ?? 0)
            ));
        }

        $this->redirect($quizPath);
    }

    // -----------------------------------------------------------------------
    // Certificates
    // -----------------------------------------------------------------------

    public function certificates(): void
    {
        $response = Api::get('/certificates', ['page' => $this->page(), 'per_page' => 12]);
        $this->guard($response, '/learning');

        View::render('learning/certificates', [
            'pageTitle' => 'My certificates',
            'breadcrumbs' => [['Learning', '/learning'], ['Certificates', '']],
            'certificates' => is_array($response['data']) ? $response['data'] : [],
            'meta' => $response['meta'],
        ]);
    }

    /** @param array<string, string> $parameters */
    public function downloadCertificate(array $parameters): void
    {
        $certificateId = (string) ($parameters['id'] ?? '');

        if (Api::stream('/certificates/' . rawurlencode($certificateId) . '/download')) {
            return;
        }

        Flash::error('That certificate could not be downloaded.');
        $this->redirect('/learning/certificates');
    }

    // -----------------------------------------------------------------------
    // Catalogue management
    // -----------------------------------------------------------------------

    public function manage(): void
    {
        $response = Api::get('/courses', ['page' => $this->page(), 'per_page' => self::MANAGE_PER_PAGE]);
        $this->guard($response, '/learning');

        // The lesson form has to post to one course's own address, so the
        // course being edited is chosen with a reload rather than a script.
        $selectedId = $this->input('course');
        $selected = null;

        if ($selectedId !== '') {
            $detail = Api::data('/courses/' . rawurlencode($selectedId));
            $selected = is_array($detail) ? $detail : null;
        }

        View::render('learning/manage', [
            'pageTitle' => 'Manage catalogue',
            'breadcrumbs' => [['Learning', '/learning'], ['Manage catalogue', '']],
            'courses' => is_array($response['data']) ? $response['data'] : [],
            'meta' => $response['meta'],
            'categories' => Api::collection('/learning/categories'),
            'departments' => Api::collection('/departments'),
            'levels' => self::LEVELS,
            'selected' => $selected,
            'selectedId' => $selectedId,
            'enrolmentCounts' => $this->mandatoryEnrolmentCounts(),
        ]);
    }

    public function storeCourse(): void
    {
        $payload = [
            'category_id' => $this->input('category_id'),
            'title' => $this->input('title'),
            'summary' => $this->input('summary'),
            'description' => $this->input('description'),
            'level' => $this->input('level', 'beginner'),
            'estimated_minutes' => $this->inputInt('estimated_minutes'),
            'is_mandatory' => $this->inputBool('is_mandatory'),
            'passing_score' => $this->inputInt('passing_score', 70),
            'certificate_enabled' => $this->inputBool('certificate_enabled'),
            'publish' => $this->inputBool('publish'),
        ];

        // A department is only meaningful on a course the company requires,
        // and an empty one must not be sent as a reference at all.
        $department = $this->input('mandatory_for_department_id');
        if ($payload['is_mandatory'] && $department !== '') {
            $payload['mandatory_for_department_id'] = $department;
        }

        $response = Api::post('/courses', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/learning/manage');
        }

        Flash::success(sprintf(
            '"%s" has been created. Add its lessons next.',
            $payload['title']
        ));

        $data = is_array($response['data']) ? $response['data'] : [];
        $courseId = (string) ($data['id'] ?? '');

        $this->redirect($courseId === ''
            ? '/learning/manage'
            : '/learning/manage?course=' . rawurlencode($courseId));
    }

    /** @param array<string, string> $parameters */
    public function storeLesson(array $parameters): void
    {
        $courseId = (string) ($parameters['id'] ?? '');
        $destination = '/learning/manage?course=' . rawurlencode($courseId);

        $minutes = $this->inputInt('duration_minutes');
        $sequence = $this->input('sequence');

        // The lesson form shares a page with the course form, so its fields
        // are named apart from theirs: redisplaying one must not fill in the
        // other with somebody's half-written course title.
        $form = [
            'lesson_title' => $this->input('lesson_title'),
            'lesson_description' => $this->input('lesson_description'),
            'video_url' => $this->input('video_url'),
            'duration_minutes' => (string) $minutes,
            'sequence' => $sequence,
        ];

        $payload = [
            'title' => $form['lesson_title'],
            'description' => $form['lesson_description'],
            'video_url' => $form['video_url'],
            // The service stores seconds; the form asks for minutes, which is
            // what a course author reads off the video they are adding.
            'duration_seconds' => $minutes * 60,
            'is_preview' => $this->inputBool('is_preview'),
        ];

        if ($sequence !== '') {
            $payload['sequence'] = (int) $sequence;
        }

        if ($minutes < 1) {
            Flash::error('Enter how long the lesson runs, in whole minutes.');
            Flash::withInput($form);
            $this->redirect($destination);
        }

        $response = Api::post('/courses/' . rawurlencode($courseId) . '/lessons', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $form, $destination);
        }

        Flash::success('Lesson added to the course.');
        $this->redirect($destination);
    }

    // -----------------------------------------------------------------------
    // Compliance and assignment
    // -----------------------------------------------------------------------

    public function compliance(): void
    {
        $response = Api::get('/learning/compliance');
        $this->guard($response, '/learning');

        $report = is_array($response['data']) ? $response['data'] : [];
        $courses = $this->listOf($report, 'courses');
        $department = $this->input('department');

        $outstanding = [];
        $departments = [];
        $people = [];

        foreach ($courses as $course) {
            $courseTitle = (string) ($course['title'] ?? 'Course');

            foreach ($this->listOf($course, 'employees') as $row) {
                $name = (string) ($row['department_name'] ?? '');

                // Collected before the filter is applied, so choosing a
                // department never removes the other options from the list.
                if ($name !== '') {
                    $departments[$name] = $name;
                }

                if ((string) ($row['status'] ?? '') === 'completed') {
                    continue;
                }

                if ($department !== '' && $name !== $department) {
                    continue;
                }

                $employeeId = (string) ($row['employee_id'] ?? '');

                $people[$employeeId] = [
                    'id' => $employeeId,
                    'full_name' => (string) ($row['full_name'] ?? 'Unknown employee'),
                    'department_name' => $name,
                ];

                $outstanding[] = $row + [
                    'course_id' => (string) ($course['course_id'] ?? ''),
                    'course_title' => $courseTitle,
                ];
            }
        }

        usort($outstanding, static function (array $a, array $b): int {
            return [!($a['is_overdue'] ?? false), (string) ($a['due_on'] ?? '9999-12-31'), (string) ($a['full_name'] ?? '')]
                <=> [!($b['is_overdue'] ?? false), (string) ($b['due_on'] ?? '9999-12-31'), (string) ($b['full_name'] ?? '')];
        });

        ksort($departments);
        uasort($people, static fn (array $a, array $b): int => strcmp($a['full_name'], $b['full_name']));

        View::render('learning/compliance', [
            'pageTitle' => 'Training compliance',
            'breadcrumbs' => [['Learning', '/learning'], ['Compliance', '']],
            'summary' => is_array($report['summary'] ?? null) ? $report['summary'] : [],
            'asOf' => (string) ($report['as_of'] ?? ''),
            'courses' => $courses,
            'outstanding' => $outstanding,
            'departments' => array_values($departments),
            'department' => $department,
            'people' => array_values($people),
        ]);
    }

    /**
     * Assigns one or more courses to one or more people.
     *
     * The service takes a single course per call and checks the whole target
     * list against the caller's reach before writing anything, so a manager
     * reaching outside their team is refused cleanly instead of half-assigned.
     */
    public function assign(): void
    {
        if (!Session::canAny(Permissions::LEARNING_ASSIGN_TEAM, Permissions::LEARNING_ASSIGN_ANY)) {
            Flash::error('You do not have permission to assign training.');
            $this->back('/learning');
        }

        $courseIds = $this->inputArray('course_ids');
        $employeeIds = $this->inputArray('employee_ids');
        $dueOn = $this->input('due_on');
        $input = ['due_on' => $dueOn];

        if ($courseIds === []) {
            Flash::error('Choose at least one course to assign.');
            Flash::withInput($input);
            $this->back('/learning');
        }

        if ($employeeIds === []) {
            Flash::error('Choose at least one person to assign it to.');
            Flash::withInput($input);
            $this->back('/learning');
        }

        $assigned = 0;
        $renewed = 0;

        foreach ($courseIds as $courseId) {
            $payload = ['course_id' => $courseId, 'employee_ids' => $employeeIds];

            if ($dueOn !== '') {
                $payload['due_on'] = $dueOn;
            }

            $response = Api::post('/enrolments/assign', $payload);

            // Stop at the first refusal. The rest of the list would fail for
            // the same reason and produce a wall of identical messages.
            if (!$response['ok']) {
                $this->backWithErrors($response, $input, '/learning');
            }

            $data = is_array($response['data']) ? $response['data'] : [];
            $assigned += (int) ($data['assigned'] ?? 0);
            $renewed += (int) ($data['renewed'] ?? 0);
        }

        Flash::success(sprintf(
            '%d new %s created%s.',
            $assigned,
            $assigned === 1 ? 'enrolment' : 'enrolments',
            $renewed === 0 ? '' : sprintf(' and %d existing %s renewed', $renewed, $renewed === 1 ? 'one' : 'ones')
        ));

        $this->back('/learning');
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    /**
     * The people and courses an assigner may pick from, or null for everyone
     * else.
     *
     * A manager holding only learning.assign.team may reach their direct
     * reports and nobody else, so their picker is built from that list rather
     * than from the directory. The service verifies the same reporting line
     * again on submission; this only keeps the form honest about it.
     *
     * @return array{scope: string, people: list<array<string, mixed>>, courses: list<array<string, mixed>>}|null
     */
    private function assignmentOptions(): ?array
    {
        $everyone = Session::can(Permissions::LEARNING_ASSIGN_ANY);

        if (!$everyone && !Session::can(Permissions::LEARNING_ASSIGN_TEAM)) {
            return null;
        }

        if ($everyone) {
            $people = Api::collection('/directory', ['per_page' => self::PICKER_LIMIT]);
        } else {
            $employeeId = Session::employeeId();
            $people = $employeeId === null
                ? []
                : Api::collection('/employees/' . rawurlencode($employeeId) . '/team');
        }

        // Only a published course can be assigned, so an unpublished draft is
        // left out of the picker rather than offered and then refused.
        $courses = array_values(array_filter(
            Api::collection('/courses', ['per_page' => self::PICKER_LIMIT]),
            static fn (array $course): bool => ($course['published_at'] ?? null) !== null
        ));

        return [
            'scope' => $everyone ? 'everyone' : 'team',
            'people' => $people,
            'courses' => $courses,
        ];
    }

    /**
     * Enrolment counts for the mandatory courses, keyed by course.
     *
     * The catalogue endpoint reports the caller's own enrolment and nothing
     * more, so the only published figures for a whole course are the ones in
     * the compliance report. Courses outside it show no number rather than a
     * made-up one.
     *
     * @return array<string, array{enrolled: int, completed: int}>
     */
    private function mandatoryEnrolmentCounts(): array
    {
        if (!Session::can(Permissions::LEARNING_ASSIGN_ANY)) {
            return [];
        }

        $report = Api::data('/learning/compliance');
        $counts = [];

        foreach (is_array($report) ? $this->listOf($report, 'courses') : [] as $course) {
            $totals = is_array($course['totals'] ?? null) ? $course['totals'] : [];

            $counts[(string) ($course['course_id'] ?? '')] = [
                'enrolled' => max(0, (int) ($totals['audience'] ?? 0) - (int) ($totals['not_enrolled'] ?? 0)),
                'completed' => (int) ($totals['completed'] ?? 0),
            ];
        }

        return $counts;
    }

    /**
     * The course to offer as "continue where you left off".
     *
     * @param list<array<string, mixed>> $inProgress
     * @return array{enrolment: array<string, mixed>, course: array<string, mixed>, lessons: list<array<string, mixed>>, next: ?array<string, mixed>}|null
     */
    private function continueWith(array $inProgress): ?array
    {
        $started = array_values(array_filter(
            $inProgress,
            static fn (array $row): bool => (string) ($row['status'] ?? '') === 'in_progress'
        ));

        if ($started === []) {
            return null;
        }

        // Most recently touched first: the dashboard orders by deadline, which
        // is the right order for obligations and the wrong one for "carry on".
        usort($started, static fn (array $a, array $b): int => strcmp(
            (string) ($b['updated_at'] ?? ''),
            (string) ($a['updated_at'] ?? '')
        ));

        $enrolment = $started[0];
        $detail = Api::data('/courses/' . rawurlencode((string) ($enrolment['course_id'] ?? '')));

        if (!is_array($detail) || !is_array($detail['course'] ?? null)) {
            return null;
        }

        $lessons = $this->listOf($detail, 'lessons');

        return [
            'enrolment' => $enrolment,
            'course' => $detail['course'],
            'lessons' => $lessons,
            'next' => $this->nextLesson($lessons, $enrolment),
        ];
    }

    /**
     * The lesson a learner should open next: the first one still unwatched,
     * or the one they were last on once everything is done.
     *
     * @param list<array<string, mixed>> $lessons
     * @param array<string, mixed>|null  $enrolment
     * @return array<string, mixed>|null
     */
    private function nextLesson(array $lessons, ?array $enrolment): ?array
    {
        foreach ($lessons as $lesson) {
            if (empty($lesson['is_completed']) && empty($lesson['is_locked'])) {
                return $lesson;
            }
        }

        $lastId = (string) ($enrolment['last_lesson_id'] ?? '');

        foreach ($lessons as $lesson) {
            if ($lastId !== '' && (string) ($lesson['id'] ?? '') === $lastId) {
                return $lesson;
            }
        }

        return $lessons[0] ?? null;
    }

    /**
     * The lesson following the given one, in course order.
     *
     * @param list<array<string, mixed>> $lessons
     * @return array<string, mixed>|null
     */
    private function lessonAfter(array $lessons, string $lessonId): ?array
    {
        foreach ($lessons as $index => $lesson) {
            if ((string) ($lesson['id'] ?? '') === $lessonId) {
                return $lessons[$index + 1] ?? null;
            }
        }

        return null;
    }

    /**
     * Which lesson to mark as the one in hand on the course page.
     *
     * @param list<array<string, mixed>> $lessons
     * @param array<string, mixed>|null  $enrolment
     */
    private function currentLessonId(array $lessons, ?array $enrolment): string
    {
        if ($enrolment === null) {
            return '';
        }

        $next = $this->nextLesson($lessons, $enrolment);

        return $next === null ? '' : (string) ($next['id'] ?? '');
    }

    /** @param list<array<string, mixed>> $lessons */
    private function completedCount(array $lessons): int
    {
        return count(array_filter($lessons, static fn (array $lesson): bool => !empty($lesson['is_completed'])));
    }

    /**
     * Overdue work first, then by deadline, then by title.
     *
     * @param list<array<string, mixed>> $enrolments
     * @return list<array<string, mixed>>
     */
    private function byUrgency(array $enrolments): array
    {
        usort($enrolments, static function (array $a, array $b): int {
            return [!($a['is_overdue'] ?? false), (string) ($a['due_on'] ?? '9999-12-31'), (string) ($a['course_title'] ?? '')]
                <=> [!($b['is_overdue'] ?? false), (string) ($b['due_on'] ?? '9999-12-31'), (string) ($b['course_title'] ?? '')];
        });

        return $enrolments;
    }

    /**
     * Reads one list out of an API payload, whatever else it contains.
     *
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function listOf(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function keyBy(array $rows, string $key): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(string) ($row[$key] ?? '')] = $row;
        }

        return $keyed;
    }

    /**
     * Takes the graded attempt parked by submitQuiz, if it is for this course.
     *
     * @return array<string, mixed>|null
     */
    private function takeQuizResult(string $courseId): ?array
    {
        $parked = Session::get(self::QUIZ_RESULT_KEY);
        Session::forget(self::QUIZ_RESULT_KEY);

        if (!is_array($parked) || !isset($parked[$courseId]) || !is_array($parked[$courseId])) {
            return null;
        }

        return $parked[$courseId];
    }
}
