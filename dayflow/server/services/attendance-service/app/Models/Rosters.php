<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class Rosters extends Repository
{
    protected string $table = 'rosters';

    protected string $primaryKey = 'id';

    protected array $fillable = ['employee_id', 'shift_id', 'roster_date', 'notes', 'created_by'];

    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    public function forEmployeeOnDate(string $employeeId, string $date): ?array
    {
        $row = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->where('roster_date', '=', $date)
            ->first();

        return $row === null ? null : $this->present($row);
    }

    /**
     * Roster entries with their shift joined in, for the planner grid.
     *
     * @param list<string>|null $employeeIds Null means every employee.
     * @return list<array<string, mixed>>
     */
    public function inRange(string $from, string $to, ?array $employeeIds = null): array
    {
        $sql = 'SELECT r.*, s.name AS shift_name, s.code AS shift_code,
                       s.starts_at AS shift_starts_at, s.ends_at AS shift_ends_at
                FROM rosters r
                JOIN shifts s ON s.id = r.shift_id
                WHERE r.roster_date BETWEEN CAST(:from_date AS DATE) AND CAST(:to_date AS DATE)';

        $bindings = ['from_date' => $from, 'to_date' => $to];

        if ($employeeIds !== null) {
            if ($employeeIds === []) {
                return [];
            }

            // Placeholders are generated from the list's own positions, never
            // from any part of the caller's data.
            $placeholders = [];
            foreach (array_values($employeeIds) as $index => $employeeId) {
                $key = 'employee' . $index;
                $placeholders[] = ':' . $key;
                $bindings[$key] = $employeeId;
            }

            $sql .= ' AND r.employee_id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY r.roster_date ASC, r.employee_id ASC';

        return $this->raw($sql, $bindings);
    }

    /** @return list<array<string, mixed>> */
    public function upcomingFor(string $employeeId, string $fromDate, int $limit): array
    {
        return $this->raw(
            'SELECT r.*, s.name AS shift_name, s.code AS shift_code,
                    s.starts_at AS shift_starts_at, s.ends_at AS shift_ends_at
             FROM rosters r
             JOIN shifts s ON s.id = r.shift_id
             WHERE r.employee_id = :employee_id AND r.roster_date >= CAST(:from_date AS DATE)
             ORDER BY r.roster_date ASC
             LIMIT :row_limit',
            ['employee_id' => $employeeId, 'from_date' => $fromDate, 'row_limit' => $limit]
        );
    }
}
