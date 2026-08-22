<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Support;

/**
 * Structured JSON logger.
 *
 * One JSON object per line means the logs can be shipped straight into a log
 * aggregator without any parsing rules. Sensitive keys are redacted centrally,
 * so a careless call site can never leak a password or a token.
 */
final class Logger
{
    private const REDACT = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'access_token', 'refresh_token', 'authorization', 'secret',
        'api_key', 'bank_account_number', 'tax_identifier', 'signature',
    ];

    private static ?self $instance = null;

    private function __construct(
        private readonly string $service,
        private readonly string $path,
    ) {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            $service = Env::get('SERVICE_NAME', 'dayflow');
            $directory = rtrim(Env::get('LOG_PATH', '/var/log/dayflow'), '/');

            if (!is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            self::$instance = new self($service, $directory . '/' . $service . '.log');
        }

        return self::$instance;
    }

    public static function debug(string $message, array $context = []): void
    {
        if (Env::isDebug()) {
            self::instance()->write('debug', $message, $context);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::instance()->write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::instance()->write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::instance()->write('error', $message, $context);
    }

    public static function exception(\Throwable $exception, array $context = []): void
    {
        self::instance()->write('error', $exception->getMessage(), $context + [
            'exception' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => Env::isDebug() ? $exception->getTraceAsString() : '(hidden)',
        ]);
    }

    private function write(string $level, string $message, array $context): void
    {
        $entry = [
            'timestamp' => Clock::iso(),
            'level' => $level,
            'service' => $this->service,
            'message' => $message,
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
            'context' => self::redact($context),
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
        error_log(rtrim($line));
    }

    /** Replaces the value of any key that looks like a credential. */
    private static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::redact($value);
                continue;
            }

            if (in_array(strtolower((string) $key), self::REDACT, true)) {
                $context[$key] = '[redacted]';
            }
        }

        return $context;
    }
}
