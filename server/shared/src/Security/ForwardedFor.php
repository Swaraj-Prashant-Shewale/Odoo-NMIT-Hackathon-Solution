<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Security;

use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;

/**
 * Proves that a forwarded client address came from our own web client.
 *
 * The web client renders every page on the server, so each page view reaches
 * the gateway as several calls from one container. Left alone the gateway would
 * see the whole company as a single visitor: rate limits would be shared by
 * everyone and the audit trail would record one address for every action ever
 * taken.
 *
 * X-Forwarded-For on its own cannot fix that, because the gateway publishes a
 * port and anyone who can reach it could claim any address they liked. The
 * header is therefore accompanied by an HMAC over the address and a timestamp,
 * computed with the same internal key the gateway uses to sign its calls to the
 * services. Only something already holding that key can hand the gateway an
 * address to believe.
 */
final class ForwardedFor
{
    public const HEADER_IP = 'X-Forwarded-For';
    public const HEADER_PROOF = 'X-Dayflow-Forwarded-Proof';

    /** How long a proof stays valid, in seconds. */
    private const TTL = 60;

    /** Builds the proof that accompanies a forwarded address. */
    public static function proof(string $ip): string
    {
        $timestamp = (string) Clock::timestamp();

        return $timestamp . '.' . self::compute($ip, $timestamp);
    }

    /** True when the proof genuinely covers this address and is still fresh. */
    public static function verify(string $ip, string $proof): bool
    {
        if ($ip === '' || $proof === '' || !str_contains($proof, '.')) {
            return false;
        }

        [$timestamp, $signature] = explode('.', $proof, 2);

        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) {
            return false;
        }

        if (abs(Clock::timestamp() - (int) $timestamp) > self::TTL) {
            return false;
        }

        return hash_equals(self::compute($ip, $timestamp), $signature);
    }

    private static function compute(string $ip, string $timestamp): string
    {
        return hash_hmac('sha256', $ip . "\n" . $timestamp, self::secret());
    }

    private static function secret(): string
    {
        $secret = Env::get('INTERNAL_SIGNING_KEY', '');

        // An empty key would make every proof verify against every other, so
        // the feature switches itself off rather than degrading into a hole.
        if ($secret === null || strlen($secret) < 32) {
            throw new \RuntimeException('INTERNAL_SIGNING_KEY must be at least 32 characters long.');
        }

        return $secret;
    }

    /** True when a proof can be produced or checked at all. */
    public static function available(): bool
    {
        $secret = Env::get('INTERNAL_SIGNING_KEY', '');

        return is_string($secret) && strlen($secret) >= 32;
    }
}
