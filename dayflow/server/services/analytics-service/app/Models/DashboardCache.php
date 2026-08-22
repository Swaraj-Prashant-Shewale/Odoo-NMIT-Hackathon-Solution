<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;

/**
 * Short-lived storage for an assembled dashboard.
 *
 * Assembling one costs a dozen calls to other services, and somebody
 * refreshing their home screen twice in a minute should not pay for it twice.
 * Entries live for a minute, which is short enough that a fresh check-in shows
 * up almost immediately. How the key is built - and why that is the single most
 * safety-critical decision in this service - is explained in
 * App\Services\DashboardAssembler::cacheKey().
 */
final class DashboardCache extends Repository
{
    protected string $table = 'dashboard_cache';

    protected array $fillable = ['cache_key', 'scope_key', 'payload', 'expires_at', 'created_at'];

    protected array $casts = ['payload' => 'json'];

    // An entry is replaced wholesale rather than edited, so created_at alone
    // describes it and the base class timestamp handling is not used.
    protected bool $timestamps = false;

    /** @return array<string, mixed>|null The cached payload, or null when absent or stale. */
    public function read(string $cacheKey): ?array
    {
        $row = $this->rawOne(
            'SELECT payload FROM dashboard_cache WHERE cache_key = :cache_key AND expires_at > :now',
            ['cache_key' => $cacheKey, 'now' => Clock::iso()]
        );

        if ($row === null) {
            return null;
        }

        $payload = is_string($row['payload']) ? json_decode($row['payload'], true) : $row['payload'];

        return is_array($payload) ? $payload : null;
    }

    /** @param array<string, mixed> $payload */
    public function write(string $cacheKey, string $scopeKey, array $payload, int $ttlSeconds): void
    {
        $sql = <<<'SQL'
            INSERT INTO dashboard_cache (id, cache_key, scope_key, payload, expires_at, created_at)
            VALUES (:id, :cache_key, :scope_key, :payload, :expires_at, :created_at)
            ON CONFLICT (cache_key)
            DO UPDATE SET payload    = EXCLUDED.payload,
                          scope_key  = EXCLUDED.scope_key,
                          expires_at = EXCLUDED.expires_at,
                          created_at = EXCLUDED.created_at
            SQL;

        $ttl = max(1, min($ttlSeconds, 900));

        $this->execute($sql, [
            'id' => Str::uuid(),
            'cache_key' => $cacheKey,
            'scope_key' => $scopeKey,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'expires_at' => Clock::now()->modify(sprintf('+%d seconds', $ttl))->format(\DateTimeInterface::ATOM),
            'created_at' => Clock::iso(),
        ]);
    }

    /** Drops every entry belonging to one caller, used when a dashboard is force-refreshed. */
    public function forgetScope(string $scopeKey): void
    {
        $this->execute('DELETE FROM dashboard_cache WHERE scope_key = :scope_key', ['scope_key' => $scopeKey]);
    }

    /** Removes stale entries so the table stays small without a scheduled job. */
    public function forgetExpired(): void
    {
        $this->execute('DELETE FROM dashboard_cache WHERE expires_at <= :now', ['now' => Clock::iso()]);
    }
}
