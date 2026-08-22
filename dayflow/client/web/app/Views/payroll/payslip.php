<?php
/**
 * One salary statement, laid out to be read on screen and printed unchanged.
 *
 * Everything that is not part of the statement itself carries .no-print, so
 * what comes out of the printer is the document and nothing else.
 *
 * @var array<string, mixed>       $payslip
 * @var array<string, mixed>       $employee
 * @var list<array<string, mixed>> $earnings
 * @var list<array<string, mixed>> $deductions
 * @var list<array<string, mixed>> $contributions
 * @var int                        $earningsTotal
 * @var int                        $deductionsTotal
 * @var int                        $contributionsTotal
 * @var array<string, mixed>       $bank
 * @var string                     $companyName
 */

$payslipId = (string) ($payslip['id'] ?? '');
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <a href="/payroll" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left"></i> Back to payroll
    </a>
    <div class="d-flex flex-wrap align-items-center gap-3">
        <?php /* Printing is the browser's own command: the page carries print
                 styles so that what comes out is the statement alone, and the
                 platform ships no inline script to open the dialogue for it. */ ?>
        <span class="small text-muted">
            <i class="fa fa-print"></i> Print this page with your browser's print command.
        </span>
        <a href="/payroll/payslips/<?= e($payslipId) ?>/download" class="btn btn-sm btn-primary">
            <i class="fa fa-download"></i> Download PDF
        </a>
    </div>
</div>

<div class="card">

    <div class="card-body border-bottom">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <div class="h5 mb-1"><?= e($companyName) ?></div>
                <div class="text-muted small">Statement of salary</div>
            </div>
            <div class="text-lg-end">
                <div class="section-label mb-0">Pay period</div>
                <div class="h6 mb-0"><?= e($payslip['period_label'] ?? ($payslip['period'] ?? '—')) ?></div>
                <div class="small text-muted">
                    <?php if (!empty($payslip['published_at'])): ?>
                        Issued <?= e(date_display($payslip['published_at'])) ?>
                    <?php else: ?>
                        Not yet published
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body border-bottom">
        <div class="row g-4">
            <div class="col-md-6">
                <div class="section-label">Employee</div>
                <div class="stat-row">
                    <span class="stat-key">Name</span>
                    <span class="stat-val"><?= field($employee, 'full_name') ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Employee code</span>
                    <span class="stat-val"><?= field($employee, 'employee_code') ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Designation</span>
                    <span class="stat-val"><?= field($employee, 'designation_name') ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Department</span>
                    <span class="stat-val"><?= field($employee, 'department_name') ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Joined on</span>
                    <span class="stat-val"><?= e(date_display($employee['joined_on'] ?? null)) ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Bank account</span>
                    <span class="stat-val tabular">
                        <?php if (!empty($bank['account_number_masked'])): ?>
                            <?= e($bank['account_number_masked']) ?>
                            <span class="text-muted"><?= e($bank['bank_name'] ?? '') ?></span>
                        <?php else: ?>
                            <span class="text-muted">Not on record</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <div class="col-md-6 divider-y ps-md-4">
                <div class="section-label">Attendance for the period</div>
                <div class="stat-row">
                    <span class="stat-key">Payable days</span>
                    <span class="stat-val tabular"><?= e((string) ($payslip['payable_days'] ?? '—')) ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Days present</span>
                    <span class="stat-val tabular"><?= e((string) ($payslip['present_days'] ?? '—')) ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Days on leave</span>
                    <span class="stat-val tabular"><?= e((string) ($payslip['leave_days'] ?? '—')) ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Loss of pay</span>
                    <span class="stat-val tabular"><?= e((string) ($payslip['lop_days'] ?? '0')) ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Currency</span>
                    <span class="stat-val"><?= field($payslip, 'currency', 'INR') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body border-bottom">
        <div class="row g-4">

            <div class="col-md-6">
                <div class="section-label">Earnings</div>
                <div class="table-wrap">
                    <table class="table">
                        <tbody>
                            <?php if ($earnings === []): ?>
                                <tr>
                                    <td colspan="2" class="text-muted">No earning components on this statement.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($earnings as $line): ?>
                                    <tr>
                                        <td><?= field($line, 'component_name') ?></td>
                                        <td class="text-end tabular"><?= e(money($line['amount_minor'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold">
                                <td>Gross earnings</td>
                                <td class="text-end tabular"><?= e(money($earningsTotal)) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="col-md-6">
                <div class="section-label">Deductions</div>
                <div class="table-wrap">
                    <table class="table">
                        <tbody>
                            <?php if ($deductions === []): ?>
                                <tr>
                                    <td colspan="2" class="text-muted">Nothing was deducted this period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($deductions as $line): ?>
                                    <tr>
                                        <td><?= field($line, 'component_name') ?></td>
                                        <td class="text-end tabular"><?= e(money($line['amount_minor'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold">
                                <td>Total deductions</td>
                                <td class="text-end tabular"><?= e(money($deductionsTotal)) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body border-bottom">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <div class="section-label mb-1">Net pay</div>
                <?php if (!empty($payslip['net_in_words'])): ?>
                    <div class="small text-muted"><?= e($payslip['net_in_words']) ?></div>
                <?php endif; ?>
            </div>
            <div class="punch-clock text-success"><?= e(money($payslip['net_minor'] ?? 0)) ?></div>
        </div>
    </div>

    <?php if ($contributions !== []): ?>
        <div class="card-body border-bottom">
            <div class="section-label">Employer contributions</div>
            <p class="small text-muted">
                Paid by the company on top of your salary. These do not affect your net pay.
            </p>
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                        <?php foreach ($contributions as $line): ?>
                            <tr>
                                <td><?= field($line, 'component_name') ?></td>
                                <td class="text-end tabular"><?= e(money($line['amount_minor'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-semibold">
                            <td>Total employer cost</td>
                            <td class="text-end tabular"><?= e(money($contributionsTotal)) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="card-footer text-muted">
        This is a computer generated statement and does not require a signature.
        Income tax withheld this period: <span class="tabular"><?= e(money($payslip['tax_minor'] ?? 0)) ?></span>.
    </div>
</div>
