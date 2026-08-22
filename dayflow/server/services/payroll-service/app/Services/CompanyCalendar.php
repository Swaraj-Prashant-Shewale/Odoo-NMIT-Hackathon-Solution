<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformSettings;
use Dayflow\Kernel\Support\Clock;

/**
 * The working week and the financial year, as configured for the company.
 *
 * Pro-rating a salary needs a denominator, and that denominator has to be the
 * same one attendance reports against. Both read it from platform.settings so
 * the two services can never disagree about how long a month is.
 */
final class CompanyCalendar
{
    private const WEEKDAY_NUMBERS = [
        'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4,
        'fri' => 5, 'sat' => 6, 'sun' => 7,
    ];

    private const DEFAULT_WORKING_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri'];

    private const DEFAULT_FINANCIAL_YEAR_START = '04-01';

    /** @var list<int>|null */
    private static ?array $weekdays = null;

    private static ?int $financialYearStartMonth = null;

    private function __construct()
    {
    }

    /** @return list<int> ISO day numbers, Monday = 1. */
    public static function workingWeekdays(): array
    {
        if (self::$weekdays !== null) {
            return self::$weekdays;
        }

        $configured = (new PlatformSettings())->arrayValue('company.working_days', self::DEFAULT_WORKING_DAYS);

        $numbers = [];
        foreach ($configured as $day) {
            $key = strtolower(substr((string) $day, 0, 3));
            if (isset(self::WEEKDAY_NUMBERS[$key])) {
                $numbers[] = self::WEEKDAY_NUMBERS[$key];
            }
        }

        if ($numbers === []) {
            foreach (self::DEFAULT_WORKING_DAYS as $day) {
                $numbers[] = self::WEEKDAY_NUMBERS[$day];
            }
        }

        return self::$weekdays = array_values(array_unique($numbers));
    }

    public static function isWorkingDay(string $date): bool
    {
        return in_array((int) Clock::parse($date)->format('N'), self::workingWeekdays(), true);
    }

    public static function workingDaysIn(string $period): int
    {
        [$from, $to] = Period::bounds($period);

        return self::workingDaysBetween($from, $to);
    }

    public static function workingDaysBetween(string $from, string $to): int
    {
        if (strcmp($to, $from) < 0) {
            return 0;
        }

        $count = 0;
        foreach (Clock::dateRange($from, $to) as $date) {
            if (self::isWorkingDay($date)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The Indian-style financial year label a period falls in.
     *
     * April to March by default, so 2026-05 belongs to "2026-27" while
     * 2026-02 still belongs to "2025-26".
     */
    public static function financialYear(string $period): string
    {
        [$year, $month] = array_map('intval', explode('-', $period, 2));

        $startYear = $month >= self::financialYearStartMonth() ? $year : $year - 1;

        return sprintf('%04d-%02d', $startYear, ($startYear + 1) % 100);
    }

    private static function financialYearStartMonth(): int
    {
        if (self::$financialYearStartMonth !== null) {
            return self::$financialYearStartMonth;
        }

        $configured = (new PlatformSettings())
            ->string('company.financial_year_start', self::DEFAULT_FINANCIAL_YEAR_START);

        $month = (int) substr($configured, 0, 2);

        return self::$financialYearStartMonth = ($month >= 1 && $month <= 12) ? $month : 4;
    }
}
