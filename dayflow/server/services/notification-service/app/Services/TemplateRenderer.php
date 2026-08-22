<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Support\Arr;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Str;

/**
 * Fills {{placeholder}} tokens in a stored template from an event payload.
 *
 * Every value substituted into the HTML body is escaped. Event payloads carry
 * names, notes and reasons that people typed, and an unescaped one here would
 * be a stored cross-site scripting hole that arrives, pre-delivered, in the
 * inbox of everybody the message reaches.
 */
final class TemplateRenderer
{
    private const PLACEHOLDER = '/\{\{\s*([A-Za-z_][A-Za-z0-9_.]*)\s*\}\}/';

    private function __construct()
    {
    }

    /** @param array<string, mixed> $values */
    public static function html(string $template, array $values): string
    {
        return self::substitute($template, $values, true);
    }

    /** @param array<string, mixed> $values */
    public static function text(string $template, array $values): string
    {
        return self::substitute($template, $values, false);
    }

    /** @param array<string, mixed> $values */
    private static function substitute(string $template, array $values, bool $escape): string
    {
        $rendered = preg_replace_callback(
            self::PLACEHOLDER,
            static function (array $matches) use ($values, $escape): string {
                $key = $matches[1];
                $value = self::stringify($key, Arr::get($values, $key));

                return $escape
                    ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
                    : $value;
            },
            $template
        );

        return $rendered ?? $template;
    }

    /**
     * Flattens one payload value into something a sentence can contain.
     *
     * Amounts and durations are the two places a raw value would be actively
     * misleading: payloads carry minor units and whole seconds, so 12345600 has
     * to become a currency amount and 27000 has to become "7h 30m" before
     * either of them reaches a person.
     */
    private static function stringify(string $key, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $parts[] = trim((string) $item);
                }
            }

            return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
        }

        if (!is_scalar($value)) {
            return '';
        }

        if (str_ends_with($key, '_minor') && is_numeric($value)) {
            return Str::money((int) $value, (string) Env::get('CURRENCY_SYMBOL', ''));
        }

        if (str_ends_with($key, '_seconds') && is_numeric($value)) {
            return Str::duration((int) $value);
        }

        // Control characters have no place in a subject line or a header and
        // would render as replacement glyphs in a message body.
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value);
    }
}
