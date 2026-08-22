<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Turns what somebody typed into a safe pattern for a bound ILIKE comparison.
 *
 * Wildcards in the term are neutralised, so searching for "50%" looks for that
 * text instead of matching every row. Backslash is already PostgreSQL's
 * default LIKE escape character, so the pattern deliberately carries no
 * ESCAPE clause: adding one would put a quoted backslash into the statement,
 * and PDO stops substituting placeholders once it meets one.
 */
final class SearchTerm
{
    public static function pattern(string $term): string
    {
        $escaped = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            mb_strtolower(trim($term))
        );

        return '%' . $escaped . '%';
    }
}
