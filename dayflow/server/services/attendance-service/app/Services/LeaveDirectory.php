<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\ServiceClient;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Logger;

/**
 * Approved leave, read from the leave service so a calendar grid can mark the
 * days somebody was legitimately away.
 *
 * Leave is decoration on an attendance grid, not its subject, so every call
 * uses tryGet: if the leave service is briefly unavailable the grid still
 * renders, with those days showing as unrecorded rather than as leave.
 */
final class LeaveDirectory
{
    /**
     * Approved leave dates for one employee inside a window.
     *
     * @return array<string, array<string, mixed>> Calendar date => leave detail.
     */
    public function datesFor(Request $request, string $employeeId, string $from, string $to): array
    {
        $rows = $this->fetch($request, [
            'employee_id' => $employeeId,
            'status' => 'approved',
            'from' => $from,
            'to' => $to,
            'per_page' => 100,
        ]);

        return $this->expand($rows, $from, $to)[$employeeId] ?? [];
    }

    /**
     * Everyone approved to be away on one day, keyed by employee.
     *
     * A single call covers the whole board: the leave service applies the
     * caller's own scope, so a manager sees their reports and HR sees all.
     *
     * @return array<string, array<string, mixed>>
     */
    public function everyoneOn(Request $request, string $date): array
    {
        $byEmployee = $this->expand(
            $this->fetch($request, ['status' => 'approved', 'from' => $date, 'to' => $date, 'per_page' => 100]),
            $date,
            $date
        );

        $onLeave = [];

        foreach ($byEmployee as $employeeId => $dates) {
            if (isset($dates[$date])) {
                $onLeave[$employeeId] = $dates[$date];
            }
        }

        return $onLeave;
    }

    /**
     * Reads approved leave, degrading to an empty answer on any failure.
     *
     * The address lookup is inside the guard as well: an attendance grid should
     * still draw when the leave service is missing from the environment, not
     * only when it is refusing connections.
     *
     * @param array<string, mixed> $query
     * @return list<mixed>
     */
    private function fetch(Request $request, array $query): array
    {
        try {
            $rows = ServiceClient::for('leave', $request->bearerToken())->tryGet('/leave/requests', $query, []);
        } catch (\Throwable $exception) {
            Logger::warning('Leave lookup unavailable', ['error' => $exception->getMessage()]);

            return [];
        }

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Turns leave requests into a per-employee set of calendar dates.
     *
     * @param list<mixed> $rows
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function expand(array $rows, string $from, string $to): array
    {
        $expanded = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $employeeId = $row['employee_id'] ?? null;
            $startsOn = $row['starts_on'] ?? null;
            $endsOn = $row['ends_on'] ?? $startsOn;

            if (!is_string($employeeId) || !is_string($startsOn) || !is_string($endsOn)) {
                continue;
            }

            // A request may run past either end of the window being drawn, so
            // it is clipped before being walked day by day.
            $rangeStart = max($startsOn, $from);
            $rangeEnd = min($endsOn, $to);

            if ($rangeStart > $rangeEnd) {
                continue;
            }

            $detail = [
                'request_id' => $row['id'] ?? null,
                'leave_type' => $row['leave_type_name'] ?? ($row['leave_type'] ?? null),
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
            ];

            foreach (Clock::dateRange($rangeStart, $rangeEnd) as $date) {
                $expanded[$employeeId][$date] = $detail;
            }
        }

        return $expanded;
    }
}
