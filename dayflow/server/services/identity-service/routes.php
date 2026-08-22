<?php

declare(strict_types=1);

use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\RoleController;
use App\Controllers\SessionController;
use App\Controllers\SettingsController;
use App\Controllers\UserController;
use Dayflow\Kernel\Http\Router;
use Dayflow\Kernel\Security\Permissions;

return static function (Router $router): void {
    $auth = new AuthController();
    $sessions = new SessionController();
    $users = new UserController();
    $roles = new RoleController();
    $audit = new AuditController();
    $settings = new SettingsController();

    // -----------------------------------------------------------------------
    // Reachable without a token.
    //
    // This list is as short as it can be, and every entry is either how a
    // session begins or how somebody recovers one they have lost. The gateway
    // publishes exactly the same set and throttles it far harder than the rest
    // of the API, so both layers have to agree before a call arrives here at
    // all.
    // -----------------------------------------------------------------------
    $router->post('/auth/register', [$auth, 'register'])->allowPublic();
    $router->post('/auth/login', [$auth, 'login'])->allowPublic();
    $router->post('/auth/refresh', [$auth, 'refresh'])->allowPublic();
    $router->post('/auth/verify-email', [$auth, 'verifyEmail'])->allowPublic();
    // A confirmation link in an email is followed as a GET, so the same
    // handler answers both verbs and reads the token from either place.
    $router->get('/auth/verify-email', [$auth, 'verifyEmail'])->allowPublic();
    $router->post('/auth/resend-verification', [$auth, 'resendVerification'])->allowPublic();
    $router->post('/auth/forgot-password', [$auth, 'forgotPassword'])->allowPublic();
    $router->post('/auth/reset-password', [$auth, 'resetPassword'])->allowPublic();
    $router->get('/auth/password-policy', [$auth, 'passwordPolicy'])->allowPublic();

    // -----------------------------------------------------------------------
    // Anyone with a valid session, acting on themselves.
    //
    // No permission would be right for these: the question is never what the
    // caller's role allows, only ever whether the record is theirs. Each
    // controller answers that itself.
    // -----------------------------------------------------------------------
    $router->get('/auth/me', [$auth, 'me'])->authenticated();
    $router->post('/auth/logout', [$auth, 'logout'])->authenticated();
    $router->post('/auth/change-password', [$auth, 'changePassword'])->authenticated();

    $router->get('/sessions', [$sessions, 'index'])->authenticated();
    $router->delete('/sessions/{id}', [$sessions, 'destroy'])->authenticated();

    // -----------------------------------------------------------------------
    // Account administration.
    // -----------------------------------------------------------------------
    $router->get('/users', [$users, 'index'])->requires(Permissions::PROFILE_VIEW_ALL);
    $router->post('/users', [$users, 'store'])->requires(Permissions::USER_MANAGE_ALL);
    // Administrators and account owners both reach this one, so the finer
    // decision belongs to the controller.
    $router->get('/users/{id}', [$users, 'show'])->authenticated();
    $router->patch('/users/{id}', [$users, 'update'])->requires(Permissions::USER_MANAGE_ALL);
    $router->post('/users/{id}/roles', [$users, 'assignRoles'])->requires(Permissions::USER_MANAGE_ROLES);
    $router->post('/users/{id}/activate', [$users, 'activate'])->requires(Permissions::USER_MANAGE_ALL);
    $router->post('/users/{id}/deactivate', [$users, 'deactivate'])->requires(Permissions::USER_MANAGE_ALL);

    // -----------------------------------------------------------------------
    // The access model itself, shown on the same screen that edits grants.
    // -----------------------------------------------------------------------
    $router->get('/roles', [$roles, 'index'])->requires(Permissions::USER_MANAGE_ROLES);
    $router->get('/permissions', [$roles, 'permissions'])->requires(Permissions::USER_MANAGE_ROLES);

    // -----------------------------------------------------------------------
    // Audit trail. Viewing and exporting are separate permissions because
    // taking a copy of the whole security history out of the platform is a
    // different act from reading a page of it on screen.
    // -----------------------------------------------------------------------
    $router->get('/audit', [$audit, 'index'])->requires(Permissions::AUDIT_VIEW);
    $router->get('/audit/export', [$audit, 'export'])->requires(Permissions::AUDIT_EXPORT);

    // -----------------------------------------------------------------------
    // Company defaults: readable by everyone signed in, writable by an
    // administrator, because every screen in the product is drawn from them.
    // -----------------------------------------------------------------------
    $router->get('/settings', [$settings, 'index'])->authenticated();
    $router->put('/settings', [$settings, 'update'])->requires(Permissions::SYSTEM_SETTINGS);
};
