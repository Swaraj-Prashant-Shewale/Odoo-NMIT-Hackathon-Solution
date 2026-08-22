<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApprovalDelegations;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Support\Clock;

/**
 * Decides who may act on a request that is waiting for a signature.
 *
 * Holding leave.approve makes someone an approver in general; it does not make
 * them the approver of any particular request. Three answers count: the person
 * the request was routed to, someone actively standing in for them, or HR.
 */
final class ApprovalPolicy
{
    public function __construct(private readonly ApprovalDelegations $delegations)
    {
    }

    /** @param array<string, mixed> $request */
    public function mayDecide(Principal $principal, array $request): bool
    {
        if ($principal->employeeId === null || $principal->employeeId === '') {
            return false;
        }

        // Deciding your own absence is never allowed, whatever else the caller
        // holds. Checked here as well as in the request service so no future
        // caller of this policy can miss it.
        if ($principal->owns((string) ($request['employee_id'] ?? ''))) {
            return false;
        }

        $approverId = $request['approver_id'] ?? null;

        if (is_string($approverId) && $approverId !== '') {
            if (hash_equals($approverId, $principal->employeeId)) {
                return true;
            }

            if ($this->delegations->isActiveBetween($approverId, $principal->employeeId, Clock::today())) {
                return true;
            }
        }

        // HR picks up anything that was never routed, and can unblock a queue
        // whose owner has left.
        return $principal->can(Permissions::LEAVE_VIEW_ALL);
    }

    /** True when the caller may read or revoke this delegation. */
    public function mayManageDelegation(Principal $principal, array $delegation): bool
    {
        if ($principal->can(Permissions::LEAVE_VIEW_ALL)) {
            return true;
        }

        return $principal->owns((string) ($delegation['delegator_id'] ?? ''))
            || $principal->owns((string) ($delegation['delegate_id'] ?? ''));
    }
}
