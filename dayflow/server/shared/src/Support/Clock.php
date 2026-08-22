<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Support;

/**
 * Single source of truth for "now".
 *
 * Routing every timestamp through one class keeps the whole platform on the
 * configured business timezone and makes time-dependent logic testable.
 */
final class Clock
{
    private static ?\DateTimeZone $zone = null;

    public static function timezone(): \DateTimeZone
    {
        return self::$zone ??= new \DateTimeZone(Env::get('APP_TIMEZONE', 'UTC'));
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::timezone());
    }

    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    public static function timestamp(): int
    {
        return self::now()->getTimestamp();
    }

    public static function iso(): string
    {
        return self::now()->format(\DateTimeInterface::ATOM);
    }

    public static function parse(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, self::timezone());
    }

    /** Whole days between two calendar dates, inclusive of both endpoints. */
    public static function inclusiveDays(string $from, string $to): int
    {
        $start = self::parse($from)->setTime(0, 0);
        $end = self::parse($to)->setTime(0, 0);

        return (int) $start->diff($end)->days + 1;
    }

    /**
     * Every calendar date from $from to $to inclusive.
     *
     * @return list<string>
     */
    public static function dateRange(string $from, string $to): array
    {
        $dates = [];
        $cursor = self::parse($from)->setTime(0, 0);
        $end = self::parse($to)->setTime(0, 0);

        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }

    /** Monday and Sunday bounding the week that contains $date. */
    public static function weekBounds(string $date): array
    {
        $day = self::parse($date);
        $monday = $day->modify('monday this week');

        return [$monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d')];
    }

    /** First and last calendar day of the month that contains $date. */
    public static function monthBounds(string $date): array
    {
        $day = self::parse($date);

        return [$day->format('Y-m-01'), $day->format('Y-m-t')];
    }

    public static function isWeekend(string $date): bool
    {
        return in_array((int) self::parse($date)->format('N'), [6, 7], true);
    }
}
