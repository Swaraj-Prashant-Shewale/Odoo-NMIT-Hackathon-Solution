<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Security;

use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;

/**
 * Proves that a request reached a service through the API gateway.
 *
 * Internal services are never published to the host, but network placement
 * alone is a weak control: anything that lands on the internal Docker network
 * could otherwise call them freely. Each proxied request therefore carries an
 * HMAC over the method, path, timestamp, nonce and body digest.
 *
 * The timestamp bounds a replay window and the body digest stops a relayed
 * request from being edited in flight.
 */
final class InternalSignature
{
    public const HEADER_SIGNATURE = 'X-Dayflow-Signature';
    public const HEADER_TIMESTAMP = 'X-Dayflow-Timestamp';
    public const HEADER_NONCE = 'X-Dayflow-Nonce';

    /**
     * Builds the headers the gateway attaches when proxying to a service.
     *
     * @return array<string, string>
     */
    public static function headers(string $method, string $path, string $body): array
    {
        $timestamp = (string) Clock::timestamp();
        $nonce = bin2hex(random_bytes(12));

        return [
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATURE => self::compute($method, $path, $body, $timestamp, $nonce),
        ];
    }

    /**
     * Verifies the signature attached to an inbound service request.
     *
     * @param array<string, string> $headers Header name => value, any casing.
     */
    public static function verify(string $method, string $path, string $body, array $headers): bool
    {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = (string) $value;
        }

        $signature = $normalised[strtolower(self::HEADER_SIGNATURE)] ?? '';
        $timestamp = $normalised[strtolower(self::HEADER_TIMESTAMP)] ?? '';
        $nonce = $normalised[strtolower(self::HEADER_NONCE)] ?? '';

        if ($signature === '' || $timestamp === '' || $nonce === '') {
            return false;
        }

        $skew = abs(Clock::timestamp() - (int) $timestamp);
        if ($skew > Env::int('INTERNAL_SIGNATURE_TTL', 60)) {
            return false;
        }

        $expected = self::compute($method, $path, $body, $timestamp, $nonce);

        return hash_equals($expected, $signature);
    }

    private static function compute(
        string $method,
        string $path,
        string $body,
        string $timestamp,
        string $nonce,
    ): string {
        $payload = implode("\n", [
            strtoupper($method),
            '/' . ltrim($path, '/'),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        return hash_hmac('sha256', $payload, self::secret());
    }

    private static function secret(): string
    {
        $secret = Env::require('INTERNAL_SIGNING_KEY');

        if (strlen($secret) < 32) {
            throw new \RuntimeException('INTERNAL_SIGNING_KEY must be at least 32 characters long.');
        }

        return $secret;
    }
}
