<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Http;

/**
 * Fluent handle returned when a route is registered.
 *
 * Usage:
 *   $router->get('/leave/requests', $handler)->requires(Permissions::LEAVE_VIEW_ALL);
 *   $router->post('/auth/login', $handler)->allowPublic();
 */
final class RouteDefinition
{
    /** @param \Closure(?string, bool): void $apply */
    public function __construct(private readonly \Closure $apply)
    {
    }

    /** Requires an authenticated principal holding this permission. */
    public function requires(string $permission): self
    {
        ($this->apply)($permission, false);

        return $this;
    }

    /** Requires authentication but performs its own finer-grained checks. */
    public function authenticated(): self
    {
        ($this->apply)(null, false);

        return $this;
    }

    /** Reachable without a token. Reserved for sign-in, sign-up and health. */
    public function allowPublic(): self
    {
        ($this->apply)(null, true);

        return $this;
    }
}
