<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\ServiceClient;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Security\Roles;

/**
 * Decides who an expense claim is routed to.
 *
 * The order is the platform-wide approval routing: the submitter's manager,
 * then HR. The answer is stamped onto the claim at submission time so a later
 * change to the reporting line cannot silently move an open claim out of
 * somebody's queue.
 */
final class ApproverResolver
{
    public function __construct(private readonly ?string $bearerToken)
    {
    }

    /** Returns the approving employee_id, or null when nobody can be resolved. */
    public function forEmployee(Principal $submitter, string $employeeId): ?string
    {
        if ($submitter->owns($employeeId) && $submitter->managerId !== null && $submitter->managerId !== '') {
            return $submitter->managerId;
        }

        $manager = $this->managerOf($employeeId);
        if ($manager !== null && $manager !== $employeeId) {
            return $manager;
        }

        return $this->humanResourcesOfficer();
    }

    private function managerOf(string $employeeId): ?string
    {
        $record = ServiceClient::for('employee', $this->bearerToken)
            ->tryGet('/employees/' . rawurlencode($employeeId));

        if (!is_array($record)) {
            return null;
        }

        $managerId = $record['manager_id'] ?? null;

        return is_string($managerId) && $managerId !== '' ? $managerId : null;
    }

    /**
     * The fallback approver when somebody has no manager.
     *
     * A claim with no approver would sit in nobody's queue forever, so HR
     * picks it up. When even that lookup fails the claim is stored unrouted,
     * and only a holder of expense.view.all can then rule on it — the access
     * policy will not let an ordinary approver decide a claim that was never
     * routed to them.
     */
    private function humanResourcesOfficer(): ?string
    {
        $users = ServiceClient::for('identity', $this->bearerToken)->tryGet('/users', [
            'role' => Roles::HR_OFFICER,
            'per_page' => 1,
        ], []);

        foreach ((array) $users as $user) {
            if (is_array($user) && is_string($user['employee_id'] ?? null) && $user['employee_id'] !== '') {
                return $user['employee_id'];
            }
        }

        return null;
    }
}
