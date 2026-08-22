<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\GoalCycles;
use App\Models\Goals;
use App\Models\GoalUpdates;
use App\Policies\GoalPolicy;
use App\Policies\TalentAccess;
use App\Services\EmployeeDirectory;
use App\Services\GoalProgress;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Database\QueryBuilder;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

final class GoalController
{
    /** The whole of an employee's attention in one cycle, expressed as weight. */
    private const WEIGHT_BUDGET = 100.0;

    private Goals $goals;
    private GoalCycles $cycles;
    private GoalUpdates $updates;

    public function __construct()
    {
        $this->goals = new Goals();
        $this->cycles = new GoalCycles();
        $this->updates = new GoalUpdates();
    }

    /** GET /goals - the caller's own goals, or a named employee's when permitted. */
    public function index(Request $request): Response
    {
        $principal = $request->principal();
        $self = TalentAccess::employeeId($principal);

        $filters = Validator::make($request->query, [
            'employee_id' => 'nullable|uuid',
            'goal_cycle_id' => 'nullable|uuid',
            'status' => 'nullable|in:draft,active,achieved,missed,cancelled',
            'category' => 'nullable|in:business,personal,learning,behavioural',
            'search' => 'nullable|safe_text|max:120',
        ])->validated();

        $employeeId = $filters['employee_id'] ?? $self;

        if ($employeeId !== $self) {
            GoalPolicy::assertMayView($principal, $employeeId, EmployeeDirectory::forRequest($request));
        }

        $builder = $this->goals->query()->where('employee_id', '=', $employeeId);
        $this->applyFilters($builder, $filters);
        $builder->orderBy('due_on', 'asc')->orderBy('created_at', 'desc');

        $page = $this->goals->paginate($builder, $request->page(), $request->perPage());

        return Response::page($page);
    }

    /** GET /goals/cycles - the cycles a goal can be filed against. */
    public function cycles(Request $request): Response
    {
        $filters = Validator::make($request->query, [
            'status' => 'nullable|in:draft,open,closed',
        ])->validated();

        $builder = $this->cycles->query();

        // A draft cycle is HR's working copy and nobody files goals against
        // it. The restriction is applied before the caller's own filter, so
        // asking for drafts directly cannot reach around it.
        if (!$request->principal()->can(Permissions::TALENT_MANAGE_CYCLES)) {
            $builder->where('status', '!=', 'draft');
        }

        if (isset($filters['status'])) {
            $builder->where('status', '=', $filters['status']);
        }

        $builder->orderBy('period_start', 'desc');

        return Response::page($this->cycles->paginate($builder, $request->page(), $request->perPage()));
    }

    /** POST /goals/cycles */
    public function storeCycle(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|safe_text|max:120',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'status' => 'nullable|in:draft,open,closed',
        ])->validated();

        $record = $this->cycles->create([
            'name' => $data['name'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'status' => $data['status'] ?? 'draft',
            'created_by' => $request->principal()->employeeId,
        ]);

        AuditLog::record($request, 'talent.goal_cycle.created', 'goal_cycle', $record['id'], [], $record);

        return Response::created($record);
    }

    /** GET /goals/{id} */
    public function show(Request $request): Response
    {
        $principal = $request->principal();

        $goal = $this->goals->find(TalentAccess::recordId($request));
        if ($goal === null) {
            throw HttpException::notFound('That goal does not exist.');
        }

        GoalPolicy::assertMayView($principal, (string) $goal['employee_id'], EmployeeDirectory::forRequest($request));

        $goal['cycle'] = $this->cycles->find((string) $goal['goal_cycle_id']);
        $goal['updates'] = $this->updates->history((string) $goal['id']);

        return Response::ok($goal);
    }

    /** POST /goals */
    public function store(Request $request): Response
    {
        $principal = $request->principal();
        $self = TalentAccess::employeeId($principal);

        $data = Validator::make($request->all(), [
            'employee_id' => 'nullable|uuid',
            'goal_cycle_id' => 'required|uuid',
            'title' => 'required|safe_text|max:180',
            'description' => 'nullable|safe_text|max:4000',
            'category' => 'required|in:business,personal,learning,behavioural',
            'metric' => 'nullable|safe_text|max:180',
            'target_value' => 'nullable|numeric|min:0|max:999999999999',
            'current_value' => 'nullable|numeric|min:0|max:999999999999',
            'unit' => 'nullable|safe_text|max:40',
            'weight_percent' => 'required|numeric|min:0|max:100',
            'starts_on' => 'nullable|date',
            'due_on' => 'nullable|date|after_or_equal:starts_on',
            'status' => 'nullable|in:draft,active',
        ])->validated();

        $employeeId = $data['employee_id'] ?? $self;

        if ($employeeId === $self) {
            if (!$principal->can(Permissions::TALENT_UPDATE_OWN_GOALS)) {
                throw HttpException::forbidden('You are not permitted to set your own goals.');
            }
        } else {
            if (!$principal->can(Permissions::TALENT_REVIEW_TEAM)) {
                throw HttpException::forbidden('Only a manager may set a goal for somebody else.');
            }

            // The reporting line is confirmed with the employee service rather
            // than taken from anything in this request.
            if (!EmployeeDirectory::forRequest($request)->isDirectReport($self, $employeeId)) {
                throw HttpException::forbidden('You may only set goals for your own direct reports.');
            }
        }

        $cycle = $this->cycles->find((string) $data['goal_cycle_id']);
        if ($cycle === null) {
            throw HttpException::unprocessable('That goal cycle does not exist.', [
                'goal_cycle_id' => ['Choose a goal cycle that exists.'],
            ]);
        }

        if ($cycle['status'] === 'closed') {
            throw HttpException::conflict('That goal cycle is closed, so no further goals can be filed against it.');
        }

        $weight = (float) $data['weight_percent'];
        $this->assertWeightFits($employeeId, (string) $cycle['id'], $weight, null);

        // A goal that omits a date inherits the cycle's, which can put the two
        // out of order when only one of them was supplied.
        $startsOn = (string) ($data['starts_on'] ?? $cycle['period_start']);
        $dueOn = (string) ($data['due_on'] ?? $cycle['period_end']);
        $this->assertDatesOrdered($startsOn, $dueOn);

        $currentValue = isset($data['current_value']) ? (float) $data['current_value'] : 0.0;
        $targetValue = $data['target_value'] ?? null;

        $record = $this->goals->create([
            'employee_id' => $employeeId,
            'goal_cycle_id' => $cycle['id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'],
            'metric' => $data['metric'] ?? null,
            'target_value' => $targetValue,
            'current_value' => $currentValue,
            'unit' => $data['unit'] ?? null,
            'weight_percent' => $weight,
            'status' => $data['status'] ?? 'draft',
            'progress_percent' => GoalProgress::resolve(
                ['target_value' => $targetValue, 'current_value' => $currentValue, 'progress_percent' => 0],
                null,
                null
            ),
            'starts_on' => $startsOn,
            'due_on' => $dueOn,
            'created_by' => $self,
        ]);

        AuditLog::record($request, 'talent.goal.created', 'goal', $record['id'], [], $record);

        return Response::created($record);
    }

    /** PATCH /goals/{id} */
    public function update(Request $request): Response
    {
        $principal = $request->principal();

        $goal = $this->goals->find(TalentAccess::recordId($request));
        if ($goal === null) {
            throw HttpException::notFound('That goal does not exist.');
        }

        $scope = GoalPolicy::assertMayEdit($principal, $goal, EmployeeDirectory::forRequest($request));

        $data = Validator::make($request->all(), [
            'title' => 'nullable|safe_text|max:180',
            'description' => 'nullable|safe_text|max:4000',
            'category' => 'nullable|in:business,personal,learning,behavioural',
            'metric' => 'nullable|safe_text|max:180',
            'target_value' => 'nullable|numeric|min:0|max:999999999999',
            'current_value' => 'nullable|numeric|min:0|max:999999999999',
            'unit' => 'nullable|safe_text|max:40',
            'weight_percent' => 'nullable|numeric|min:0|max:100',
            'progress_percent' => 'nullable|numeric|min:0|max:100',
            'status' => 'nullable|in:draft,active,achieved,missed,cancelled',
            'starts_on' => 'nullable|date',
            'due_on' => 'nullable|date',
        ])->validated();

        if ($data === []) {
            throw HttpException::badRequest('Send at least one field to change.');
        }

        $this->assertNotCleared($data, ['title', 'category', 'weight_percent', 'progress_percent', 'status', 'current_value']);

        GoalPolicy::assertMayChangeTerms($scope, $goal, $data);

        // The budget has to be measured against what this goal will claim
        // afterwards, not merely against a new weight_percent. Reinstating a
        // cancelled goal puts its whole weight back on the cycle without the
        // field being sent at all, and re-weighting a goal that stays
        // cancelled claims nothing however large the number is.
        $weightAfter = $this->weightClaimed(
            (string) ($data['status'] ?? $goal['status']),
            array_key_exists('weight_percent', $data) ? (float) $data['weight_percent'] : (float) $goal['weight_percent']
        );

        if ($weightAfter > $this->weightClaimed((string) $goal['status'], (float) $goal['weight_percent'])) {
            $this->assertWeightFits(
                (string) $goal['employee_id'],
                (string) $goal['goal_cycle_id'],
                $weightAfter,
                (string) $goal['id']
            );
        }

        $changes = $data;

        // current_value and progress_percent move together: changing one
        // without the other would leave the goal telling two different stories.
        if (array_key_exists('current_value', $changes) || array_key_exists('progress_percent', $changes)) {
            $changes['progress_percent'] = GoalProgress::resolve(
                $goal,
                array_key_exists('current_value', $changes) ? (float) $changes['current_value'] : null,
                array_key_exists('progress_percent', $changes) ? (float) $changes['progress_percent'] : null
            );
        }

        $changes += $this->completionChange(
            (string) ($changes['status'] ?? $goal['status']),
            (string) $goal['status']
        );

        $this->assertDatesOrdered(
            array_key_exists('starts_on', $changes) ? $changes['starts_on'] : ($goal['starts_on'] ?? null),
            array_key_exists('due_on', $changes) ? $changes['due_on'] : ($goal['due_on'] ?? null)
        );

        $updated = $this->goals->update((string) $goal['id'], $changes);
        if ($updated === null) {
            throw HttpException::notFound('That goal does not exist.');
        }

        AuditLog::record($request, 'talent.goal.updated', 'goal', $updated['id'], $goal, $updated, [
            'scope' => $scope,
            'changed' => array_keys(AuditLog::diff($goal, $updated)),
        ]);

        return Response::ok($updated);
    }

    /** POST /goals/{id}/progress */
    public function progress(Request $request): Response
    {
        $principal = $request->principal();
        $self = TalentAccess::employeeId($principal);

        $goal = $this->goals->find(TalentAccess::recordId($request));
        if ($goal === null) {
            throw HttpException::notFound('That goal does not exist.');
        }

        $scope = GoalPolicy::assertMayEdit($principal, $goal, EmployeeDirectory::forRequest($request));

        $data = Validator::make($request->all(), [
            'progress_percent' => 'nullable|numeric|min:0|max:100',
            'current_value' => 'nullable|numeric|min:0|max:999999999999',
            'note' => 'nullable|safe_text|max:1000',
        ])->validated();

        $hasProgress = isset($data['progress_percent']);
        $hasValue = isset($data['current_value']);

        if (!$hasProgress && !$hasValue) {
            throw HttpException::unprocessable('Record either a progress percentage or the latest value.', [
                'progress_percent' => ['Supply progress_percent or current_value.'],
            ]);
        }

        $progress = GoalProgress::resolve(
            $goal,
            $hasValue ? (float) $data['current_value'] : null,
            $hasProgress ? (float) $data['progress_percent'] : null
        );

        $updated = Connection::transaction(function () use ($goal, $data, $progress, $hasValue, $self) {
            $changes = ['progress_percent' => $progress];

            if ($hasValue) {
                $changes['current_value'] = (float) $data['current_value'];
            }

            $changes += $this->statusForProgress((string) $goal['status'], $progress);

            $fresh = $this->goals->update((string) $goal['id'], $changes);

            $this->updates->create([
                'goal_id' => $goal['id'],
                'employee_id' => $goal['employee_id'],
                'progress_percent' => $progress,
                'note' => $data['note'] ?? null,
                'created_by' => $self,
            ]);

            EventPublisher::publish('talent.goal.updated', [
                'employee_id' => (string) $goal['employee_id'],
                'goal_id' => (string) $goal['id'],
                'progress' => $progress,
            ]);

            return $fresh;
        });

        if ($updated === null) {
            throw HttpException::notFound('That goal does not exist.');
        }

        AuditLog::record($request, 'talent.goal.progress_recorded', 'goal', $updated['id'], $goal, $updated, [
            'scope' => $scope,
        ]);

        $updated['updates'] = $this->updates->history((string) $updated['id']);

        return Response::ok($updated);
    }

    /** GET /goals/team - direct reports and how their goals are tracking. */
    public function team(Request $request): Response
    {
        $principal = $request->principal();
        $self = TalentAccess::employeeId($principal);

        $filters = Validator::make($request->query, [
            'goal_cycle_id' => 'nullable|uuid',
            'status' => 'nullable|in:draft,active,achieved,missed,cancelled',
        ])->validated();

        $reports = EmployeeDirectory::forRequest($request)->directReports($self);

        if ($reports === []) {
            return Response::ok([], ['team_size' => 0, 'goal_count' => 0]);
        }

        $names = EmployeeDirectory::nameMap($reports);
        $reportIds = array_keys($names);

        $builder = $this->goals->query()->whereIn('employee_id', $reportIds);
        $this->applyFilters($builder, $filters);
        $builder->orderBy('employee_id', 'asc')->orderBy('due_on', 'asc');

        $goals = array_map([$this->goals, 'present'], $builder->get());

        $today = Clock::today();
        $team = [];
        $goalCount = 0;

        foreach ($reports as $person) {
            $employeeId = (string) $person['id'];
            $theirs = array_values(array_filter(
                $goals,
                static fn (array $goal): bool => (string) $goal['employee_id'] === $employeeId
            ));
            $goalCount += count($theirs);

            $team[] = [
                'employee_id' => $employeeId,
                'employee_name' => $names[$employeeId] ?? null,
                'designation_name' => $person['designation_name'] ?? null,
                'summary' => $this->goals->summaryFor($employeeId, $filters['goal_cycle_id'] ?? null, $today),
                'goals' => $theirs,
            ];
        }

        return Response::ok($team, ['team_size' => count($team), 'goal_count' => $goalCount]);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(QueryBuilder $builder, array $filters): void
    {
        if (isset($filters['goal_cycle_id'])) {
            $builder->where('goal_cycle_id', '=', $filters['goal_cycle_id']);
        }

        if (isset($filters['status'])) {
            $builder->where('status', '=', $filters['status']);
        }

        if (isset($filters['category'])) {
            $builder->where('category', '=', $filters['category']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $builder->whereAnyLike(['title', 'description', 'metric'], (string) $filters['search']);
        }
    }

    /**
     * The share of the cycle's 100% a goal in this state actually claims.
     *
     * A cancelled goal claims nothing, which is what returns its weight to the
     * pool; every other status keeps it, because the weight is what the goal's
     * result counts for at appraisal time.
     */
    private function weightClaimed(string $status, float $weight): float
    {
        return $status === 'cancelled' ? 0.0 : $weight;
    }

    /**
     * Refuses a change that would commit more than a whole person's effort.
     */
    private function assertWeightFits(string $employeeId, string $cycleId, float $weight, ?string $excludeGoalId): void
    {
        $committed = $this->goals->committedWeight($employeeId, $cycleId, $excludeGoalId);
        $total = round($committed + $weight, 3);

        if ($total <= self::WEIGHT_BUDGET) {
            return;
        }

        throw HttpException::unprocessable(
            sprintf(
                'Goal weights for this cycle would total %s%%. Weights already committed come to %s%%, leaving %s%% available.',
                GoalProgress::format($total),
                GoalProgress::format($committed),
                GoalProgress::format(max(0.0, self::WEIGHT_BUDGET - $committed))
            ),
            [
                'weight_percent' => [sprintf(
                    'Reduce this goal to %s%% or less, or free up weight elsewhere in the cycle.',
                    GoalProgress::format(max(0.0, self::WEIGHT_BUDGET - $committed))
                )],
                'committed_percent' => $committed,
                'available_percent' => max(0.0, self::WEIGHT_BUDGET - $committed),
            ]
        );
    }

    /**
     * Keeps completed_at honest whenever the status moves.
     *
     * @return array<string, mixed>
     */
    private function completionChange(string $newStatus, string $previousStatus): array
    {
        if ($newStatus === $previousStatus) {
            return [];
        }

        if ($newStatus === 'achieved') {
            return ['completed_at' => Clock::iso()];
        }

        return $previousStatus === 'achieved' ? ['completed_at' => null] : [];
    }

    /**
     * A goal that reaches its target is closed out automatically.
     *
     * Otherwise every completed goal would sit at 100% and still be counted as
     * outstanding until somebody remembered to change its status by hand.
     *
     * @return array<string, mixed>
     */
    private function statusForProgress(string $status, float $progress): array
    {
        if ($progress >= 100.0 && in_array($status, ['draft', 'active'], true)) {
            return ['status' => 'achieved', 'completed_at' => Clock::iso()];
        }

        if ($progress < 100.0 && $status === 'achieved') {
            return ['status' => 'active', 'completed_at' => null];
        }

        return [];
    }

    private function assertDatesOrdered(?string $startsOn, ?string $dueOn): void
    {
        if ($startsOn === null || $dueOn === null || $startsOn === '' || $dueOn === '') {
            return;
        }

        if (strtotime($dueOn) < strtotime($startsOn)) {
            throw HttpException::unprocessable('The due date cannot fall before the start date.', [
                'due_on' => ['Choose a due date on or after the start date.'],
            ]);
        }
    }

    /**
     * Rejects an explicit null for a column the schema requires a value in.
     *
     * Sending one is almost always a client bug, and turning it into a
     * database error would tell the caller nothing useful.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $columns
     */
    private function assertNotCleared(array $data, array $columns): void
    {
        foreach ($columns as $column) {
            if (array_key_exists($column, $data) && $data[$column] === null) {
                throw HttpException::unprocessable(
                    sprintf('%s must have a value.', ucfirst(str_replace('_', ' ', $column))),
                    [$column => ['This field cannot be cleared.']]
                );
            }
        }
    }
}
