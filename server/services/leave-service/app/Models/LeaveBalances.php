<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class LeaveBalances extends Repository
{
    protected string $table = 'leave_balances';

    protected array $fillable = [
        'employee_id', 'leave_type_id', 'year', 'opening_days', 'accrued_days',
        'used_days', 'pending_days', 'carried_forward_days', 'adjusted_days',
        'last_accrual_period',
    ];

    protected array $casts = [
        'year' => 'int',
        'opening_days' => 'float',
        'accrued_days' => 'float',
        'used_days' => 'float',
        'pending_days' => 'float',
        'carried_forward_days' => 'float',
        'adjusted_days' => 'float',
    ];

    /** Adds the one figure every caller actually wants to read. */
    public function present(array $row): array
    {
        $row = parent::present($row);

        if (array_key_exists('opening_days', $row)) {
            $row['available_days'] = self::availableFrom($row);
        }

        return $row;
    }

    /** available = opening + accrued + carried forward + adjusted - used - pending */
    public static function availableFrom(array $row): float
    {
        return round(
            (float) ($row['opening_days'] ?? 0)
            + (float) ($row['accrued_days'] ?? 0)
            + (float) ($row['carried_forward_days'] ?? 0)
            + (float) ($row['adjusted_days'] ?? 0)
            - (float) ($row['used_days'] ?? 0)
            - (float) ($row['pending_days'] ?? 0),
            2
        );
    }

    public function forEmployeeTypeYear(string $employeeId, string $leaveTypeId, int $year): ?array
    {
        $row = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->where('leave_type_id', '=', $leaveTypeId)
            ->where('year', '=', $year)
            ->first();

        return $row === null ? null : $this->present($row);
    }

    /**
     * Every active leave type alongside this employee's balance for the year.
     *
     * A left join from the type table means a type the employee has never
     * touched still appears, with zeroes, instead of silently vanishing from
     * the self-service screen.
     *
     * @return list<array<string, mixed>>
     */
    public function summaryFor(string $employeeId, int $year): array
    {
        $sql = <<<'SQL'
            SELECT t.id                                    AS leave_type_id,
                   t.name                                  AS leave_type_name,
                   t.code                                  AS leave_type_code,
                   t.category                              AS category,
                   t.colour                                AS colour,
                   t.is_paid                               AS is_paid,
                   t.allows_half_day                       AS allows_half_day,
                   t.annual_quota_days                     AS annual_quota_days,
                   b.id                                    AS balance_id,
                   COALESCE(b.opening_days, 0)             AS opening_days,
                   COALESCE(b.accrued_days, 0)             AS accrued_days,
                   COALESCE(b.used_days, 0)                AS used_days,
                   COALESCE(b.pending_days, 0)             AS pending_days,
                   COALESCE(b.carried_forward_days, 0)     AS carried_forward_days,
                   COALESCE(b.adjusted_days, 0)            AS adjusted_days,
                   b.last_accrual_period                   AS last_accrual_period
            FROM leave_types t
            LEFT JOIN leave_balances b
                   ON b.leave_type_id = t.id
                  AND b.employee_id = :employee_id
                  AND b.year = :year
            WHERE t.is_active = TRUE
            ORDER BY t.name
        SQL;

        $rows = $this->raw($sql, ['employee_id' => $employeeId, 'year' => $year]);

        return array_map(
            static function (array $row): array {
                foreach ([
                    'annual_quota_days', 'opening_days', 'accrued_days', 'used_days',
                    'pending_days', 'carried_forward_days', 'adjusted_days',
                ] as $column) {
                    $row[$column] = (float) $row[$column];
                }

                $row['is_paid'] = filter_var($row['is_paid'], FILTER_VALIDATE_BOOLEAN);
                $row['allows_half_day'] = filter_var($row['allows_half_day'], FILTER_VALIDATE_BOOLEAN);
                $row['available_days'] = self::availableFrom($row);

                return $row;
            },
            $rows
        );
    }
}
