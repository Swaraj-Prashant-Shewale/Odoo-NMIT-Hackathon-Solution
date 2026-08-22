<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Competencies;
use App\Models\ReviewCycles;
use App\Models\ReviewParticipants;
use App\Models\ReviewRatings;
use App\Models\Reviews;
use App\Policies\ReviewVisibility;
use App\Policies\TalentAccess;
use App\Services\EmployeeDirectory;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

final class ReviewController
{
    /** Review types whose subject is expected to acknowledge the outcome. */
    private const ACKNOWLEDGEABLE = ['manager', 'skip_level'];

    private Reviews $reviews;
    private ReviewCycles $cycles;
    private ReviewRatings $ratings;
    private ReviewParticipants $participants;
    private Competencies $competencies;

    public function __construct()
    {
        $this->reviews = new Reviews();
        $this->cycles = new ReviewCycles();
        $this->ratings = new ReviewRatings();
        $this->participants = new ReviewParticipants();
        $this->competencies = new Competencies();
    }

    /** GET /reviews/competencies - the framework a review is scored against. */
    public function competencies(Request $request): Response
    {
        $filters = Validator::make($request->query, [
            'designation_id' => 'nullable|uuid',
        ])->validated();

        return Response::ok($this->competencies->frameworkFor($filters['designation_id'] ?? null));
    }

    /** GET /reviews */
    public function index(Request $request): Response
    {
        $principal = $request->principal();
        $employeeId = TalentAccess::employeeId($principal);

        $filters = Validator::make($request->query, [
            'review_cycle_id' => 'nullable|uuid',
            'scope' => 'nullable|in:to_complete,received,all',
            'status' => 'nullable|in:draft,submitted,acknowledged',
        ])->validated();

        $page = $this->reviews->visibleTo($employeeId, $filters, $request->page(), $request->perPage());

        $cycleNames = $this->cycleNames($page['data']);

        $page['data'] = array_map(function (array $review) use ($principal, $cycleNames): array {
            $review = ReviewVisibility::present($review, $principal);
            $review['cycle_name'] = $cycleNames[(string) $review['review_cycle_id']] ?? null;
            $review['is_reviewer'] = ReviewVisibility::isReviewer($principal, $review);

            return $review;
        }, $page['data']);

        return Response::page($page);
    }

    /** GET /reviews/{id} */
    public function show(Request $request): Response
    {
        $principal = $request->principal();
        TalentAccess::employeeId($principal);

        $review = $this->reviews->find(TalentAccess::recordId($request));
        if ($review === null) {
            throw HttpException::notFound('The requested record does not exist.');
        }

        ReviewVisibility::assertVisible($principal, $review);

        $review = ReviewVisibility::present($review, $principal);
        $review['cycle'] = $this->cycles->find((string) $review['review_cycle_id']);
        $review['ratings'] = $this->ratings->forReview((string) $review['id']);
        $review['is_reviewer'] = ReviewVisibility::isReviewer($principal, $review);

        $directory = EmployeeDirectory::forRequest($request);
        $subject = $directory->find((string) $review['employee_id']);
        $review['employee_name'] = $subject === null ? null : EmployeeDirectory::displayName($subject);

        // A null reviewer_id at this point means the name was withheld above,
        // so no lookup is attempted and nothing can leak through the decoration.
        $review['reviewer_name'] = null;
        if ($review['reviewer_id'] !== null) {
            $reviewer = $directory->find((string) $review['reviewer_id']);
            $review['reviewer_name'] = $reviewer === null ? null : EmployeeDirectory::displayName($reviewer);
        }

        return Response::ok($review);
    }

    /** PATCH /reviews/{id} */
    public function update(Request $request): Response
    {
        $principal = $request->principal();
        TalentAccess::employeeId($principal);

        $review = $this->reviews->find(TalentAccess::recordId($request));
        if ($review === null) {
            throw HttpException::notFound('The requested record does not exist.');
        }

        ReviewVisibility::assertMayEdit($principal, $review);

        $cycle = $this->cycles->find((string) $review['review_cycle_id']);
        if ($cycle === null) {
            throw HttpException::conflict('The cycle this review belongs to is no longer available.');
        }

        $scaleMax = (int) $cycle['rating_scale_max'];

        $data = Validator::make($request->all(), [
            'overall_rating' => 'nullable|numeric|min:0|max:' . $scaleMax,
            'strengths' => 'nullable|safe_text|max:4000',
            'improvements' => 'nullable|safe_text|max:4000',
            'achievements' => 'nullable|safe_text|max:4000',
            'manager_comments' => 'nullable|safe_text|max:4000',
            'is_anonymous' => 'nullable|boolean',
        ])->validated();

        $ratings = $this->validateRatings($request->array('ratings'), $scaleMax);

        if ($data === [] && $ratings === []) {
            throw HttpException::badRequest('Send at least one field to change.');
        }

        $changes = $data;

        if (array_key_exists('manager_comments', $changes)
            && !in_array((string) $review['review_type'], self::ACKNOWLEDGEABLE, true)) {
            throw HttpException::unprocessable('Manager comments belong on a manager or skip-level review.', [
                'manager_comments' => ['This review is not a manager review.'],
            ]);
        }

        if (array_key_exists('is_anonymous', $changes)) {
            if ((string) $review['review_type'] !== 'peer') {
                throw HttpException::unprocessable('Only a peer review can be given anonymously.', [
                    'is_anonymous' => ['This review type always names its reviewer.'],
                ]);
            }

            $changes['is_anonymous'] = (bool) $changes['is_anonymous'];
        }

        $updated = Connection::transaction(function () use ($review, $changes, $ratings) {
            $fresh = $changes === [] ? $review : $this->reviews->update((string) $review['id'], $changes);

            foreach ($ratings as $rating) {
                $this->ratings->put((string) $review['id'], $rating);
            }

            return $fresh;
        });

        if ($updated === null) {
            throw HttpException::notFound('The requested record does not exist.');
        }

        AuditLog::record($request, 'talent.review.updated', 'review', (string) $review['id'], $review, $updated, [
            'competencies_scored' => count($ratings),
        ]);

        $updated = ReviewVisibility::present($updated, $principal);
        $updated['ratings'] = $this->ratings->forReview((string) $review['id']);

        return Response::ok($updated);
    }

    /** POST /reviews/{id}/submit */
    public function submit(Request $request): Response
    {
        $principal = $request->principal();
        TalentAccess::employeeId($principal);

        $review = $this->reviews->find(TalentAccess::recordId($request));
        if ($review === null) {
            throw HttpException::notFound('The requested record does not exist.');
        }

        ReviewVisibility::assertMayEdit($principal, $review);

        if ($review['overall_rating'] === null) {
            throw HttpException::unprocessable('Give an overall rating before submitting this review.', [
                'overall_rating' => ['An overall rating is required to submit.'],
            ]);
        }

        $cycleId = (string) $review['review_cycle_id'];
        $subjectId = (string) $review['employee_id'];

        $updated = Connection::transaction(function () use ($review, $cycleId, $subjectId) {
            $fresh = $this->reviews->update((string) $review['id'], [
                'status' => 'submitted',
                'submitted_at' => Clock::iso(),
            ]);

            // A participant is finished once nothing about them is still a draft.
            $outstanding = $this->reviews->outstandingFor($cycleId, $subjectId);
            $this->participants->markStatus($cycleId, $subjectId, $outstanding === 0 ? 'completed' : 'in_progress');

            EventPublisher::publish('talent.review.submitted', [
                'employee_id' => $subjectId,
                'cycle_id' => $cycleId,
                'rating' => $fresh === null ? null : (float) $fresh['overall_rating'],
            ]);

            return $fresh;
        });

        if ($updated === null) {
            throw HttpException::notFound('The requested record does not exist.');
        }

        AuditLog::record($request, 'talent.review.submitted', 'review', (string) $review['id'], $review, $updated);

        return Response::ok(ReviewVisibility::present($updated, $principal));
    }

    /** POST /reviews/{id}/acknowledge */
    public function acknowledge(Request $request): Response
    {
        $principal = $request->principal();
        TalentAccess::employeeId($principal);

        $review = $this->reviews->find(TalentAccess::recordId($request));
        if ($review === null) {
            throw HttpException::notFound('The requested record does not exist.');
        }

        ReviewVisibility::assertVisible($principal, $review);

        if (!ReviewVisibility::isSubject($principal, $review)) {
            throw HttpException::forbidden('Only the person a review is about may acknowledge it.');
        }

        if (!in_array((string) $review['review_type'], self::ACKNOWLEDGEABLE, true)) {
            throw HttpException::unprocessable('Only a manager or skip-level review is acknowledged.');
        }

        if ((string) $review['status'] !== 'submitted') {
            throw HttpException::conflict(
                (string) $review['status'] === 'acknowledged'
                    ? 'You have already acknowledged this review.'
                    : 'This review has not been submitted yet.'
            );
        }

        $data = Validator::make($request->all(), [
            'employee_comments' => 'nullable|safe_text|max:4000',
        ])->validated();

        $updated = $this->reviews->update((string) $review['id'], [
            'status' => 'acknowledged',
            'acknowledged_at' => Clock::iso(),
            'employee_comments' => $data['employee_comments'] ?? $review['employee_comments'],
        ]);

        if ($updated === null) {
            throw HttpException::notFound('The requested record does not exist.');
        }

        AuditLog::record($request, 'talent.review.acknowledged', 'review', (string) $review['id'], $review, $updated);

        return Response::ok(ReviewVisibility::present($updated, $principal));
    }

    /**
     * Validates a list of competency scores sent alongside a review.
     *
     * The validator works on one flat set of fields at a time, so each element
     * is run through it individually rather than trusted as a whole.
     *
     * @param array<int|string, mixed> $input
     * @return list<array{competency_id: string, rating: float, comment: string|null}>
     */
    private function validateRatings(array $input, int $scaleMax): array
    {
        if ($input === []) {
            return [];
        }

        if (count($input) > 50) {
            throw HttpException::unprocessable('Too many competency scores were sent at once.', [
                'ratings' => ['Score no more than 50 competencies in one request.'],
            ]);
        }

        $clean = [];
        $seen = [];

        foreach ($input as $index => $item) {
            if (!is_array($item)) {
                throw HttpException::unprocessable('Each competency score must be an object.', [
                    'ratings' => [sprintf('Entry %s is not structured correctly.', (string) $index)],
                ]);
            }

            $validated = Validator::make($item, [
                'competency_id' => 'required|uuid',
                'rating' => 'required|numeric|min:0|max:' . $scaleMax,
                'comment' => 'nullable|safe_text|max:1000',
            ])->validated();

            $competencyId = (string) $validated['competency_id'];

            if (isset($seen[$competencyId])) {
                throw HttpException::unprocessable('The same competency was scored twice.', [
                    'ratings' => ['Score each competency once.'],
                ]);
            }

            if (!$this->competencies->isSelectable($competencyId)) {
                throw HttpException::unprocessable('That competency is not part of the current framework.', [
                    'ratings' => [sprintf('Competency %s is unknown or retired.', $competencyId)],
                ]);
            }

            $seen[$competencyId] = true;

            $clean[] = [
                'competency_id' => $competencyId,
                'rating' => (float) $validated['rating'],
                'comment' => $validated['comment'] ?? null,
            ];
        }

        return $clean;
    }

    /**
     * @param list<array<string, mixed>> $reviews
     * @return array<string, string>
     */
    private function cycleNames(array $reviews): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $review): string => (string) $review['review_cycle_id'],
            $reviews
        )));

        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach ($this->cycles->query()->select('id', 'name')->whereIn('id', $ids)->get() as $row) {
            $names[(string) $row['id']] = (string) $row['name'];
        }

        return $names;
    }
}
