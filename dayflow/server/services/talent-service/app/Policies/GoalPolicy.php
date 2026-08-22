<?php

declare(strict_types=1);

namespace App\Policies;

use App\Services\EmployeeDirectory;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;

/**
 * Who may look at, set and change a goal.
 *
 * A permission answers "may this role work with goals at all". These methods
 * answer the question that actually protects the data: whose goal is it.
 */
final class GoalPolicy
{
    public const SCOPE_OWNER = 'owner';
    public const SCOPE_MANAGER = 'manager';
    public const SCOPE_ADMINISTRATIVE = 'administrative';

    /**
     * Terms an employee cannot move on their own once a goal is live.
     *
     * The weighting and the target are the bargain struck with the manager. If
     * an employee could halve their target or re-weight the cycle mid-flight,
     * the appraisal built on those numbers would mean nothing.
     */
    public const MANAGER_ONLY_FIELDS = ['weight_percent', 'target_value'];

    public static function assertMayView(Principal $principal, string $employeeId, EmployeeDirectory $directory): string
    {
        if ($principal->owns($employeeId)) {
            return self::SCOPE_OWNER;
        }

        if ($principal->can(Permissions::TALENT_VIEW_ALL)) {
            return self::SCOPE_ADMINISTRATIVE;
        }

        if ($principal->can(Permissions::TALENT_VIEW_TEAM)
            && $directory->isDirectReport($principal->employeeId, $employeeId)) {
            return self::SCOPE_MANAGER;
        }

        throw HttpException::forbidden('You may only view your own goals.');
    }

    /**
     * Who is allowed to change this goal, and in what capacity.
     *
     * Viewing everybody's goals is an HR reporting need; rewriting them is
     * not, so TALENT_VIEW_ALL deliberately grants nothing here.
     *
     * @param array<string, mixed> $goal
     */
    public static function assertMayEdit(Principal $principal, array $goal, EmployeeDirectory $directory): string
    {
        $owner = (string) ($goal['employee_id'] ?? '');

        if ($principal->owns($owner)) {
            return self::SCOPE_OWNER;
        }

        if ($principal->can(Permissions::TALENT_REVIEW_TEAM)
            && $directory->isDirectReport($principal->employeeId, $owner)) {
            return self::SCOPE_MANAGER;
        }

        throw HttpException::forbidden('You may only change your own goals or those of your direct reports.');
    }

    /**
     * Stops an employee editing the terms of a goal that is already running.
     *
     * The request is refused outright rather than having the offending fields
     * quietly dropped, so the caller knows the change did not happen.
     *
     * @param array<string, mixed> $goal
     * @param array<string, mixed> $changes
     */
    public static function assertMayChangeTerms(string $scope, array $goal, array $changes): void
    {
        if ($scope !== self::SCOPE_OWNER) {
            return;
        }

        if (($goal['status'] ?? 'draft') === 'draft') {
            return;
        }

        $attempted = array_values(array_filter(
            self::MANAGER_ONLY_FIELDS,
            static fn (string $field): bool => array_key_exists($field, $changes)
        ));

        if ($attempted === []) {
            return;
        }

        throw HttpException::forbidden(sprintf(
            'The %s of a goal that is already under way can only be changed by your manager.',
            implode(' and ', array_map(
                static fn (string $field): string => str_replace('_', ' ', $field),
                $attempted
            ))
        ));
    }
}
