<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Support;

/**
 * Reads configuration from the process environment.
 *
 * Every deployable value (database credentials, signing keys, service URLs)
 * enters the application through this class and nowhere else. Nothing is ever
 * hard-coded, which keeps secrets out of the repository.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return self::$cache[$key] = (string) $value;
    }

    /**
     * Fetches a value that the application cannot start without.
     *
     * @throws \RuntimeException when the variable is missing.
     */
    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new \RuntimeException(sprintf('Required environment variable "%s" is not set.', $key));
        }

        return $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string> */
    public static function list(string $key, array $default = []): array
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== ''
        ));
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'local') === 'production';
    }

    public static function isDebug(): bool
    {
        return self::bool('APP_DEBUG', !self::isProduction());
    }
}
