<?php

declare(strict_types=1);

namespace Gateway;

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Jwt;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Security\RateLimiter;
use Dayflow\Kernel\Security\TokenException;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;

/**
 * The single entry point to the Dayflow API.
 *
 * Responsibilities, in the order they are applied:
 *
 *   1. Cross-origin policy      - only the configured client origin is allowed
 *   2. Rate limiting            - per address, with a strict tier for sign-in
 *   3. Route resolution         - an allow-list of published path prefixes
 *   4. Authentication           - verifies the access token once, centrally
 *   5. Proxying                 - signs the call and forwards it downstream
 *
 * Concentrating these here means a service can never be added to the platform
 * without a conscious decision to publish it, and no service has to reimplement
 * cross-cutting policy for itself.
 */
final class Gateway
{
    public function handle(): void
    {
        $request = Request::capture();

        try {
            $response = $this->route($request);
        } catch (HttpException $exception) {
            $response = $exception->toResponse();
        } catch (\Throwable $exception) {
            Logger::exception($exception, ['path' => $request->path]);
            $response = Response::error(
                500,
                'gateway_error',
                Env::isDebug() ? $exception->getMessage() : 'The gateway could not complete this request.'
            );
        }

        $response
            ->withHeaders($this->corsHeaders($request) + ['X-Request-Id' => $request->requestId])
            ->send();
    }

    private function route(Request $request): Response
    {
        // Browsers send a preflight before any cross-origin call with headers.
        if ($request->method === 'OPTIONS') {
            return Response::noContent();
        }

        if ($request->path === '/health') {
            return $this->health();
        }

        if ($request->path === '/_catalogue' && !Env::isProduction()) {
            return Response::ok(RouteTable::catalogue());
        }

        $this->enforceRateLimit($request);

        $service = RouteTable::resolve($request->path);

        if ($service === null) {
            throw HttpException::notFound('No API endpoint matches this address.');
        }

        $token = null;

        if (!RouteTable::isPublic($request->path, $request->method)) {
            $token = $this->requireValidToken($request);
        }

        return (new Proxy())->forward($service, $request, $token);
    }

    /**
     * Verifies the caller's access token and returns it for forwarding.
     *
     * The token is checked once, here, so a service never has to trust an
     * unverified credential. It is then passed downstream unchanged, which
     * lets each service apply its own authorisation rules against the real
     * user rather than against the gateway.
     */
    private function requireValidToken(Request $request): string
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw HttpException::unauthorized('A valid access token is required.');
        }

        try {
            $claims = Jwt::verify($token);
        } catch (TokenException $exception) {
            throw HttpException::unauthorized($exception->getMessage());
        }

        if (($claims['type'] ?? 'access') !== 'access') {
            throw HttpException::unauthorized('This token cannot be used to call the API.');
        }

        if ($this->isRevoked((string) ($claims['jti'] ?? ''))) {
            throw HttpException::unauthorized('This session has been signed out.');
        }

        // Building the principal here validates the claim shape early and
        // gives the access log a real actor rather than an opaque token.
        $principal = Principal::fromClaims($claims);

        Logger::debug('Request authenticated', [
            'user_id' => $principal->userId,
            'role' => $principal->primaryRole(),
            'path' => $request->path,
        ]);

        return $token;
    }

    /**
     * Checks the revocation list.
     *
     * Access tokens are short lived, but signing out has to take effect at
     * once rather than up to fifteen minutes later.
     */
    private function isRevoked(string $tokenId): bool
    {
        if ($tokenId === '') {
            return true;
        }

        try {
            $statement = Connection::pdo()->prepare(
                'SELECT 1 FROM identity.revoked_tokens WHERE token_id = :token_id AND expires_at > NOW()'
            );
            $statement->execute(['token_id' => $tokenId]);

            return $statement->fetchColumn() !== false;
        } catch (\Throwable $exception) {
            // The table is created by the identity service's first migration.
            // Until then, treat tokens as valid rather than locking everyone out.
            Logger::warning('Revocation check unavailable', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * Applies request throttling.
     *
     * Two tiers: a broad ceiling on all API traffic from one address, and a
     * much tighter allowance on the authentication endpoints, which are the
     * ones worth attacking.
     */
    private function enforceRateLimit(Request $request): void
    {
        $strict = RouteTable::strictLimit($request->path);

        if ($strict !== null) {
            $result = RateLimiter::hit(
                'gw:strict:' . $request->path . ':' . $request->clientIp,
                $strict['limit'],
                $strict['window']
            );

            if (!$result['allowed']) {
                Logger::warning('Strict rate limit exceeded', [
                    'path' => $request->path,
                    'ip' => $request->clientIp,
                ]);

                throw HttpException::tooManyRequests(
                    'Too many attempts. Please wait a few minutes and try again.',
                    $result['retry_after']
                );
            }
        }

        $result = RateLimiter::hit(
            'gw:general:' . $request->clientIp,
            Env::int('RATE_LIMIT_PER_MINUTE', 120),
            60
        );

        if (!$result['allowed']) {
            throw HttpException::tooManyRequests(
                'You are sending requests too quickly. Please slow down.',
                $result['retry_after']
            );
        }

        // Housekeeping, run occasionally rather than on every request.
        if (random_int(1, 200) === 1) {
            RateLimiter::prune();
        }
    }

    /** Reports the gateway's own state plus that of every service behind it. */
    private function health(): Response
    {
        $services = [
            'identity', 'employee', 'attendance', 'leave', 'payroll',
            'learning', 'talent', 'notification', 'analytics',
        ];

        $statuses = [];
        $allHealthy = true;

        foreach ($services as $service) {
            $healthy = (new Proxy())->ping($service);
            $statuses[$service] = $healthy ? 'healthy' : 'unreachable';

            if (!$healthy) {
                $allHealthy = false;
            }
        }

        $database = Connection::healthy();

        return Response::ok([
            'gateway' => 'healthy',
            'database' => $database ? 'healthy' : 'unreachable',
            'services' => $statuses,
            'status' => $allHealthy && $database ? 'healthy' : 'degraded',
            'time' => Clock::iso(),
        ]);
    }

    /**
     * Cross-origin headers.
     *
     * The origin is echoed back only when it appears in the configured list;
     * a wildcard is never used, because credentials are involved.
     *
     * @return array<string, string>
     */
    private function corsHeaders(Request $request): array
    {
        $origin = $request->header('origin');
        $allowed = Env::list('CORS_ALLOWED_ORIGINS', ['http://localhost:8000']);

        if ($origin === '' || !in_array($origin, $allowed, true)) {
            return ['Vary' => 'Origin'];
        }

        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Request-Id',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
    }
}
