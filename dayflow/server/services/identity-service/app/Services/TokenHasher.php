<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Support\Env;

/**
 * Turns a bearer secret into the value stored beside it.
 *
 * Refresh tokens, verification links and password-reset links are all long
 * random values, so a password hash would be pointless expense. What they do
 * need is a lookup that is exact, constant time and useless to anyone reading
 * the table, which is what a keyed digest gives:
 *
 *  - keyed, so a stolen copy of the database cannot be searched for a token
 *    the attacker already holds, and a rainbow table buys nothing;
 *  - deterministic, so the row is found with a single indexed equality rather
 *    than a scan or a loop over candidates;
 *  - fixed width, so comparison time never depends on the value.
 */
final class TokenHasher
{
    private static ?string $key = null;

    public static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, self::key());
    }

    /**
     * Confirms a stored digest belongs to the presented token.
     *
     * The indexed lookup has already narrowed to one row; this is the constant
     * time comparison that actually decides, so a near miss cannot be teased
     * out by measuring the response.
     */
    public static function matches(string $token, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($token));
    }

    private static function key(): string
    {
        // Derived from the platform key rather than configured separately, so
        // there is one secret to rotate and no way to deploy with this one
        // accidentally left at a default.
        return self::$key ??= hash('sha256', Env::require('ENCRYPTION_KEY') . '|identity.token.digest', true);
    }
}
