<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Rosters;
use App\Models\ShiftAssignments;
use App\Models\Shifts;
use Dayflow\Kernel\Support\Clock;

/**
 * Decides which shift an employee is measured against on a given day.
 *
 * Precedence is roster, then standing assignment, then the company default.
 * A roster entry is a deliberate one-day override, so it has to beat the
 * assignment rather than merely supplement it.
 *
 * A shift is always returned. Attendance arithmetic needs a full day length, a
 * grace period and a break to mean anything, so the alternative to a resolved
 * shift is not "no shift" but the company working pattern.
 */
final class ShiftResolver
{
    /**
     * The company working pattern, used when nothing else applies.
     *
     * These mirror the platform defaults published in platform.settings; a
     * null id records that no shift row was responsible for the day.
     */
    private const COMPANY_DEFAULT = [
        'id' => null,
        'name' => 'Company default',
        'code' => 'DEFAULT',
        'starts_at' => '09:30',
        'ends_at' => '18:30',
        'break_minutes' => 60,
        'full_day_hours' => 8.0,
        'half_day_hours' => 4.0,
        'grace_minutes' => 15,
        'is_night_shift' => false,
        'working_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
        'is_active' => true,
    ];

    private Shifts $shifts;
    private ShiftAssignments $assignments;
    private Rosters $rosters;

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct()
    {
        $this->shifts = new Shifts();
        $this->assignments = new ShiftAssignments();
        $this->rosters = new Rosters();
    }

    /** @return array<string, mixed> */
    public function resolve(string $employeeId, string $date): array
    {
        $key = $employeeId . '|' . $date;

        // A monthly grid resolves the same assignment thirty-odd times, so the
        // answer is memoised for the life of the request.
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $roster = $this->rosters->forEmployeeOnDate($employeeId, $date);
        if ($roster !== null) {
            $shift = $this->shifts->find((string) $roster['shift_id']);

            if ($shift !== null) {
                return $this->cache[$key] = $shift;
            }
        }

        $assignment = $this->assignments->effectiveOn($employeeId, $date);
        if ($assignment !== null) {
            $shift = $this->shifts->find((string) $assignment['shift_id']);

            if ($shift !== null) {
                return $this->cache[$key] = $shift;
            }
        }

        return $this->cache[$key] = $this->shifts->fallback() ?? self::COMPANY_DEFAULT;
    }

    /** The shift a stored record was measured against, by its own shift_id. */
    public function forRecord(array $record, string $employeeId): array
    {
        $shiftId = $record['shift_id'] ?? null;

        if (is_string($shiftId) && $shiftId !== '') {
            $shift = $this->shifts->find($shiftId);

            if ($shift !== null) {
                return $shift;
            }
        }

        return $this->resolve($employeeId, (string) $record['work_date']);
    }

    /**
     * The working day a punch made at $now belongs to.
     *
     * A punch in the small hours belongs to the night shift that started the
     * previous calendar day, not to the day the clock has just rolled into.
     */
    public function workDateFor(string $employeeId, \DateTimeImmutable $now): string
    {
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $overnight = $this->resolve($employeeId, $yesterday);

        if ((bool) $overnight['is_night_shift'] && $now <= AttendanceCalculator::shiftEnd($overnight, $yesterday)) {
            return $yesterday;
        }

        return $now->format('Y-m-d');
    }

    /** True when the shift pattern expects work on that weekday. */
    public function isWorkingDay(array $shift, string $date): bool
    {
        $days = $shift['working_days'] ?? [];

        if (!is_array($days) || $days === []) {
            return !Clock::isWeekend($date);
        }

        return in_array(TimeFormat::weekdayKey($date), array_map('strtolower', $days), true);
    }

    /** The compact shift description embedded in calendar responses. */
    public function summarise(array $shift): array
    {
        return [
            'id' => $shift['id'],
            'name' => $shift['name'],
            'code' => $shift['code'],
            'starts_at' => $shift['starts_at'],
            'ends_at' => $shift['ends_at'],
            'break_minutes' => (int) $shift['break_minutes'],
            'grace_minutes' => (int) $shift['grace_minutes'],
            'full_day_hours' => (float) $shift['full_day_hours'],
            'half_day_hours' => (float) $shift['half_day_hours'],
            'is_night_shift' => (bool) $shift['is_night_shift'],
            'working_days' => $shift['working_days'] ?? [],
        ];
    }
}
