<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Support;

/** Small, dependency-free string utilities shared by every service. */
final class Str
{
    /** RFC 4122 version 4 identifier built from a cryptographically secure source. */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    /**
     * A stable RFC 4122 version 5 identifier derived from a name.
     *
     * Nine services seed their own slice of the same demo company, so they have
     * to agree on the identifier for a person before any of them writes a row.
     * Deriving it from the employee code means each service can work the id out
     * for itself: no shared table of literals to copy, and no ordering
     * requirement between the seeds.
     *
     * @param string $namespace A UUID naming the kind of thing being identified.
     */
    public static function uuidFor(string $name, string $namespace): string
    {
        $namespaceBytes = hex2bin(str_replace('-', '', $namespace));

        if ($namespaceBytes === false || strlen($namespaceBytes) !== 16) {
            throw new \InvalidArgumentException('The namespace must be a UUID.');
        }

        $hash = sha1($namespaceBytes . $name, true);

        $bytes = substr($hash, 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    /** URL-safe random token, used for verification links and refresh tokens. */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Numeric one-time code, zero padded to the requested length. */
    public static function numericCode(int $digits = 6): string
    {
        $max = (10 ** $digits) - 1;

        return str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);
    }

    public static function slug(string $value): string
    {
        $value = preg_replace('/[^\p{L}\p{Nd}]+/u', '-', $value) ?? '';

        return trim(strtolower($value), '-');
    }

    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    /** Hides all but the first and last characters of an identifier for logs. */
    public static function mask(string $value, int $visible = 2): string
    {
        $length = strlen($value);
        if ($length <= $visible * 2) {
            return str_repeat('*', max($length, 1));
        }

        return substr($value, 0, $visible)
            . str_repeat('*', $length - ($visible * 2))
            . substr($value, -$visible);
    }

    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        $local = $parts[0];
        $domain = $parts[1] ?? '';

        return self::mask($local) . '@' . $domain;
    }

    public static function initials(string $first, string $last): string
    {
        return strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
    }

    /** Formats a monetary amount held in minor units (paise / cents). */
    public static function money(int $minorUnits, string $symbol = ''): string
    {
        $formatted = number_format($minorUnits / 100, 2, '.', ',');

        return $symbol === '' ? $formatted : $symbol . $formatted;
    }

    /**
     * Extracts the video id from any common YouTube URL shape.
     *
     * Training material is stored as a plain URL but embedded as a privacy
     * enhanced iframe, so the id has to be isolated before rendering.
     */
    public static function youtubeId(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $patterns = [
            '~youtu\.be/([A-Za-z0-9_-]{11})~',
            '~youtube\.com/watch\?(?:.*&)?v=([A-Za-z0-9_-]{11})~',
            '~youtube\.com/embed/([A-Za-z0-9_-]{11})~',
            '~youtube-nocookie\.com/embed/([A-Za-z0-9_-]{11})~',
            '~youtube\.com/shorts/([A-Za-z0-9_-]{11})~',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches) === 1) {
                return $matches[1];
            }
        }

        // A bare id may also be supplied directly.
        if (preg_match('~^[A-Za-z0-9_-]{11}$~', $url) === 1) {
            return $url;
        }

        return null;
    }

    /** Converts seconds into a human duration such as "1h 24m". */
    public static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return $minutes > 0 ? sprintf('%dh %dm', $hours, $minutes) : sprintf('%dh', $hours);
        }

        return sprintf('%dm', max($minutes, 1));
    }
}
