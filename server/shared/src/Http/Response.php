<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Http;

/**
 * A JSON API response.
 *
 * Every service answers with the same envelope, so the web client and any
 * future mobile client can handle success and failure identically:
 *
 *   { "data": ..., "meta": {...} }
 *   { "error": { "code": "...", "message": "...", "details": {...} } }
 */
final class Response
{
    /** @param array<string, string> $headers */
    private function __construct(
        public readonly int $status,
        public readonly mixed $payload,
        public readonly array $headers = [],
    ) {
    }

    public static function ok(mixed $data = null, array $meta = []): self
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new self(200, $payload);
    }

    public static function created(mixed $data = null): self
    {
        return new self(201, ['data' => $data]);
    }

    public static function accepted(mixed $data = null): self
    {
        return new self(202, ['data' => $data]);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    /**
     * Returns a paginated collection produced by Repository::paginate().
     *
     * @param array{data: array, meta: array} $page
     */
    public static function page(array $page): self
    {
        return new self(200, [
            'data' => $page['data'] ?? [],
            'meta' => $page['meta'] ?? [],
        ]);
    }

    /** @param array<string, mixed> $details */
    public static function error(int $status, string $code, string $message, array $details = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return new self($status, ['error' => $error]);
    }

    /** Streams a file (payslip PDF, report export) back to the caller. */
    public static function download(string $contents, string $filename, string $contentType): self
    {
        // The filename is quoted and stripped of anything that could break out
        // of the header or inject a second header line.
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'download';

        return new self(200, $contents, [
            'Content-Type' => $contentType,
            'Content-Disposition' => sprintf('attachment; filename="%s"', $safe),
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Builds a response from an already-shaped payload.
     *
     * Used by the API gateway, which relays a service's envelope verbatim
     * rather than re-wrapping it.
     *
     * @param array<string, string> $headers
     */
    public static function raw(int $status, mixed $payload, array $headers = []): self
    {
        return new self($status, $payload, $headers);
    }

    /**
     * Relays a non-JSON body, such as a generated PDF, unchanged.
     *
     * @param array<string, string> $headers
     */
    public static function binary(int $status, string $body, array $headers): self
    {
        return new self($status, $body, $headers + ['Content-Type' => 'application/octet-stream']);
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $this->payload, $this->headers + $headers);
    }

    /** Writes the response to the output buffer. */
    public function send(): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($this->status);

        $isRaw = isset($this->headers['Content-Type'])
            && !str_contains($this->headers['Content-Type'], 'application/json');

        if (!$isRaw) {
            header('Content-Type: application/json; charset=utf-8');
        }

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        foreach (self::securityHeaders() as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->status === 204 || $this->payload === null) {
            return;
        }

        if ($isRaw) {
            echo $this->payload;

            return;
        }

        echo json_encode(
            $this->payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * Headers applied to every API response.
     *
     * An API returns data, never markup, so the policy simply forbids the
     * browser from treating any of it as a document or executing it.
     *
     * @return array<string, string>
     */
    private static function securityHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            'Permissions-Policy' => 'geolocation=(), camera=(), microphone=()',
        ];
    }
}
