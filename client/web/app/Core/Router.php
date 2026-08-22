<?php

declare(strict_types=1);

namespace App\Core;

use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;

/**
 * The web client's router.
 *
 * Two guarantees are enforced here rather than being left to each controller,
 * because a route that forgets either one is a security hole:
 *
 *   - a page marked as requiring sign-in cannot be reached without a session;
 *   - every POST, PUT, PATCH and DELETE must carry a valid CSRF token.
 *
 * Both are opt-out rather than opt-in, so the unsafe case has to be written
 * deliberately and is visible in review.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, parameters: list<string>, handler: array, guest: bool, open: bool, permission: ?string}> */
    private array $routes = [];

    public function get(string $pattern, array $handler): RouteOptions
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, array $handler): RouteOptions
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = '/' . trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
        $path = $path === '//' ? '/' : $path;

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method || preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $parameters = [];
            foreach ($route['parameters'] as $name) {
                $parameters[$name] = $matches[$name] ?? '';
            }

            $this->run($route, $parameters);

            return;
        }

        $this->notFound();
    }

    /**
     * @param array{method: string, handler: array, guest: bool, open: bool, permission: ?string} $route
     * @param array<string, string> $parameters
     */
    private function run(array $route, array $parameters): void
    {
        // A signed-in user landing on the sign-in page goes to their dashboard.
        if ($route['guest'] && Session::isAuthenticated()) {
            $this->redirect('/');

            return;
        }

        if (!$route['guest'] && !$route['open'] && !Session::isAuthenticated()) {
            // Remember where they were headed so sign-in can return them there.
            if ($route['method'] === 'GET') {
                Session::put('_intended_url', $_SERVER['REQUEST_URI'] ?? '/');
            }

            Flash::info('Please sign in to continue.');
            $this->redirect('/login');

            return;
        }

        if ($route['method'] !== 'GET' && !Csrf::verify($_POST[Csrf::FIELD] ?? null)) {
            Logger::warning('Rejected request with an invalid CSRF token', [
                'path' => $_SERVER['REQUEST_URI'] ?? '',
                'user_id' => Session::userId(),
            ]);

            Flash::error('Your session expired while that form was open. Please try again.');

            // Back to the page that was submitted, so the person sees the form
            // again with the explanation. The Referer header is deliberately
            // not used: it is supplied by the client, and an off-site value
            // would simply be discarded by redirect() and land them on the
            // dashboard with no idea what happened.
            $this->redirect((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));

            return;
        }

        if ($route['permission'] !== null && !Session::can($route['permission'])) {
            $this->forbidden();

            return;
        }

        [$class, $action] = $route['handler'];

        try {
            (new $class())->{$action}($parameters);
        } catch (\Throwable $exception) {
            Logger::exception($exception, ['path' => $_SERVER['REQUEST_URI'] ?? '']);

            http_response_code(500);
            View::render('errors/500', [
                'pageTitle' => 'Something went wrong',
                'detail' => Env::isDebug() ? $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine() : null,
            ]);
        }
    }

    private function add(string $method, string $pattern, array $handler): RouteOptions
    {
        $normalised = '/' . trim($pattern, '/');

        [$regex, $parameters] = self::compile($normalised);

        $index = count($this->routes);

        $this->routes[] = [
            'method' => $method,
            'regex' => $regex,
            'parameters' => $parameters,
            'handler' => $handler,
            'guest' => false,
            'open' => false,
            'permission' => null,
        ];

        return new RouteOptions(function (bool $guest, ?string $permission, bool $open) use ($index): void {
            $this->routes[$index]['guest'] = $guest;
            $this->routes[$index]['permission'] = $permission;
            $this->routes[$index]['open'] = $open;
        });
    }

    /**
     * Turns "/people/{id}/edit" into a named-capture regular expression.
     *
     * The pattern is split on its placeholders so literal segments go through
     * preg_quote while placeholders become capture groups. Building it by
     * string replacement instead would leave the "/" inside the generated
     * [^/] character class unescaped, and PHP scans for the closing delimiter
     * without regard for character classes — the expression would be cut short
     * and rejected outright.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function compile(string $pattern): array
    {
        $parameters = [];
        $regex = '';

        $segments = preg_split(
            '/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/',
            $pattern,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        ) ?: [];

        foreach ($segments as $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $matches) === 1) {
                $parameters[] = $matches[1];
                $regex .= '(?P<' . $matches[1] . '>[^/]+)';

                continue;
            }

            $regex .= preg_quote($segment, '~');
        }

        return ['~^' . $regex . '$~', $parameters];
    }

    private function redirect(string $to): void
    {
        // Only ever redirect within this application. An absolute URL from a
        // header or a query string would be an open redirect, which is the
        // usual first step of a convincing phishing link.
        $safe = str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/';

        header('Location: ' . $safe, true, 302);
        exit;
    }

    private function notFound(): void
    {
        http_response_code(404);

        if (Session::isAuthenticated()) {
            View::render('errors/404', ['pageTitle' => 'Page not found']);
        } else {
            View::renderAuth('errors/404', ['pageTitle' => 'Page not found']);
        }
    }

    private function forbidden(): void
    {
        http_response_code(403);
        View::render('errors/403', ['pageTitle' => 'Access denied']);
    }
}
