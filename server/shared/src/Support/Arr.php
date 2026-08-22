<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Support;

/** Array helpers used when shaping API payloads. */
final class Arr
{
    /** Reads a nested value using dotted notation: Arr::get($data, 'user.profile.name'). */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        $value = $array;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** Keeps only the listed keys. */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /** Drops the listed keys, typically before returning a record to a client. */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /** Re-indexes a list of rows by one of their columns. */
    public static function keyBy(array $rows, string $column): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (isset($row[$column])) {
                $result[$row[$column]] = $row;
            }
        }

        return $result;
    }

    /** Sums one numeric column across a list of rows. */
    public static function sumBy(array $rows, string $column): float
    {
        return array_sum(array_map(
            static fn (array $row): float => (float) ($row[$column] ?? 0),
            $rows
        ));
    }

    /** Groups rows into buckets by a column value. */
    public static function groupBy(array $rows, string $column): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row[$column] ?? ''][] = $row;
        }

        return $groups;
    }

    /** True when every element passes the callback. */
    public static function every(array $items, callable $callback): bool
    {
        foreach ($items as $key => $item) {
            if (!$callback($item, $key)) {
                return false;
            }
        }

        return true;
    }

    /** Returns the first element passing the callback, or $default. */
    public static function first(array $items, ?callable $callback = null, mixed $default = null): mixed
    {
        foreach ($items as $key => $item) {
            if ($callback === null || $callback($item, $key)) {
                return $item;
            }
        }

        return $default;
    }
}
