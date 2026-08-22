<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Security;

/**
 * The complete catalogue of things a user can be permitted to do.
 *
 * Every authorisation check in every service names one of these constants.
 * Keeping them in one file makes the platform's access model reviewable at a
 * glance and prevents typo-driven permission checks that silently pass.
 */
final class Permissions
{
    // Profile and people records
    public const PROFILE_VIEW_SELF = 'profile.view.self';
    public const PROFILE_EDIT_SELF = 'profile.edit.self';
    public const PROFILE_VIEW_TEAM = 'profile.view.team';
    public const PROFILE_VIEW_ALL = 'profile.view.all';
    public const PROFILE_EDIT_ALL = 'profile.edit.all';
    public const DIRECTORY_VIEW = 'directory.view';
    public const EMPLOYEE_CREATE = 'employee.create';
    public const EMPLOYEE_DEACTIVATE = 'employee.deactivate';
    public const ONBOARDING_MANAGE = 'onboarding.manage';
    public const ORG_MANAGE = 'org.manage';

    // Documents
    public const DOCUMENT_VIEW_SELF = 'document.view.self';
    public const DOCUMENT_UPLOAD_SELF = 'document.upload.self';
    public const DOCUMENT_VIEW_ALL = 'document.view.all';
    public const DOCUMENT_MANAGE = 'document.manage';

    // Attendance and time
    public const ATTENDANCE_PUNCH = 'attendance.punch';
    public const ATTENDANCE_VIEW_SELF = 'attendance.view.self';
    public const ATTENDANCE_VIEW_TEAM = 'attendance.view.team';
    public const ATTENDANCE_VIEW_ALL = 'attendance.view.all';
    public const ATTENDANCE_EDIT = 'attendance.edit';
    public const ATTENDANCE_REQUEST_REGULARISATION = 'attendance.regularisation.request';
    public const ATTENDANCE_APPROVE_REGULARISATION = 'attendance.regularisation.approve';
    public const ATTENDANCE_MANAGE_SHIFTS = 'attendance.shifts.manage';
    public const ATTENDANCE_MANAGE_HOLIDAYS = 'attendance.holidays.manage';

    // Leave and time off
    public const LEAVE_APPLY = 'leave.apply';
    public const LEAVE_CANCEL_SELF = 'leave.cancel.self';
    public const LEAVE_VIEW_SELF = 'leave.view.self';
    public const LEAVE_VIEW_TEAM = 'leave.view.team';
    public const LEAVE_VIEW_ALL = 'leave.view.all';
    public const LEAVE_APPROVE = 'leave.approve';
    public const LEAVE_ADJUST_BALANCE = 'leave.balance.adjust';
    public const LEAVE_MANAGE_POLICY = 'leave.policy.manage';

    // Payroll and expenses
    public const PAYROLL_VIEW_SELF = 'payroll.view.self';
    public const PAYROLL_VIEW_ALL = 'payroll.view.all';
    public const PAYROLL_EDIT_STRUCTURE = 'payroll.structure.edit';
    public const PAYROLL_RUN = 'payroll.run';
    public const EXPENSE_SUBMIT = 'expense.submit';
    public const EXPENSE_VIEW_SELF = 'expense.view.self';
    public const EXPENSE_VIEW_ALL = 'expense.view.all';
    public const EXPENSE_APPROVE = 'expense.approve';
    public const EXPENSE_REIMBURSE = 'expense.reimburse';

    // Learning
    public const LEARNING_VIEW_CATALOGUE = 'learning.catalogue.view';
    public const LEARNING_ENROL_SELF = 'learning.enrol.self';
    public const LEARNING_ASSIGN_TEAM = 'learning.assign.team';
    public const LEARNING_ASSIGN_ANY = 'learning.assign.any';
    public const LEARNING_MANAGE_CATALOGUE = 'learning.catalogue.manage';

    // Performance and talent
    public const TALENT_VIEW_SELF = 'talent.view.self';
    public const TALENT_UPDATE_OWN_GOALS = 'talent.goals.update.self';
    public const TALENT_VIEW_TEAM = 'talent.view.team';
    public const TALENT_REVIEW_TEAM = 'talent.review.team';
    public const TALENT_VIEW_ALL = 'talent.view.all';
    public const TALENT_MANAGE_CYCLES = 'talent.cycles.manage';

    // Communication
    public const NOTIFICATION_VIEW_SELF = 'notification.view.self';
    public const ANNOUNCEMENT_PUBLISH = 'announcement.publish';

    // Reporting and platform
    public const REPORT_VIEW_TEAM = 'report.view.team';
    public const REPORT_VIEW_ALL = 'report.view.all';
    public const REPORT_EXPORT = 'report.export';
    public const AUDIT_VIEW = 'audit.view';
    public const AUDIT_EXPORT = 'audit.export';
    public const USER_MANAGE_ROLES = 'user.roles.manage';
    public const USER_MANAGE_ALL = 'user.manage.all';
    public const SYSTEM_SETTINGS = 'system.settings';

    /**
     * Human readable grouping, used to render the permission matrix screen.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [
            'People' => [
                self::PROFILE_VIEW_SELF, self::PROFILE_EDIT_SELF, self::PROFILE_VIEW_TEAM,
                self::PROFILE_VIEW_ALL, self::PROFILE_EDIT_ALL, self::DIRECTORY_VIEW,
                self::EMPLOYEE_CREATE, self::EMPLOYEE_DEACTIVATE, self::ONBOARDING_MANAGE,
                self::ORG_MANAGE,
            ],
            'Documents' => [
                self::DOCUMENT_VIEW_SELF, self::DOCUMENT_UPLOAD_SELF,
                self::DOCUMENT_VIEW_ALL, self::DOCUMENT_MANAGE,
            ],
            'Attendance' => [
                self::ATTENDANCE_PUNCH, self::ATTENDANCE_VIEW_SELF, self::ATTENDANCE_VIEW_TEAM,
                self::ATTENDANCE_VIEW_ALL, self::ATTENDANCE_EDIT,
                self::ATTENDANCE_REQUEST_REGULARISATION, self::ATTENDANCE_APPROVE_REGULARISATION,
                self::ATTENDANCE_MANAGE_SHIFTS, self::ATTENDANCE_MANAGE_HOLIDAYS,
            ],
            'Leave' => [
                self::LEAVE_APPLY, self::LEAVE_CANCEL_SELF, self::LEAVE_VIEW_SELF,
                self::LEAVE_VIEW_TEAM, self::LEAVE_VIEW_ALL, self::LEAVE_APPROVE,
                self::LEAVE_ADJUST_BALANCE, self::LEAVE_MANAGE_POLICY,
            ],
            'Payroll' => [
                self::PAYROLL_VIEW_SELF, self::PAYROLL_VIEW_ALL, self::PAYROLL_EDIT_STRUCTURE,
                self::PAYROLL_RUN, self::EXPENSE_SUBMIT, self::EXPENSE_VIEW_SELF,
                self::EXPENSE_VIEW_ALL, self::EXPENSE_APPROVE, self::EXPENSE_REIMBURSE,
            ],
            'Learning' => [
                self::LEARNING_VIEW_CATALOGUE, self::LEARNING_ENROL_SELF,
                self::LEARNING_ASSIGN_TEAM, self::LEARNING_ASSIGN_ANY,
                self::LEARNING_MANAGE_CATALOGUE,
            ],
            'Performance' => [
                self::TALENT_VIEW_SELF, self::TALENT_UPDATE_OWN_GOALS, self::TALENT_VIEW_TEAM,
                self::TALENT_REVIEW_TEAM, self::TALENT_VIEW_ALL, self::TALENT_MANAGE_CYCLES,
            ],
            'Platform' => [
                self::NOTIFICATION_VIEW_SELF, self::ANNOUNCEMENT_PUBLISH,
                self::REPORT_VIEW_TEAM, self::REPORT_VIEW_ALL, self::REPORT_EXPORT,
                self::AUDIT_VIEW, self::AUDIT_EXPORT, self::USER_MANAGE_ROLES,
                self::USER_MANAGE_ALL, self::SYSTEM_SETTINGS,
            ],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::groups()))));
    }

    /** Turns a permission key into a readable sentence for the settings screen. */
    public static function label(string $permission): string
    {
        return ucfirst(str_replace(['.', '_'], [' ', ' '], $permission));
    }
}
