<?php

declare(strict_types=1);

namespace App\Core;

use Dayflow\Kernel\Support\Env;

/**
 * Base class for every page controller.
 *
 * Holds the small set of operations a controller performs that are not
 * rendering: reading input, redirecting, and turning an API error into
 * something a person can act on.
 */
abstract class Controller
{
    /** Reads a submitted field, trimmed. */
    protected function input(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    protected function inputInt(string $key, int $default = 0): int
    {
        $value = $this->input($key, (string) $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    protected function inputBool(string $key): bool
    {
        return filter_var($_POST[$key] ?? $_GET[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return list<string> */
    protected function inputArray(string $key): array
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $value
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * Collects several fields at once, dropping the ones not submitted.
     *
     * @return array<string, string>
     */
    protected function collect(string ...$keys): array
    {
        $data = [];

        foreach ($keys as $key) {
            if (isset($_POST[$key]) || isset($_GET[$key])) {
                $data[$key] = $this->input($key);
            }
        }

        return $data;
    }

    /** The current page number for a paginated list. */
    protected function page(): int
    {
        return max(1, $this->inputInt('page', 1));
    }

    /**
     * Sends the browser somewhere else.
     *
     * The destination is forced to be a path within this application. An
     * attacker-supplied absolute URL reaching a Location header would be an
     * open redirect: a link that appears to be to this site but lands on
     * theirs, which is how a convincing credential-phishing page gets opened.
     */
    protected function redirect(string $path): never
    {
        $safe = str_starts_with($path, '/') && !str_starts_with($path, '//') ? $path : '/';

        header('Location: ' . $safe, true, 302);
        exit;
    }

    /** Returns to the page the form was submitted from. */
    protected function back(string $fallback = '/'): never
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        if ($referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);
            $query = parse_url($referer, PHP_URL_QUERY);
            $host = parse_url($referer, PHP_URL_HOST);
            $ownHost = parse_url(Env::get('APP_URL', 'http://localhost:8000'), PHP_URL_HOST);

            // Only follow the referrer when it is genuinely one of our pages.
            if (is_string($path) && $path !== '' && ($host === null || $host === $ownHost)) {
                $this->redirect($path . ($query ? '?' . $query : ''));
            }
        }

        $this->redirect($fallback);
    }

    /**
     * Redisplays a form with the submitted values and the server's complaints.
     *
     * @param array{error: ?array} $response An envelope returned by Api.
     */
    protected function backWithErrors(array $response, array $input, string $fallback = '/'): never
    {
        $error = $response['error'] ?? null;
        $message = is_array($error) ? (string) ($error['message'] ?? 'That could not be saved.') : 'That could not be saved.';
        $details = is_array($error) && is_array($error['details'] ?? null) ? $error['details'] : [];

        Flash::error($message);
        Flash::withInput($input);

        if ($details !== []) {
            Flash::withErrors($details);
        }

        $this->back($fallback);
    }

    /**
     * Renders a page, or a friendly failure if the API call behind it failed.
     *
     * @param array{ok: bool, status: int, error: ?array} $response
     */
    protected function guard(array $response, string $fallback = '/'): void
    {
        if ($response['ok']) {
            return;
        }

        if ($response['status'] === 401) {
            Session::destroy();
            Flash::info('Please sign in to continue.');
            $this->redirect('/login');
        }

        if ($response['status'] === 403) {
            http_response_code(403);
            View::render('errors/403', ['pageTitle' => 'Access denied']);
            exit;
        }

        if ($response['status'] === 404) {
            http_response_code(404);
            View::render('errors/404', ['pageTitle' => 'Not found']);
            exit;
        }

        // Anything left is a fault rather than a decision about this person:
        // the service is busy, throttled, or broken. Bouncing them to the
        // dashboard would hide which page failed and immediately fire another
        // round of calls at the thing that just gave way, so the failure is
        // shown in place instead.
        http_response_code($response['status'] >= 400 && $response['status'] < 600 ? $response['status'] : 503);

        View::render('errors/unavailable', [
            'pageTitle' => $response['status'] === 429 ? 'Too many requests' : 'Temporarily unavailable',
            'status' => $response['status'],
            'message' => (string) (($response['error']['message']) ?? 'That could not be loaded.'),
        ]);

        exit;
    }
}
