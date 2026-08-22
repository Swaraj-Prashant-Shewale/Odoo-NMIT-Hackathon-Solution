<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Logger;
use Dayflow\Kernel\Support\Str;

/**
 * Keeps the attendance register in step with approved leave.
 *
 * The two facts live in different services and neither can read the other's
 * tables, which is by design: each database role holds rights on its own
 * schema and nothing else. So the leave service says what it decided and this
 * service decides what that means for the register.
 *
 * Without it, somebody on a fortnight's approved leave is simply absent from
 * the register for ten working days. Their attendance rate falls as though
 * they had failed to turn up, "Leave taken this month" reads zero because
 * nothing carries the on_leave status, and a manager looking at the team's
 * month sees gaps with no explanation.
 *
 * A day already carrying a real punch is never overwritten. Somebody who came
 * in for the morning and then went on leave has an attendance record that is
 * true, and a leave approval arriving afterwards must not erase it.
 */
final class LeaveMirror
{
    /** Statuses that mean the register should show the day as taken. */
    private const AWAY = 'on_leave';

    /**
     * Records approved leave across a date range.
     *
     * @return int The number of days written.
     */
    public function markAway(string $employeeId, string $from, string $to, ?string $note = null): int
    {
        $days = $this->workingDaysBetween($from, $to);

        if ($days === []) {
            return 0;
        }

        // Only where nothing is recorded yet. A day with a punch on it is a
        // statement about what actually happened and outranks a plan.
        $insert = Connection::pdo()->prepare(
            "INSERT INTO attendance_records
                 (id, employee_id, work_date, status, worked_seconds, break_seconds,
                  overtime_seconds, late_minutes, early_leave_minutes, check_in_source,
                  remarks, created_at, updated_at)
             VALUES
                 (:id, :employee_id, CAST(:work_date AS DATE), :status, 0, 0, 0, 0, 0, 'import',
                  :remarks, NOW(), NOW())
             ON CONFLICT (employee_id, work_date) DO UPDATE
                SET status = EXCLUDED.status,
                    remarks = EXCLUDED.remarks,
                    updated_at = NOW()
              WHERE attendance_records.check_in_at IS NULL
                AND attendance_records.status IN ('absent', 'on_leave')"
        );

        $written = 0;

        // Deliberately not wrapped in a per-day try/catch. This runs inside
        // the transaction that claims the event, so the first failure poisons
        // every statement after it - catching them would turn one real fault
        // into a cascade of meaningless ones and, worse, report success for
        // days that were never written, marking the event handled so it is
        // never retried. Letting it throw rolls the claim back with it.
        foreach ($days as $date) {
            $insert->execute([
                'id' => Str::uuid(),
                'employee_id' => $employeeId,
                'work_date' => $date,
                'status' => self::AWAY,
                'remarks' => $note ?? 'Approved leave.',
            ]);

            $written += $insert->rowCount();
        }

        return $written;
    }

    /**
     * Undoes it, for leave that was cancelled or a decision that was reversed.
     *
     * Only rows this service wrote are removed - anything carrying a punch was
     * a real day at work and stays.
     *
     * @return int The number of days cleared.
     */
    public function clearAway(string $employeeId, string $from, string $to): int
    {
        $days = $this->workingDaysBetween($from, $to);

        if ($days === []) {
            return 0;
        }

        $placeholders = [];
        $bindings = ['employee_id' => $employeeId];

        foreach ($days as $index => $date) {
            $placeholders[] = ':day' . $index;
            $bindings['day' . $index] = $date;
        }

        $statement = Connection::pdo()->prepare(sprintf(
            "DELETE FROM attendance_records
              WHERE employee_id = :employee_id
                AND status = 'on_leave'
                AND check_in_at IS NULL
                AND work_date IN (%s)",
            implode(', ', array_map(static fn (string $p): string => 'CAST(' . $p . ' AS DATE)', $placeholders))
        ));

        $statement->execute($bindings);

        return $statement->rowCount();
    }

    /**
     * The days in a range that anybody would be expected to work.
     *
     * Weekends and published holidays are left out: a Saturday is already not
     * a working day, and marking it as leave would charge somebody for a day
     * they were never going to be here.
     *
     * @return list<string>
     */
    private function workingDaysBetween(string $from, string $to): array
    {
        if ($from === '' || $to === '' || $from > $to) {
            return [];
        }

        // A range is a request, not a fact, so it is bounded before it is
        // turned into rows: a typo of 2999-01-01 must not write a million days.
        $start = Clock::parse($from);
        $end = Clock::parse($to);

        if ($start->diff($end)->days > 366) {
            Logger::warning('Refusing to mirror an implausible leave range', ['from' => $from, 'to' => $to]);

            return [];
        }

        $closures = $this->closures($from, $to);
        $days = [];
        $cursor = $start;

        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');

            if (!in_array((int) $cursor->format('N'), [6, 7], true) && !isset($closures[$date])) {
                $days[] = $date;
            }

            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    /** @return array<string, true> */
    private function closures(string $from, string $to): array
    {
        $statement = Connection::pdo()->prepare(
            "SELECT holiday_date FROM holidays
              WHERE is_active AND holiday_type <> 'restricted'
                AND holiday_date BETWEEN CAST(:from_date AS DATE) AND CAST(:to_date AS DATE)"
        );

        $statement->execute(['from_date' => $from, 'to_date' => $to]);

        $closures = [];

        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $date) {
            $closures[(string) $date] = true;
        }

        return $closures;
    }
}
