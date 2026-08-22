<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Reads values out of a payload that arrived from another service.
 *
 * Analytics consumes eight services it does not own. Each one names its own
 * columns, and a dashboard card should not break because a service calls a
 * field "leave_type_name" where another calls it "leave_type". Every read
 * therefore names the keys it will accept, in order of preference, so the
 * normalisation is visible at the call site instead of hidden in a mapper.
 */
final class Payload
{
    /** @param list<string> $keys */
    public static function value(mixed $row, array $keys, mixed $default = null): mixed
    {
        if (!is_array($row)) {
            return $default;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return $default;
    }

    /** @param list<string> $keys */
    public static function text(mixed $row, array $keys, string $default = ''): string
    {
        $value = self::value($row, $keys);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @param list<string> $keys */
    public static function int(mixed $row, array $keys, int $default = 0): int
    {
        $value = self::value($row, $keys);

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @param list<string> $keys */
    public static function float(mixed $row, array $keys, float $default = 0.0): float
    {
        $value = self::value($row, $keys);

        return is_numeric($value) ? (float) $value : $default;
    }

    /** @param list<string> $keys */
    public static function bool(mixed $row, array $keys, bool $default = false): bool
    {
        $value = self::value($row, $keys);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Turns whatever a service returned into a list of rows.
     *
     * Collections arrive as a bare list, and a few endpoints wrap theirs in a
     * further "data" or "items" element; a single record is treated as a list
     * of one so a caller never has to branch.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $data): array
    {
        if (!is_array($data) || $data === []) {
            return [];
        }

        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        foreach (['data', 'items', 'rows', 'results'] as $wrapper) {
            if (isset($data[$wrapper]) && is_array($data[$wrapper])) {
                return self::rows($data[$wrapper]);
            }
        }

        return [$data];
    }

    /** Sums one numeric field across a set of rows. */
    public static function sum(array $rows, array $keys): float
    {
        $total = 0.0;

        foreach ($rows as $row) {
            $total += self::float($row, $keys);
        }

        return $total;
    }

    /** Rounds a value for display without ever emitting a negative zero. */
    public static function round(float $value, int $precision = 2): float
    {
        $rounded = round($value, $precision);

        return $rounded === -0.0 ? 0.0 : $rounded;
    }

    /** Share of $part in $whole as a percentage, guarding division by zero. */
    public static function percent(float $part, float $whole, int $precision = 1): float
    {
        if ($whole <= 0.0) {
            return 0.0;
        }

        return self::round(($part / $whole) * 100, $precision);
    }
}
