<?php

declare(strict_types=1);

use App\Controllers\BankAccountController;
use App\Controllers\ExpenseClaimController;
use App\Controllers\PayComponentController;
use App\Controllers\PayrollRunController;
use App\Controllers\PayslipController;
use App\Controllers\SalaryStructureController;
use Dayflow\Kernel\Http\Router;
use Dayflow\Kernel\Security\Permissions;

return static function (Router $router): void {
    $payslips = new PayslipController();
    $structures = new SalaryStructureController();
    $runs = new PayrollRunController();
    $components = new PayComponentController();
    $bank = new BankAccountController();
    $expenses = new ExpenseClaimController();

    // --- Payslips ----------------------------------------------------------
    // Authenticated rather than permission-gated: every one of these decides
    // scope on the record itself, because "may read payslips" is never the
    // whole answer to "may read this payslip".
    $router->get('/payslips', [$payslips, 'index'])->authenticated();
    $router->get('/payslips/{id}', [$payslips, 'show'])->authenticated();
    $router->get('/payslips/{id}/download', [$payslips, 'download'])->authenticated();

    // --- Salary structures -------------------------------------------------
    $router->post('/salary-structures', [$structures, 'store'])->requires(Permissions::PAYROLL_EDIT_STRUCTURE);
    $router->get('/salary-structures/{employee_id}', [$structures, 'current'])->authenticated();
    $router->get('/salary-structures/{employee_id}/history', [$structures, 'history'])->authenticated();

    // --- Payroll runs ------------------------------------------------------
    $router->get('/payroll/summary', [$runs, 'summary'])->requires(Permissions::PAYROLL_VIEW_ALL);
    $router->get('/payroll/runs', [$runs, 'index'])->requires(Permissions::PAYROLL_VIEW_ALL);
    $router->post('/payroll/runs', [$runs, 'store'])->requires(Permissions::PAYROLL_RUN);
    $router->get('/payroll/runs/{id}', [$runs, 'show'])->requires(Permissions::PAYROLL_VIEW_ALL);
    $router->post('/payroll/runs/{id}/process', [$runs, 'process'])->requires(Permissions::PAYROLL_RUN);
    $router->post('/payroll/runs/{id}/approve', [$runs, 'approve'])->requires(Permissions::PAYROLL_RUN);
    $router->post('/payroll/runs/{id}/publish', [$runs, 'publish'])->requires(Permissions::PAYROLL_RUN);

    // --- Bank details ------------------------------------------------------
    $router->get('/payroll/bank-account', [$bank, 'show'])->authenticated();
    $router->put('/payroll/bank-account', [$bank, 'update'])->authenticated();

    // --- Pay component catalogue -------------------------------------------
    $router->get('/pay-components', [$components, 'index'])->requires(Permissions::PAYROLL_VIEW_ALL);
    $router->post('/pay-components', [$components, 'store'])->requires(Permissions::PAYROLL_EDIT_STRUCTURE);
    $router->get('/pay-components/{id}', [$components, 'show'])->requires(Permissions::PAYROLL_VIEW_ALL);
    $router->put('/pay-components/{id}', [$components, 'update'])->requires(Permissions::PAYROLL_EDIT_STRUCTURE);

    // --- Expense claims ----------------------------------------------------
    $router->get('/expenses', [$expenses, 'index'])->authenticated();
    $router->post('/expenses', [$expenses, 'store'])->requires(Permissions::EXPENSE_SUBMIT);
    $router->get('/expenses/{id}', [$expenses, 'show'])->authenticated();
    $router->post('/expenses/{id}/decide', [$expenses, 'decide'])->requires(Permissions::EXPENSE_APPROVE);
    $router->post('/expenses/{id}/reimburse', [$expenses, 'reimburse'])->requires(Permissions::EXPENSE_REIMBURSE);
};
