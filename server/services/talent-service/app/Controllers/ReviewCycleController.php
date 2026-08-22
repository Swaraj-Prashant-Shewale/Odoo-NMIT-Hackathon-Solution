<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ReviewCycles;
use App\Models\ReviewParticipants;
use App\Models\Reviews;
use App\Policies\TalentAccess;
use App\Services\EmployeeDirectory;
use App\Services\ReviewCycleOpener;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Validation\Validator;

final class ReviewCycleController
{
    /**
     * Fewest submitted reviews before a rating distribution is released.
     *
     * Below this, a band containing one person is not an aggregate at all: it
     * is that person's rating with a count of 1 printed next to it.
     */
    private const MINIMUM_COHORT = 3;

    private ReviewCycles $cycles;
    private ReviewParticipants $participants;
    private Reviews $reviews;
    private ReviewCycleOpener $opener;

    public function __construct()
    {
        $this->cycles = new ReviewCycles();
        $this->participants = new ReviewParticipants();
        $this->reviews = new Reviews();
        $this->opener = new ReviewCycleOpener($this->cycles, $this->participants, $this->reviews);
    }

    /** GET /reviews/cycles */
    public function index(Request $request): Response
    {
        $principal = $request->principal();

        if ($principal->can(Permissions::TALENT_MANAGE_CYCLES)) {
            $filters = Validator::make($request->query, [
                'status' => 'nullable|in:draft,open,in_review,closed',
            ])->validated();

            $builder = $this->cycles->query();

            if (isset($filters['status'])) {
                $builder->where('status', '=', $filters['status']);
            }

            $builder->orderBy('period_start', 'desc');

            return Response::page($this->cycles->paginate($builder, $request->page(), $request->perPage()));
        }

        $cycles = $this->cycles->visibleTo(TalentAccess::employeeId($principal));

        return Response::ok($cycles, ['total' => count($cycles)]);
    }

    /** POST /reviews/cycles */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|safe_text|max:120',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'self_review_due_on' => 'nullable|date|after_or_equal:period_end',
            'manager_review_due_on' => 'nullable|date|after_or_equal:period_end',
            'rating_scale_max' => 'nullable|integer|min:2|max:10',
            'instructions' => 'nullable|safe_text|max:4000',
        ])->validated();

        $record = $this->cycles->create([
            'name' => $data['name'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'self_review_due_on' => $data['self_review_due_on'] ?? null,
            'manager_review_due_on' => $data['manager_review_due_on'] ?? null,
            'rating_scale_max' => $data['rating_scale_max'] ?? 5,
            'instructions' => $data['instructions'] ?? null,
            // A cycle is always born as a draft. Enrolling people and creating
            // their forms is a separate, deliberate step.
            'status' => 'draft',
            'created_by' => $request->principal()->employeeId,
        ]);

        AuditLog::record($request, 'talent.review_cycle.created', 'review_cycle', $record['id'], [], $record);

        return Response::created($record);
    }

    /** POST /reviews/cycles/{id}/open */
    public function open(Request $request): Response
    {
        $cycle = $this->cycles->find(TalentAccess::recordId($request));
        if ($cycle === null) {
            throw HttpException::notFound('That review cycle does not exist.');
        }

        if ($cycle['status'] !== 'draft') {
            throw HttpException::conflict('This review cycle has already been opened.', [
                'status' => $cycle['status'],
            ]);
        }

        $data = Validator::make($request->all(), [
            'scope' => 'nullable|in:all,department',
            'department_id' => 'nullable|uuid',
        ])->validated();

        $scope = $data['scope'] ?? 'all';
        $departmentId = $data['department_id'] ?? null;

        if ($scope === 'department' && $departmentId === null) {
            throw HttpException::unprocessable('Choose the department this cycle covers.', [
                'department_id' => ['A department is required when the scope is limited to one.'],
            ]);
        }

        $people = EmployeeDirectory::forRequest($request)
            ->activeEmployees($scope === 'department' ? $departmentId : null);

        if ($people === []) {
            throw HttpException::unprocessable('No active employees match that scope, so there is nobody to enrol.');
        }

        $result = $this->opener->open($cycle, $people);
        $fresh = $this->cycles->find((string) $cycle['id']);

        AuditLog::record($request, 'talent.review_cycle.opened', 'review_cycle', (string) $cycle['id'], $cycle, $fresh ?? [], [
            'scope' => $scope,
            'department_id' => $departmentId,
            'participants_enrolled' => $result['participants'],
            'reviews_created' => $result['reviews'],
        ]);

        return Response::ok([
            'cycle' => $fresh,
            'participants_enrolled' => $result['participants'],
            'reviews_created' => $result['reviews'],
            'participants_without_manager' => $result['without_manager'],
        ]);
    }

    /** GET /reviews/cycles/{id}/summary - completion and ratings, in aggregate only. */
    public function summary(Request $request): Response
    {
        $cycle = $this->cycles->find(TalentAccess::recordId($request));
        if ($cycle === null) {
            throw HttpException::notFound('That review cycle does not exist.');
        }

        $cycleId = (string) $cycle['id'];
        $breakdown = $this->reviews->progressBreakdown($cycleId);

        $totals = ['total' => 0, 'draft' => 0, 'submitted' => 0, 'acknowledged' => 0];
        $byType = [];

        foreach ($breakdown as $row) {
            $type = (string) $row['review_type'];
            $status = (string) $row['status'];
            $count = (int) $row['total'];

            $byType[$type] ??= ['total' => 0, 'draft' => 0, 'submitted' => 0, 'acknowledged' => 0];
            $byType[$type]['total'] += $count;
            $byType[$type][$status] += $count;

            $totals['total'] += $count;
            $totals[$status] += $count;
        }

        // Acknowledging a review does not un-complete it, so both count as done.
        $completed = $totals['submitted'] + $totals['acknowledged'];
        $completionRate = $totals['total'] === 0 ? 0.0 : round($completed / $totals['total'] * 100, 2);

        foreach ($byType as $type => $counts) {
            $done = $counts['submitted'] + $counts['acknowledged'];
            $byType[$type]['completion_rate'] = $counts['total'] === 0
                ? 0.0
                : round($done / $counts['total'] * 100, 2);
        }

        $aggregate = $this->reviews->ratingAggregate($cycleId);
        $released = $aggregate['rated'] >= self::MINIMUM_COHORT;

        return Response::ok([
            'cycle' => $cycle,
            'participants' => $this->participants->countFor($cycleId),
            'reviews' => $totals,
            'reviews_by_type' => $byType,
            'completion_rate' => $completionRate,
            'rating_summary' => [
                'rated' => $aggregate['rated'],
                'average' => $released ? $aggregate['average'] : null,
                'lowest' => $released ? $aggregate['lowest'] : null,
                'highest' => $released ? $aggregate['highest'] : null,
            ],
            'rating_distribution' => $released
                ? $this->distribution($cycleId, (int) $cycle['rating_scale_max'])
                : [],
            'rating_distribution_withheld' => !$released,
            'minimum_cohort' => self::MINIMUM_COHORT,
        ]);
    }

    /**
     * Whole-number rating bands, with every band present so a gap in the
     * middle of the scale is visible rather than merely absent.
     *
     * @return list<array{band: string, from: int, to: float, count: int}>
     */
    private function distribution(string $cycleId, int $scaleMax): array
    {
        $counts = [];
        foreach ($this->reviews->ratingBands($cycleId) as $row) {
            // Anything below the first whole number belongs in the bottom band.
            $band = max(1, min($scaleMax, (int) $row['band']));
            $counts[$band] = ($counts[$band] ?? 0) + (int) $row['total'];
        }

        $bands = [];
        for ($band = 1; $band <= $scaleMax; $band++) {
            $upper = $band === $scaleMax ? (float) $scaleMax : $band + 0.99;

            $bands[] = [
                'band' => sprintf('%d.00 - %.2f', $band, $upper),
                'from' => $band,
                'to' => $upper,
                'count' => $counts[$band] ?? 0,
            ];
        }

        return $bands;
    }
}
