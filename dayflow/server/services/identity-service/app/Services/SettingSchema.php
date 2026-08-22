<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The shape of every company setting.
 *
 * These values are read by eight other services to decide what a working day
 * is, how long one lasts and what currency to render. A malformed entry would
 * therefore not fail here; it would fail somewhere else, hours later, in a
 * payroll run. So each key is checked against its own structure before it is
 * allowed into the table, and unknown keys are refused outright rather than
 * quietly stored where nothing will ever read them.
 */
final class SettingSchema
{
    private const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * The defaults, which are also the complete list of permitted keys.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'company.name' => 'Dayflow Technologies Pvt. Ltd.',
            'company.working_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'company.work_hours' => ['start' => '09:30', 'end' => '18:30'],
            'company.half_day_hours' => 4,
            'company.full_day_hours' => 8,
            'company.late_grace_minutes' => 15,
            'company.currency' => ['code' => 'INR', 'symbol' => '₹'],
            'company.financial_year_start' => '04-01',
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::defaults());
    }

    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::defaults());
    }

    /**
     * Checks and normalises one value.
     *
     * @return array{ok: bool, value: mixed, errors: list<string>}
     */
    public static function normalise(string $key, mixed $value): array
    {
        return match ($key) {
            'company.name' => self::text($value, 2, 150),
            'company.working_days' => self::workingDays($value),
            'company.work_hours' => self::workHours($value),
            'company.half_day_hours', 'company.full_day_hours' => self::hours($value),
            'company.late_grace_minutes' => self::wholeNumber($value, 0, 240),
            'company.currency' => self::currency($value),
            'company.financial_year_start' => self::monthDay($value),
            default => self::reject('This setting is not recognised.'),
        };
    }

    /**
     * Human labels for the settings screen, so the client does not invent its own.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'company.name' => 'Registered company name',
            'company.working_days' => 'Working days',
            'company.work_hours' => 'Standard working hours',
            'company.half_day_hours' => 'Hours that count as a half day',
            'company.full_day_hours' => 'Hours that count as a full day',
            'company.late_grace_minutes' => 'Grace period before an arrival counts as late',
            'company.currency' => 'Currency',
            'company.financial_year_start' => 'Financial year start (MM-DD)',
        ];
    }

    /** @return array{ok: bool, value: mixed, errors: list<string>} */
    private static function text(mixed $value, int $min, int $max): array
    {
        if (!is_string($value)) {
            return self::reject('This setting must be text.');
        }

        $value = trim($value);
        $length = mb_strlen($value);

        if ($length < $min || $length > $max) {
            return self::reject(sprintf('This setting must be between %d and %d characters.', $min, $max));
        }

        // Control characters have no place in a value that is rendered on a
        // payslip and inside a PDF header.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return self::reject('This setting contains characters that are not allowed.');
        }

        return self::accept($value);
    }

    /** @return array{ok: bool, value: mixed, errors: list<string>} */
    private static function workingDays(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return self::reject('At least one working day must be selected.');
        }

        $days = [];
        foreach ($value as $day) {
            if (!is_string($day) || !in_array(strtolower(trim($day)), self::WEEKDAYS, true)) {
                return self::reject('Working days must be chosen from mon, tue, wed, thu, fri, sat and sun.');
            }

            $days[] = strtolower(trim($day));
        }

        // Stored in week order rather than the order they were clicked, so a
        // calendar built from this list never renders Friday before Monday.
        $ordered = array_values(array_intersect(self::WEEKDAYS, array_unique($days)));

        return self::accept($ordered);
    }

    /** @return array{ok: bool, value: mixed, errors: list<string>} */
    private static function workHours(mixed $value): array
    {
        if (!is_array($value) || !isset($value['start'], $value['end'])) {
            return self::reject('Working hours must supply both a start and an end time.');
        }

        $start = self::timeOfDay($value['start']);
        $end = self::timeOfDay($value['end']);

        if ($start === null || $end === null) {
            return self::reject('Working hours must be times in HH:MM format.');
        }

        if ($end <= $start) {
            return self::reject('The end of the working day must be later than its start.');
        }

        return self::accept(['start' => $start, 'end' => $end]);
    }

    /** @return array{ok: bool, value: mixed, errors: list<string>} */
    private static function hours(mixed $value): array
    {
        if (!is_numeric($value)) {
            return self::reject('This setting must be a number of hours.');
        }

        $hours = (float) $value;

        if ($hours <= 0 || $hours > 24) {
            return self::reject('This setting must be more than zero and no more than 24 hours.');
        }

        // Whole hours stay whole so the value round-trips as 8 rather than 8.0.
        return self::accept($hours === floor($hours) ? (int) $hours : $hours);
    }

    /** @return array{ok: bool, value: mixed, errors: list<string>} */
    private static function wholeNumber(mixed $value, int $min, int $max): array
    {
        if (!is_numeric($value) || (string) (int) $value !== (string) $value) {
            return self::reject('This setting must be a whole number.');
        }

        $number = (int) $value;

        if ($number < $min || $number > $max) {
            return self::reject(sprintf('This setting must be between %d and %d.', $min, $max));
        }

        return self::accept($number);
    }

    /** @return array{ok: bool, value: mixed, errors: list<string>} */
    private static function currency(mixed $value): array
    {
        if (!is_array($value) || !isset($value['code'], $value['symbol'])) {
            return self::reject('Currency must supply both a code and a symbol.');
        }

        $code = is_string($value['code']) ? strtoupper(trim($value['code'])) : '';
        $symbol = is_string($value['symbol']) ? trim($value['symbol']) : '';

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            return self::reject('Currency code must be three letters, such as INR.');
        }

        if ($symbol === '' || mb_strlen($symbol) > 5) {
            return self::reject('Currency symbol must be between one and five characters.');
        }

        return self::accept(['code' => $code, 'symbol' => $symbol]);
    }

    /** @return array{ok: bool, value: mixed, errors: list<string>} */
    private static function monthDay(mixed $value): array
    {
        if (!is_string($value) || preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', trim($value)) !== 1) {
            return self::reject('This setting must be a month and day in MM-DD format.');
        }

        $value = trim($value);
        [$month, $day] = array_map('intval', explode('-', $value));

        // A leap-year day would silently vanish in three years out of four, so
        // the financial year cannot be pinned to one.
        if (!checkdate($month, $day, 2001)) {
            return self::reject('This setting must be a date that exists in every year.');
        }

        return self::accept($value);
    }

    private static function timeOfDay(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', trim($value)) !== 1) {
            return null;
        }

        return trim($value);
    }

    /** @return array{ok: bool, value: mixed, errors: list<string>} */
    private static function accept(mixed $value): array
    {
        return ['ok' => true, 'value' => $value, 'errors' => []];
    }

    /** @return array{ok: bool, value: mixed, errors: list<string>} */
    private static function reject(string $message): array
    {
        return ['ok' => false, 'value' => null, 'errors' => [$message]];
    }
}
