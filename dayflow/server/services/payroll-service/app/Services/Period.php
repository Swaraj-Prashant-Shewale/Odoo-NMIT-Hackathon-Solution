<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Support\Clock;

/** Calendar arithmetic for a payroll period, which is always a whole month. */
final class Period
{
    private const PATTERN = '/^[0-9]{4}-(0[1-9]|1[0-2])$/';

    private function __construct()
    {
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /** @throws HttpException 422 when the value is not a YYYY-MM month. */
    public static function normalise(string $value): string
    {
        $value = trim($value);

        if (!self::isValid($value)) {
            throw HttpException::unprocessable(
                'A payroll period must be a month in YYYY-MM format.',
                ['period' => ['Use a value such as 2026-04.']]
            );
        }

        return $value;
    }

    /** @return array{0: string, 1: string} First and last calendar day. */
    public static function bounds(string $period): array
    {
        $month = Clock::parse($period . '-01');

        return [$month->format('Y-m-01'), $month->format('Y-m-t')];
    }

    public static function label(string $period): string
    {
        return Clock::parse($period . '-01')->format('F Y');
    }

    public static function current(): string
    {
        return Clock::now()->format('Y-m');
    }

    /** Moves a period forward (positive) or back (negative) by whole months. */
    public static function shift(string $period, int $months): string
    {
        return Clock::parse($period . '-01')
            ->modify(sprintf('%+d months', $months))
            ->format('Y-m');
    }

    /** True when $period is later than the month we are currently in. */
    public static function isFuture(string $period): bool
    {
        return strcmp($period, self::current()) > 0;
    }

    /** The day immediately before a period starts, used to close a structure. */
    public static function dayBefore(string $date): string
    {
        return Clock::parse($date)->modify('-1 day')->format('Y-m-d');
    }
}
