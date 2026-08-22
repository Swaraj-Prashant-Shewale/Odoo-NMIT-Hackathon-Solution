<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LeaveBalances;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;

/**
 * Every movement of a leave balance.
 *
 * Balances are only ever changed through this class and only ever with a
 * relative expression (`used_days = used_days + :days`), never by writing a
 * total computed in PHP. A read-modify-write would lose an update whenever two
 * approvals land together; a relative update cannot.
 */
final class BalanceLedger
{
    public function __construct(private readonly LeaveBalances $balances)
    {
    }

    /**
     * Returns the balance row for an employee, creating it when absent.
     *
     * A type that does not accrue opens the year with its full entitlement; an
     * accruing type opens at zero and is credited month by month.
     *
     * @param array<string, mixed> $leaveType
     * @return array<string, mixed>
     */
    public function ensure(string $employeeId, array $leaveType, int $year): array
    {
        $existing = $this->balances->forEmployeeTypeYear($employeeId, (string) $leaveType['id'], $year);

        if ($existing !== null) {
            return $existing;
        }

        $opening = ($leaveType['accrual_frequency'] ?? 'none') === 'none'
            ? (float) ($leaveType['annual_quota_days'] ?? 0)
            : 0.0;

        // ON CONFLICT rather than a check-then-insert: two requests submitted
        // at the same moment for a type the employee has never used would both
        // find nothing and both try to insert.
        $sql = <<<'SQL'
            INSERT INTO leave_balances (id, employee_id, leave_type_id, year, opening_days, created_at, updated_at)
            VALUES (:id, :employee_id, :leave_type_id, :year, :opening_days, :created_at, :updated_at)
            ON CONFLICT (employee_id, leave_type_id, year) DO NOTHING
        SQL;

        $now = Clock::iso();

        $this->balances->execute($sql, [
            'id' => Str::uuid(),
            'employee_id' => $employeeId,
            'leave_type_id' => (string) $leaveType['id'],
            'year' => $year,
            'opening_days' => $opening,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = $this->balances->forEmployeeTypeYear($employeeId, (string) $leaveType['id'], $year);

        if ($row === null) {
            throw new \RuntimeException('The leave balance could not be opened for this employee.');
        }

        return $row;
    }

    /**
     * Reads a balance under a row lock and returns what is left to spend.
     *
     * The lock is what makes the check meaningful: without it two requests can
     * both read the same available figure and both be allowed through.
     */
    public function availableForUpdate(string $balanceId): float
    {
        $row = $this->balances->rawOne('SELECT * FROM leave_balances WHERE id = :id FOR UPDATE', ['id' => $balanceId]);

        if ($row === null) {
            throw HttpException::notFound('The leave balance for this request no longer exists.');
        }

        return LeaveBalances::availableFrom($row);
    }

    /** Holds days aside while a request waits for a decision. */
    public function reserve(string $balanceId, float $days): void
    {
        $this->move($balanceId, 'pending_days = pending_days + :days', $days);
    }

    /** Turns a held reservation into consumed leave on approval. */
    public function consumeReserved(string $balanceId, float $days): void
    {
        // GREATEST keeps the column inside its non-negative CHECK if a request
        // was ever decided twice through some path that bypassed the row lock.
        $this->move(
            $balanceId,
            'pending_days = GREATEST(pending_days - :days, 0), used_days = used_days + :days_used',
            $days,
            ['days_used' => $days]
        );
    }

    /** Gives back days held for a request that was rejected or withdrawn. */
    public function releaseReserved(string $balanceId, float $days): void
    {
        $this->move($balanceId, 'pending_days = GREATEST(pending_days - :days, 0)', $days);
    }

    /** Gives back days already consumed, when approved leave is cancelled. */
    public function releaseUsed(string $balanceId, float $days): void
    {
        $this->move($balanceId, 'used_days = GREATEST(used_days - :days, 0)', $days);
    }

    /** Applies a signed correction made by an administrator. */
    public function adjust(string $balanceId, float $deltaDays): void
    {
        $this->move($balanceId, 'adjusted_days = adjusted_days + :days', $deltaDays);
    }

    /**
     * Credits an accrual, but only once per period.
     *
     * The period guard lives in the WHERE clause rather than in a preceding
     * SELECT, so a second run in the same month updates no rows however many
     * copies of the job are started at once.
     *
     * @return bool True when this call actually credited the balance.
     */
    public function accrue(string $balanceId, float $days, string $period, float $quotaCap): bool
    {
        $sql = <<<'SQL'
            UPDATE leave_balances
               SET accrued_days = CASE
                       WHEN CAST(:quota_cap AS numeric) > 0
                            THEN LEAST(accrued_days + CAST(:days AS numeric), CAST(:quota_cap_limit AS numeric))
                       ELSE accrued_days + CAST(:days_uncapped AS numeric)
                   END,
                   last_accrual_period = :period,
                   updated_at = :updated_at
             WHERE id = :id
               AND (last_accrual_period IS NULL OR last_accrual_period <> :period_guard)
        SQL;

        return $this->balances->execute($sql, [
            'quota_cap' => $quotaCap,
            'days' => $days,
            'quota_cap_limit' => $quotaCap,
            'days_uncapped' => $days,
            'period' => $period,
            'updated_at' => Clock::iso(),
            'id' => $balanceId,
            'period_guard' => $period,
        ]) > 0;
    }

    /** @param array<string, mixed> $extra */
    private function move(string $balanceId, string $assignment, float $days, array $extra = []): void
    {
        $this->balances->execute(
            sprintf('UPDATE leave_balances SET %s, updated_at = :updated_at WHERE id = :id', $assignment),
            ['days' => $days, 'updated_at' => Clock::iso(), 'id' => $balanceId] + $extra
        );
    }
}
