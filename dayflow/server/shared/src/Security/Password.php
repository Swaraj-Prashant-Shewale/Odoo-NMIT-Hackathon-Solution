<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Security;

use Dayflow\Kernel\Support\Env;

/**
 * Password hashing and strength policy.
 *
 * Argon2id is used where the runtime provides it and bcrypt is the fallback.
 * Hashes carry their own parameters, so rehashing on login keeps older
 * accounts moving forward as the cost settings are raised over time.
 */
final class Password
{
    private const MIN_LENGTH = 10;
    private const MAX_LENGTH = 200;

    /**
     * A small set of passwords that show up in every breach corpus. Rejecting
     * them costs nothing and removes the easiest possible account takeover.
     */
    private const BLOCKLIST = [
        'password', 'password1', 'password123', '12345678', '123456789', '1234567890',
        'qwerty123', 'letmein123', 'welcome123', 'admin123', 'iloveyou1', 'football1',
        'dayflow123', 'changeme123', 'passw0rd', 'p@ssw0rd', 'administrator',
    ];

    public static function hash(string $plain): string
    {
        $hash = password_hash($plain, self::algorithm(), self::options());

        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    public static function verify(string $plain, string $hash): bool
    {
        // password_verify is already constant time for a given hash.
        return password_verify($plain, $hash);
    }

    /** True when the stored hash was made with weaker settings than current policy. */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm(), self::options());
    }

    /**
     * Runs a password through the account policy.
     *
     * @return list<string> Human readable problems; empty means the password is acceptable.
     */
    public static function problems(string $plain, array $personalData = []): array
    {
        $problems = [];
        $length = strlen($plain);

        if ($length < self::MIN_LENGTH) {
            $problems[] = sprintf('Password must be at least %d characters long.', self::MIN_LENGTH);
        }

        if ($length > self::MAX_LENGTH) {
            $problems[] = sprintf('Password must be no longer than %d characters.', self::MAX_LENGTH);
        }

        if (preg_match('/[A-Z]/', $plain) !== 1) {
            $problems[] = 'Password must contain at least one uppercase letter.';
        }

        if (preg_match('/[a-z]/', $plain) !== 1) {
            $problems[] = 'Password must contain at least one lowercase letter.';
        }

        if (preg_match('/[0-9]/', $plain) !== 1) {
            $problems[] = 'Password must contain at least one number.';
        }

        if (in_array(strtolower($plain), self::BLOCKLIST, true)) {
            $problems[] = 'That password is too common. Please choose a different one.';
        }

        // A password that simply repeats the person's own name or email local
        // part is trivially guessable by anyone who knows them.
        foreach ($personalData as $value) {
            $value = trim((string) $value);
            if (strlen($value) >= 4 && stripos($plain, $value) !== false) {
                $problems[] = 'Password must not contain your name or email address.';
                break;
            }
        }

        if (preg_match('/(.)\1{3,}/', $plain) === 1) {
            $problems[] = 'Password must not repeat the same character four or more times.';
        }

        return array_values(array_unique($problems));
    }

    /** Score from 0 to 4, used to drive the strength meter in the interface. */
    public static function strength(string $plain): int
    {
        $score = 0;
        $length = strlen($plain);

        if ($length >= self::MIN_LENGTH) {
            $score++;
        }
        if ($length >= 14) {
            $score++;
        }
        if (preg_match('/[A-Z]/', $plain) === 1 && preg_match('/[a-z]/', $plain) === 1) {
            $score++;
        }
        if (preg_match('/[0-9]/', $plain) === 1 && preg_match('/[^A-Za-z0-9]/', $plain) === 1) {
            $score++;
        }

        return min($score, 4);
    }

    /** A policy-compliant password, used when an administrator creates an account. */
    public static function generate(int $length = 16): string
    {
        $sets = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnpqrstuvwxyz',
            '23456789',
            '!@#$%^&*-_=+',
        ];

        $password = '';
        foreach ($sets as $set) {
            $password .= $set[random_int(0, strlen($set) - 1)];
        }

        $pool = implode('', $sets);
        for ($i = strlen($password); $i < $length; $i++) {
            $password .= $pool[random_int(0, strlen($pool) - 1)];
        }

        return str_shuffle($password);
    }

    private static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private static function options(): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return [
                'memory_cost' => Env::int('ARGON_MEMORY_COST', 65536),
                'time_cost' => Env::int('ARGON_TIME_COST', 4),
                'threads' => Env::int('ARGON_THREADS', 2),
            ];
        }

        return ['cost' => Env::int('BCRYPT_COST', 12)];
    }
}
