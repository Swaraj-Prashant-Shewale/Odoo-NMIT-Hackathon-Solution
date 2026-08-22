<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Support\Env;

/**
 * Guards the development inbox.
 *
 * The outbox holds the rendered body of every message the platform has sent,
 * which includes every verification link and every password-reset link in the
 * company. Reachable in production, that single endpoint is a complete account
 * takeover for anybody who gets to it.
 */
final class MailboxPolicy
{
    private function __construct()
    {
    }

    public static function assertAvailable(Request $request): void
    {
        // Environment first, ahead of any check on the caller. In production
        // this endpoint does not exist, and answering 403 would confirm to
        // everybody that it is there waiting to be reached another way.
        if (Env::isProduction()) {
            throw HttpException::notFound('No endpoint matches this address.');
        }

        if (!$request->principal()->can(Permissions::SYSTEM_SETTINGS)) {
            throw HttpException::forbidden('The development inbox is restricted to platform administrators.');
        }
    }
}
