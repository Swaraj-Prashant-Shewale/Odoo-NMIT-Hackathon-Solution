<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ChecklistTasks;
use App\Models\Employees;
use App\Policies\EmployeeAccessPolicy;
use App\Services\RecordId;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\ValidationException;
use Dayflow\Kernel\Validation\Validator;

/**
 * Shared behaviour of the joiner and leaver checklists.
 *
 * Both are the same resource with a different anchor date and a different
 * table, so the rules live here once and the two concrete controllers supply
 * only what differs. Extending is the intent, not an accident, which is why
 * this class is not final.
 */
abstract class ChecklistController
{
    protected Employees $employees;
    protected EmployeeAccessPolicy $access;

    protected function __construct(
        protected readonly ChecklistTasks $tasks,
        protected readonly string $kind,
    ) {
        $this->employees = new Employees();
        $this->access = new EmployeeAccessPolicy($this->employees);
    }

    /** Everyone with an open checklist. */
    public function index(Request $request): Response
    {
        $rows = $this->tasks->inFlight(Clock::today());

        return Response::ok($rows, [
            'kind' => $this->kind,
            'total' => count($rows),
            'overdue' => count(array_filter($rows, static fn (array $row): bool => $row['overdue'] > 0)),
        ]);
    }

    /** One person's checklist. */
    public function forEmployee(Request $request): Response
    {
        $employee = $this->requireEmployee($request->route('employee_id'));
        $principal = $request->principal();

        if (!$principal->can(Permissions::ONBOARDING_MANAGE)) {
            $this->access->assertCanView($principal, $employee);
        }

        $employeeId = (string) $employee['id'];

        return Response::ok($this->tasks->forEmployee($employeeId), [
            'kind' => $this->kind,
            'employee_id' => $employeeId,
            'employee_name' => $employee['full_name'],
            'progress' => $this->tasks->progressFor($employeeId, Clock::today()),
        ]);
    }

    /** Marks one task done. */
    public function complete(Request $request): Response
    {
        $before = $this->requireTask($request->route('id'));
        $principal = $request->principal();

        $this->assertMayComplete($principal, $before);

        if ($before['status'] === 'completed') {
            throw HttpException::conflict('That task is already marked as done.');
        }

        $after = $this->tasks->update((string) $before['id'], [
            'status' => 'completed',
            'completed_at' => Clock::iso(),
            'completed_by' => RecordId::orNull($principal->employeeId),
        ]);

        if ($after === null) {
            throw HttpException::notFound('That task does not exist.');
        }

        AuditLog::record(
            $request,
            $this->kind . '.task.completed',
            $this->kind . '_task',
            (string) $after['id'],
            ['status' => $before['status']],
            ['status' => $after['status']],
            ['employee_id' => $after['employee_id'], 'title' => $after['title']]
        );

        return Response::ok($after);
    }

    /** Adds a task to one person's checklist. */
    public function storeTask(Request $request): Response
    {
        $employee = $this->requireEmployee($request->route('employee_id'));
        $employeeId = (string) $employee['id'];

        $data = Validator::make($request->all(), [
            'title' => 'required|safe_text|max:150',
            'description' => 'nullable|safe_text|max:1000',
            'category' => 'nullable|safe_text|max:40',
            'owner_role' => 'nullable|role',
            'assigned_to' => 'nullable|uuid',
            'due_on' => 'nullable|date',
            'sequence' => 'nullable|integer|between:0,999',
        ])->validated();

        // The unique index on (employee_id, title) would otherwise surface as
        // a database error rather than an answer the caller can act on.
        if ($this->tasks->titleExistsFor($employeeId, (string) $data['title'])) {
            throw HttpException::conflict('That checklist already has a task with this title.');
        }

        if (!empty($data['assigned_to']) && $this->employees->find((string) $data['assigned_to']) === null) {
            throw new ValidationException('Some of the information supplied is not valid.', [
                'assigned_to' => ['That person does not exist.'],
            ]);
        }

        $task = $this->tasks->create([
            'employee_id' => $employeeId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? 'general',
            'owner_role' => $data['owner_role'] ?? 'hr_officer',
            'assigned_to' => $data['assigned_to'] ?? null,
            'due_on' => $data['due_on'] ?? null,
            'sequence' => $data['sequence'] ?? ($this->tasks->highestSequenceFor($employeeId) + 1),
            'status' => 'pending',
        ]);

        AuditLog::record(
            $request,
            $this->kind . '.task.added',
            $this->kind . '_task',
            (string) $task['id'],
            [],
            $task
        );

        return Response::created($task);
    }

    /**
     * A task may be closed by whoever owns it.
     *
     * HR holds the checklist, the named assignee holds their own item, and a
     * task addressed to the employee themselves is theirs to tick off. Nobody
     * else may close somebody else's step.
     *
     * @param array<string, mixed> $task
     */
    private function assertMayComplete(Principal $principal, array $task): void
    {
        if ($principal->can(Permissions::ONBOARDING_MANAGE)) {
            return;
        }

        $assignee = $task['assigned_to'] === null ? null : (string) $task['assigned_to'];

        if ($assignee !== null && $principal->owns($assignee)) {
            return;
        }

        if ($task['owner_role'] === 'employee' && $principal->owns((string) $task['employee_id'])) {
            return;
        }

        throw HttpException::forbidden('That task is not yours to complete.');
    }

    /** @return array<string, mixed> */
    protected function requireEmployee(string $id): array
    {
        $id = RecordId::orNull($id);
        $employee = $id === null ? null : $this->employees->find($id);

        if ($employee === null) {
            throw HttpException::notFound('That person record does not exist.');
        }

        return $employee;
    }

    /** @return array<string, mixed> */
    protected function requireTask(string $id): array
    {
        $id = RecordId::orNull($id);
        $task = $id === null ? null : $this->tasks->find($id);

        if ($task === null) {
            throw HttpException::notFound('That task does not exist.');
        }

        return $task;
    }
}
