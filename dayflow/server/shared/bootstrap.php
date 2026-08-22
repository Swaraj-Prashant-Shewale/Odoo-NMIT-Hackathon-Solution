<?php

declare(strict_types=1);

/**
 * Shared bootstrap for every backend process.
 *
 * Registers a PSR-4 style autoloader for the kernel and for the calling
 * service's own application namespace, then applies the runtime hardening that
 * should be identical everywhere.
 *
 * A service front controller uses it like this:
 *
 *   require __DIR__ . '/../../shared/bootstrap.php';
 *   dayflow_autoload_app('App', __DIR__ . '/../app');
 */

// ---------------------------------------------------------------------------
// Runtime hardening
// ---------------------------------------------------------------------------

// Errors are logged, never rendered: an error page that leaks a file path or a
// query is a genuine information disclosure.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// The PHP version is not advertised in response headers.
if (function_exists('header_remove')) {
    header_remove('X-Powered-By');
}
ini_set('expose_php', '0');

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

// ---------------------------------------------------------------------------
// Autoloading
// ---------------------------------------------------------------------------

/**
 * Maps a namespace prefix onto a directory.
 *
 * The resolved path is checked against the registered base directory so a
 * crafted class name containing traversal sequences cannot reach outside it.
 */
function dayflow_autoload_app(string $prefix, string $baseDirectory): void
{
    $prefix = rtrim($prefix, '\\') . '\\';
    $baseDirectory = rtrim(str_replace('\\', '/', $baseDirectory), '/');
    $realBase = realpath($baseDirectory);

    if ($realBase === false) {
        return;
    }

    $realBase = str_replace('\\', '/', $realBase);

    spl_autoload_register(static function (string $class) use ($prefix, $realBase): void {
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));

        // Class names are restricted to word characters and separators.
        if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
            return;
        }

        $candidate = $realBase . '/' . str_replace('\\', '/', $relative) . '.php';
        $resolved = realpath($candidate);

        if ($resolved === false) {
            return;
        }

        $resolved = str_replace('\\', '/', $resolved);

        if (!str_starts_with($resolved, $realBase . '/')) {
            return;
        }

        require $resolved;
    });
}

dayflow_autoload_app('Dayflow\\Kernel', __DIR__ . '/src');

// ---------------------------------------------------------------------------
// Fatal error safety net
// ---------------------------------------------------------------------------

/**
 * Guarantees a JSON response even for a fatal error.
 *
 * Without this a memory exhaustion or parse failure would return an empty body
 * with a 200 status, which a client cannot distinguish from success.
 */
register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    if (headers_sent()) {
        return;
    }

    error_log(sprintf('Fatal: %s in %s:%d', $error['message'], $error['file'], $error['line']));

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'error' => [
            'code' => 'internal_error',
            'message' => 'The service encountered an unrecoverable error.',
        ],
    ]);
});
