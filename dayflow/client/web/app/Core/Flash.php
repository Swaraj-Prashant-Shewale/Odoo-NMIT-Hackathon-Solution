<?php

declare(strict_types=1);

namespace App\Core;

/**
 * One-shot messages that survive a redirect.
 *
 * After a form is submitted the browser is redirected rather than shown the
 * result directly, so a refresh cannot resubmit it. The message that should
 * appear on the next page is parked here.
 */
final class Flash
{
    private const KEY = '_flash';
    private const OLD_INPUT = '_old_input';
    private const ERRORS = '_field_errors';

    public static function success(string $message): void
    {
        self::add('success', $message);
    }

    public static function error(string $message): void
    {
        self::add('danger', $message);
    }

    public static function warning(string $message): void
    {
        self::add('warning', $message);
    }

    public static function info(string $message): void
    {
        self::add('info', $message);
    }

    /**
     * Takes every pending message and clears the queue.
     *
     * @return list<array{type: string, message: string}>
     */
    public static function drain(): array
    {
        $messages = Session::get(self::KEY, []);
        Session::forget(self::KEY);

        return is_array($messages) ? $messages : [];
    }

    /**
     * Remembers what was typed so a rejected form can be redisplayed filled in.
     *
     * Password fields are dropped: re-rendering a password into the HTML would
     * put it into the browser cache and into any page-source screenshot.
     *
     * @param array<string, mixed> $input
     */
    public static function withInput(array $input): void
    {
        foreach (array_keys($input) as $key) {
            if (str_contains(strtolower((string) $key), 'password')) {
                unset($input[$key]);
            }
        }

        Session::put(self::OLD_INPUT, $input);
    }

    /** Reads a remembered field value, then leaves it in place for this render. */
    public static function old(string $key, string $default = ''): string
    {
        $input = Session::get(self::OLD_INPUT, []);
        $value = is_array($input) ? ($input[$key] ?? $default) : $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @param array<string, list<string>> $errors */
    public static function withErrors(array $errors): void
    {
        Session::put(self::ERRORS, $errors);
    }

    /** @return list<string> */
    public static function errorsFor(string $field): array
    {
        $errors = Session::get(self::ERRORS, []);

        if (!is_array($errors) || !isset($errors[$field]) || !is_array($errors[$field])) {
            return [];
        }

        return array_map('strval', $errors[$field]);
    }

    public static function hasError(string $field): bool
    {
        return self::errorsFor($field) !== [];
    }

    /** @return array<string, list<string>> */
    public static function allErrors(): array
    {
        $errors = Session::get(self::ERRORS, []);

        return is_array($errors) ? $errors : [];
    }

    /**
     * Clears the remembered form state.
     *
     * Called by the router once a page has finished rendering, so old input
     * appears exactly once rather than leaking into the next unrelated form.
     */
    public static function clearInput(): void
    {
        Session::forget(self::OLD_INPUT);
        Session::forget(self::ERRORS);
    }

    private static function add(string $type, string $message): void
    {
        $messages = Session::get(self::KEY, []);
        $messages = is_array($messages) ? $messages : [];
        $messages[] = ['type' => $type, 'message' => $message];

        Session::put(self::KEY, $messages);
    }
}
