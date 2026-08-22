<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Http;

/**
 * Route table for a service.
 *
 * Routes declare their own authorisation requirement at the point of
 * registration, so no endpoint can be added without a conscious decision about
 * who may call it. Anything not explicitly marked public requires a verified
 * principal: access is closed by default rather than open by default.
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, parameters: list<string>, handler: callable, permission: ?string, public: bool}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): RouteDefinition
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): RouteDefinition
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): RouteDefinition
    {
        return $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): RouteDefinition
    {
        return $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): RouteDefinition
    {
        return $this->add('DELETE', $pattern, $handler);
    }

    /**
     * Finds the route matching a request.
     *
     * @return array{route: array, parameters: array<string, string>}
     * @throws HttpException 404 when no path matches, 405 when only the verb is wrong.
     */
    public function match(string $method, string $path): array
    {
        $pathMatched = false;
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $pathMatched = true;
            $allowed[] = $route['method'];

            if ($route['method'] !== $method) {
                continue;
            }

            $parameters = [];
            foreach ($route['parameters'] as $name) {
                $parameters[$name] = $matches[$name] ?? '';
            }

            return ['route' => $route, 'parameters' => $parameters];
        }

        if ($pathMatched) {
            throw new HttpException(
                405,
                sprintf('The %s method is not supported for this endpoint.', $method),
                'method_not_allowed',
                ['allowed' => array_values(array_unique($allowed))]
            );
        }

        throw HttpException::notFound('No endpoint matches this address.');
    }

    /** @return list<array{method: string, path: string, permission: ?string, public: bool}> */
    public function inventory(): array
    {
        return array_map(
            static fn (array $route): array => [
                'method' => $route['method'],
                'path' => $route['pattern'],
                'permission' => $route['permission'],
                'public' => $route['public'],
            ],
            $this->routes
        );
    }

    private function add(string $method, string $pattern, callable $handler): RouteDefinition
    {
        $normalised = '/' . trim($pattern, '/');

        [$regex, $parameters] = self::compile($normalised);

        $index = count($this->routes);

        $this->routes[] = [
            'method' => $method,
            'pattern' => $normalised,
            'regex' => $regex,
            'parameters' => $parameters,
            'handler' => $handler,
            'permission' => null,
            'public' => false,
        ];

        return new RouteDefinition(
            function (?string $permission, bool $public) use ($index): void {
                $this->routes[$index]['permission'] = $permission;
                $this->routes[$index]['public'] = $public;
            }
        );
    }

    /**
     * Turns "/employees/{id}" into a named-capture regular expression.
     *
     * The pattern is split on its placeholders so that literal segments can be
     * passed through preg_quote while the placeholders become capture groups.
     * Building the expression by string replacement instead would leave the
     * "/" inside the generated [^/] character class unescaped, and PHP scans
     * for the closing delimiter without regard for character classes — the
     * expression would be cut short and rejected.
     *
     * "~" is used as the delimiter for the same reason: paths are full of
     * slashes, and not having to escape them keeps the result readable.
     *
     * @return array{0: string, 1: list<string>} The expression and its parameter names.
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
}
