<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Feedback;
use App\Models\GoalCycles;
use App\Models\Goals;
use App\Models\ReviewCycles;
use App\Models\Reviews;
use App\Policies\ReviewVisibility;
use App\Policies\TalentAccess;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

final class TalentController
{
    private Goals $goals;
    private GoalCycles $goalCycles;
    private Reviews $reviews;
    private ReviewCycles $reviewCycles;
    private Feedback $feedback;

    public function __construct()
    {
        $this->goals = new Goals();
        $this->goalCycles = new GoalCycles();
        $this->reviews = new Reviews();
        $this->reviewCycles = new ReviewCycles();
        $this->feedback = new Feedback();
    }

    /** GET /talent/my-summary - the caller's own performance picture. */
    public function mySummary(Request $request): Response
    {
        $principal = $request->principal();
        $employeeId = TalentAccess::employeeId($principal);

        $filters = Validator::make($request->query, [
            'goal_cycle_id' => 'nullable|uuid',
        ])->validated();

        $today = Clock::today();
        $cycle = isset($filters['goal_cycle_id'])
            ? $this->goalCycles->find((string) $filters['goal_cycle_id'])
            : $this->goalCycles->current($today);

        $cycleId = $cycle === null ? null : (string) $cycle['id'];

        return Response::ok([
            'employee_id' => $employeeId,
            'goal_cycle' => $cycle,
            'goals' => $this->goals->summaryFor($employeeId, $cycleId, $today),
            'latest_review' => $this->latestReview($principal, $employeeId),
            'reviews_to_complete' => $this->reviews->query()
                ->where('reviewer_id', '=', $employeeId)
                ->where('status', '=', 'draft')
                ->count(),
            'feedback' => $this->feedback->summaryFor($employeeId),
        ]);
    }

    /**
     * The most recent appraisal of the caller that they are entitled to read.
     *
     * @return array<string, mixed>|null
     */
    private function latestReview(Principal $principal, string $employeeId): ?array
    {
        $review = $this->reviews->latestVisibleAbout($employeeId);

        if ($review === null || !ReviewVisibility::canView($principal, $review)) {
            return null;
        }

        $review = ReviewVisibility::present($review, $principal);
        $cycle = $this->reviewCycles->find((string) $review['review_cycle_id']);

        return [
            'id' => $review['id'],
            'review_cycle_id' => $review['review_cycle_id'],
            'cycle_name' => $cycle === null ? null : $cycle['name'],
            'rating_scale_max' => $cycle === null ? null : (int) $cycle['rating_scale_max'],
            'review_type' => $review['review_type'],
            'overall_rating' => $review['overall_rating'],
            'status' => $review['status'],
            'submitted_at' => $review['submitted_at'],
            'acknowledged_at' => $review['acknowledged_at'],
        ];
    }
}
