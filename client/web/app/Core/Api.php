<?php

declare(strict_types=1);

namespace App\Core;

use Dayflow\Kernel\Security\ForwardedFor;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;

/**
 * The web client's connection to the API gateway.
 *
 * This is the only place in the client that performs network I/O. Controllers
 * ask it for data and it returns plain arrays; they never see a status code or
 * a token.
 *
 * When the short-lived access token expires mid-session it is renewed
 * transparently using the refresh token held in the server-side session, and
 * the original call is retried once. The person using the application never
 * sees a spurious sign-in prompt fifteen minutes into their day.
 */
final class Api
{
    /** Thrown as a signal that the caller should be sent to the sign-in page. */
    public const UNAUTHENTICATED = 'unauthenticated';

    /** @return array{ok: bool, status: int, data: mixed, meta: array, error: ?array} */
    public static function get(string $path, array $query = []): array
    {
        $suffix = $query === [] ? '' : '?' . http_build_query($query);

        return self::send('GET', $path . $suffix, null);
    }

    public static function post(string $path, array $payload = []): array
    {
        return self::send('POST', $path, $payload);
    }

    /**
     * Calls an endpoint that is open to people who are not signed in.
     *
     * Registration, email verification, and the forgotten-password flow all
     * happen before there is a session to authenticate with. Routing them
     * through post() would have them turned away at the door by the token
     * check and never reach the gateway at all - the caller would be handed a
     * manufactured 401 and would report "Please sign in to continue" to
     * somebody whose entire problem is that they cannot.
     */
    public static function postPublic(string $path, array $payload = []): array
    {
        return self::sendRaw('POST', $path, $payload, null);
    }

    public static function put(string $path, array $payload = []): array
    {
        return self::send('PUT', $path, $payload);
    }

    public static function patch(string $path, array $payload = []): array
    {
        return self::send('PATCH', $path, $payload);
    }

    public static function delete(string $path): array
    {
        return self::send('DELETE', $path, null);
    }

    /**
     * Returns just the data element, or a fallback.
     *
     * Used for page sections that should degrade rather than fail: a dashboard
     * card that cannot load is better than a dashboard that will not render.
     */
    public static function data(string $path, array $query = [], mixed $fallback = null): mixed
    {
        $response = self::get($path, $query);

        return $response['ok'] ? ($response['data'] ?? $fallback) : $fallback;
    }

    /** @return list<array<string, mixed>> */
    public static function collection(string $path, array $query = []): array
    {
        $data = self::data($path, $query, []);

        return is_array($data) ? $data : [];
    }

    /**
     * Uploads a file through the gateway using a multipart request.
     *
     * @param array<string, string> $fields
     * @param array{tmp_name: string, name: string, type: string} $file
     */
    public static function upload(string $path, array $fields, array $file, string $fieldName = 'file'): array
    {
        $token = self::token();

        if ($token === null) {
            return self::unauthenticated();
        }

        $payload = $fields;
        $payload[$fieldName] = new \CURLFile(
            $file['tmp_name'],
            $file['type'],
            // The uploaded name is passed through for the record, but the
            // service generates its own storage filename; it never trusts this.
            basename($file['name'])
        );

        $handle = curl_init(self::gateway() . '/' . ltrim($path, '/'));
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array_merge(
                ['Authorization: Bearer ' . $token, 'Accept: application/json'],
                self::forwardedHeaders()
            ),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return self::interpret($status, is_string($raw) ? $raw : '');
    }

    /**
     * Fetches a binary document (a payslip, a certificate, a report export) and
     * streams it straight to the browser.
     */
    public static function stream(string $path, array $query = []): bool
    {
        $token = self::token();

        if ($token === null) {
            return false;
        }

        $suffix = $query === [] ? '' : '?' . http_build_query($query);

        $handle = curl_init(self::gateway() . '/' . ltrim($path, '/') . $suffix);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => array_merge(
                ['Authorization: Bearer ' . $token],
                self::forwardedHeaders()
            ),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        if ($raw === false || $status !== 200) {
            return false;
        }

        $headers = substr((string) $raw, 0, $headerSize);
        $body = substr((string) $raw, $headerSize);

        foreach (explode("\r\n", $headers) as $line) {
            if (preg_match('/^(Content-Type|Content-Disposition|Content-Length):\s*(.+)$/i', $line, $matches) === 1) {
                header($matches[1] . ': ' . str_replace(["\r", "\n"], '', $matches[2]));
            }
        }

        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');

        echo $body;

        return true;
    }

    /**
     * Signs in and stores the resulting tokens in the server-side session.
     *
     * @return array{ok: bool, error: ?array}
     */
    public static function login(string $email, string $password): array
    {
        $response = self::sendRaw('POST', '/auth/login', ['email' => $email, 'password' => $password], null);

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error']];
        }

        $data = $response['data'];

        Session::authenticate(
            (string) ($data['access_token'] ?? ''),
            (string) ($data['refresh_token'] ?? ''),
            (int) ($data['expires_in'] ?? 900),
            is_array($data['user'] ?? null) ? $data['user'] : []
        );

        Csrf::rotate();

        return ['ok' => true, 'error' => null];
    }

    /** Revokes the session at the server, then clears it locally. */
    public static function logout(): void
    {
        $refreshToken = Session::refreshToken();

        if ($refreshToken !== null && Session::accessToken() !== null) {
            // A failure here must not stop the local sign-out: the user asked
            // to leave, so the session is cleared regardless.
            self::sendRaw('POST', '/auth/logout', ['refresh_token' => $refreshToken], Session::accessToken());
        }

        Session::destroy();
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{ok: bool, status: int, data: mixed, meta: array, error: ?array}
     */
    private static function send(string $method, string $path, ?array $payload): array
    {
        $token = self::token();

        if ($token === null) {
            return self::unauthenticated();
        }

        $response = self::sendRaw($method, $path, $payload, $token);

        // One transparent retry after renewing an expired token. If the second
        // attempt also fails on authentication, the session is genuinely over.
        if ($response['status'] === 401 && self::renew()) {
            $response = self::sendRaw($method, $path, $payload, Session::accessToken());
        }

        return $response;
    }

    /**
     * Renews the access token using the refresh token.
     *
     * Refresh tokens rotate on every use, so the new one must be stored. If the
     * renewal fails the session is destroyed rather than left in a half-valid
     * state that would fail confusingly on the next click.
     */
    private static function renew(): bool
    {
        $refreshToken = Session::refreshToken();

        if ($refreshToken === null) {
            return false;
        }

        $response = self::sendRaw('POST', '/auth/refresh', ['refresh_token' => $refreshToken], null);

        if (!$response['ok'] || !is_array($response['data'])) {
            Session::destroy();

            return false;
        }

        $data = $response['data'];

        Session::refreshTokens(
            (string) ($data['access_token'] ?? ''),
            (string) ($data['refresh_token'] ?? $refreshToken),
            (int) ($data['expires_in'] ?? 900)
        );

        if (isset($data['user']) && is_array($data['user'])) {
            Session::setUser($data['user']);
        }

        return true;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{ok: bool, status: int, data: mixed, meta: array, error: ?array}
     */
    private static function sendRaw(string $method, string $path, ?array $payload, ?string $token): array
    {
        $url = self::gateway() . '/' . ltrim($path, '/');
        $body = $payload === null ? '' : (string) json_encode($payload, JSON_UNESCAPED_SLASHES);

        $headers = ['Accept: application/json'];

        if ($body !== '') {
            $headers[] = 'Content-Type: application/json';
        }

        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $headers = array_merge($headers, self::forwardedHeaders());

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => Env::int('API_TIMEOUT_SECONDS', 30),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false || $status === 0) {
            Logger::error('Gateway unreachable', ['url' => $url, 'error' => $error]);

            return [
                'ok' => false,
                'status' => 503,
                'data' => null,
                'meta' => [],
                'error' => [
                    'code' => 'gateway_unreachable',
                    'message' => 'The service is not responding right now. Please try again in a moment.',
                ],
            ];
        }

        return self::interpret($status, (string) $raw);
    }

    /** @return array{ok: bool, status: int, data: mixed, meta: array, error: ?array} */
    private static function interpret(int $status, string $raw): array
    {
        $decoded = json_decode($raw, true);
        $envelope = is_array($decoded) ? $decoded : [];

        $ok = $status >= 200 && $status < 300;

        return [
            'ok' => $ok,
            'status' => $status,
            'data' => $envelope['data'] ?? null,
            'meta' => is_array($envelope['meta'] ?? null) ? $envelope['meta'] : [],
            'error' => $ok ? null : ($envelope['error'] ?? [
                'code' => 'unexpected_error',
                'message' => 'Something went wrong. Please try again.',
            ]),
        ];
    }

    /** @return array{ok: bool, status: int, data: mixed, meta: array, error: array} */
    private static function unauthenticated(): array
    {
        return [
            'ok' => false,
            'status' => 401,
            'data' => null,
            'meta' => [],
            'error' => ['code' => self::UNAUTHENTICATED, 'message' => 'Please sign in to continue.'],
        ];
    }

    private static function token(): ?string
    {
        if (!Session::isAuthenticated()) {
            return null;
        }

        // Renew proactively rather than waiting for a 401, which saves a
        // round trip on the very common "first click after a coffee" case.
        if (Session::tokenExpired() && !self::renew()) {
            return null;
        }

        return Session::accessToken();
    }

    private static function gateway(): string
    {
        return rtrim(Env::get('GATEWAY_URL', 'http://gateway'), '/');
    }

    /**
     * Passes the browser's own address through to the gateway.
     *
     * Without this every call arrives from the web container, so the gateway
     * would throttle the whole company as if it were one visitor and the audit
     * trail would record the same address for every action ever taken.
     *
     * @return list<string>
     */
    private static function forwardedHeaders(): array
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return [];
        }

        $headers = [
            ForwardedFor::HEADER_IP . ': ' . $remote,
            'X-Forwarded-Proto: ' . ((($_SERVER['HTTPS'] ?? '') !== '') ? 'https' : 'http'),
        ];

        // Unsigned, the address would be a claim anyone could make; the gateway
        // would rightly ignore it and go back to throttling the whole company
        // as one visitor.
        if (ForwardedFor::available()) {
            $headers[] = ForwardedFor::HEADER_PROOF . ': ' . ForwardedFor::proof($remote);
        }

        return $headers;
    }
}
