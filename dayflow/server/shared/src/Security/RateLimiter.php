<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Security;

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Support\Clock;

/**
 * Fixed-window rate limiter backed by PostgreSQL.
 *
 * Counters live in the shared "platform" schema so the gateway and the identity
 * service observe the same limits. An atomic upsert increments and reads the
 * counter in a single statement, which keeps the count correct when several
 * requests arrive at once.
 *
 * Two layers are applied to sign-in: one keyed on the client address, and one
 * keyed on the account being targeted. The first slows a broad scan; the second
 * stops a distributed attempt from grinding a single account.
 */
final class RateLimiter
{
    /**
     * Registers one attempt and reports whether the caller is over the limit.
     *
     * @return array{allowed: bool, remaining: int, retry_after: int, limit: int}
     */
    public static function hit(string $key, int $limit, int $windowSeconds): array
    {
        $bucket = self::bucket($key, $windowSeconds);
        $expiresAt = self::windowEnd($windowSeconds);

        $sql = <<<'SQL'
            INSERT INTO platform.rate_limits (bucket_key, hits, expires_at)
            VALUES (:bucket, 1, :expires_at)
            ON CONFLICT (bucket_key)
            DO UPDATE SET hits = platform.rate_limits.hits + 1
            RETURNING hits, expires_at
        SQL;

        $statement = Connection::pdo()->prepare($sql);
        $statement->execute(['bucket' => $bucket, 'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM)]);

        $row = $statement->fetch();
        $hits = (int) ($row['hits'] ?? 1);
        $retryAfter = max(0, $expiresAt->getTimestamp() - Clock::timestamp());

        return [
            'allowed' => $hits <= $limit,
            'remaining' => max(0, $limit - $hits),
            'retry_after' => $retryAfter,
            'limit' => $limit,
        ];
    }

    /** Reads the current count without registering an attempt. */
    public static function tooManyAttempts(string $key, int $limit, int $windowSeconds): bool
    {
        $statement = Connection::pdo()->prepare(
            'SELECT hits FROM platform.rate_limits WHERE bucket_key = :bucket AND expires_at > NOW()'
        );
        $statement->execute(['bucket' => self::bucket($key, $windowSeconds)]);

        return (int) ($statement->fetchColumn() ?: 0) >= $limit;
    }

    /** Clears a counter, called after a successful sign-in. */
    public static function clear(string $key, int $windowSeconds): void
    {
        $statement = Connection::pdo()->prepare('DELETE FROM platform.rate_limits WHERE bucket_key = :bucket');
        $statement->execute(['bucket' => self::bucket($key, $windowSeconds)]);
    }

    /** Removes expired counters; called opportunistically so the table stays small. */
    public static function prune(): void
    {
        Connection::pdo()->exec('DELETE FROM platform.rate_limits WHERE expires_at < NOW()');
    }

    /**
     * Builds the storage key.
     *
     * The window start is folded into the key so a new window naturally begins
     * with a fresh counter, and the raw key is hashed so an address or email
     * address is never written to the table in the clear.
     */
    private static function bucket(string $key, int $windowSeconds): string
    {
        $windowStart = intdiv(Clock::timestamp(), max(1, $windowSeconds)) * $windowSeconds;

        return hash('sha256', $key . '|' . $windowStart . '|' . $windowSeconds);
    }

    private static function windowEnd(int $windowSeconds): \DateTimeImmutable
    {
        $windowSeconds = max(1, $windowSeconds);
        $windowStart = intdiv(Clock::timestamp(), $windowSeconds) * $windowSeconds;

        return (new \DateTimeImmutable('@' . ($windowStart + $windowSeconds)))
            ->setTimezone(Clock::timezone());
    }
}
