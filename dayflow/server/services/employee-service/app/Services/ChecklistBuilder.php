<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChecklistTasks;
use App\Models\ChecklistTemplates;
use Dayflow\Kernel\Security\Roles;
use Dayflow\Kernel\Support\Clock;

/**
 * Turns the stored checklist templates into real tasks for one person.
 *
 * Due dates are derived from an anchor - the joining date for a new starter,
 * the exit date for a leaver - so a checklist created months in advance still
 * lands on sensible days.
 */
final class ChecklistBuilder
{
    public function __construct(private readonly ChecklistTemplates $templates)
    {
    }

    /**
     * Creates any template task the employee does not already have.
     *
     * Written to be safe to call twice: re-running it after a template is added
     * fills in the gap rather than duplicating the whole list.
     *
     * @param array<string, mixed> $employee
     * @return list<array<string, mixed>> The tasks that were created.
     */
    public function apply(ChecklistTasks $tasks, string $kind, array $employee, string $anchorDate): array
    {
        $employeeId = (string) $employee['id'];
        $managerId = isset($employee['manager_id']) ? (string) $employee['manager_id'] : null;
        $created = [];

        foreach ($this->templates->activeFor($kind) as $template) {
            $title = (string) $template['title'];

            if ($tasks->titleExistsFor($employeeId, $title)) {
                continue;
            }

            $created[] = $tasks->create([
                'employee_id' => $employeeId,
                'title' => $title,
                'description' => $template['description'],
                'category' => $template['category'],
                'sequence' => (int) $template['sequence'],
                'owner_role' => $template['owner_role'],
                'assigned_to' => $this->assignee((string) $template['owner_role'], $employeeId, $managerId),
                'due_on' => $this->dueDate($anchorDate, (int) $template['due_offset_days']),
                'status' => 'pending',
            ]);
        }

        return $created;
    }

    /**
     * Who the task lands on.
     *
     * Tasks owned by an HR or finance role stay unassigned so they sit in that
     * team's shared queue rather than on one named person who might be away.
     */
    private function assignee(string $ownerRole, string $employeeId, ?string $managerId): ?string
    {
        return match ($ownerRole) {
            Roles::EMPLOYEE => $employeeId,
            Roles::MANAGER => $managerId,
            default => null,
        };
    }

    private function dueDate(string $anchorDate, int $offsetDays): string
    {
        return Clock::parse($anchorDate)
            ->modify(sprintf('%+d days', $offsetDays))
            ->format('Y-m-d');
    }
}
