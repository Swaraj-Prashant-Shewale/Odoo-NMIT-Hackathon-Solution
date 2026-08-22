<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class LeaveRequests extends Repository
{
    public const OPEN_STATUSES = ['pending', 'approved'];
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'withdrawn'];

    /**
     * Namespace for the per-employee advisory lock taken while a request is
     * being submitted. Two-argument advisory locks are keyed by (scope, key),
     * so a constant scope keeps this service's locks from colliding with any
     * other advisory lock taken against the same database.
     */
    private const ADVISORY_SCOPE = 19798;

    protected string $table = 'leave_requests';

    protected array $fillable = [
        'employee_id', 'leave_type_id', 'starts_on', 'ends_on', 'day_count',
        'is_half_day', 'half_day_period', 'reason', 'contact_during_leave',
        'status', 'approver_id', 'decided_by', 'decided_at', 'decision_note',
        'cancelled_at', 'cancelled_by', 'supporting_document_id',
        'holiday_calendar_applied', 'applied_at',
    ];

    protected array $casts = [
        'day_count' => 'float',
        'is_half_day' => 'bool',
        'holiday_calendar_applied' => 'bool',
    ];

    /**
     * Serialises submissions for one employee.
     *
     * The overlap test and the insert that follows it must not be able to
     * interleave with a second submission, or two requests covering the same
     * dates can both pass the test before either is written. The lock is held
     * until the surrounding transaction ends.
     */
    public function lockEmployee(string $employeeId): void
    {
        $this->execute('SELECT pg_advisory_xact_lock(:scope, :key)', [
            'scope' => self::ADVISORY_SCOPE,
            'key' => self::signedKey($employeeId),
        ]);
    }

    /**
     * True when the employee already has an open request touching these dates.
     *
     * Two ranges overlap exactly when each starts on or before the other ends,
     * which is one index-friendly predicate rather than a day-by-day walk.
     */
    public function overlapping(string $employeeId, string $startsOn, string $endsOn): ?array
    {
        $sql = <<<'SQL'
            SELECT id, starts_on, ends_on, status
            FROM leave_requests
            WHERE employee_id = :employee_id
              AND status IN ('pending','approved')
              AND starts_on <= CAST(:ends_on AS date)
              AND ends_on   >= CAST(:starts_on AS date)
            ORDER BY starts_on
            LIMIT 1
        SQL;

        return $this->rawOne($sql, [
            'employee_id' => $employeeId,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);
    }

    /**
     * Reads a request and holds it for the rest of the transaction.
     *
     * Two approvers pressing the button at the same instant both read a
     * pending request without this; with it the second waits, sees the status
     * the first wrote, and is refused.
     */
    public function lockForUpdate(string $id): ?array
    {
        $row = $this->rawOne('SELECT * FROM leave_requests WHERE id = :id FOR UPDATE', ['id' => $id]);

        return $row === null ? null : $this->present($row);
    }

    /**
     * The approval queue for one person, including anything delegated to them.
     *
     * @return list<array<string, mixed>>
     */
    public function queueFor(string $approverId, string $onDate, bool $includeUnassigned): array
    {
        $sql = <<<'SQL'
            SELECT r.*
            FROM leave_requests r
            WHERE r.status = 'pending'
              AND (
                    r.approver_id = :approver_id
                 OR r.approver_id IN (
                        SELECT d.delegator_id
                        FROM approval_delegations d
                        WHERE d.delegate_id = :delegate_id
                          AND d.is_active = TRUE
                          AND d.starts_on <= CAST(:today AS date)
                          AND d.ends_on   >= CAST(:today_end AS date)
                    )
                 OR (CAST(:include_unassigned AS boolean) AND r.approver_id IS NULL)
              )
            ORDER BY r.starts_on, r.applied_at
        SQL;

        $rows = $this->raw($sql, [
            'approver_id' => $approverId,
            'delegate_id' => $approverId,
            'today' => $onDate,
            'today_end' => $onDate,
            'include_unassigned' => $includeUnassigned ? 'true' : 'false',
        ]);

        return array_map([$this, 'present'], $rows);
    }

    /**
     * Every day of absence inside a window, one row per employee per day.
     *
     * Expanding the ranges in the database keeps the calendar to a single
     * query no matter how many people are away.
     *
     * @param list<string>|null $employeeIds Null means every employee.
     * @return list<array<string, mixed>>
     */
    public function daysAwayBetween(string $from, string $to, ?array $employeeIds): array
    {
        $bindings = [
            'from_bound' => $from,
            'to_bound' => $to,
            'from_overlap' => $from,
            'to_overlap' => $to,
        ];

        $scope = '';

        if ($employeeIds !== null) {
            if ($employeeIds === []) {
                return [];
            }

            // Parameter names come from the loop index and the values stay
            // bound, so nothing supplied by a caller reaches the SQL text.
            $placeholders = [];
            foreach (array_values($employeeIds) as $index => $employeeId) {
                $name = 'emp' . $index;
                $placeholders[] = ':' . $name;
                $bindings[$name] = $employeeId;
            }

            $scope = ' AND r.employee_id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql = <<<SQL
            SELECT r.id,
                   r.employee_id,
                   r.leave_type_id,
                   r.status,
                   r.is_half_day,
                   r.half_day_period,
                   r.reason,
                   r.starts_on,
                   r.ends_on,
                   t.name   AS leave_type_name,
                   t.code   AS leave_type_code,
                   t.colour AS colour,
                   day_series::date AS on_date
            FROM leave_requests r
            JOIN leave_types t ON t.id = r.leave_type_id
            CROSS JOIN LATERAL generate_series(
                GREATEST(r.starts_on, CAST(:from_bound AS date)),
                LEAST(r.ends_on, CAST(:to_bound AS date)),
                INTERVAL '1 day'
            ) AS day_series
            WHERE r.status IN ('pending','approved')
              AND r.starts_on <= CAST(:to_overlap AS date)
              AND r.ends_on   >= CAST(:from_overlap AS date){$scope}
            ORDER BY day_series, t.name
        SQL;

        return $this->raw($sql, $bindings);
    }

    /**
     * Folds a UUID into the signed 32-bit range PostgreSQL advisory locks use.
     *
     * crc32() returns an unsigned value on 64-bit builds, which is out of
     * range for the int4 the two-argument lock function expects.
     */
    private static function signedKey(string $value): int
    {
        $hash = crc32($value);

        return $hash >= 2147483648 ? $hash - 4294967296 : $hash;
    }
}
