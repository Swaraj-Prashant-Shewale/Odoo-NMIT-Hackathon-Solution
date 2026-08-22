<?php

declare(strict_types=1);

namespace App\Core;

use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;

/**
 * Server-side session for the web client.
 *
 * The browser only ever holds an opaque session cookie. The access and refresh
 * tokens live here, on the server, and are never written into the page, into
 * JavaScript, or into a cookie the browser can read. That single decision
 * removes the entire class of token-theft-by-script attacks: a cross-site
 * scripting flaw in a view could not steal a credential that the document
 * never contained.
 *
 * Two independent timeouts are enforced. An idle timeout signs out an
 * unattended machine; an absolute timeout bounds how long any one session can
 * live even if it is used constantly.
 */
final class Session
{
    private const KEY_ACCESS_TOKEN = '_access_token';
    private const KEY_REFRESH_TOKEN = '_refresh_token';
    private const KEY_EXPIRES_AT = '_token_expires_at';
    private const KEY_USER = '_user';
    private const KEY_LAST_SEEN = '_last_seen_at';
    private const KEY_STARTED = '_started_at';
    private const KEY_FINGERPRINT = '_fingerprint';

    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;

            return;
        }

        // Stated explicitly rather than relied upon from php.ini. The session
        // cookie is the entire authentication mechanism of the client, so its
        // security properties are set here, next to the code that depends on
        // them, and cannot be weakened by a change to a shared runtime file.
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => Env::bool('SESSION_SECURE_COOKIE', false),
            'httponly' => true,
            // Lax rather than Strict: the verification and password-reset links
            // arrive from an email client, and Strict would drop the session on
            // that first navigation.
            'samesite' => 'Lax',
        ]);

        session_start();
        self::$started = true;

        self::enforceLifetime();
        self::enforceFingerprint();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Records a successful sign-in.
     *
     * @param array<string, mixed> $user
     */
    public static function authenticate(string $accessToken, string $refreshToken, int $expiresIn, array $user): void
    {
        // Regenerating the id on privilege change defeats session fixation: a
        // session identifier planted before sign-in is worthless afterwards.
        session_regenerate_id(true);

        $_SESSION[self::KEY_ACCESS_TOKEN] = $accessToken;
        $_SESSION[self::KEY_REFRESH_TOKEN] = $refreshToken;
        $_SESSION[self::KEY_EXPIRES_AT] = Clock::timestamp() + max(0, $expiresIn - 30);
        $_SESSION[self::KEY_USER] = $user;
        $_SESSION[self::KEY_STARTED] = Clock::timestamp();
        $_SESSION[self::KEY_LAST_SEEN] = Clock::timestamp();
        $_SESSION[self::KEY_FINGERPRINT] = self::fingerprint();
    }

    public static function accessToken(): ?string
    {
        return $_SESSION[self::KEY_ACCESS_TOKEN] ?? null;
    }

    public static function refreshToken(): ?string
    {
        return $_SESSION[self::KEY_REFRESH_TOKEN] ?? null;
    }

    /** True when the access token is at or near expiry and should be renewed. */
    public static function tokenExpired(): bool
    {
        $expiresAt = $_SESSION[self::KEY_EXPIRES_AT] ?? 0;

        return Clock::timestamp() >= (int) $expiresAt;
    }

    public static function refreshTokens(string $accessToken, string $refreshToken, int $expiresIn): void
    {
        $_SESSION[self::KEY_ACCESS_TOKEN] = $accessToken;
        $_SESSION[self::KEY_REFRESH_TOKEN] = $refreshToken;
        $_SESSION[self::KEY_EXPIRES_AT] = Clock::timestamp() + max(0, $expiresIn - 30);
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        return $_SESSION[self::KEY_USER] ?? null;
    }

    /** @param array<string, mixed> $user */
    public static function setUser(array $user): void
    {
        $_SESSION[self::KEY_USER] = $user;
    }

    public static function isAuthenticated(): bool
    {
        return isset($_SESSION[self::KEY_ACCESS_TOKEN], $_SESSION[self::KEY_USER]);
    }

    /** True when the signed-in user holds a permission. */
    public static function can(string $permission): bool
    {
        $permissions = self::user()['permissions'] ?? [];

        return is_array($permissions) && in_array($permission, $permissions, true);
    }

    public static function canAny(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function hasRole(string $role): bool
    {
        $roles = self::user()['roles'] ?? [];

        return is_array($roles) && in_array($role, $roles, true);
    }

    public static function userId(): ?string
    {
        $value = self::user()['user_id'] ?? null;

        return $value === null ? null : (string) $value;
    }

    public static function employeeId(): ?string
    {
        $value = self::user()['employee_id'] ?? null;

        return $value === null ? null : (string) $value;
    }

    public static function displayName(): string
    {
        return (string) (self::user()['name'] ?? 'there');
    }

    public static function firstName(): string
    {
        return explode(' ', self::displayName())[0];
    }

    /** Clears everything and issues a fresh, empty session. */
    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
        self::$started = false;
    }

    /** Seconds of inactivity remaining before an automatic sign-out. */
    public static function idleSecondsRemaining(): int
    {
        $lastSeen = (int) ($_SESSION[self::KEY_LAST_SEEN] ?? Clock::timestamp());
        $limit = Env::int('SESSION_IDLE_TIMEOUT', 1800);

        return max(0, ($lastSeen + $limit) - Clock::timestamp());
    }

    private static function enforceLifetime(): void
    {
        if (!isset($_SESSION[self::KEY_STARTED])) {
            return;
        }

        $now = Clock::timestamp();
        $idleLimit = Env::int('SESSION_IDLE_TIMEOUT', 1800);
        $absoluteLimit = Env::int('SESSION_ABSOLUTE_TIMEOUT', 28800);

        $lastSeen = (int) ($_SESSION[self::KEY_LAST_SEEN] ?? $now);
        $startedAt = (int) $_SESSION[self::KEY_STARTED];

        if (($now - $lastSeen) > $idleLimit) {
            self::expire('Your session ended because you were inactive for a while.');

            return;
        }

        if (($now - $startedAt) > $absoluteLimit) {
            self::expire('Your session reached its maximum length. Please sign in again.');

            return;
        }

        $_SESSION[self::KEY_LAST_SEEN] = $now;
    }

    /**
     * Detects a session cookie being replayed from a different browser.
     *
     * The fingerprint deliberately excludes the client address, because a
     * roaming or mobile connection changes address legitimately and signing
     * such a user out repeatedly would be a bug, not a protection.
     */
    private static function enforceFingerprint(): void
    {
        if (!isset($_SESSION[self::KEY_FINGERPRINT])) {
            return;
        }

        if (!hash_equals((string) $_SESSION[self::KEY_FINGERPRINT], self::fingerprint())) {
            self::expire('Your session could not be verified. Please sign in again.');
        }
    }

    private static function expire(string $message): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        Flash::error($message);
    }

    private static function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        ]));
    }
}
