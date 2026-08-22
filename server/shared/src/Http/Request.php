<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Http;

use Dayflow\Kernel\Security\Principal;

/**
 * An immutable view of the inbound HTTP request.
 *
 * Controllers only ever read input through this object, which keeps decoding
 * and normalisation in one place. Values arrive as raw strings; converting and
 * validating them is the Validator's job, never the controller's.
 */
final class Request
{
    private ?Principal $principal = null;

    /** @var array<string, string> */
    private array $routeParameters = [];

    private function __construct(
        public readonly string $method,
        public readonly string $path,
        /** @var array<string, mixed> */
        public readonly array $query,
        /** @var array<string, mixed> */
        public readonly array $body,
        /** @var array<string, string> */
        public readonly array $headers,
        public readonly string $rawBody,
        public readonly string $clientIp,
        public readonly string $requestId,
        /** @var array<string, mixed> */
        public readonly array $files,
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = '/' . trim((string) parse_url($uri, PHP_URL_PATH), '/');

        $headers = self::readHeaders();
        $rawBody = (string) file_get_contents('php://input');
        $body = self::decodeBody($rawBody, $headers['content-type'] ?? '');

        return new self(
            method: $method,
            path: $path === '//' ? '/' : $path,
            query: $_GET,
            body: $body,
            headers: $headers,
            rawBody: $rawBody,
            clientIp: self::resolveClientIp($headers),
            requestId: $headers['x-request-id'] ?? bin2hex(random_bytes(8)),
            files: $_FILES,
        );
    }

    /** Reads from the body first, then the query string. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** @return array<int|string, mixed> */
    public function array(string $key): array
    {
        $value = $this->input($key, []);

        return is_array($value) ? $value : [];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    /** @return array<string, mixed> Body merged over query string. */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /** @return array<string, mixed> */
    public function only(string ...$keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /** Pagination page number, clamped to a sane range. */
    public function page(): int
    {
        return max(1, $this->int('page', 1));
    }

    public function perPage(int $default = 20, int $max = 100): int
    {
        return max(1, min($this->int('per_page', $default), $max));
    }

    public function withPrincipal(Principal $principal): self
    {
        $clone = clone $this;
        $clone->principal = $principal;

        return $clone;
    }

    public function principal(): Principal
    {
        if ($this->principal === null) {
            throw new HttpException(401, 'Authentication is required for this action.');
        }

        return $this->principal;
    }

    public function hasPrincipal(): bool
    {
        return $this->principal !== null;
    }

    /** @param array<string, string> $parameters */
    public function withRouteParameters(array $parameters): self
    {
        $clone = clone $this;
        $clone->routeParameters = $parameters;

        return $clone;
    }

    public function route(string $key, string $default = ''): string
    {
        return $this->routeParameters[$key] ?? $default;
    }

    /** @return array<string, string> */
    public function routeParameters(): array
    {
        return $this->routeParameters;
    }

    public function userAgent(): string
    {
        return substr($this->header('user-agent'), 0, 255);
    }

    /** @return array<string, string> */
    private static function readHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $header) {
            if (isset($_SERVER[$server])) {
                $headers[$header] = (string) $_SERVER[$server];
            }
        }

        // The Authorization header is a persistent source of trouble. Web
        // servers commonly withhold it from the application, and an internal
        // rewrite to a front controller can rename it with a REDIRECT_ prefix.
        // The server configuration passes it through explicitly, and these
        // fallbacks make the application correct even where it does not.
        if (!isset($headers['authorization'])) {
            $candidates = [
                $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
                $_SERVER['HTTP_X_AUTHORIZATION'] ?? null,
            ];

            if (function_exists('apache_request_headers')) {
                foreach (apache_request_headers() ?: [] as $name => $value) {
                    if (strtolower((string) $name) === 'authorization') {
                        $candidates[] = $value;
                        break;
                    }
                }
            }

            foreach ($candidates as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $headers['authorization'] = $candidate;
                    break;
                }
            }
        }

        return $headers;
    }

    /** @return array<string, mixed> */
    private static function decodeBody(string $raw, string $contentType): array
    {
        if ($raw === '') {
            return $_POST;
        }

        if (str_contains(strtolower($contentType), 'application/json')) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        if ($_POST !== []) {
            return $_POST;
        }

        // PUT and PATCH bodies are not populated into $_POST by PHP.
        if (str_contains(strtolower($contentType), 'application/x-www-form-urlencoded')) {
            parse_str($raw, $parsed);

            return $parsed;
        }

        return [];
    }

    /**
     * Determines the caller's address.
     *
     * X-Forwarded-For is only honoured when the immediate peer is a configured
     * trusted proxy. Accepting it unconditionally would let any client spoof
     * its own address and walk straight past the rate limiter.
     *
     * @param array<string, string> $headers
     */
    private static function resolveClientIp(array $headers): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        $forwarded = trim(explode(',', $headers['x-forwarded-for'] ?? '')[0]);

        if ($forwarded === '' || filter_var($forwarded, FILTER_VALIDATE_IP) === false) {
            return $remote;
        }

        // A signed forwarding proof is accepted from anywhere, because only a
        // holder of the internal key can produce one. This is how the web
        // client hands over the browser's real address without the gateway
        // having to trust its own published port.
        $proof = $headers[strtolower(\Dayflow\Kernel\Security\ForwardedFor::HEADER_PROOF)] ?? '';

        if ($proof !== ''
            && \Dayflow\Kernel\Security\ForwardedFor::available()
            && \Dayflow\Kernel\Security\ForwardedFor::verify($forwarded, $proof)
        ) {
            return $forwarded;
        }

        // Otherwise fall back to the classic arrangement: believe the header
        // only when the peer that sent it is a configured proxy.
        $trusted = \Dayflow\Kernel\Support\Env::list('TRUSTED_PROXIES', []);

        if ($trusted !== [] && self::isTrustedPeer($remote, $trusted)) {
            return $forwarded;
        }

        return $remote;
    }

    /** @param list<string> $trusted Addresses or CIDR ranges. */
    private static function isTrustedPeer(string $remote, array $trusted): bool
    {
        foreach ($trusted as $entry) {
            if ($entry === '*' || $entry === $remote) {
                return true;
            }

            if (str_contains($entry, '/') && self::inCidr($remote, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
