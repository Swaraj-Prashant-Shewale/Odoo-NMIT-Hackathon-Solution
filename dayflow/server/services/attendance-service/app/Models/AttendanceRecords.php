<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\TimeFormat;
use Dayflow\Kernel\Database\Repository;

final class AttendanceRecords extends Repository
{
    protected string $table = 'attendance_records';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'employee_id', 'work_date', 'shift_id',
        'check_in_at', 'check_out_at', 'check_in_ip', 'check_out_ip', 'check_in_source',
        'worked_seconds', 'break_seconds', 'overtime_seconds',
        'late_minutes', 'early_leave_minutes',
        'status', 'is_regularised', 'remarks',
    ];

    protected array $casts = [
        'worked_seconds' => 'int',
        'break_seconds' => 'int',
        'overtime_seconds' => 'int',
        'late_minutes' => 'int',
        'early_leave_minutes' => 'int',
        'is_regularised' => 'bool',
    ];

    protected bool $softDeletes = false;

    public function forEmployeeOnDate(string $employeeId, string $date): ?array
    {
        $row = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->where('work_date', '=', $date)
            ->first();

        return $row === null ? null : $this->present($row);
    }

    /**
     * Loads one day and holds a row lock on it until the transaction ends.
     *
     * Punching is the one place in this service where the same person can send
     * two requests at once, so the state a punch is judged against has to be
     * read inside the transaction that changes it rather than before it.
     */
    public function lockById(string $id): ?array
    {
        $row = $this->rawOne('SELECT * FROM attendance_records WHERE id = :id FOR UPDATE', ['id' => $id]);

        return $row === null ? null : $this->present($row);
    }

    /** The day the employee is currently punched into, if any. */
    public function openFor(string $employeeId): ?array
    {
        $row = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->orderBy('work_date', 'desc')
            ->first();

        return $row === null ? null : $this->present($row);
    }

    /** @return list<array<string, mixed>> */
    public function betweenDates(string $employeeId, string $from, string $to): array
    {
        $rows = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->whereBetween('work_date', $from, $to)
            ->orderBy('work_date', 'asc')
            ->get();

        return array_map([$this, 'present'], $rows);
    }

    /**
     * Every record for a set of people on one day, keyed by employee.
     *
     * @param list<string> $employeeIds
     * @return array<string, array<string, mixed>>
     */
    public function forEmployeesOnDate(array $employeeIds, string $date): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $rows = $this->query()
            ->whereIn('employee_id', $employeeIds)
            ->where('work_date', '=', $date)
            ->get();

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(string) $row['employee_id']] = $this->present($row);
        }

        return $keyed;
    }

    /**
     * Everyone with a record on a day.
     *
     * The live board is normally driven by the employee directory; this is the
     * fallback that keeps it populated when that lookup is unavailable.
     *
     * @return list<string>
     */
    public function employeeIdsOn(string $date): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['employee_id'],
            $this->raw(
                'SELECT DISTINCT employee_id FROM attendance_records WHERE work_date = CAST(:work_date AS DATE)',
                ['work_date' => $date]
            )
        );
    }

    /**
     * Overtime totalled per employee and calendar month.
     *
     * @param list<string>|null $employeeIds Null means every employee.
     * @return list<array<string, mixed>>
     */
    public function overtimeSummary(string $from, string $to, ?array $employeeIds): array
    {
        $sql = "SELECT employee_id,
                       to_char(work_date, 'YYYY-MM')       AS month,
                       SUM(overtime_seconds)::BIGINT       AS overtime_seconds,
                       SUM(worked_seconds)::BIGINT         AS worked_seconds,
                       COUNT(*) FILTER (WHERE overtime_seconds > 0)::INTEGER AS overtime_days,
                       COUNT(*)::INTEGER                   AS recorded_days
                FROM attendance_records
                WHERE work_date BETWEEN CAST(:from_date AS DATE) AND CAST(:to_date AS DATE)";

        $bindings = ['from_date' => $from, 'to_date' => $to];

        if ($employeeIds !== null) {
            if ($employeeIds === []) {
                return [];
            }

            $placeholders = [];
            foreach (array_values($employeeIds) as $index => $employeeId) {
                $key = 'employee' . $index;
                $placeholders[] = ':' . $key;
                $bindings[$key] = $employeeId;
            }

            $sql .= ' AND employee_id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= " GROUP BY employee_id, to_char(work_date, 'YYYY-MM')
                  HAVING SUM(overtime_seconds) > 0
                  ORDER BY month DESC, overtime_seconds DESC";

        return array_map(
            static fn (array $row): array => [
                'employee_id' => (string) $row['employee_id'],
                'month' => (string) $row['month'],
                'overtime_seconds' => (int) $row['overtime_seconds'],
                'overtime_hours' => TimeFormat::hours((int) $row['overtime_seconds']),
                'worked_seconds' => (int) $row['worked_seconds'],
                'worked_hours' => TimeFormat::hours((int) $row['worked_seconds']),
                'overtime_days' => (int) $row['overtime_days'],
                'recorded_days' => (int) $row['recorded_days'],
            ],
            $this->raw($sql, $bindings)
        );
    }

    public function present(array $row): array
    {
        $row = parent::present($row);

        foreach (['check_in_at', 'check_out_at'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = TimeFormat::local($row[$column] === null ? null : (string) $row[$column]);
            }
        }

        return $row;
    }
}
