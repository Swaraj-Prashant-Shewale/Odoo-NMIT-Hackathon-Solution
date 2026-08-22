<?php

declare(strict_types=1);

namespace App\Core;

use Dayflow\Kernel\Support\Clock;

/**
 * Cross-site request forgery protection.
 *
 * Every form that changes state carries a token bound to the session. Without
 * it, another site could quietly submit a request on a signed-in user's behalf
 * — approving their own leave, changing a salary — because the browser would
 * attach the session cookie automatically.
 *
 * Tokens are per-session rather than per-form, which keeps multiple open tabs
 * working, and they rotate on sign-in and on a fixed schedule.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';
    private const ISSUED_KEY = '_csrf_issued_at';
    public const FIELD = '_token';

    /** Token lifetime. Long enough to fill in a form, short enough to matter. */
    private const LIFETIME = 7200;

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        $issuedAt = (int) Session::get(self::ISSUED_KEY, 0);

        if (!is_string($token) || $token === '' || (Clock::timestamp() - $issuedAt) > self::LIFETIME) {
            $token = bin2hex(random_bytes(32));
            Session::put(self::SESSION_KEY, $token);
            Session::put(self::ISSUED_KEY, Clock::timestamp());
        }

        return $token;
    }

    /** The hidden input to drop into every form. */
    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
        );
    }

    /** Verifies a submitted token in constant time. */
    public static function verify(?string $submitted): bool
    {
        $expected = Session::get(self::SESSION_KEY);

        if (!is_string($expected) || $expected === '' || !is_string($submitted) || $submitted === '') {
            return false;
        }

        $issuedAt = (int) Session::get(self::ISSUED_KEY, 0);
        if ((Clock::timestamp() - $issuedAt) > self::LIFETIME) {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    /** Issues a fresh token, called whenever the privilege level changes. */
    public static function rotate(): void
    {
        Session::forget(self::SESSION_KEY);
        Session::forget(self::ISSUED_KEY);
        self::token();
    }
}
