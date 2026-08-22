<?php

declare(strict_types=1);

use App\Controllers\AnalyticsController;
use App\Controllers\DashboardController;
use App\Controllers\ReportController;
use Dayflow\Kernel\Http\Router;
use Dayflow\Kernel\Security\Permissions;

/**
 * Analytics routes, published by the gateway under /dashboard, /analytics and
 * /reports.
 *
 * Attendance and leave analysis is declared ->authenticated() because two
 * different permissions grant it - report.view.team for a manager and
 * report.view.all for HR - and the controller has to know which one the caller
 * holds anyway in order to decide how wide the answer may be. Everything else
 * is governed by exactly one permission and says so here.
 */
return static function (Router $router): void {
    $dashboard = new DashboardController();
    $analytics = new AnalyticsController();
    $reports = new ReportController();

    $router->get('/dashboard', [$dashboard, 'index'])->authenticated();

    $router->get('/analytics/attendance', [$analytics, 'attendance'])->authenticated();
    $router->get('/analytics/leave', [$analytics, 'leave'])->authenticated();
    $router->get('/analytics/headcount', [$analytics, 'headcount'])->requires(Permissions::REPORT_VIEW_ALL);
    $router->get('/analytics/payroll', [$analytics, 'payroll'])->requires(Permissions::PAYROLL_VIEW_ALL);
    $router->get('/analytics/learning', [$analytics, 'learning'])->requires(Permissions::REPORT_VIEW_ALL);

    $router->get('/reports', [$reports, 'index'])->authenticated();
    $router->get('/reports/{slug}', [$reports, 'show'])->authenticated();
    $router->get('/reports/{slug}/export', [$reports, 'export'])->requires(Permissions::REPORT_EXPORT);
};
