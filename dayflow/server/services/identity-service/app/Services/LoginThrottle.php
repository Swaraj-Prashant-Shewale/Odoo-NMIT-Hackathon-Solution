<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoginAttempts;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Encryptor;
use Dayflow\Kernel\Security\RateLimiter;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Str;

/**
 * The three layers that stand between a guessed password and an account.
 *
 * They answer different attacks and are deliberately not the same number:
 *
 *  1. A per-address counter blunts a broad scan across many accounts from one
 *     machine.
 *  2. A per-account counter blunts a distributed attempt at one account, where
 *     every request arrives from a different address.
 *  3. The account lockout is the real defence. It is slower to trip than the
 *     counters above so that the loud, auditable, notifying response is what a
 *     targeted attack meets, rather than a quiet 429 nobody reads.
 *
 * Both counters are cleared on a successful sign-in, so somebody who simply
 * uses the product a lot is never throttled for it.
 */
final class LoginThrottle
{
    private LoginAttempts $attempts;

    public function __construct()
    {
        $this->attempts = new LoginAttempts();
    }

    public function maxAttempts(): int
    {
        return max(1, Env::int('LOGIN_MAX_ATTEMPTS', 5));
    }

    public function lockoutSeconds(): int
    {
        return max(60, Env::int('LOGIN_LOCKOUT_SECONDS', 900));
    }

    /**
     * Registers one attempt against both counters and refuses if either is spent.
     *
     * @throws HttpException 429 when the caller must wait.
     */
    public function guard(string $email, string $ipAddress): void
    {
        $window = $this->lockoutSeconds();

        $perAddress = RateLimiter::hit(
            'identity:login:ip:' . $ipAddress,
            max(1, Env::int('LOGIN_IP_MAX_ATTEMPTS', 30)),
            $window
        );

        $perAccount = RateLimiter::hit(
            'identity:login:account:' . $this->fingerprint($email),
            $this->maxAttempts() * 2,
            $window
        );

        foreach ([$perAddress, $perAccount] as $result) {
            if (!$result['allowed']) {
                throw HttpException::tooManyRequests(
                    'Too many sign-in attempts. Please wait a few minutes and try again.',
                    (int) $result['retry_after']
                );
            }
        }
    }

    /**
     * Registers one attempt against a named bucket, used by the recovery flows.
     *
     * The subject is whatever the flow is protecting - an address for a reset,
     * a client address for token refresh - and is fingerprinted either way so
     * the counter table never holds the value itself.
     */
    public function guardRecovery(string $bucket, string $subject, int $limit): void
    {
        $result = RateLimiter::hit(
            'identity:' . $bucket . ':' . $this->fingerprint($subject),
            max(1, $limit),
            3600
        );

        if (!$result['allowed']) {
            throw HttpException::tooManyRequests(
                'Too many requests. Please wait a few minutes before trying again.',
                (int) $result['retry_after']
            );
        }
    }

    public function clear(string $email, string $ipAddress): void
    {
        $window = $this->lockoutSeconds();

        RateLimiter::clear('identity:login:ip:' . $ipAddress, $window);
        RateLimiter::clear('identity:login:account:' . $this->fingerprint($email), $window);
    }

    /** Writes one line to the sign-in security log. */
    public function record(string $email, string $ipAddress, bool $successful, ?string $failureReason): void
    {
        $this->attempts->create([
            'id' => Str::uuid(),
            'email_hash' => $this->fingerprint($email),
            'ip_address' => $ipAddress,
            'successful' => $successful,
            'attempted_at' => Clock::iso(),
            'failure_reason' => $failureReason,
        ]);
    }

    /** The instant a lock taken now would lift. */
    public function lockedUntil(): string
    {
        return Clock::now()
            ->modify('+' . $this->lockoutSeconds() . ' seconds')
            ->format(\DateTimeInterface::ATOM);
    }

    /**
     * A keyed, non-reversible stand-in for the address.
     *
     * Rate-limit buckets and the attempt log both need to recognise the same
     * account across requests without ever holding the address itself.
     */
    private function fingerprint(string $subject): string
    {
        return Encryptor::blindIndex($subject);
    }
}
