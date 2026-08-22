<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Holidays;

/**
 * The holiday calendar as the attendance grids need it: one lookup by date.
 */
final class HolidayCalendar
{
    private Holidays $holidays;

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $cache = [];

    public function __construct()
    {
        $this->holidays = new Holidays();
    }

    /**
     * Holidays in a window, keyed by calendar date.
     *
     * A location-specific entry wins over a company-wide one for the same day,
     * because it is the more precise statement about that office.
     *
     * @return array<string, array<string, mixed>>
     */
    public function map(string $from, string $to, ?string $locationId = null): array
    {
        $key = $from . '|' . $to . '|' . ($locationId ?? '-');

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $keyed = [];

        foreach ($this->holidays->inRange($from, $to, $locationId) as $holiday) {
            $date = (string) $holiday['holiday_date'];

            if (!isset($keyed[$date]) || $holiday['location_id'] !== null) {
                $keyed[$date] = $holiday;
            }
        }

        return $this->cache[$key] = $keyed;
    }

    /**
     * True when a date is a non-working holiday.
     *
     * Restricted holidays are optional: an employee may work them, so they
     * never turn a day into a company closure on their own.
     */
    public function isClosure(array $holidays, string $date): bool
    {
        $holiday = $holidays[$date] ?? null;

        return $holiday !== null && $holiday['holiday_type'] !== 'restricted';
    }

    /** @return array{name: string, type: string, description: string|null}|null */
    public function describe(array $holidays, string $date): ?array
    {
        $holiday = $holidays[$date] ?? null;

        if ($holiday === null) {
            return null;
        }

        return [
            'name' => (string) $holiday['name'],
            'type' => (string) $holiday['holiday_type'],
            'description' => $holiday['description'] === null ? null : (string) $holiday['description'],
        ];
    }
}
