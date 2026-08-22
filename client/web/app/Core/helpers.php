<?php

declare(strict_types=1);

/**
 * Template helpers.
 *
 * Declared in the global namespace on purpose: they appear on nearly every
 * line of every template, and a namespace prefix would make the markup far
 * harder to read. Loaded once from the front controller.
 */

use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;

// ---------------------------------------------------------------------------
// Template helpers
//
// Deliberately short names, because they appear on almost every line of every
// template and long ones would make the markup unreadable.
// ---------------------------------------------------------------------------

/**
 * Escapes a value for HTML output.
 *
 * Every dynamic value in every template goes through this. It is the single
 * defence against a stored cross-site scripting attack, and the reason an
 * employee cannot put a script tag in their address field and have it run in
 * the HR officer's browser.
 */
function e(mixed $value): string
{
    if ($value === null || is_bool($value) || is_array($value)) {
        return '';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escapes a value for use inside a JavaScript string or JSON block. */
function ejs(mixed $value): string
{
    return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

/** Builds an application URL. */
function url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

/** Reads a value from a record with a fallback, already escaped. */
function field(array $record, string $key, string $fallback = '—'): string
{
    $value = $record[$key] ?? null;

    if ($value === null || $value === '') {
        return e($fallback);
    }

    return e($value);
}

/** Formats a stored date as "12 Mar 2026". */
function date_display(?string $date, string $fallback = '—'): string
{
    if ($date === null || $date === '') {
        return $fallback;
    }

    $timestamp = strtotime($date);

    return $timestamp === false ? $fallback : date('j M Y', $timestamp);
}

/** Formats a stored timestamp as "12 Mar 2026, 9:42 am". */
function datetime_display(?string $value, string $fallback = '—'): string
{
    if ($value === null || $value === '') {
        return $fallback;
    }

    try {
        $moment = new \DateTimeImmutable($value);
    } catch (\Exception) {
        return $fallback;
    }

    return $moment->setTimezone(Clock::timezone())->format('j M Y, g:i a');
}

/** Formats a stored timestamp as just the time, "9:42 am". */
function time_display(?string $value, string $fallback = '—'): string
{
    if ($value === null || $value === '') {
        return $fallback;
    }

    try {
        $moment = new \DateTimeImmutable($value);
    } catch (\Exception) {
        return $fallback;
    }

    return $moment->setTimezone(Clock::timezone())->format('g:i a');
}

/** "3 days ago", "in 2 weeks". */
function relative_time(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '—';
    }

    $delta = time() - $timestamp;
    $future = $delta < 0;
    $delta = abs($delta);

    $units = [
        [31536000, 'year'],
        [2592000, 'month'],
        [604800, 'week'],
        [86400, 'day'],
        [3600, 'hour'],
        [60, 'minute'],
    ];

    foreach ($units as [$seconds, $label]) {
        if ($delta >= $seconds) {
            $count = (int) floor($delta / $seconds);
            $plural = $count === 1 ? '' : 's';

            return $future
                ? sprintf('in %d %s%s', $count, $label, $plural)
                : sprintf('%d %s%s ago', $count, $label, $plural);
        }
    }

    return 'just now';
}

/** Formats minor currency units for display. */
function money(mixed $minorUnits, bool $withSymbol = true): string
{
    $amount = number_format(((int) $minorUnits) / 100, 2, '.', ',');

    if (!$withSymbol) {
        return $amount;
    }

    return Env::get('CURRENCY_SYMBOL', '₹') . $amount;
}

/** Formats a number of hours from a count of seconds: "7h 45m". */
function hours(mixed $seconds): string
{
    $seconds = (int) $seconds;

    if ($seconds <= 0) {
        return '—';
    }

    return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
}

/** Maps a domain status onto a Bootstrap contextual colour. */
function status_colour(?string $status): string
{
    return match (strtolower((string) $status)) {
        'approved', 'present', 'completed', 'active', 'confirmed', 'verified', 'paid', 'achieved', 'sent' => 'success',
        'pending', 'draft', 'in_progress', 'processing', 'submitted', 'probation', 'not_started', 'queued', 'open' => 'warning',
        'rejected', 'absent', 'cancelled', 'expired', 'terminated', 'failed', 'missed' => 'danger',
        'half_day', 'on_leave', 'wfh', 'notice_period', 'restricted' => 'info',
        'holiday', 'weekly_off', 'withdrawn', 'resigned', 'closed' => 'secondary',
        default => 'secondary',
    };
}

/** Turns a stored enum into a readable label: "half_day" becomes "Half day". */
function label(?string $value, string $fallback = '—'): string
{
    if ($value === null || $value === '') {
        return $fallback;
    }

    return ucfirst(str_replace('_', ' ', $value));
}

/** Renders a coloured status pill. */
function badge(?string $status, string $fallback = '—'): string
{
    if ($status === null || $status === '') {
        return '<span class="text-muted">' . e($fallback) . '</span>';
    }

    return sprintf(
        '<span class="badge bg-%s-subtle text-%s-emphasis border border-%s-subtle">%s</span>',
        status_colour($status),
        status_colour($status),
        status_colour($status),
        e(label($status))
    );
}

/** Initials for an avatar placeholder. */
function initials(?string $name): string
{
    $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
    $first = $parts[0] ?? '';
    $last = count($parts) > 1 ? end($parts) : '';

    return strtoupper(substr($first, 0, 1) . substr((string) $last, 0, 1)) ?: '?';
}

/** True when the current path matches, used to highlight the active nav item. */
function is_active(string $prefix): bool
{
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    return $path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/');
}

/** Percentage clamped to 0-100, for progress bars. */
function percent(mixed $value): int
{
    return max(0, min(100, (int) round((float) $value)));
}
