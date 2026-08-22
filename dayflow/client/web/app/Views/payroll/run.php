<?php
/**
 * One payroll run: its totals, its payslips and whatever it can do next.
 *
 * @var array<string, mixed>       $run
 * @var list<array<string, mixed>> $payslips
 * @var array<string, string>      $employees Employee id to display name.
 * @var array<string, string>      $userNames Account id to display name.
 * @var bool                       $mayRun    Whether the caller may act on the run.
 */

use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;

$runId = (string) ($run['id'] ?? '');
$status = (string) ($run['status'] ?? '');
$processedBy = (string) ($run['processed_by'] ?? '');
$approvedBy = (string) ($run['approved_by'] ?? '');
$processedByMe = $processedBy !== '' && $processedBy === (string) Session::userId();

$mayProcess = $mayRun && in_array($status, ['draft', 'processing'], true);
$mayApprove = $mayRun && $status === 'processing' && !$processedByMe;
$mayPublish = $mayRun && $status === 'approved';
?>

<?php View::partial('page-header', [
    'title' => (string) ($run['period_label'] ?? ($run['period'] ?? 'Payroll run')),
    'subtitle' => (string) ($run['run_label'] ?? ''),
    'actions' => '<a href="/payroll/runs" class="btn btn-outline-secondary">'
        . '<i class="fa fa-arrow-left"></i> All runs</a>',
]) ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="tile">
            <div class="tile-icon"><i class="fa fa-users"></i></div>
            <div class="tile-label">Employees</div>
            <div class="tile-value tabular"><?= e((string) ($run['employee_count'] ?? 0)) ?></div>
            <div class="tile-hint"><?= e((string) ($run['working_days'] ?? '—')) ?> working days</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tile tile-info">
            <div class="tile-icon"><i class="fa fa-coins"></i></div>
            <div class="tile-label">Gross</div>
            <div class="tile-value tabular"><?= e(money($run['total_gross_minor'] ?? 0)) ?></div>
            <div class="tile-hint">Before deductions</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tile tile-warning">
            <div class="tile-icon"><i class="fa fa-minus-circle"></i></div>
            <div class="tile-label">Deductions</div>
            <div class="tile-value tabular"><?= e(money($run['total_deductions_minor'] ?? 0)) ?></div>
            <div class="tile-hint">Tax and statutory</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tile tile-success">
            <div class="tile-icon"><i class="fa fa-wallet"></i></div>
            <div class="tile-label">Net payable</div>
            <div class="tile-value tabular"><?= e(money($run['total_net_minor'] ?? 0)) ?></div>
            <div class="tile-hint">What reaches bank accounts</div>
        </div>
    </div>
</div>

<div class="row g-4">

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-flag"></i> State</span>
                <?= badge($status) ?>
            </div>
            <div class="card-body">
                <div class="stat-row">
                    <span class="stat-key">Processed by</span>
                    <span class="stat-val">
                        <?= $processedBy === '' ? '<span class="text-muted">Not yet</span>' : e($userNames[$processedBy] ?? 'An account') ?>
                    </span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Processed at</span>
                    <span class="stat-val"><?= e(datetime_display($run['processed_at'] ?? null)) ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Approved by</span>
                    <span class="stat-val">
                        <?= $approvedBy === '' ? '<span class="text-muted">Not yet</span>' : e($userNames[$approvedBy] ?? 'An account') ?>
                    </span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Approved at</span>
                    <span class="stat-val"><?= e(datetime_display($run['approved_at'] ?? null)) ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Published</span>
                    <span class="stat-val"><?= e(datetime_display($run['paid_at'] ?? null)) ?></span>
                </div>

                <?php if (!empty($run['notes'])): ?>
                    <div class="section-label mt-3">Notes</div>
                    <p class="small mb-0"><?= e($run['notes']) ?></p>
                <?php endif; ?>
            </div>

            <div class="card-footer">
                <div class="small text-muted">
                    <i class="fa fa-user-shield"></i>
                    A run must be approved by somebody other than the person who processed it. The payroll
                    service refuses the approval otherwise.
                </div>
            </div>
        </div>

        <?php if ($mayRun): ?>
            <div class="card mt-4">
                <div class="card-header"><i class="fa fa-bolt"></i> Actions</div>
                <div class="card-body d-grid gap-2">

                    <?php if ($mayProcess): ?>
                        <form method="post" action="/payroll/runs/<?= e($runId) ?>/process" class="d-grid m-0">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn-primary" data-busy-label="Processing..."
                                    data-confirm="Calculate every payslip in this run? Any figures already calculated will be replaced.">
                                <i class="fa fa-calculator"></i> Process run
                            </button>
                        </form>
                        <p class="small text-muted mb-0">
                            Reads every salary structure effective in this month and the attendance behind it.
                        </p>
                    <?php endif; ?>

                    <?php if ($status === 'processing' && $processedByMe): ?>
                        <div class="alert alert-warning mb-0">
                            You processed this run, so somebody else has to approve it.
                        </div>
                    <?php endif; ?>

                    <?php if ($mayApprove): ?>
                        <form method="post" action="/payroll/runs/<?= e($runId) ?>/approve" class="d-grid m-0">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn-success" data-busy-label="Approving..."
                                    data-confirm="Approve this run for payment? This records you as the approver.">
                                <i class="fa fa-check"></i> Approve run
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($mayPublish): ?>
                        <form method="post" action="/payroll/runs/<?= e($runId) ?>/publish" class="d-grid m-0">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn-success" data-busy-label="Publishing..."
                                    data-confirm="Publish this run? Every payslip becomes visible to the employee it belongs to and this cannot be undone.">
                                <i class="fa fa-paper-plane"></i> Publish payslips
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if (!$mayProcess && !$mayApprove && !$mayPublish): ?>
                        <p class="small text-muted mb-0">
                            <?= $status === 'paid'
                                ? 'This run is closed. Its payslips are published.'
                                : 'There is nothing to do on this run from here right now.' ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="fa fa-file-invoice-dollar"></i> Payslips in this run</span>
                <?php if ($payslips !== []): ?>
                    <input type="search" class="form-control form-control-sm" style="width: 220px;"
                           placeholder="Filter by name" aria-label="Filter payslips"
                           data-filter-table="runPayslips">
                <?php endif; ?>
            </div>

            <?php if ($payslips === []): ?>
                <div class="card-body">
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-calculator',
                        'title' => 'Nothing calculated yet',
                        'message' => 'Process the run to produce a payslip for everybody with a salary structure in this month.',
                    ]) ?>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table align-middle" id="runPayslips">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th class="text-end">Payable days</th>
                                <th class="text-end">Loss of pay</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end">Deductions</th>
                                <th class="text-end">Net</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payslips as $slip): ?>
                                <?php $employeeId = (string) ($slip['employee_id'] ?? ''); ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-sm">
                                                <?= e(initials($employees[$employeeId] ?? '?')) ?>
                                            </span>
                                            <span class="truncate">
                                                <?= e($employees[$employeeId] ?? 'Employee record unavailable') ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-end tabular"><?= e((string) ($slip['payable_days'] ?? '—')) ?></td>
                                    <td class="text-end tabular"><?= e((string) ($slip['lop_days'] ?? '0')) ?></td>
                                    <td class="text-end tabular"><?= e(money($slip['gross_minor'] ?? 0)) ?></td>
                                    <td class="text-end tabular"><?= e(money($slip['total_deductions_minor'] ?? 0)) ?></td>
                                    <td class="text-end tabular fw-semibold"><?= e(money($slip['net_minor'] ?? 0)) ?></td>
                                    <td class="text-end">
                                        <a href="/payroll/payslips/<?= e($slip['id'] ?? '') ?>"
                                           class="btn btn-sm btn-outline-secondary" title="Open payslip">
                                            <i class="fa fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer text-muted" data-filter-empty style="display: none;">
                    No payslip in this run matches that name.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
