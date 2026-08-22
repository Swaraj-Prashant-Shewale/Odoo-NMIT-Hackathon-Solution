<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fluent handle returned when a client route is registered.
 *
 *   $router->get('/login', [AuthController::class, 'showLogin'])->guest();
 *   $router->get('/payroll', [PayrollController::class, 'index'])->requires(Permissions::PAYROLL_VIEW_ALL);
 *
 * A route with neither call requires a signed-in user and performs its own
 * finer-grained checks, which is the common case.
 */
final class RouteOptions
{
    /** @param \Closure(bool, ?string, bool): void $apply */
    public function __construct(private readonly \Closure $apply)
    {
    }

    /** Reachable only when signed out: sign-in, sign-up, password reset. */
    public function guest(): self
    {
        ($this->apply)(true, null, false);

        return $this;
    }

    /**
     * Reachable either way, signed in or not.
     *
     * Only for routes that carry no information about anybody: the container's
     * own health probe. A page that shows a person anything of theirs is not
     * open, however harmless it looks.
     */
    public function open(): self
    {
        ($this->apply)(false, null, true);

        return $this;
    }

    /**
     * Requires a permission before the page is even rendered.
     *
     * This is a convenience that hides a menu item and returns a clean 403.
     * The API enforces the same rule independently, so removing this check
     * would be a usability regression rather than a way in.
     */
    public function requires(string $permission): self
    {
        ($this->apply)(false, $permission, false);

        return $this;
    }
}
