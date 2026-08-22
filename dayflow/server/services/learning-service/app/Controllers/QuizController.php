<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Courses;
use App\Models\Enrolments;
use App\Models\QuizAttempts;
use App\Models\QuizQuestions;
use App\Models\Quizzes;
use App\Policies\EnrolmentPolicy;
use App\Services\CourseCompletion;
use App\Services\QuizGrader;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/**
 * Serves and marks course assessments.
 *
 * The answer key never leaves this service until an attempt has been graded,
 * and even then only when the learner has no attempt left to gain from it.
 */
final class QuizController
{
    private Courses $courses;
    private Quizzes $quizzes;
    private QuizQuestions $questions;
    private QuizAttempts $attempts;
    private Enrolments $enrolments;
    private QuizGrader $grader;
    private CourseCompletion $completion;

    public function __construct()
    {
        $this->courses = new Courses();
        $this->quizzes = new Quizzes();
        $this->questions = new QuizQuestions();
        $this->attempts = new QuizAttempts();
        $this->enrolments = new Enrolments();
        $this->grader = new QuizGrader();
        $this->completion = new CourseCompletion();
    }

    public function show(Request $request): Response
    {
        [$course, $quiz, $enrolment] = $this->context($request);

        $questions = $this->questions->forQuiz((string) $quiz['id']);
        $used = $this->attempts->countForEnrolment((string) $quiz['id'], (string) $enrolment['id']);

        return Response::ok([
            'course_id' => $course['id'],
            'course_title' => $course['title'],
            'enrolment_id' => $enrolment['id'],
            'quiz' => [
                'id' => $quiz['id'],
                'title' => $quiz['title'],
                'pass_percent' => $quiz['pass_percent'],
                'max_attempts' => $quiz['max_attempts'],
                'question_count' => count($questions),
                'total_points' => array_sum(array_map(
                    static fn (array $question): int => (int) $question['points'],
                    $questions
                )),
            ],
            'attempts_used' => $used,
            'attempts_remaining' => max(0, (int) $quiz['max_attempts'] - $used),
            'best_score_percent' => $this->attempts->bestScore((string) $enrolment['id']),
            'questions' => $this->grader->withoutAnswerKey($questions),
        ]);
    }

    public function submit(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'answers' => 'required|array',
        ])->validated();

        [$course, $quiz, $enrolment] = $this->context($request);

        $questions = $this->questions->forQuiz((string) $quiz['id']);

        if ($questions === []) {
            throw HttpException::conflict('This quiz has no questions yet.');
        }

        $used = $this->attempts->countForEnrolment((string) $quiz['id'], (string) $enrolment['id']);
        $maxAttempts = (int) $quiz['max_attempts'];

        if ($used >= $maxAttempts) {
            throw HttpException::conflict(
                'You have used every attempt available on this quiz.',
                ['max_attempts' => $maxAttempts]
            );
        }

        $selections = $this->readSelections($data['answers'], $questions);
        $result = $this->grader->grade($questions, $selections);

        $passed = $result['score_percent'] >= (int) $quiz['pass_percent'];
        $attemptNumber = $used + 1;
        $now = Clock::iso();

        $attempt = $this->attempts->create([
            'quiz_id' => (string) $quiz['id'],
            'enrolment_id' => (string) $enrolment['id'],
            'employee_id' => (string) $enrolment['employee_id'],
            'answers' => $result['answers'],
            'score_percent' => $result['score_percent'],
            'passed' => $passed,
            'started_at' => $now,
            'submitted_at' => $now,
            'attempt_number' => $attemptNumber,
            'created_at' => $now,
        ]);

        $remaining = max(0, $maxAttempts - $attemptNumber);

        $certificate = null;
        $justCompleted = false;

        if ($passed) {
            $outcome = $this->completion->complete($enrolment, $course, $result['score_percent']);
            $enrolment = $outcome['enrolment'];
            $certificate = $outcome['certificate'];
            $justCompleted = $outcome['newly_completed'];
        }

        AuditLog::record(
            $request,
            'learning.quiz.submitted',
            'quiz_attempt',
            (string) $attempt['id'],
            [],
            [
                'quiz_id' => (string) $quiz['id'],
                'enrolment_id' => (string) $enrolment['id'],
                'score_percent' => $result['score_percent'],
                'passed' => $passed,
                'attempt_number' => $attemptNumber,
            ]
        );

        // The worked answers are held back while another attempt is available,
        // otherwise a deliberate first failure would hand over the answer key.
        $reveal = $passed || $remaining === 0;

        return Response::created([
            'attempt' => $attempt,
            'score_percent' => $result['score_percent'],
            'points_earned' => $result['points_earned'],
            'points_possible' => $result['points_possible'],
            'correct_count' => $result['correct_count'],
            'question_count' => count($questions),
            'pass_percent' => $quiz['pass_percent'],
            'passed' => $passed,
            'attempt_number' => $attemptNumber,
            'attempts_remaining' => $remaining,
            'course_completed' => $justCompleted,
            'enrolment' => $enrolment,
            'certificate' => $certificate,
            'review' => $reveal ? $this->grader->review($questions, $result['answers']) : null,
        ]);
    }

    /**
     * Resolves the course, its live quiz and the caller's enrolment.
     *
     * The enrolment is the real gate here: the route only proves the caller is
     * signed in, and an assessment is not part of the public catalogue.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function context(Request $request): array
    {
        $parameters = Validator::make($request->routeParameters(), [
            'id' => 'required|uuid',
        ])->validated();

        $course = $this->courses->find((string) $parameters['id']);

        if ($course === null) {
            throw HttpException::notFound('That course does not exist.');
        }

        $quiz = $this->quizzes->activeForCourse((string) $course['id']);

        if ($quiz === null) {
            throw HttpException::notFound('This course does not have a quiz.');
        }

        $principal = $request->principal();
        $employeeId = EnrolmentPolicy::requireEmployeeId($principal);
        $enrolment = $this->enrolments->forCourseAndEmployee((string) $course['id'], $employeeId);

        if ($enrolment === null) {
            throw HttpException::forbidden('Enrol on this course before taking its quiz.');
        }

        return [$course, $quiz, $enrolment];
    }

    /**
     * Turns a submitted answer list into question id => chosen option index.
     *
     * Anything referring to a question outside this quiz, or to an option that
     * does not exist, is rejected rather than quietly dropped.
     *
     * @param mixed                      $raw
     * @param list<array<string, mixed>> $questions
     * @return array<string, int>
     */
    private function readSelections(mixed $raw, array $questions): array
    {
        $optionCounts = [];
        foreach ($questions as $question) {
            $optionCounts[(string) $question['id']] = count((array) $question['options']);
        }

        $selections = [];

        foreach ((array) $raw as $entry) {
            if (!is_array($entry) || !isset($entry['question_id'])) {
                throw $this->malformedAnswers();
            }

            $questionId = strtolower(trim((string) $entry['question_id']));
            $selected = $entry['selected_index'] ?? null;

            if (!isset($optionCounts[$questionId])) {
                throw HttpException::unprocessable(
                    'One of the answers refers to a question that is not part of this quiz.',
                    ['answers' => ['Submit answers for this quiz only.']]
                );
            }

            if ($selected === null || $selected === '') {
                // An unanswered question is allowed; it simply scores nothing.
                continue;
            }

            if (!is_numeric($selected) || (int) $selected < 0 || (int) $selected >= $optionCounts[$questionId]) {
                throw HttpException::unprocessable(
                    'One of the answers selects an option that does not exist.',
                    ['answers' => ['Choose one of the options offered for each question.']]
                );
            }

            $selections[$questionId] = (int) $selected;
        }

        return $selections;
    }

    private function malformedAnswers(): HttpException
    {
        return HttpException::unprocessable(
            'The submitted answers are not in the expected form.',
            ['answers' => ['Each answer needs a question_id and a selected_index.']]
        );
    }
}
