<?php

declare(strict_types=1);

use App\Controllers\AttendanceController;
use App\Controllers\HolidayController;
use App\Controllers\OvertimeController;
use App\Controllers\RegularisationController;
use App\Controllers\RosterController;
use App\Controllers\ShiftController;
use App\Controllers\TimesheetController;
use Dayflow\Kernel\Http\Router;
use Dayflow\Kernel\Security\Permissions;

/**
 * Routes are matched in registration order, so a literal path is always
 * declared before the pattern that would otherwise swallow it: "/shifts/mine"
 * has to win over "/shifts/{id}".
 */
return static function (Router $router): void {
    $attendance = new AttendanceController();
    $regularisations = new RegularisationController();
    $shifts = new ShiftController();
    $rosters = new RosterController();
    $holidays = new HolidayController();
    $timesheets = new TimesheetController();
    $overtime = new OvertimeController();

    // --- Punching in and out -------------------------------------------------
    $router->post('/attendance/check-in', [$attendance, 'checkIn'])->requires(Permissions::ATTENDANCE_PUNCH);
    $router->post('/attendance/check-out', [$attendance, 'checkOut'])->requires(Permissions::ATTENDANCE_PUNCH);

    // --- Reading attendance --------------------------------------------------
    $router->get('/attendance/today', [$attendance, 'today'])->requires(Permissions::ATTENDANCE_VIEW_SELF);
    $router->get('/attendance/weekly', [$attendance, 'weekly'])->authenticated();
    $router->get('/attendance/monthly', [$attendance, 'monthly'])->authenticated();
    $router->get('/attendance/team-today', [$attendance, 'teamToday'])->requires(Permissions::ATTENDANCE_VIEW_TEAM);
    $router->get('/attendance', [$attendance, 'index'])->authenticated();
    $router->patch('/attendance/{id}', [$attendance, 'update'])->requires(Permissions::ATTENDANCE_EDIT);

    // --- Regularisation ------------------------------------------------------
    $router->get('/regularisations', [$regularisations, 'index'])->authenticated();
    $router->post('/regularisations', [$regularisations, 'store'])
        ->requires(Permissions::ATTENDANCE_REQUEST_REGULARISATION);
    $router->get('/regularisations/{id}', [$regularisations, 'show'])->authenticated();
    $router->post('/regularisations/{id}/decide', [$regularisations, 'decide'])
        ->requires(Permissions::ATTENDANCE_APPROVE_REGULARISATION);

    // --- Shift patterns ------------------------------------------------------
    $router->get('/shifts/mine', [$shifts, 'mine'])->authenticated();
    $router->get('/shifts/assignments', [$shifts, 'assignments'])->requires(Permissions::ATTENDANCE_MANAGE_SHIFTS);
    $router->post('/shifts/assignments', [$shifts, 'assign'])->requires(Permissions::ATTENDANCE_MANAGE_SHIFTS);
    $router->delete('/shifts/assignments/{id}', [$shifts, 'unassign'])->requires(Permissions::ATTENDANCE_MANAGE_SHIFTS);
    $router->get('/shifts', [$shifts, 'index'])->requires(Permissions::ATTENDANCE_MANAGE_SHIFTS);
    $router->post('/shifts', [$shifts, 'store'])->requires(Permissions::ATTENDANCE_MANAGE_SHIFTS);
    $router->get('/shifts/{id}', [$shifts, 'show'])->requires(Permissions::ATTENDANCE_MANAGE_SHIFTS);
    $router->patch('/shifts/{id}', [$shifts, 'update'])->requires(Permissions::ATTENDANCE_MANAGE_SHIFTS);
    $router->delete('/shifts/{id}', [$shifts, 'destroy'])->requires(Permissions::ATTENDANCE_MANAGE_SHIFTS);

    // --- Rosters -------------------------------------------------------------
    $router->get('/rosters/mine', [$rosters, 'mine'])->authenticated();
    $router->get('/rosters', [$rosters, 'index'])->requires(Permissions::ATTENDANCE_MANAGE_SHIFTS);
    $router->post('/rosters', [$rosters, 'store'])->requires(Permissions::ATTENDANCE_MANAGE_SHIFTS);
    $router->patch('/rosters/{id}', [$rosters, 'update'])->requires(Permissions::ATTENDANCE_MANAGE_SHIFTS);
    $router->delete('/rosters/{id}', [$rosters, 'destroy'])->requires(Permissions::ATTENDANCE_MANAGE_SHIFTS);

    // --- Holiday calendar ----------------------------------------------------
    // Everybody needs to know when the office is shut, so reading is open to
    // any signed-in user; only HR administration may change it.
    $router->get('/holidays/upcoming', [$holidays, 'upcoming'])->authenticated();
    $router->get('/holidays', [$holidays, 'index'])->authenticated();
    $router->post('/holidays', [$holidays, 'store'])->requires(Permissions::ATTENDANCE_MANAGE_HOLIDAYS);
    $router->get('/holidays/{id}', [$holidays, 'show'])->authenticated();
    $router->patch('/holidays/{id}', [$holidays, 'update'])->requires(Permissions::ATTENDANCE_MANAGE_HOLIDAYS);
    $router->delete('/holidays/{id}', [$holidays, 'destroy'])->requires(Permissions::ATTENDANCE_MANAGE_HOLIDAYS);

    // --- Timesheets ----------------------------------------------------------
    // Deciding somebody else's logged time is the same manager-level approval
    // right as deciding their attendance corrections, so it uses that
    // permission rather than inventing a parallel one.
    $router->get('/timesheets/summary', [$timesheets, 'summary'])->authenticated();
    $router->get('/timesheets', [$timesheets, 'index'])->authenticated();
    $router->post('/timesheets', [$timesheets, 'store'])->authenticated();
    $router->patch('/timesheets/{id}', [$timesheets, 'update'])->authenticated();
    $router->delete('/timesheets/{id}', [$timesheets, 'destroy'])->authenticated();
    $router->post('/timesheets/{id}/submit', [$timesheets, 'submit'])->authenticated();
    $router->post('/timesheets/{id}/decide', [$timesheets, 'decide'])
        ->requires(Permissions::ATTENDANCE_APPROVE_REGULARISATION);

    // --- Overtime ------------------------------------------------------------
    $router->get('/overtime', [$overtime, 'index'])->authenticated();
};
