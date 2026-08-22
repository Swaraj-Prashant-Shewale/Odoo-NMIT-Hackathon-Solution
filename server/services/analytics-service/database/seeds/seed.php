<?php

declare(strict_types=1);

/**
 * Analytics reference data.
 *
 * Only the report catalogue is seeded. There is deliberately no demo data
 * behind SEED_DEMO_DATA here: every figure this service publishes is computed
 * live from the service that owns the underlying records, so a fabricated
 * snapshot would be a number nothing could reproduce.
 *
 * Each definition names the permission that governs it. That string is what
 * ReportPolicy checks on every run and every export, so it is treated as part
 * of the access model rather than as a label.
 */

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;

$definitions = [
    [
        'name' => 'Monthly Attendance Register',
        'slug' => 'monthly-attendance-register',
        'description' => 'Per-person attendance for a calendar month: days present, absent, half days, leave and hours worked.',
        'report_type' => 'attendance',
        'required_permission' => 'report.view.team',
        'default_filters' => ['range' => 'current_month'],
    ],
    [
        'name' => 'Leave Balance Statement',
        'slug' => 'leave-balance-statement',
        'description' => 'Entitlement, days used and days remaining for every leave type, person by person.',
        'report_type' => 'leave',
        // Reads /leave-balances person by person, which the leave service
        // refuses without leave visibility. Gated on report.view.team it was
        // offered to Finance and silently returned only their own six rows.
        'required_permission' => 'leave.view.team',
        'default_filters' => [],
    ],
    [
        'name' => 'Leave Utilisation Summary',
        'slug' => 'leave-utilisation-summary',
        'description' => 'Days taken and requests raised for each leave type across the organisation.',
        'report_type' => 'leave',
        'required_permission' => 'report.view.all',
        'default_filters' => ['range' => 'last_12_months'],
    ],
    [
        'name' => 'Headcount by Department',
        'slug' => 'headcount-by-department',
        'description' => 'Current headcount for each department, its share of the workforce and average tenure.',
        'report_type' => 'people',
        'required_permission' => 'report.view.all',
        'default_filters' => [],
    ],
    [
        'name' => 'New Joiners and Exits',
        'slug' => 'new-joiners-and-exits',
        'description' => 'Everyone who joined or left within the period, with department, designation and date.',
        'report_type' => 'people',
        'required_permission' => 'report.view.all',
        'default_filters' => ['range' => 'last_12_months'],
    ],
    [
        'name' => 'Payroll Register',
        'slug' => 'payroll-register',
        'description' => 'Gross pay, deductions and net pay for every payslip issued in the period.',
        'report_type' => 'payroll',
        'required_permission' => 'payroll.view.all',
        'default_filters' => ['range' => 'current_month'],
    ],
    [
        'name' => 'Salary Disbursement Summary',
        'slug' => 'salary-disbursement-summary',
        'description' => 'One line per payroll run: employees paid, gross, deductions, net disbursed and payment date.',
        'report_type' => 'payroll',
        'required_permission' => 'payroll.view.all',
        'default_filters' => ['range' => 'last_12_months'],
    ],
    [
        'name' => 'Expense Claim Summary',
        'slug' => 'expense-claim-summary',
        'description' => 'Expense claims raised in the period with category, amount and approval status.',
        'report_type' => 'expense',
        'required_permission' => 'expense.view.all',
        'default_filters' => ['range' => 'last_12_months'],
    ],
    [
        'name' => 'Training Compliance',
        'slug' => 'training-compliance',
        'description' => 'Mandatory training assigned against training completed, person by person, lowest compliance first.',
        'report_type' => 'learning',
        // Built from /learning/compliance, which the learning service guards
        // with learning.assign.any. Listing it against report.view.all offered
        // it to Finance, who then got a 503 every time.
        'required_permission' => 'learning.assign.any',
        'default_filters' => [],
    ],
    [
        'name' => 'Document Expiry',
        'slug' => 'document-expiry',
        'description' => 'Employee documents falling due for renewal, soonest first, with days remaining.',
        'report_type' => 'document',
        'required_permission' => 'document.view.all',
        'default_filters' => ['days' => 60],
    ],
    [
        'name' => 'Overtime Summary',
        'slug' => 'overtime-summary',
        'description' => 'Overtime hours recorded and approved for each person over the period.',
        'report_type' => 'attendance',
        'required_permission' => 'report.view.team',
        'default_filters' => ['range' => 'current_month'],
    ],
    [
        'name' => 'Performance Rating Distribution',
        'slug' => 'performance-rating-distribution',
        'description' => 'How closed review ratings are spread, with the share of reviews at each rating.',
        'report_type' => 'performance',
        'required_permission' => 'talent.view.all',
        'default_filters' => [],
    ],
];

$pdo = Connection::pdo();

// The catalogue is reference data whose runners live in code, so an existing
// row is brought back into step rather than left to drift: a definition whose
// required_permission no longer matched its runner would be a real access-
// control defect. The conflict target makes the whole seed re-runnable.
$statement = $pdo->prepare(<<<'SQL'
    INSERT INTO report_definitions
        (id, name, slug, description, report_type, default_filters,
         required_permission, is_active, created_at, updated_at)
    VALUES
        (:id, :name, :slug, :description, :report_type, CAST(:default_filters AS JSONB),
         :required_permission, TRUE, :created_at, :updated_at)
    ON CONFLICT (slug) DO UPDATE SET
        name                = EXCLUDED.name,
        description         = EXCLUDED.description,
        report_type         = EXCLUDED.report_type,
        default_filters     = EXCLUDED.default_filters,
        required_permission = EXCLUDED.required_permission,
        is_active           = TRUE,
        updated_at          = EXCLUDED.updated_at
SQL);

$now = Clock::iso();

foreach ($definitions as $definition) {
    $statement->execute([
        'id' => Str::uuid(),
        'name' => $definition['name'],
        'slug' => $definition['slug'],
        'description' => $definition['description'],
        'report_type' => $definition['report_type'],
        'default_filters' => json_encode($definition['default_filters'], JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT),
        'required_permission' => $definition['required_permission'],
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
