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
 * Goals, appraisals and continuous feedback.
 *
 * Every rule about who may read what lives in the talent service, and this
 * controller is careful never to imply a different one. Three of those rules
 * shape the pages below:
 *
 *   - a self review belongs to the person writing it, and the service will not
 *     hand it to anybody else, so no screen here offers to show one;
 *   - a review written about somebody reaches them only after it is submitted,
 *     so "Reviews about me" lists what the service returned and says plainly
 *     that unfinished ones are not shown rather than hinting at their contents;
 *   - an anonymous item comes back with its author's identifier removed. When
 *     that identifier is null the page prints "Anonymous"; it never falls back
 *     to a name from anywhere else.
 *
 * Names are not stored by the talent service, only employee identifiers, so
 * they are resolved once per request from the company directory and cached on
 * the instance. A caller who cannot read the directory simply sees a neutral
 * placeholder instead of a name.
 */
final class PerformanceController extends Controller
{
    /** The whole of one person's attention in a cycle, expressed as weight. */
    private const WEIGHT_BUDGET = 100.0;

    /** Committed weight at which the interface starts warning. */
    private const WEIGHT_WARN_AT = 85.0;

    /** Directory pages to follow when building the name map. */
    private const DIRECTORY_PAGES = 6;

    private const DIRECTORY_PAGE_SIZE = 100;

    /** @var list<array<string, mixed>>|null Directory entries, fetched at most once. */
    private ?array $directory = null;

    // -----------------------------------------------------------------------
    // Overview
    // -----------------------------------------------------------------------

    public function index(): void
    {
        $response = Api::get('/talent/my-summary');
        $this->guard($response, '/');

        $summary = is_array($response['data']) ? $response['data'] : [];
        $goalCycle = is_array($summary['goal_cycle'] ?? null) ? $summary['goal_cycle'] : null;
        $goalSummary = is_array($summary['goals'] ?? null) ? $summary['goals'] : [];
        $latestReview = is_array($summary['latest_review'] ?? null) ? $summary['latest_review'] : null;
        $feedbackCounts = is_array($summary['feedback'] ?? null) ? $summary['feedback'] : [];

        // Goals are read for the cycle the summary settled on, so the weight
        // total on the page and the cap the service enforces are the same sum.
        $goalQuery = ['status' => 'active', 'per_page' => 50];
        if ($goalCycle !== null && isset($goalCycle['id'])) {
            $goalQuery['goal_cycle_id'] = (string) $goalCycle['id'];
        }

        $goals = Api::collection('/goals', $goalQuery);

        $outstanding = Api::collection('/reviews', ['scope' => 'to_complete', 'per_page' => 20]);
        $cycles = $this->reviewCyclesById();
        $currentCycle = $this->currentReviewCycle($cycles);

        $feedback = Api::collection('/feedback/received', ['per_page' => 5]);

        $committedWeight = (float) ($goalSummary['weight_percent'] ?? 0);

        View::render('performance/index', [
            'pageTitle' => 'Performance',
            'breadcrumbs' => [['Performance', '']],
            'goalCycle' => $goalCycle,
            'goalSummary' => $goalSummary,
            'goals' => $goals,
            'latestReview' => $latestReview,
            'feedbackCounts' => $feedbackCounts,
            'feedback' => $feedback,
            'names' => $this->names(),
            'outstanding' => $outstanding,
            'cycles' => $cycles,
            'currentCycle' => $currentCycle,
            'committedWeight' => $committedWeight,
            'availableWeight' => max(0.0, self::WEIGHT_BUDGET - $committedWeight),
            'weightBudget' => self::WEIGHT_BUDGET,
            'weightWarnAt' => self::WEIGHT_WARN_AT,
            'canSetGoals' => Session::can(Permissions::TALENT_UPDATE_OWN_GOALS),
            'canManageCycles' => Session::can(Permissions::TALENT_MANAGE_CYCLES),
        ]);
    }

    // -----------------------------------------------------------------------
    // Goals
    // -----------------------------------------------------------------------

    public function newGoal(): void
    {
        $response = Api::get('/goals/cycles', ['status' => 'open', 'per_page' => 20]);
        $this->guard($response, '/performance');

        $cycles = is_array($response['data']) ? $response['data'] : [];

        // What is still available in each cycle is a separate question per
        // cycle, because the service caps the weight one cycle at a time.
        $weights = [];
        foreach (array_slice($cycles, 0, 5) as $cycle) {
            $cycleId = (string) ($cycle['id'] ?? '');
            if ($cycleId === '') {
                continue;
            }

            $cycleSummary = Api::data('/talent/my-summary', ['goal_cycle_id' => $cycleId], []);
            $committed = is_array($cycleSummary) && is_array($cycleSummary['goals'] ?? null)
                ? (float) ($cycleSummary['goals']['weight_percent'] ?? 0)
                : 0.0;

            $weights[$cycleId] = [
                'committed' => $committed,
                'available' => max(0.0, self::WEIGHT_BUDGET - $committed),
            ];
        }

        $reports = [];
        $employeeId = Session::employeeId();

        if (Session::can(Permissions::TALENT_REVIEW_TEAM) && $employeeId !== null) {
            $reports = Api::collection('/employees', [
                'manager_id' => $employeeId,
                'per_page' => 100,
            ]);
        }

        View::render('performance/goal-new', [
            'pageTitle' => 'New goal',
            'breadcrumbs' => [['Performance', '/performance'], ['New goal', '']],
            'cycles' => $cycles,
            'weights' => $weights,
            'reports' => $reports,
            'weightBudget' => self::WEIGHT_BUDGET,
        ]);
    }

    public function storeGoal(): void
    {
        $payload = [
            'goal_cycle_id' => $this->input('goal_cycle_id'),
            'title' => $this->input('title'),
            'description' => $this->input('description'),
            'category' => $this->input('category'),
            'metric' => $this->input('metric'),
            'target_value' => $this->input('target_value'),
            'current_value' => $this->input('current_value'),
            'unit' => $this->input('unit'),
            'weight_percent' => $this->input('weight_percent'),
            'starts_on' => $this->input('starts_on'),
            'due_on' => $this->input('due_on'),
            'status' => $this->input('status', 'active'),
        ];

        // Sent only when a manager picked somebody; otherwise the service files
        // the goal against the caller, which is what an employee always wants.
        $employeeId = $this->input('employee_id');
        if ($employeeId !== '') {
            $payload['employee_id'] = $employeeId;
        }

        $response = Api::post('/goals', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/performance/goals/new');
        }

        Flash::success('Your goal has been set.');
        $this->redirect('/performance');
    }

    /** @param array<string, string> $parameters */
    public function updateGoalProgress(array $parameters): void
    {
        $goalId = (string) ($parameters['id'] ?? '');

        $payload = [];
        $currentValue = $this->input('current_value');
        $progressPercent = $this->input('progress_percent');

        if ($currentValue !== '') {
            $payload['current_value'] = $currentValue;
        }

        if ($progressPercent !== '') {
            $payload['progress_percent'] = $progressPercent;
        }

        if ($payload === []) {
            Flash::error('Record the latest figure before saving an update.');
            $this->back('/performance');
        }

        $note = $this->input('note');
        if ($note !== '') {
            $payload['note'] = $note;
        }

        $response = Api::post('/goals/' . rawurlencode($goalId) . '/progress', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/performance');
        }

        $goal = is_array($response['data']) ? $response['data'] : [];
        $progress = (float) ($goal['progress_percent'] ?? 0);

        Flash::success(sprintf('Progress recorded. That goal now stands at %s%%.', $this->trimNumber($progress)));
        $this->back('/performance');
    }

    // -----------------------------------------------------------------------
    // Reviews
    // -----------------------------------------------------------------------

    public function reviews(): void
    {
        // One call returns both halves of this page: the service's own listing
        // is already scoped to what the caller may read, and splitting it here
        // keeps the two sections consistent with each other.
        $response = Api::get('/reviews', [
            'scope' => 'all',
            'page' => $this->page(),
            'per_page' => 100,
        ]);
        $this->guard($response, '/performance');

        $reviews = is_array($response['data']) ? $response['data'] : [];
        $me = (string) (Session::employeeId() ?? '');

        $toComplete = [];
        $aboutMe = [];

        foreach ($reviews as $review) {
            if (!is_array($review)) {
                continue;
            }

            if (!empty($review['is_reviewer'])) {
                $toComplete[] = $review;
            }

            if ($me !== '' && (string) ($review['employee_id'] ?? '') === $me) {
                $aboutMe[] = $review;
            }
        }

        // Unfinished work first: that is the part of the page that needs acting on.
        usort($toComplete, static function (array $left, array $right): int {
            $rank = static fn (array $review): int => ($review['status'] ?? '') === 'draft' ? 0 : 1;

            return $rank($left) <=> $rank($right);
        });

        View::render('performance/reviews', [
            'pageTitle' => 'Reviews',
            'breadcrumbs' => [['Performance', '/performance'], ['Reviews', '']],
            'toComplete' => $toComplete,
            'aboutMe' => $aboutMe,
            'cycles' => $this->reviewCyclesById(),
            'names' => $this->names(),
            'meta' => $response['meta'],
            'me' => $me,
        ]);
    }

    /** @param array<string, string> $parameters */
    public function review(array $parameters): void
    {
        $reviewId = (string) ($parameters['id'] ?? '');

        // The talent service registers "/reviews/competencies" and
        // "/reviews/cycles" ahead of "/reviews/{id}", so an identifier that is
        // not a record identifier would be answered by one of those listings
        // with a perfectly successful 200 and this page would render a review
        // built from something that is not a review. Every key in that schema
        // is a UUID, so anything else is simply a page that does not exist.
        if (!$this->looksLikeRecordId($reviewId)) {
            $this->notFound();

            return;
        }

        $response = Api::get('/reviews/' . rawurlencode($reviewId));
        $this->guard($response, '/performance/reviews');

        $review = is_array($response['data']) ? $response['data'] : [];
        $cycle = is_array($review['cycle'] ?? null) ? $review['cycle'] : [];
        $subjectId = (string) ($review['employee_id'] ?? '');

        $ratings = [];
        foreach (is_array($review['ratings'] ?? null) ? $review['ratings'] : [] as $rating) {
            if (is_array($rating) && isset($rating['competency_id'])) {
                $ratings[(string) $rating['competency_id']] = $rating;
            }
        }

        // A framework may be narrowed to the subject's designation. A reviewer
        // who cannot read that record still gets the company-wide framework
        // rather than an empty form.
        $subject = Api::data('/employees/' . rawurlencode($subjectId), [], null);
        $designationId = is_array($subject) ? (string) ($subject['designation_id'] ?? '') : '';

        $competencies = Api::collection(
            '/reviews/competencies',
            $designationId === '' ? [] : ['designation_id' => $designationId]
        );

        // Shown so the reviewer can see what was actually committed to. The
        // service refuses this to anyone without standing over the subject's
        // goals, and the page then simply omits the panel.
        $goals = Api::collection('/goals', ['employee_id' => $subjectId, 'per_page' => 50]);

        $isReviewer = (bool) ($review['is_reviewer'] ?? false);
        $status = (string) ($review['status'] ?? 'draft');
        $reviewType = (string) ($review['review_type'] ?? '');

        View::render('performance/review', [
            'pageTitle' => 'Review',
            'breadcrumbs' => [
                ['Performance', '/performance'],
                ['Reviews', '/performance/reviews'],
                ['Review', ''],
            ],
            'review' => $review,
            'cycle' => $cycle,
            'competencies' => $competencies,
            'ratings' => $ratings,
            'goals' => $goals,
            'isReviewer' => $isReviewer,
            'isEditable' => $isReviewer && $status === 'draft',
            'canAcknowledge' => !$isReviewer
                && $status === 'submitted'
                && in_array($reviewType, ['manager', 'skip_level'], true),
            'scaleMax' => (int) ($cycle['rating_scale_max'] ?? 5),
        ]);
    }

    /** @param array<string, string> $parameters */
    public function saveReview(array $parameters): void
    {
        $reviewId = (string) ($parameters['id'] ?? '');
        $destination = '/performance/reviews/' . rawurlencode($reviewId);

        $payload = $this->reviewPayload();

        $response = Api::patch('/reviews/' . rawurlencode($reviewId), $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $this->flatten($payload), $destination);
        }

        Flash::success('Your draft has been saved. Nobody else can read it until you submit it.');
        $this->redirect($destination);
    }

    /** @param array<string, string> $parameters */
    public function submitReview(array $parameters): void
    {
        $reviewId = (string) ($parameters['id'] ?? '');
        $destination = '/performance/reviews/' . rawurlencode($reviewId);

        // Whatever is on screen is saved first. Submitting locks the review, so
        // an unsaved sentence left in a box would otherwise be lost for good.
        $payload = $this->reviewPayload();
        $saved = Api::patch('/reviews/' . rawurlencode($reviewId), $payload);

        if (!$saved['ok']) {
            $this->backWithErrors($saved, $this->flatten($payload), $destination);
        }

        $response = Api::post('/reviews/' . rawurlencode($reviewId) . '/submit');

        if (!$response['ok']) {
            $this->backWithErrors($response, $this->flatten($payload), $destination);
        }

        Flash::success('Your review has been submitted. It can no longer be edited.');
        $this->redirect($destination);
    }

    /** @param array<string, string> $parameters */
    public function acknowledgeReview(array $parameters): void
    {
        $reviewId = (string) ($parameters['id'] ?? '');
        $destination = '/performance/reviews/' . rawurlencode($reviewId);

        $payload = ['employee_comments' => $this->input('employee_comments')];

        $response = Api::post('/reviews/' . rawurlencode($reviewId) . '/acknowledge', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, $destination);
        }

        Flash::success('You have acknowledged this review.');
        $this->redirect($destination);
    }

    // -----------------------------------------------------------------------
    // Feedback
    // -----------------------------------------------------------------------

    public function feedback(): void
    {
        $canViewTeam = Session::can(Permissions::TALENT_VIEW_TEAM);

        $tab = $this->input('tab', 'received');
        if (!in_array($tab, ['received', 'sent', 'team'], true) || ($tab === 'team' && !$canViewTeam)) {
            $tab = 'received';
        }

        $endpoint = match ($tab) {
            'sent' => '/feedback/sent',
            'team' => '/feedback/team',
            default => '/feedback/received',
        };

        $response = Api::get($endpoint, ['page' => $this->page(), 'per_page' => 20]);
        $this->guard($response, '/performance');

        $summary = Api::data('/talent/my-summary', [], []);
        $counts = is_array($summary) && is_array($summary['feedback'] ?? null) ? $summary['feedback'] : [];

        $me = (string) (Session::employeeId() ?? '');

        View::render('performance/feedback', [
            'pageTitle' => 'Feedback',
            'breadcrumbs' => [['Performance', '/performance'], ['Feedback', '']],
            'tab' => $tab,
            'items' => is_array($response['data']) ? $response['data'] : [],
            'meta' => $response['meta'],
            'counts' => $counts,
            'people' => array_values(array_filter(
                $this->people(),
                static fn (array $person): bool => (string) ($person['id'] ?? '') !== $me
            )),
            'names' => $this->names(),
            'goals' => Api::collection('/goals', ['per_page' => 50]),
            'canViewTeam' => $canViewTeam,
        ]);
    }

    public function sendFeedback(): void
    {
        $payload = [
            'to_employee_id' => $this->input('to_employee_id'),
            'feedback_type' => $this->input('feedback_type'),
            'visibility' => $this->input('visibility'),
            'subject' => $this->input('subject'),
            'body' => $this->input('body'),
            'is_anonymous' => $this->inputBool('is_anonymous'),
        ];

        $goalId = $this->input('related_goal_id');
        if ($goalId !== '') {
            $payload['related_goal_id'] = $goalId;
        }

        $response = Api::post('/feedback', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/performance/feedback');
        }

        Flash::success('Your feedback has been sent.');
        $this->redirect('/performance/feedback?tab=sent');
    }

    // -----------------------------------------------------------------------
    // Review cycles
    // -----------------------------------------------------------------------

    public function cycles(): void
    {
        $response = Api::get('/reviews/cycles', ['page' => $this->page(), 'per_page' => 20]);
        $this->guard($response, '/performance');

        $cycles = is_array($response['data']) ? $response['data'] : [];

        // Completion and participation are aggregate figures behind a separate
        // permission. Without it the columns read "—" rather than guessing.
        $summaries = [];
        if (Session::can(Permissions::TALENT_VIEW_ALL)) {
            foreach ($cycles as $cycle) {
                $cycleId = (string) ($cycle['id'] ?? '');
                if ($cycleId === '') {
                    continue;
                }

                $summary = Api::data('/reviews/cycles/' . rawurlencode($cycleId) . '/summary', [], null);
                if (is_array($summary)) {
                    $summaries[$cycleId] = $summary;
                }
            }
        }

        View::render('performance/cycles', [
            'pageTitle' => 'Review cycles',
            'breadcrumbs' => [['Performance', '/performance'], ['Review cycles', '']],
            'cycles' => $cycles,
            'summaries' => $summaries,
            'meta' => $response['meta'],
            'departments' => Api::collection('/departments', ['per_page' => 100]),
        ]);
    }

    public function storeCycle(): void
    {
        $payload = [
            'name' => $this->input('name'),
            'period_start' => $this->input('period_start'),
            'period_end' => $this->input('period_end'),
            'self_review_due_on' => $this->input('self_review_due_on'),
            'manager_review_due_on' => $this->input('manager_review_due_on'),
            'rating_scale_max' => $this->input('rating_scale_max', '5'),
            'instructions' => $this->input('instructions'),
        ];

        $response = Api::post('/reviews/cycles', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/performance/cycles');
        }

        Flash::success('The cycle has been created as a draft. Open it when you are ready to enrol people.');
        $this->redirect('/performance/cycles');
    }

    /** @param array<string, string> $parameters */
    public function openCycle(array $parameters): void
    {
        $cycleId = (string) ($parameters['id'] ?? '');

        $scope = $this->input('scope', 'all');
        $payload = ['scope' => in_array($scope, ['all', 'department'], true) ? $scope : 'all'];

        $departmentId = $this->input('department_id');
        if ($payload['scope'] === 'department' && $departmentId !== '') {
            $payload['department_id'] = $departmentId;
        }

        $response = Api::post('/reviews/cycles/' . rawurlencode($cycleId) . '/open', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/performance/cycles');
        }

        $result = is_array($response['data']) ? $response['data'] : [];
        $enrolled = (int) ($result['participants_enrolled'] ?? 0);
        $created = (int) ($result['reviews_created'] ?? 0);
        $withoutManager = (int) ($result['participants_without_manager'] ?? 0);

        Flash::success(sprintf(
            'The cycle is open. %d %s enrolled and %d %s created.',
            $enrolled,
            $enrolled === 1 ? 'person was' : 'people were',
            $created,
            $created === 1 ? 'review form was' : 'review forms were'
        ));

        if ($withoutManager > 0) {
            Flash::warning(sprintf(
                '%d %s no manager on record, so only a self review was created for them.',
                $withoutManager,
                $withoutManager === 1 ? 'participant has' : 'participants have'
            ));
        }

        $this->redirect('/performance/cycles');
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    /**
     * Everything the form holds, ready for the talent service.
     *
     * Manager comments and the anonymity flag are only ever sent when the form
     * actually offered them: the service rejects a manager comment on a peer
     * review and an anonymity flag on anything but a peer review, and sending
     * either one blindly would turn a valid save into an error.
     *
     * @return array<string, mixed>
     */
    private function reviewPayload(): array
    {
        $payload = [
            'overall_rating' => $this->input('overall_rating'),
            'strengths' => $this->input('strengths'),
            'improvements' => $this->input('improvements'),
            'achievements' => $this->input('achievements'),
        ];

        if (isset($_POST['manager_comments'])) {
            $payload['manager_comments'] = $this->input('manager_comments');
        }

        if ($this->input('anonymity_offered') === '1') {
            $payload['is_anonymous'] = $this->inputBool('is_anonymous');
        }

        $ratings = $this->ratingsPayload();
        if ($ratings !== []) {
            $payload['ratings'] = $ratings;
        }

        return $payload;
    }

    /**
     * The competency scores, as the list shape the service validates.
     *
     * A competency left blank is omitted rather than sent as zero, because a
     * zero is a score somebody chose to give and an empty box is not.
     *
     * @return list<array{competency_id: string, rating: string, comment: string}>
     */
    private function ratingsPayload(): array
    {
        $raw = $_POST['ratings'] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $ratings = [];

        foreach ($raw as $competencyId => $entry) {
            if (!is_string($competencyId) || !is_array($entry)) {
                continue;
            }

            $rating = isset($entry['rating']) && is_scalar($entry['rating']) ? trim((string) $entry['rating']) : '';
            $comment = isset($entry['comment']) && is_scalar($entry['comment']) ? trim((string) $entry['comment']) : '';

            if ($rating === '') {
                continue;
            }

            $ratings[] = [
                'competency_id' => $competencyId,
                'rating' => $rating,
                'comment' => $comment,
            ];
        }

        // The service refuses more than fifty in one request.
        return array_slice($ratings, 0, 50);
    }

    /**
     * Reduces a payload to scalars so a rejected form can be redisplayed.
     *
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function flatten(array $payload): array
    {
        $flat = [];

        foreach ($payload as $key => $value) {
            if (is_scalar($value)) {
                $flat[$key] = (string) $value;
            }
        }

        return $flat;
    }

    /**
     * Review cycles the caller can see, indexed by identifier.
     *
     * @return array<string, array<string, mixed>>
     */
    private function reviewCyclesById(): array
    {
        $cycles = [];

        foreach (Api::collection('/reviews/cycles', ['per_page' => 50]) as $cycle) {
            if (is_array($cycle) && isset($cycle['id'])) {
                $cycles[(string) $cycle['id']] = $cycle;
            }
        }

        return $cycles;
    }

    /**
     * The cycle a person is being appraised in right now.
     *
     * @param array<string, array<string, mixed>> $cycles
     * @return array<string, mixed>|null
     */
    private function currentReviewCycle(array $cycles): ?array
    {
        foreach ($cycles as $cycle) {
            if (in_array((string) ($cycle['status'] ?? ''), ['open', 'in_review'], true)) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * The company directory, read once per request.
     *
     * @return list<array<string, mixed>>
     */
    private function people(): array
    {
        if ($this->directory !== null) {
            return $this->directory;
        }

        $people = [];

        for ($page = 1; $page <= self::DIRECTORY_PAGES; $page++) {
            $response = Api::get('/directory', ['page' => $page, 'per_page' => self::DIRECTORY_PAGE_SIZE]);

            if (!$response['ok'] || !is_array($response['data'])) {
                break;
            }

            foreach ($response['data'] as $person) {
                if (is_array($person) && isset($person['id'])) {
                    $people[] = $person;
                }
            }

            $totalPages = (int) ($response['meta']['total_pages'] ?? 1);
            if ($page >= $totalPages) {
                break;
            }
        }

        return $this->directory = $people;
    }

    /**
     * Employee identifier to display name.
     *
     * @return array<string, string>
     */
    private function names(): array
    {
        $names = [];

        foreach ($this->people() as $person) {
            $full = trim((string) ($person['full_name'] ?? ''));

            if ($full === '') {
                $full = trim(((string) ($person['first_name'] ?? '')) . ' ' . ((string) ($person['last_name'] ?? '')));
            }

            if ($full !== '') {
                $names[(string) $person['id']] = $full;
            }
        }

        return $names;
    }

    /** True when a path segment has the shape of a record identifier. */
    private function looksLikeRecordId(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /** Renders the not-found page for a record that cannot exist. */
    private function notFound(): void
    {
        http_response_code(404);
        View::render('errors/404', ['pageTitle' => 'Not found']);
    }

    /** Renders a percentage without its trailing zeros. */
    private function trimNumber(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
