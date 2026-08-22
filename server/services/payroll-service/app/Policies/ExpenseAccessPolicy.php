<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;

/** Visibility and decision rules for expense claims. */
final class ExpenseAccessPolicy
{
    private function __construct()
    {
    }

    public static function seesEveryone(Principal $principal): bool
    {
        return $principal->can(Permissions::EXPENSE_VIEW_ALL);
    }

    /** @param array<string, mixed> $claim */
    public static function assertMayView(Principal $principal, array $claim): void
    {
        if (self::seesEveryone($principal)) {
            return;
        }

        if ($principal->owns($claim['employee_id'] ?? null)) {
            return;
        }

        // The person the claim was routed to has to be able to read it before
        // they can sensibly approve or reject it.
        if ($principal->can(Permissions::EXPENSE_APPROVE) && $principal->owns($claim['approver_id'] ?? null)) {
            return;
        }

        throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $claim
     *
     * @throws HttpException 403 when the caller may not rule on this claim.
     */
    public static function assertMayDecide(Principal $principal, array $claim): void
    {
        // Nobody signs off their own spending, whatever permissions they hold.
        if ($principal->owns($claim['employee_id'] ?? null)) {
            throw HttpException::forbidden('You cannot decide your own expense claim.');
        }

        if (self::seesEveryone($principal)) {
            return;
        }

        if (!$principal->owns($claim['approver_id'] ?? null)) {
            throw HttpException::forbidden('This claim was routed to a different approver.');
        }
    }
}
