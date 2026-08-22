<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Support\Clock;

/**
 * Calendar arithmetic for every chart in the product.
 *
 * Trends are expressed as a list of buckets rather than as a start and an end,
 * because a month with no data still has to appear on the chart as a gap. A
 * chart that silently omits empty periods misrepresents the shape of the data.
 */
final class Period
{
    /** Longest window a caller may request, in days. */
    private const MAX_RANGE_DAYS = 800;

    /** @return array{period: string, from: string, to: string, label: string} */
    public static function month(string $date): array
    {
        $day = Clock::parse($date);

        return [
            'period' => $day->format('Y-m'),
            'from' => $day->format('Y-m-01'),
            'to' => $day->format('Y-m-t'),
            'label' => $day->format('M Y'),
        ];
    }

    /**
     * The last $count whole months, oldest first, ending with the month that
     * contains $endingOn.
     *
     * @return list<array{period: string, from: string, to: string, label: string}>
     */
    public static function lastMonths(int $count, ?string $endingOn = null): array
    {
        $count = max(1, min($count, 36));
        $cursor = Clock::parse($endingOn ?? Clock::today())->modify('first day of this month');

        $months = [];
        for ($offset = $count - 1; $offset >= 0; $offset--) {
            $months[] = self::month($cursor->modify(sprintf('-%d months', $offset))->format('Y-m-d'));
        }

        return $months;
    }

    /**
     * Resolves a caller-supplied window, falling back to a default span.
     *
     * @param array<string, mixed> $filters Already validated by the controller.
     * @return array{from: string, to: string}
     */
    public static function range(array $filters, int $defaultDays = 30): array
    {
        $to = isset($filters['to']) && is_string($filters['to']) ? $filters['to'] : Clock::today();
        $from = isset($filters['from']) && is_string($filters['from'])
            ? $filters['from']
            : Clock::parse($to)->modify(sprintf('-%d days', max(1, $defaultDays) - 1))->format('Y-m-d');

        if (strtotime($from) > strtotime($to)) {
            throw HttpException::unprocessable('The start of the range cannot be after its end.', [
                'from' => ['The start of the range cannot be after its end.'],
            ]);
        }

        // An unbounded window would fan out into thousands of downstream calls,
        // so the range is capped rather than trusted.
        if (Clock::inclusiveDays($from, $to) > self::MAX_RANGE_DAYS) {
            throw HttpException::unprocessable(
                sprintf('A reporting range may cover at most %d days.', self::MAX_RANGE_DAYS),
                ['to' => [sprintf('A reporting range may cover at most %d days.', self::MAX_RANGE_DAYS)]]
            );
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * The buckets a range breaks into for a given grouping.
     *
     * @return list<array{key: string, label: string, from: string, to: string}>
     */
    public static function buckets(string $from, string $to, string $groupBy): array
    {
        return match ($groupBy) {
            'month' => self::monthBuckets($from, $to),
            'week' => self::weekBuckets($from, $to),
            default => self::dayBuckets($from, $to),
        };
    }

    /** Which bucket a single date belongs to, matching the keys from buckets(). */
    public static function bucketFor(string $date, string $groupBy): string
    {
        $day = Clock::parse($date);

        return match ($groupBy) {
            'month' => $day->format('Y-m'),
            'week' => $day->modify('monday this week')->format('Y-m-d'),
            default => $day->format('Y-m-d'),
        };
    }

    /** Human label for a period key such as "2026-08" or "2026-08-17". */
    public static function label(string $key): string
    {
        if (preg_match('/^\d{4}-\d{2}$/', $key) === 1) {
            return Clock::parse($key . '-01')->format('M Y');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $key) === 1) {
            return Clock::parse($key)->format('d M');
        }

        return $key;
    }

    /** @return list<array{key: string, label: string, from: string, to: string}> */
    private static function dayBuckets(string $from, string $to): array
    {
        return array_map(
            static fn (string $date): array => [
                'key' => $date,
                'label' => Clock::parse($date)->format('d M'),
                'from' => $date,
                'to' => $date,
            ],
            Clock::dateRange($from, $to)
        );
    }

    /** @return list<array{key: string, label: string, from: string, to: string}> */
    private static function weekBuckets(string $from, string $to): array
    {
        $buckets = [];
        $cursor = Clock::parse($from)->modify('monday this week');
        $end = Clock::parse($to);

        while ($cursor <= $end) {
            $close = $cursor->modify('+6 days');
            $buckets[] = [
                'key' => $cursor->format('Y-m-d'),
                'label' => 'Week of ' . $cursor->format('d M'),
                'from' => max($cursor->format('Y-m-d'), $from),
                'to' => min($close->format('Y-m-d'), $to),
            ];
            $cursor = $cursor->modify('+7 days');
        }

        return $buckets;
    }

    /** @return list<array{key: string, label: string, from: string, to: string}> */
    private static function monthBuckets(string $from, string $to): array
    {
        $buckets = [];
        $cursor = Clock::parse($from)->modify('first day of this month');
        $end = Clock::parse($to);

        while ($cursor <= $end) {
            $buckets[] = [
                'key' => $cursor->format('Y-m'),
                'label' => $cursor->format('M Y'),
                'from' => max($cursor->format('Y-m-01'), $from),
                'to' => min($cursor->format('Y-m-t'), $to),
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $buckets;
    }
}
