<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * Shared behaviour of the joiner and leaver checklists.
 *
 * The two tables are identical in shape and are queried in exactly the same
 * ways, so the queries live here once and the concrete classes supply only the
 * table they operate on.
 */
abstract class ChecklistTasks extends Repository
{
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'employee_id', 'title', 'description', 'category', 'sequence',
        'owner_role', 'assigned_to', 'due_on', 'completed_at', 'completed_by', 'status',
    ];

    protected array $casts = ['sequence' => 'int'];

    protected bool $softDeletes = false;

    /** @return list<array<string, mixed>> */
    public function forEmployee(string $employeeId): array
    {
        $rows = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->orderBy('sequence')
            ->orderBy('created_at')
            ->get();

        return array_map([$this, 'present'], $rows);
    }

    /**
     * Completion summary for one person.
     *
     * @return array{total: int, completed: int, outstanding: int, overdue: int}
     */
    public function progressFor(string $employeeId, string $today): array
    {
        $sql = sprintf(
            'SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = \'completed\') AS completed,
                    COUNT(*) FILTER (WHERE status IN (\'pending\', \'in_progress\')) AS outstanding,
                    COUNT(*) FILTER (WHERE status IN (\'pending\', \'in_progress\')
                                       AND due_on IS NOT NULL
                                       AND due_on < CAST(:today AS DATE)) AS overdue
               FROM %s
              WHERE employee_id = :employee_id',
            $this->quote($this->table)
        );

        $row = $this->rawOne($sql, ['employee_id' => $employeeId, 'today' => $today]) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'outstanding' => (int) ($row['outstanding'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
        ];
    }

    /**
     * Everyone with at least one task still open, with their progress.
     *
     * One grouped query rather than a list followed by a count per person.
     *
     * @return list<array<string, mixed>>
     */
    public function inFlight(string $today): array
    {
        $sql = sprintf(
            'SELECT e.id AS employee_id,
                    e.employee_code,
                    TRIM(e.first_name || \' \' || e.last_name) AS full_name,
                    e.work_email,
                    e.joined_on,
                    e.exit_date,
                    e.employment_status,
                    d.name AS department_name,
                    g.title AS designation_name,
                    COUNT(t.id) AS total,
                    COUNT(t.id) FILTER (WHERE t.status = \'completed\') AS completed,
                    COUNT(t.id) FILTER (WHERE t.status IN (\'pending\', \'in_progress\')) AS outstanding,
                    COUNT(t.id) FILTER (WHERE t.status IN (\'pending\', \'in_progress\')
                                          AND t.due_on IS NOT NULL
                                          AND t.due_on < CAST(:today AS DATE)) AS overdue,
                    MIN(t.due_on) FILTER (WHERE t.status IN (\'pending\', \'in_progress\')) AS next_due_on
               FROM %s t
               JOIN employees e ON e.id = t.employee_id AND e.deleted_at IS NULL
               LEFT JOIN departments  d ON d.id = e.department_id
               LEFT JOIN designations g ON g.id = e.designation_id
              GROUP BY e.id, e.employee_code, e.first_name, e.last_name, e.work_email,
                       e.joined_on, e.exit_date, e.employment_status, d.name, g.title
             HAVING COUNT(t.id) FILTER (WHERE t.status IN (\'pending\', \'in_progress\')) > 0
              ORDER BY MIN(t.due_on) FILTER (WHERE t.status IN (\'pending\', \'in_progress\')) NULLS LAST,
                       e.first_name',
            $this->quote($this->table)
        );

        return array_map(
            static fn (array $row): array => array_merge($row, [
                'total' => (int) $row['total'],
                'completed' => (int) $row['completed'],
                'outstanding' => (int) $row['outstanding'],
                'overdue' => (int) $row['overdue'],
            ]),
            $this->raw($sql, ['today' => $today])
        );
    }

    public function titleExistsFor(string $employeeId, string $title): bool
    {
        $sql = sprintf(
            'SELECT 1 AS found FROM %s WHERE employee_id = :employee_id AND LOWER(title) = LOWER(:title) LIMIT 1',
            $this->quote($this->table)
        );

        return $this->rawOne($sql, ['employee_id' => $employeeId, 'title' => $title]) !== null;
    }

    /** The highest sequence already used, so an added task lands at the end. */
    public function highestSequenceFor(string $employeeId): int
    {
        $sql = sprintf(
            'SELECT COALESCE(MAX(sequence), 0) AS highest FROM %s WHERE employee_id = :employee_id',
            $this->quote($this->table)
        );

        $row = $this->rawOne($sql, ['employee_id' => $employeeId]);

        return (int) ($row['highest'] ?? 0);
    }
}
