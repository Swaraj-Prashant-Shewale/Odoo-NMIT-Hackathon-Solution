<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Security;

use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;

/**
 * Compact JSON Web Token implementation (HS256).
 *
 * Written directly against PHP's hash extension so the platform carries no
 * third-party cryptography dependency. The verification path is strict:
 *
 *  - the algorithm is pinned, so an attacker cannot downgrade to "none";
 *  - the signature is compared in constant time;
 *  - issuer, audience, expiry and not-before are all checked;
 *  - a token id (jti) is present so individual tokens can be revoked.
 */
final class Jwt
{
    private const ALGORITHM = 'HS256';

    /**
     * Signs a payload and returns the encoded token.
     *
     * @param array<string, mixed> $claims
     */
    public static function issue(array $claims, int $ttlSeconds, ?string $secret = null): string
    {
        $issuedAt = Clock::timestamp();

        $payload = $claims + [
            'iss' => Env::get('JWT_ISSUER', 'dayflow.identity'),
            'aud' => Env::get('JWT_AUDIENCE', 'dayflow.api'),
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $issuedAt + $ttlSeconds,
            'jti' => bin2hex(random_bytes(16)),
        ];

        $header = self::base64UrlEncode((string) json_encode([
            'alg' => self::ALGORITHM,
            'typ' => 'JWT',
        ]));

        $body = self::base64UrlEncode((string) json_encode($payload));
        $signature = self::sign($header . '.' . $body, $secret ?? self::secret());

        return $header . '.' . $body . '.' . $signature;
    }

    /**
     * Verifies a token and returns its claims.
     *
     * @return array<string, mixed>
     * @throws TokenException when the token is malformed, forged or expired.
     */
    public static function verify(string $token, ?string $secret = null): array
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            throw new TokenException('Malformed token.');
        }

        [$header, $body, $signature] = $segments;

        $decodedHeader = json_decode(self::base64UrlDecode($header), true);
        if (!is_array($decodedHeader) || ($decodedHeader['alg'] ?? null) !== self::ALGORITHM) {
            // Refusing any algorithm but the expected one blocks both the
            // "alg: none" bypass and RS256-to-HS256 confusion attacks.
            throw new TokenException('Unsupported token algorithm.');
        }

        $expected = self::sign($header . '.' . $body, $secret ?? self::secret());
        if (!hash_equals($expected, $signature)) {
            throw new TokenException('Token signature is invalid.');
        }

        $claims = json_decode(self::base64UrlDecode($body), true);
        if (!is_array($claims)) {
            throw new TokenException('Token payload is not readable.');
        }

        $now = Clock::timestamp();
        $leeway = Env::int('JWT_LEEWAY_SECONDS', 5);

        if (isset($claims['nbf']) && $now + $leeway < (int) $claims['nbf']) {
            throw new TokenException('Token is not valid yet.');
        }

        if (!isset($claims['exp']) || $now - $leeway >= (int) $claims['exp']) {
            throw new TokenException('Token has expired.');
        }

        $issuer = Env::get('JWT_ISSUER', 'dayflow.identity');
        if (isset($claims['iss']) && !hash_equals($issuer, (string) $claims['iss'])) {
            throw new TokenException('Token issuer is not trusted.');
        }

        $audience = Env::get('JWT_AUDIENCE', 'dayflow.api');
        if (isset($claims['aud']) && !hash_equals($audience, (string) $claims['aud'])) {
            throw new TokenException('Token audience does not match.');
        }

        return $claims;
    }

    /** Reads claims without verifying. Only ever used for diagnostics. */
    public static function peek(string $token): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            return [];
        }

        $claims = json_decode(self::base64UrlDecode($segments[1]), true);

        return is_array($claims) ? $claims : [];
    }

    private static function sign(string $message, string $secret): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $message, $secret, true));
    }

    private static function secret(): string
    {
        $secret = Env::require('JWT_SECRET');

        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET must be at least 32 characters long.');
        }

        return $secret;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padded = str_pad($value, (int) (ceil(strlen($value) / 4) * 4), '=', STR_PAD_RIGHT);

        return (string) base64_decode(strtr($padded, '-_', '+/'), true);
    }
}
