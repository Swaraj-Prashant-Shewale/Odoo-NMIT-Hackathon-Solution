<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Security;

/**
 * The platform's role catalogue and the permissions each role carries.
 *
 * Authorisation is always checked against a named permission rather than a
 * role string, so a rule such as "may approve leave" is written once and stays
 * correct as the role list evolves.
 *
 * Inheritance is declared explicitly rather than derived from seniority order.
 * A purely positional model would silently hand a specialist role (Finance)
 * every permission of the roles listed beneath it, which is exactly the kind of
 * quiet privilege creep an access-control model should prevent.
 */
final class Roles
{
    public const SUPER_ADMIN = 'super_admin';
    public const HR_ADMIN = 'hr_admin';
    public const HR_OFFICER = 'hr_officer';
    public const MANAGER = 'manager';
    public const FINANCE = 'finance';
    public const EMPLOYEE = 'employee';

    /** Seniority order, most senior first. Used for display and for outranks(). */
    public const HIERARCHY = [
        self::SUPER_ADMIN,
        self::HR_ADMIN,
        self::HR_OFFICER,
        self::FINANCE,
        self::MANAGER,
        self::EMPLOYEE,
    ];

    public const LABELS = [
        self::SUPER_ADMIN => 'System Administrator',
        self::HR_ADMIN => 'HR Administrator',
        self::HR_OFFICER => 'HR Officer',
        self::FINANCE => 'Finance Officer',
        self::MANAGER => 'People Manager',
        self::EMPLOYEE => 'Employee',
    ];

    public const DESCRIPTIONS = [
        self::SUPER_ADMIN => 'Full control of the platform, including settings, roles and the audit trail.',
        self::HR_ADMIN => 'Owns organisation structure, leave policies, shift patterns and the learning catalogue.',
        self::HR_OFFICER => 'Day-to-day HR operations: people records, attendance, leave approvals and onboarding.',
        self::FINANCE => 'Salary structures, payroll runs and expense reimbursement.',
        self::MANAGER => 'Approves leave, attendance corrections and expenses for direct reports.',
        self::EMPLOYEE => 'Self-service access to their own profile, attendance, leave, payslips and training.',
    ];

    /**
     * Which roles each role inherits from.
     *
     * @var array<string, list<string>>
     */
    private const INHERITS = [
        self::EMPLOYEE => [],
        self::MANAGER => [self::EMPLOYEE],
        self::FINANCE => [self::EMPLOYEE],
        self::HR_OFFICER => [self::EMPLOYEE, self::MANAGER],
        self::HR_ADMIN => [self::HR_OFFICER],
        self::SUPER_ADMIN => [self::HR_ADMIN, self::FINANCE],
    ];

    /**
     * Permissions granted directly to each role, before inheritance.
     *
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        self::EMPLOYEE => [
            Permissions::PROFILE_VIEW_SELF,
            Permissions::PROFILE_EDIT_SELF,
            Permissions::ATTENDANCE_VIEW_SELF,
            Permissions::ATTENDANCE_PUNCH,
            Permissions::ATTENDANCE_REQUEST_REGULARISATION,
            Permissions::LEAVE_VIEW_SELF,
            Permissions::LEAVE_APPLY,
            Permissions::LEAVE_CANCEL_SELF,
            Permissions::PAYROLL_VIEW_SELF,
            Permissions::EXPENSE_SUBMIT,
            Permissions::EXPENSE_VIEW_SELF,
            Permissions::LEARNING_VIEW_CATALOGUE,
            Permissions::LEARNING_ENROL_SELF,
            Permissions::TALENT_VIEW_SELF,
            Permissions::TALENT_UPDATE_OWN_GOALS,
            Permissions::DOCUMENT_VIEW_SELF,
            Permissions::DOCUMENT_UPLOAD_SELF,
            Permissions::NOTIFICATION_VIEW_SELF,
            Permissions::DIRECTORY_VIEW,
        ],
        self::MANAGER => [
            Permissions::PROFILE_VIEW_TEAM,
            Permissions::ATTENDANCE_VIEW_TEAM,
            Permissions::ATTENDANCE_APPROVE_REGULARISATION,
            Permissions::LEAVE_VIEW_TEAM,
            Permissions::LEAVE_APPROVE,
            Permissions::EXPENSE_APPROVE,
            Permissions::TALENT_VIEW_TEAM,
            Permissions::TALENT_REVIEW_TEAM,
            Permissions::LEARNING_ASSIGN_TEAM,
            Permissions::REPORT_VIEW_TEAM,
        ],
        self::FINANCE => [
            Permissions::PROFILE_VIEW_ALL,
            Permissions::PAYROLL_VIEW_ALL,
            Permissions::PAYROLL_RUN,
            Permissions::PAYROLL_EDIT_STRUCTURE,
            Permissions::EXPENSE_VIEW_ALL,
            Permissions::EXPENSE_REIMBURSE,
            Permissions::REPORT_VIEW_ALL,
            Permissions::REPORT_EXPORT,
        ],
        self::HR_OFFICER => [
            Permissions::PROFILE_VIEW_ALL,
            Permissions::PROFILE_EDIT_ALL,
            Permissions::EMPLOYEE_CREATE,
            Permissions::ATTENDANCE_VIEW_ALL,
            Permissions::ATTENDANCE_EDIT,
            Permissions::LEAVE_VIEW_ALL,
            Permissions::LEAVE_ADJUST_BALANCE,
            Permissions::ONBOARDING_MANAGE,
            Permissions::DOCUMENT_VIEW_ALL,
            Permissions::DOCUMENT_MANAGE,
            Permissions::LEARNING_ASSIGN_ANY,
            Permissions::TALENT_VIEW_ALL,
            Permissions::ANNOUNCEMENT_PUBLISH,
            Permissions::REPORT_VIEW_ALL,
            Permissions::REPORT_EXPORT,
        ],
        self::HR_ADMIN => [
            Permissions::EMPLOYEE_DEACTIVATE,
            Permissions::ORG_MANAGE,
            Permissions::LEAVE_MANAGE_POLICY,
            Permissions::ATTENDANCE_MANAGE_SHIFTS,
            Permissions::ATTENDANCE_MANAGE_HOLIDAYS,
            Permissions::LEARNING_MANAGE_CATALOGUE,
            Permissions::TALENT_MANAGE_CYCLES,
            Permissions::USER_MANAGE_ROLES,
            Permissions::AUDIT_VIEW,
        ],
        self::SUPER_ADMIN => [
            Permissions::SYSTEM_SETTINGS,
            Permissions::USER_MANAGE_ALL,
            Permissions::AUDIT_VIEW,
            Permissions::AUDIT_EXPORT,
        ],
    ];

    public static function isValid(string $role): bool
    {
        return in_array($role, self::HIERARCHY, true);
    }

    public static function label(string $role): string
    {
        return self::LABELS[$role] ?? ucwords(str_replace('_', ' ', $role));
    }

    public static function description(string $role): string
    {
        return self::DESCRIPTIONS[$role] ?? '';
    }

    /**
     * Roles a visitor may choose while registering.
     *
     * Elevated roles are deliberately absent: they can only ever be granted by
     * an existing administrator, so nobody can sign themselves up as an admin.
     */
    public static function selfServiceRoles(): array
    {
        return [self::EMPLOYEE, self::HR_OFFICER];
    }

    /**
     * Every permission a role holds once inheritance is resolved.
     *
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        if (!self::isValid($role)) {
            return [];
        }

        return array_values(array_unique(self::resolve($role, [])));
    }

    /**
     * The union of permissions across all of a user's roles.
     *
     * @param list<string> $roles
     * @return list<string>
     */
    public static function permissionsForAll(array $roles): array
    {
        $permissions = [];
        foreach ($roles as $role) {
            $permissions = array_merge($permissions, self::permissionsFor((string) $role));
        }

        return array_values(array_unique($permissions));
    }

    /** True when $role sits at or above $other in the hierarchy. */
    public static function outranks(string $role, string $other): bool
    {
        $a = array_search($role, self::HIERARCHY, true);
        $b = array_search($other, self::HIERARCHY, true);

        if ($a === false || $b === false) {
            return false;
        }

        return $a <= $b;
    }

    /** The most senior role in a list, used to label a user in the interface. */
    public static function primary(array $roles): string
    {
        foreach (self::HIERARCHY as $candidate) {
            if (in_array($candidate, $roles, true)) {
                return $candidate;
            }
        }

        return self::EMPLOYEE;
    }

    /** Roles treated as HR or administrative staff in the interface. */
    public static function administrative(): array
    {
        return [self::SUPER_ADMIN, self::HR_ADMIN, self::HR_OFFICER];
    }

    /**
     * Full role-to-permission matrix, rendered on the admin settings screen.
     *
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        $matrix = [];
        foreach (self::HIERARCHY as $role) {
            $matrix[$role] = self::permissionsFor($role);
        }

        return $matrix;
    }

    /**
     * Walks the inheritance graph, guarding against a cyclic declaration.
     *
     * @param list<string> $seen
     * @return list<string>
     */
    private static function resolve(string $role, array $seen): array
    {
        if (in_array($role, $seen, true)) {
            return [];
        }

        $seen[] = $role;
        $permissions = self::GRANTS[$role] ?? [];

        foreach (self::INHERITS[$role] ?? [] as $parent) {
            $permissions = array_merge($permissions, self::resolve($parent, $seen));
        }

        return $permissions;
    }
}
