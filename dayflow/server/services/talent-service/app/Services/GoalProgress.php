<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Works out where a goal stands.
 *
 * An explicitly supplied percentage always wins over one derived from the
 * numbers, because not every metric improves upward: "cut p95 latency to
 * 250 ms" and "reduce attrition to 9%" both get worse as current_value rises,
 * so current / target would report them backwards.
 */
final class GoalProgress
{
    /**
     * @param array<string, mixed> $goal The stored goal, used for its target
     *                                   and its existing progress.
     */
    public static function resolve(array $goal, ?float $currentValue, ?float $progressPercent): float
    {
        if ($progressPercent !== null) {
            return self::clamp($progressPercent);
        }

        $target = isset($goal['target_value']) && $goal['target_value'] !== null
            ? (float) $goal['target_value']
            : null;

        $current = $currentValue ?? (
            isset($goal['current_value']) && $goal['current_value'] !== null
                ? (float) $goal['current_value']
                : null
        );

        if ($target !== null && $target > 0.0 && $current !== null) {
            return self::clamp($current / $target * 100);
        }

        return self::clamp((float) ($goal['progress_percent'] ?? 0));
    }

    /** Keeps a percentage inside the range the column will accept. */
    public static function clamp(float $value): float
    {
        return round(max(0.0, min(100.0, $value)), 3);
    }

    /** Renders a percentage without trailing zeros, for error messages. */
    public static function format(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');

        return str_contains($formatted, '.')
            ? rtrim(rtrim($formatted, '0'), '.')
            : $formatted;
    }
}
