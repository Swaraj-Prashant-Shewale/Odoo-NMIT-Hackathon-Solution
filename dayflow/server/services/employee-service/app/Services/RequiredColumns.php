<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Drops explicit nulls aimed at columns the table declares NOT NULL.
 *
 * Every partial-update endpoint declares its fields as nullable so that a
 * caller can clear an optional value by sending null. For the handful of
 * columns that cannot be null, the same null is not a request the table can
 * honour, and letting it through would turn a caller mistake into a constraint
 * violation and a 500. Removing the key leaves the stored value untouched,
 * which is what "no value supplied" already means everywhere else.
 */
final class RequiredColumns
{
    /**
     * @param array<string, mixed> $data
     * @param list<string>         $columns Columns that must never be null.
     * @return array<string, mixed>
     */
    public static function stripNulls(array $data, array $columns): array
    {
        foreach ($columns as $column) {
            if (array_key_exists($column, $data) && $data[$column] === null) {
                unset($data[$column]);
            }
        }

        return $data;
    }
}
