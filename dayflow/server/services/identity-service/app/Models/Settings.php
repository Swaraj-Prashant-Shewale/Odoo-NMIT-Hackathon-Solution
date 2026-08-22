<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

/**
 * Company-wide defaults.
 *
 * The table lives in the shared "platform" schema because every service reads
 * it and only this service may write it. Each statement names the schema
 * explicitly rather than relying on the search path, so a table of the same
 * name appearing in the identity schema could never shadow it.
 */
final class Settings extends Repository
{
    protected string $table = 'settings';

    protected string $primaryKey = 'key';

    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    /** @return array<string, mixed> Key to decoded value. */
    public function all(string $orderBy = 'key', string $direction = 'asc'): array
    {
        $rows = $this->raw('SELECT key, value FROM platform.settings ORDER BY key');

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['key']] = json_decode((string) $row['value'], true);
        }

        return $settings;
    }

    /** @return list<array<string, mixed>> */
    public function detailed(): array
    {
        $rows = $this->raw('SELECT key, value, updated_at, updated_by FROM platform.settings ORDER BY key');

        return array_map(
            static fn (array $row): array => [
                'key' => (string) $row['key'],
                'value' => json_decode((string) $row['value'], true),
                'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
                'updated_by' => $row['updated_by'] === null ? null : (string) $row['updated_by'],
            ],
            $rows
        );
    }

    public function put(string $key, mixed $value, ?string $updatedBy): void
    {
        $this->execute(
            'INSERT INTO platform.settings (key, value, updated_at, updated_by)
             VALUES (:setting_key, :setting_value::jsonb, :updated_at, :updated_by)
             ON CONFLICT (key)
             DO UPDATE SET value = EXCLUDED.value,
                           updated_at = EXCLUDED.updated_at,
                           updated_by = EXCLUDED.updated_by',
            [
                'setting_key' => $key,
                'setting_value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => Clock::iso(),
                'updated_by' => $updatedBy,
            ]
        );
    }
}
