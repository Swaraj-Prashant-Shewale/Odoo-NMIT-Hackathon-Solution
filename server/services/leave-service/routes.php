<?php

declare(strict_types=1);

use App\Controllers\DelegationController;
use App\Controllers\LeaveBalanceController;
use App\Controllers\LeaveCalendarController;
use App\Controllers\LeavePolicyController;
use App\Controllers\LeaveRequestController;
use App\Controllers\LeaveTypeController;
use Dayflow\Kernel\Http\Router;
use Dayflow\Kernel\Security\Permissions;

return static function (Router $router): void {
    $requests = new LeaveRequestController();
    $balances = new LeaveBalanceController();
    $calendar = new LeaveCalendarController();
    $types = new LeaveTypeController();
    $policies = new LeavePolicyController();
    $delegations = new DelegationController();

    // ---------------------------------------------------------------------
    // Requests
    //
    // The list, the detail view and the calendar are authenticated rather
    // than permission-gated because a single permission cannot express
    // "mine, or my team's, or everybody's" — the controller narrows the
    // query to whichever of those the caller has earned.
    // ---------------------------------------------------------------------
    $router->post('/leave/requests', [$requests, 'store'])->requires(Permissions::LEAVE_APPLY);
    $router->get('/leave/requests', [$requests, 'index'])->authenticated();
    $router->get('/leave/calendar', [$calendar, 'index'])->authenticated();
    $router->get('/leave/pending-approvals', [$requests, 'pendingApprovals'])->requires(Permissions::LEAVE_APPROVE);
    $router->get('/leave/requests/{id}', [$requests, 'show'])->authenticated();
    $router->post('/leave/requests/{id}/decide', [$requests, 'decide'])->requires(Permissions::LEAVE_APPROVE);
    $router->post('/leave/requests/{id}/cancel', [$requests, 'cancel'])->requires(Permissions::LEAVE_CANCEL_SELF);

    // ---------------------------------------------------------------------
    // Balances
    // ---------------------------------------------------------------------
    $router->get('/leave-balances', [$balances, 'index'])->authenticated();
    $router->get('/leave-balances/adjustments', [$balances, 'adjustmentHistory'])->authenticated();
    $router->post('/leave-balances/adjust', [$balances, 'adjust'])->requires(Permissions::LEAVE_ADJUST_BALANCE);
    $router->post('/leave-balances/accrue', [$balances, 'accrue'])->requires(Permissions::LEAVE_MANAGE_POLICY);

    // ---------------------------------------------------------------------
    // Leave types: everyone reads the catalogue, HR writes it.
    // ---------------------------------------------------------------------
    $router->get('/leave-types', [$types, 'index'])->authenticated();
    $router->get('/leave-types/{id}', [$types, 'show'])->authenticated();
    $router->post('/leave-types', [$types, 'store'])->requires(Permissions::LEAVE_MANAGE_POLICY);
    $router->put('/leave-types/{id}', [$types, 'update'])->requires(Permissions::LEAVE_MANAGE_POLICY);
    $router->delete('/leave-types/{id}', [$types, 'destroy'])->requires(Permissions::LEAVE_MANAGE_POLICY);

    // ---------------------------------------------------------------------
    // Entitlement policy
    // ---------------------------------------------------------------------
    $router->get('/leave-policies', [$policies, 'index'])->requires(Permissions::LEAVE_MANAGE_POLICY);
    $router->get('/leave-policies/{id}', [$policies, 'show'])->requires(Permissions::LEAVE_MANAGE_POLICY);
    $router->post('/leave-policies', [$policies, 'store'])->requires(Permissions::LEAVE_MANAGE_POLICY);
    $router->put('/leave-policies/{id}', [$policies, 'update'])->requires(Permissions::LEAVE_MANAGE_POLICY);
    $router->delete('/leave-policies/{id}', [$policies, 'destroy'])->requires(Permissions::LEAVE_MANAGE_POLICY);

    // ---------------------------------------------------------------------
    // Approval delegation
    // ---------------------------------------------------------------------
    $router->get('/approvals/delegations', [$delegations, 'index'])->authenticated();
    $router->get('/approvals/delegations/{id}', [$delegations, 'show'])->authenticated();
    $router->post('/approvals/delegations', [$delegations, 'store'])->requires(Permissions::LEAVE_APPROVE);
    $router->delete('/approvals/delegations/{id}', [$delegations, 'destroy'])->requires(Permissions::LEAVE_APPROVE);
};
