<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Support\Clock;

/**
 * Rendering rules for the times this service returns.
 *
 * PostgreSQL hands back timestamps in UTC because the connection sets that
 * zone explicitly. Everything leaving the service is re-expressed in the
 * configured business timezone, so a client never has to guess which one a
 * value is in.
 */
final class TimeFormat
{
    private function __construct()
    {
    }

    /** An instant rendered in the business timezone, or null. */
    public static function local(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Clock::parse($value)->setTimezone(Clock::timezone())->format(\DateTimeInterface::ATOM);
    }

    /** Just the wall-clock part of an instant, as HH:MM. */
    public static function timeOfDay(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Clock::parse($value)->setTimezone(Clock::timezone())->format('H:i');
    }

    /** Normalises a TIME column, which may arrive as HH:MM or HH:MM:SS. */
    public static function hhmm(string $time): string
    {
        return substr($time, 0, 5);
    }

    /** Seconds expressed as decimal hours, the unit the reports use. */
    public static function hours(int $seconds): float
    {
        return round($seconds / 3600, 2);
    }

    /** The three-letter lowercase weekday key used by shifts.working_days. */
    public static function weekdayKey(string $date): string
    {
        return strtolower(Clock::parse($date)->format('D'));
    }

    /**
     * The mean time of day across a set of instants, as HH:MM.
     *
     * Averaging the raw seconds-since-midnight is only meaningful while the
     * values cluster within one working day, which is exactly the case here:
     * these are arrival and departure times for a single employee.
     *
     * @param list<string> $instants
     */
    public static function averageTimeOfDay(array $instants): ?string
    {
        if ($instants === []) {
            return null;
        }

        $total = 0;

        foreach ($instants as $instant) {
            $moment = Clock::parse($instant)->setTimezone(Clock::timezone());
            $total += ((int) $moment->format('H')) * 3600
                + ((int) $moment->format('i')) * 60
                + (int) $moment->format('s');
        }

        $average = intdiv($total, count($instants));

        return sprintf('%02d:%02d', intdiv($average, 3600) % 24, intdiv($average % 3600, 60));
    }
}
