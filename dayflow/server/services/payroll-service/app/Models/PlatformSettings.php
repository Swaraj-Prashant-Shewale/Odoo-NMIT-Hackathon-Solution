<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * Read-only access to the organisation-wide settings.
 *
 * The table lives in the shared "platform" schema and is owned by the identity
 * service; payroll holds SELECT on it and nothing more, so the company name,
 * working week and financial-year start are read here rather than duplicated.
 */
final class PlatformSettings extends Repository
{
    protected string $table = 'settings';

    protected string $primaryKey = 'key';

    // Nothing in this service ever writes a platform setting.
    protected array $fillable = [];

    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    /** @var array<string, mixed> */
    private static array $cache = [];

    /** Returns the decoded JSON value, or $default when the key is absent. */
    public function value(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        try {
            $row = $this->rawOne('SELECT value FROM platform.settings WHERE key = :key', ['key' => $key]);
        } catch (\Throwable) {
            // A settings table that is not there yet must not stop payroll from
            // running; every caller supplies a sensible default.
            return $default;
        }

        if ($row === null) {
            return self::$cache[$key] = $default;
        }

        $decoded = is_string($row['value']) ? json_decode($row['value'], true) : $row['value'];

        return self::$cache[$key] = $decoded ?? $default;
    }

    public function string(string $key, string $default): string
    {
        $value = $this->value($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return array<int|string, mixed> */
    public function arrayValue(string $key, array $default): array
    {
        $value = $this->value($key, $default);

        return is_array($value) ? $value : $default;
    }
}
