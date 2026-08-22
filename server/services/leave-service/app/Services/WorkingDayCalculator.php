<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Http\ServiceClient;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Logger;

/**
 * Turns a date range into the number of days an employee is actually charged.
 *
 * Weekends come from the company's configured working week, and public
 * holidays from the attendance service, which owns the holiday calendar. The
 * holiday lookup is treated as decoration: if attendance cannot be reached the
 * count still happens, excluding weekends only, and the caller is told so it
 * can be recorded on the request rather than quietly presented as exact.
 */
final class WorkingDayCalculator
{
    /** ISO-8601 day numbers, Monday = 1. */
    private const DAY_NUMBERS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    private const DEFAULT_WORKING_WEEK = [1, 2, 3, 4, 5];

    /** @var list<int>|null */
    private ?array $workingWeek = null;

    /** @var array<string, list<string>|null> Keyed by "from..to". */
    private array $holidayCache = [];

    /** @var array<int, list<string>|null> One published calendar year each. */
    private array $holidayYearCache = [];

    public function __construct(private readonly ?string $bearerToken = null)
    {
    }

    /**
     * @return array{days: float, dates: list<string>, holiday_calendar_applied: bool}
     */
    public function count(string $from, string $to): array
    {
        $holidays = $this->holidays($from, $to);
        $excluded = $holidays === null ? [] : array_flip($holidays);
        $workingWeek = $this->workingWeek();
        $dates = [];

        foreach (Clock::dateRange($from, $to) as $date) {
            if (!in_array((int) Clock::parse($date)->format('N'), $workingWeek, true)) {
                continue;
            }

            if (isset($excluded[$date])) {
                continue;
            }

            $dates[] = $date;
        }

        return [
            'days' => (float) count($dates),
            'dates' => $dates,
            'holiday_calendar_applied' => $holidays !== null,
        ];
    }

    /**
     * The set of working dates in a window, for filtering an expanded calendar.
     *
     * @return array<string, true>
     */
    public function workingDateSet(string $from, string $to): array
    {
        $set = [];

        foreach ($this->count($from, $to)['dates'] as $date) {
            $set[$date] = true;
        }

        return $set;
    }

    /**
     * Public holidays between two dates, or null when they are unknown.
     *
     * The attendance service publishes its calendar a year at a time: its
     * /holidays endpoint filters on `year` and silently ignores anything else,
     * so asking it for a date window would quietly hand back the current
     * year's holidays whatever range was requested. Each year the range
     * touches is therefore fetched by year and trimmed here.
     *
     * @return list<string>|null
     */
    private function holidays(string $from, string $to): ?array
    {
        $key = $from . '..' . $to;

        if (array_key_exists($key, $this->holidayCache)) {
            return $this->holidayCache[$key];
        }

        $dates = [];

        foreach ($this->yearsSpanned($from, $to) as $year) {
            $rows = $this->holidaysForYear($year);

            if ($rows === null) {
                return $this->holidayCache[$key] = null;
            }

            foreach ($rows as $date) {
                if ($date >= $from && $date <= $to) {
                    $dates[] = $date;
                }
            }
        }

        return $this->holidayCache[$key] = array_values(array_unique($dates));
    }

    /**
     * Every calendar year touched by a date range, in order.
     *
     * @return list<int>
     */
    private function yearsSpanned(string $from, string $to): array
    {
        $first = (int) substr($from, 0, 4);
        $last = (int) substr($to, 0, 4);

        if ($last < $first) {
            return [$first];
        }

        return range($first, $last);
    }

    /**
     * One year of the published holiday calendar, or null when unreachable.
     *
     * @return list<string>|null
     */
    private function holidaysForYear(int $year): ?array
    {
        if (array_key_exists($year, $this->holidayYearCache)) {
            return $this->holidayYearCache[$year];
        }

        try {
            $response = ServiceClient::for('attendance', $this->bearerToken)
                ->get('/holidays', ['year' => $year]);
        } catch (\Throwable $exception) {
            Logger::warning('Holiday calendar unavailable, counting weekends only', [
                'year' => $year,
                'error' => $exception->getMessage(),
            ]);

            return $this->holidayYearCache[$year] = null;
        }

        $rows = $response['data'] ?? [];

        if (!is_array($rows)) {
            return $this->holidayYearCache[$year] = null;
        }

        $dates = [];

        foreach ($rows as $row) {
            // The calendar may be published either as plain dates or as full
            // holiday records; both forms are accepted so a later change to
            // the attendance payload cannot silently break leave counting.
            $value = is_array($row)
                ? ($row['holiday_date'] ?? $row['date'] ?? $row['observed_on'] ?? null)
                : $row;

            if (!is_string($value)) {
                continue;
            }

            $date = substr(trim($value), 0, 10);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $dates[] = $date;
            }
        }

        return $this->holidayYearCache[$year] = array_values(array_unique($dates));
    }

    /**
     * The company's working week, as ISO day numbers.
     *
     * Read from the shared platform settings rather than assumed, so a company
     * running a six-day week gets its leave counted correctly.
     *
     * @return list<int>
     */
    private function workingWeek(): array
    {
        if ($this->workingWeek !== null) {
            return $this->workingWeek;
        }

        try {
            $statement = Connection::pdo()->prepare(
                'SELECT value FROM platform.settings WHERE key = :key'
            );
            $statement->execute(['key' => 'company.working_days']);
            $raw = $statement->fetchColumn();

            $decoded = is_string($raw) ? json_decode($raw, true) : null;

            if (is_array($decoded)) {
                $days = [];

                foreach ($decoded as $day) {
                    $number = self::DAY_NUMBERS[strtolower(substr((string) $day, 0, 3))] ?? null;

                    if ($number !== null) {
                        $days[] = $number;
                    }
                }

                if ($days !== []) {
                    return $this->workingWeek = array_values(array_unique($days));
                }
            }
        } catch (\Throwable $exception) {
            Logger::warning('Working week setting unreadable, assuming Monday to Friday', [
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->workingWeek = self::DEFAULT_WORKING_WEEK;
    }
}
