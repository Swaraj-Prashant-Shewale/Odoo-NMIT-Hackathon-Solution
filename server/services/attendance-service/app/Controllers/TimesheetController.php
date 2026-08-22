<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Timesheets;
use App\Policies\AttendanceScope;
use App\Services\RouteId;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/**
 * Project time logs: an employee records effort, a manager signs it off.
 */
final class TimesheetController
{
    private const HOURS_IN_A_DAY = 24.0;

    private Timesheets $timesheets;
    private AttendanceScope $scope;

    public function __construct()
    {
        $this->timesheets = new Timesheets();
        $this->scope = new AttendanceScope();
    }

    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'employee_id' => 'nullable|uuid',
            'project_code' => 'nullable|safe_text|max:40',
            'status' => 'nullable|in:draft,submitted,approved,rejected',
        ])->validated();

        [$monthStart, $monthEnd] = Clock::monthBounds(Clock::today());
        $from = $filters['from'] ?? $monthStart;
        $to = $filters['to'] ?? $monthEnd;

        if ($from > $to) {
            throw HttpException::unprocessable('The start of the range is after its end.');
        }

        $builder = $this->timesheets->query()
            ->whereBetween('work_date', $from, $to)
            ->orderBy('work_date', 'desc');

        if (($filters['employee_id'] ?? null) !== null) {
            $builder->where('employee_id', '=', $this->scope->resolveSubject($request, (string) $filters['employee_id']));
        } else {
            $this->scope->apply($builder, $request);
        }

        if (isset($filters['project_code'])) {
            $builder->where('project_code', '=', strtoupper((string) $filters['project_code']));
        }

        if (isset($filters['status'])) {
            $builder->where('status', '=', $filters['status']);
        }

        $page = $this->timesheets->paginate($builder, $request->page(), $request->perPage());
        $page['meta'] += ['from' => $from, 'to' => $to];

        return Response::page($page);
    }

    /** Effort by project across whoever the caller may see. */
    public function summary(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ])->validated();

        [$monthStart, $monthEnd] = Clock::monthBounds(Clock::today());
        $from = $filters['from'] ?? $monthStart;
        $to = $filters['to'] ?? $monthEnd;

        if ($from > $to) {
            throw HttpException::unprocessable('The start of the range is after its end.');
        }

        $projects = $this->timesheets->projectSummary($from, $to, $this->scope->visibleIds($request));

        return Response::ok([
            'from' => $from,
            'to' => $to,
            'projects' => $projects,
            'total_hours' => round(array_sum(array_column($projects, 'total_hours')), 2),
            'billable_hours' => round(array_sum(array_column($projects, 'billable_hours')), 2),
        ]);
    }

    /** Logs effort against one of the caller's own days. */
    public function store(Request $request): Response
    {
        $employeeId = $this->scope->selfId($request);

        $data = Validator::make($request->all(), [
            'work_date' => 'required|date|before_or_equal:today',
            'project_code' => 'required|safe_text|max:40',
            'task_description' => 'required|safe_text|min:3|max:500',
            'hours' => 'required|numeric|between:0.25,24',
            'billable' => 'nullable|boolean',
            'submit' => 'nullable|boolean',
        ])->validated();

        $data['project_code'] = $this->normaliseProjectCode((string) $data['project_code']);
        $workDate = (string) $data['work_date'];
        $hours = round((float) $data['hours'], 2);

        $this->assertDayNotOverbooked($employeeId, $workDate, $hours, null);

        $entry = $this->timesheets->create([
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'project_code' => $data['project_code'],
            'task_description' => $data['task_description'],
            'hours' => $hours,
            'billable' => $data['billable'] ?? true,
            'status' => ($data['submit'] ?? false) === true ? 'submitted' : 'draft',
        ]);

        AuditLog::record($request, 'attendance.timesheet.created', 'timesheet', (string) $entry['id'], [], $entry);

        return Response::created($entry);
    }

    public function update(Request $request): Response
    {
        $entry = $this->requireOwnEditable($request);

        $data = Validator::make($request->all(), [
            'project_code' => 'nullable|safe_text|max:40',
            'task_description' => 'nullable|safe_text|min:3|max:500',
            'hours' => 'nullable|numeric|between:0.25,24',
            'billable' => 'nullable|boolean',
        ])->validated();

        $data = array_filter($data, static fn (mixed $value): bool => $value !== null);

        if ($data === []) {
            throw HttpException::unprocessable('No changes were supplied.');
        }

        if (isset($data['project_code'])) {
            $data['project_code'] = $this->normaliseProjectCode((string) $data['project_code']);
        }

        if (isset($data['hours'])) {
            $data['hours'] = round((float) $data['hours'], 2);
            $this->assertDayNotOverbooked(
                (string) $entry['employee_id'],
                (string) $entry['work_date'],
                (float) $data['hours'],
                (string) $entry['id']
            );
        }

        // Editing a rejected entry puts it back in front of the approver rather
        // than leaving it stranded in a state nobody is looking at.
        if ($entry['status'] === 'rejected') {
            $data['status'] = 'draft';
            $data['approved_by'] = null;
            $data['approved_at'] = null;
        }

        $updated = $this->timesheets->update((string) $entry['id'], $data) ?? $entry;

        AuditLog::record($request, 'attendance.timesheet.updated', 'timesheet', (string) $entry['id'], $entry, $updated);

        return Response::ok($updated);
    }

    /** Hands an entry to the approver. */
    public function submit(Request $request): Response
    {
        $entry = $this->requireOwnEditable($request);

        if ($entry['status'] === 'submitted') {
            throw HttpException::conflict('This entry has already been submitted.');
        }

        $updated = $this->timesheets->update((string) $entry['id'], [
            'status' => 'submitted',
            'approved_by' => null,
            'approved_at' => null,
        ]) ?? $entry;

        AuditLog::record($request, 'attendance.timesheet.submitted', 'timesheet', (string) $entry['id'], $entry, $updated);

        return Response::ok($updated);
    }

    public function destroy(Request $request): Response
    {
        $entry = $this->requireOwnEditable($request);

        $this->timesheets->delete((string) $entry['id']);

        AuditLog::record($request, 'attendance.timesheet.deleted', 'timesheet', (string) $entry['id'], $entry, []);

        return Response::noContent();
    }

    /**
     * Approves or rejects somebody else's entry.
     *
     * The route permission establishes that the caller signs off other people's
     * time; this confirms the entry actually belongs to somebody in their scope.
     */
    public function decide(Request $request): Response
    {
        $entry = $this->timesheets->find(RouteId::of($request));

        if ($entry === null) {
            throw HttpException::notFound();
        }

        $employeeId = (string) $entry['employee_id'];

        if ($request->principal()->owns($employeeId)) {
            throw HttpException::forbidden('You may not approve your own timesheet.');
        }

        if (!$this->scope->canView($request, $employeeId)) {
            throw HttpException::forbidden('This entry belongs to somebody outside your team.');
        }

        if ($entry['status'] !== 'submitted') {
            throw HttpException::conflict('Only a submitted entry can be decided.');
        }

        $data = Validator::make($request->all(), [
            'decision' => 'required|in:approve,reject',
        ])->validated();

        // The status check above was made against a row read a moment earlier.
        // Carrying it into the UPDATE itself is what stops two approvers
        // deciding the same entry at once and each recording their own answer.
        $updated = $this->timesheets->decideIfSubmitted(
            (string) $entry['id'],
            $data['decision'] === 'approve' ? 'approved' : 'rejected',
            $this->scope->selfId($request),
            Clock::iso()
        );

        if ($updated === null) {
            throw HttpException::conflict('Only a submitted entry can be decided.');
        }

        AuditLog::record($request, 'attendance.timesheet.decided', 'timesheet', (string) $entry['id'], $entry, $updated);

        return Response::ok($updated);
    }

    /** Loads an entry the caller owns and is still allowed to change. */
    private function requireOwnEditable(Request $request): array
    {
        $entry = $this->timesheets->find(RouteId::of($request));

        if ($entry === null) {
            throw HttpException::notFound();
        }

        if (!$request->principal()->owns((string) $entry['employee_id'])) {
            throw HttpException::forbidden('This timesheet entry belongs to somebody else.');
        }

        if ($entry['status'] === 'approved') {
            throw HttpException::conflict('An approved entry can no longer be changed.');
        }

        return $entry;
    }

    /** Nobody logs more than a day's worth of hours against a single day. */
    private function assertDayNotOverbooked(string $employeeId, string $workDate, float $hours, ?string $ignoreId): void
    {
        $alreadyLogged = $this->timesheets->hoursLoggedOn($employeeId, $workDate, $ignoreId);

        if (($alreadyLogged + $hours) > self::HOURS_IN_A_DAY) {
            throw HttpException::unprocessable(
                sprintf('That would put %s past 24 logged hours.', $workDate),
                ['hours' => [sprintf('%.2f hours are already logged for that day.', $alreadyLogged)]]
            );
        }
    }

    private function normaliseProjectCode(string $code): string
    {
        $clean = strtoupper(trim($code));

        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', $clean) !== 1) {
            throw HttpException::unprocessable(
                'A project code is 2 to 40 letters, numbers, hyphens or underscores.',
                ['project_code' => ['A project code is 2 to 40 letters, numbers, hyphens or underscores.']]
            );
        }

        return $clean;
    }
}
