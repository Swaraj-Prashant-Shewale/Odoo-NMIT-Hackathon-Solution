<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Http;

/**
 * An error that maps directly onto an HTTP status code.
 *
 * Throwing one of these anywhere in a service produces a clean, predictable
 * API error instead of a stack trace, so no controller needs its own
 * try/catch boilerplate.
 */
class HttpException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        private readonly int $status,
        string $message,
        private readonly string $errorCode = '',
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message, array $details = []): self
    {
        return new self(400, $message, 'bad_request', $details);
    }

    public static function unauthorized(string $message = 'Authentication is required.'): self
    {
        return new self(401, $message, 'unauthenticated');
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): self
    {
        return new self(403, $message, 'forbidden');
    }

    public static function notFound(string $message = 'The requested record does not exist.'): self
    {
        return new self(404, $message, 'not_found');
    }

    public static function conflict(string $message, array $details = []): self
    {
        return new self(409, $message, 'conflict', $details);
    }

    public static function unprocessable(string $message, array $details = []): self
    {
        return new self(422, $message, 'validation_failed', $details);
    }

    public static function tooManyRequests(string $message, int $retryAfter = 60): self
    {
        return new self(429, $message, 'rate_limited', ['retry_after' => $retryAfter]);
    }

    public static function serviceUnavailable(string $message = 'The service is temporarily unavailable.'): self
    {
        return new self(503, $message, 'service_unavailable');
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode !== '' ? $this->errorCode : 'error';
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }

    public function toResponse(): Response
    {
        $response = Response::error($this->status, $this->errorCode(), $this->getMessage(), $this->details);

        if ($this->status === 429 && isset($this->details['retry_after'])) {
            return $response->withHeaders(['Retry-After' => (string) $this->details['retry_after']]);
        }

        return $response;
    }
}
