<?php

declare(strict_types=1);

/**
 * Web client route map.
 *
 * Every page a person can open is listed here. A route with neither ->guest()
 * nor ->requires() needs a signed-in user and performs its own finer checks,
 * which is the usual case; the router refuses anonymous access to it and
 * rejects any POST without a valid CSRF token.
 */

use App\Controllers\AdminController;
use App\Controllers\ApprovalController;
use App\Controllers\AttendanceController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\InsightsController;
use App\Controllers\DirectoryController;
use App\Controllers\LeaveController;
use App\Controllers\LearningController;
use App\Controllers\NotificationController;
use App\Controllers\PayrollController;
use App\Controllers\PeopleController;
use App\Controllers\PerformanceController;
use App\Controllers\ProfileController;
use App\Controllers\ReportController;
use App\Core\Router;
use Dayflow\Kernel\Security\Permissions;

return static function (Router $router): void {

    // -----------------------------------------------------------------------
    // Authentication - the only pages reachable while signed out
    // -----------------------------------------------------------------------
    $router->get('/login', [AuthController::class, 'showLogin'])->guest();
    $router->post('/login', [AuthController::class, 'login'])->guest();
    $router->get('/register', [AuthController::class, 'showRegister'])->guest();
    $router->post('/register', [AuthController::class, 'register'])->guest();
    $router->get('/verify-email', [AuthController::class, 'verifyEmail'])->guest();
    $router->get('/resend-verification', [AuthController::class, 'showResendVerification'])->guest();
    $router->post('/resend-verification', [AuthController::class, 'resendVerification'])->guest();
    $router->get('/forgot-password', [AuthController::class, 'showForgotPassword'])->guest();
    $router->post('/forgot-password', [AuthController::class, 'forgotPassword'])->guest();
    $router->get('/reset-password', [AuthController::class, 'showResetPassword'])->guest();
    $router->post('/reset-password', [AuthController::class, 'resetPassword'])->guest();

    $router->post('/logout', [AuthController::class, 'logout']);

    // The runtime image probes this on every container; see AuthController.
    $router->get('/health', [AuthController::class, 'health'])->open();

    // -----------------------------------------------------------------------
    // Dashboard
    // -----------------------------------------------------------------------
    $router->get('/', [DashboardController::class, 'index']);

    // -----------------------------------------------------------------------
    // My profile
    // -----------------------------------------------------------------------
    $router->get('/profile', [ProfileController::class, 'show']);
    $router->get('/profile/edit', [ProfileController::class, 'edit']);
    $router->post('/profile/edit', [ProfileController::class, 'update']);
    $router->post('/profile/photo', [ProfileController::class, 'uploadPhoto']);
    $router->get('/profile/documents', [ProfileController::class, 'documents']);
    $router->post('/profile/documents', [ProfileController::class, 'uploadDocument']);
    $router->get('/profile/documents/{id}/download', [ProfileController::class, 'downloadDocument']);
    $router->get('/profile/security', [ProfileController::class, 'security']);
    $router->post('/profile/security/password', [ProfileController::class, 'changePassword']);
    $router->post('/profile/security/sessions/{id}/revoke', [ProfileController::class, 'revokeSession']);
    $router->get('/profile/bank', [ProfileController::class, 'bankDetails']);
    $router->post('/profile/bank', [ProfileController::class, 'updateBankDetails']);

    // -----------------------------------------------------------------------
    // Attendance
    // -----------------------------------------------------------------------
    $router->get('/attendance', [AttendanceController::class, 'index']);
    $router->post('/attendance/check-in', [AttendanceController::class, 'checkIn']);
    $router->post('/attendance/check-out', [AttendanceController::class, 'checkOut']);
    $router->get('/attendance/monthly', [AttendanceController::class, 'monthly']);
    $router->get('/attendance/team', [AttendanceController::class, 'team'])
        ->requires(Permissions::ATTENDANCE_VIEW_TEAM);
    $router->get('/attendance/register', [AttendanceController::class, 'register'])
        ->requires(Permissions::ATTENDANCE_VIEW_ALL);
    $router->get('/attendance/regularise', [AttendanceController::class, 'showRegularise']);
    $router->post('/attendance/regularise', [AttendanceController::class, 'regularise']);
    $router->get('/attendance/holidays', [AttendanceController::class, 'holidays']);
    $router->get('/attendance/timesheets', [AttendanceController::class, 'timesheets']);
    $router->post('/attendance/timesheets', [AttendanceController::class, 'storeTimesheet']);

    // -----------------------------------------------------------------------
    // Leave and time off
    // -----------------------------------------------------------------------
    $router->get('/leave', [LeaveController::class, 'index']);
    $router->get('/leave/apply', [LeaveController::class, 'showApply']);
    $router->post('/leave/apply', [LeaveController::class, 'apply']);
    $router->get('/leave/{id}', [LeaveController::class, 'show']);
    $router->post('/leave/{id}/cancel', [LeaveController::class, 'cancel']);
    $router->get('/leave-calendar', [LeaveController::class, 'calendar']);
    $router->get('/leave-balances', [LeaveController::class, 'balances']);

    // -----------------------------------------------------------------------
    // Approvals - one queue for leave, attendance corrections and expenses
    // -----------------------------------------------------------------------
    $router->get('/approvals', [ApprovalController::class, 'index']);
    $router->post('/approvals/leave/{id}', [ApprovalController::class, 'decideLeave'])
        ->requires(Permissions::LEAVE_APPROVE);
    $router->post('/approvals/regularisation/{id}', [ApprovalController::class, 'decideRegularisation'])
        ->requires(Permissions::ATTENDANCE_APPROVE_REGULARISATION);
    $router->post('/approvals/expense/{id}', [ApprovalController::class, 'decideExpense'])
        ->requires(Permissions::EXPENSE_APPROVE);
    $router->get('/approvals/delegations', [ApprovalController::class, 'delegations']);
    $router->post('/approvals/delegations', [ApprovalController::class, 'storeDelegation']);

    // -----------------------------------------------------------------------
    // Payroll and expenses
    // -----------------------------------------------------------------------
    $router->get('/payroll', [PayrollController::class, 'index']);
    $router->get('/payroll/payslips/{id}', [PayrollController::class, 'payslip']);
    $router->get('/payroll/payslips/{id}/download', [PayrollController::class, 'downloadPayslip']);
    $router->get('/payroll/salary', [PayrollController::class, 'salaryStructure']);
    $router->get('/payroll/runs', [PayrollController::class, 'runs'])
        ->requires(Permissions::PAYROLL_VIEW_ALL);
    $router->get('/payroll/runs/{id}', [PayrollController::class, 'run'])
        ->requires(Permissions::PAYROLL_VIEW_ALL);
    $router->post('/payroll/runs', [PayrollController::class, 'createRun'])
        ->requires(Permissions::PAYROLL_RUN);
    $router->post('/payroll/runs/{id}/process', [PayrollController::class, 'processRun'])
        ->requires(Permissions::PAYROLL_RUN);
    $router->post('/payroll/runs/{id}/approve', [PayrollController::class, 'approveRun'])
        ->requires(Permissions::PAYROLL_RUN);
    $router->post('/payroll/runs/{id}/publish', [PayrollController::class, 'publishRun'])
        ->requires(Permissions::PAYROLL_RUN);
    $router->get('/payroll/structures', [PayrollController::class, 'structures'])
        ->requires(Permissions::PAYROLL_EDIT_STRUCTURE);
    $router->post('/payroll/structures', [PayrollController::class, 'storeStructure'])
        ->requires(Permissions::PAYROLL_EDIT_STRUCTURE);

    $router->get('/expenses', [PayrollController::class, 'expenses']);
    $router->get('/expenses/new', [PayrollController::class, 'newExpense']);
    $router->post('/expenses/new', [PayrollController::class, 'storeExpense']);
    $router->post('/expenses/{id}/reimburse', [PayrollController::class, 'reimburseExpense'])
        ->requires(Permissions::EXPENSE_REIMBURSE);

    // -----------------------------------------------------------------------
    // Learning
    // -----------------------------------------------------------------------
    $router->get('/learning', [LearningController::class, 'index']);
    $router->get('/learning/catalogue', [LearningController::class, 'catalogue']);
    $router->get('/learning/courses/{id}', [LearningController::class, 'course']);
    $router->get('/learning/courses/{id}/lessons/{lessonId}', [LearningController::class, 'lesson']);
    $router->post('/learning/courses/{id}/enrol', [LearningController::class, 'enrol']);
    $router->post('/learning/courses/{id}/progress', [LearningController::class, 'recordProgress']);
    $router->get('/learning/courses/{id}/quiz', [LearningController::class, 'quiz']);
    $router->post('/learning/courses/{id}/quiz', [LearningController::class, 'submitQuiz']);
    $router->get('/learning/certificates', [LearningController::class, 'certificates']);
    $router->get('/learning/certificates/{id}/download', [LearningController::class, 'downloadCertificate']);
    $router->get('/learning/manage', [LearningController::class, 'manage'])
        ->requires(Permissions::LEARNING_MANAGE_CATALOGUE);
    $router->post('/learning/manage/courses', [LearningController::class, 'storeCourse'])
        ->requires(Permissions::LEARNING_MANAGE_CATALOGUE);
    $router->post('/learning/manage/courses/{id}/lessons', [LearningController::class, 'storeLesson'])
        ->requires(Permissions::LEARNING_MANAGE_CATALOGUE);
    $router->get('/learning/compliance', [LearningController::class, 'compliance'])
        ->requires(Permissions::LEARNING_ASSIGN_ANY);
    $router->post('/learning/assign', [LearningController::class, 'assign']);

    // -----------------------------------------------------------------------
    // Performance
    // -----------------------------------------------------------------------
    $router->get('/performance', [PerformanceController::class, 'index']);
    $router->get('/performance/goals/new', [PerformanceController::class, 'newGoal']);
    $router->post('/performance/goals/new', [PerformanceController::class, 'storeGoal']);
    $router->post('/performance/goals/{id}/progress', [PerformanceController::class, 'updateGoalProgress']);
    $router->get('/performance/reviews', [PerformanceController::class, 'reviews']);
    $router->get('/performance/reviews/{id}', [PerformanceController::class, 'review']);
    $router->post('/performance/reviews/{id}', [PerformanceController::class, 'saveReview']);
    $router->post('/performance/reviews/{id}/submit', [PerformanceController::class, 'submitReview']);
    $router->post('/performance/reviews/{id}/acknowledge', [PerformanceController::class, 'acknowledgeReview']);
    $router->get('/performance/feedback', [PerformanceController::class, 'feedback']);
    $router->post('/performance/feedback', [PerformanceController::class, 'sendFeedback']);
    $router->get('/performance/cycles', [PerformanceController::class, 'cycles'])
        ->requires(Permissions::TALENT_MANAGE_CYCLES);
    $router->post('/performance/cycles', [PerformanceController::class, 'storeCycle'])
        ->requires(Permissions::TALENT_MANAGE_CYCLES);
    $router->post('/performance/cycles/{id}/open', [PerformanceController::class, 'openCycle'])
        ->requires(Permissions::TALENT_MANAGE_CYCLES);

    // -----------------------------------------------------------------------
    // People and organisation
    // -----------------------------------------------------------------------
    $router->get('/people', [PeopleController::class, 'index']);
    $router->get('/people/new', [PeopleController::class, 'create'])
        ->requires(Permissions::EMPLOYEE_CREATE);
    $router->post('/people/new', [PeopleController::class, 'store'])
        ->requires(Permissions::EMPLOYEE_CREATE);
    $router->get('/people/{id}', [PeopleController::class, 'show']);
    $router->get('/people/{id}/edit', [PeopleController::class, 'edit'])
        ->requires(Permissions::PROFILE_EDIT_ALL);
    $router->post('/people/{id}/edit', [PeopleController::class, 'update'])
        ->requires(Permissions::PROFILE_EDIT_ALL);
    $router->post('/people/{id}/offboard', [PeopleController::class, 'offboard'])
        ->requires(Permissions::EMPLOYEE_DEACTIVATE);
    $router->get('/people/{id}/documents', [PeopleController::class, 'documents'])
        ->requires(Permissions::DOCUMENT_VIEW_ALL);
    $router->get('/onboarding', [PeopleController::class, 'onboarding'])
        ->requires(Permissions::ONBOARDING_MANAGE);
    $router->post('/onboarding/{id}/complete', [PeopleController::class, 'completeOnboardingTask'])
        ->requires(Permissions::ONBOARDING_MANAGE);
    $router->get('/org-chart', [PeopleController::class, 'orgChart']);
    $router->get('/directory', [DirectoryController::class, 'index']);

    // -----------------------------------------------------------------------
    // Notifications and announcements
    // -----------------------------------------------------------------------
    $router->get('/notifications', [NotificationController::class, 'index']);
    $router->post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    $router->post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    $router->get('/notifications/preferences', [NotificationController::class, 'preferences']);
    $router->post('/notifications/preferences', [NotificationController::class, 'savePreferences']);
    $router->get('/announcements', [NotificationController::class, 'announcements']);
    $router->post('/announcements', [NotificationController::class, 'storeAnnouncement'])
        ->requires(Permissions::ANNOUNCEMENT_PUBLISH);

    // -----------------------------------------------------------------------
    // Reports
    // -----------------------------------------------------------------------
    // Deeper than the dashboard cards: the five analyses the analytics service
    // computes and, until now, nothing rendered.
    $router->get('/insights', [InsightsController::class, 'index'])
        ->requires(Permissions::REPORT_VIEW_TEAM);

    $router->get('/reports', [ReportController::class, 'index']);
    $router->get('/reports/{slug}', [ReportController::class, 'show']);
    $router->get('/reports/{slug}/export', [ReportController::class, 'export'])
        ->requires(Permissions::REPORT_EXPORT);

    // -----------------------------------------------------------------------
    // Administration
    // -----------------------------------------------------------------------
    $router->get('/admin', [AdminController::class, 'index']);
    $router->get('/admin/users', [AdminController::class, 'users'])
        ->requires(Permissions::USER_MANAGE_ALL);
    $router->get('/admin/users/new', [AdminController::class, 'createUser'])
        ->requires(Permissions::USER_MANAGE_ALL);
    $router->post('/admin/users/new', [AdminController::class, 'storeUser'])
        ->requires(Permissions::USER_MANAGE_ALL);
    $router->post('/admin/users/{id}/roles', [AdminController::class, 'updateRoles'])
        ->requires(Permissions::USER_MANAGE_ROLES);
    $router->post('/admin/users/{id}/activate', [AdminController::class, 'activateUser'])
        ->requires(Permissions::USER_MANAGE_ALL);
    $router->post('/admin/users/{id}/deactivate', [AdminController::class, 'deactivateUser'])
        ->requires(Permissions::USER_MANAGE_ALL);
    $router->get('/admin/roles', [AdminController::class, 'roles'])
        ->requires(Permissions::USER_MANAGE_ROLES);
    $router->get('/admin/organisation', [AdminController::class, 'organisation'])
        ->requires(Permissions::ORG_MANAGE);
    $router->post('/admin/organisation/departments', [AdminController::class, 'storeDepartment'])
        ->requires(Permissions::ORG_MANAGE);
    $router->post('/admin/organisation/designations', [AdminController::class, 'storeDesignation'])
        ->requires(Permissions::ORG_MANAGE);
    $router->post('/admin/organisation/locations', [AdminController::class, 'storeLocation'])
        ->requires(Permissions::ORG_MANAGE);
    $router->get('/admin/leave-types', [AdminController::class, 'leaveTypes'])
        ->requires(Permissions::LEAVE_MANAGE_POLICY);
    $router->post('/admin/leave-types', [AdminController::class, 'storeLeaveType'])
        ->requires(Permissions::LEAVE_MANAGE_POLICY);
    $router->get('/admin/shifts', [AdminController::class, 'shifts'])
        ->requires(Permissions::ATTENDANCE_MANAGE_SHIFTS);
    $router->post('/admin/shifts', [AdminController::class, 'storeShift'])
        ->requires(Permissions::ATTENDANCE_MANAGE_SHIFTS);
    $router->get('/admin/holidays', [AdminController::class, 'holidays'])
        ->requires(Permissions::ATTENDANCE_MANAGE_HOLIDAYS);
    $router->post('/admin/holidays', [AdminController::class, 'storeHoliday'])
        ->requires(Permissions::ATTENDANCE_MANAGE_HOLIDAYS);
    $router->get('/admin/audit', [AdminController::class, 'audit'])
        ->requires(Permissions::AUDIT_VIEW);
    $router->get('/admin/audit/export', [AdminController::class, 'exportAudit'])
        ->requires(Permissions::AUDIT_EXPORT);
    $router->get('/admin/settings', [AdminController::class, 'settings'])
        ->requires(Permissions::SYSTEM_SETTINGS);
    $router->post('/admin/settings', [AdminController::class, 'saveSettings'])
        ->requires(Permissions::SYSTEM_SETTINGS);
    $router->get('/admin/assets', [AdminController::class, 'assets'])
        ->requires(Permissions::ORG_MANAGE);
    $router->post('/admin/assets', [AdminController::class, 'storeAsset'])
        ->requires(Permissions::ORG_MANAGE);
    $router->get('/admin/health', [AdminController::class, 'health'])
        ->requires(Permissions::SYSTEM_SETTINGS);

    // The development mail inbox. The notification service refuses this
    // endpoint outright in production, so the page is a thin viewer over it.
    $router->get('/mailbox', [AdminController::class, 'mailbox'])
        ->requires(Permissions::SYSTEM_SETTINGS);
};
