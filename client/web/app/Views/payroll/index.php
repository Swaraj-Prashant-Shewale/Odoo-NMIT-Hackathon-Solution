<?php
/**
 * The signed-in person's own payroll.
 *
 * @var array<string, mixed>|null       $latest       Most recent payslip, if any.
 * @var list<array<string, mixed>>      $payslips     The page of statements being shown.
 * @var array<string, int>              $meta         Pagination block from the API.
 * @var array{gross: int, deductions: int, net: int, tax: int, months: int} $yearToDate
 * @var string                          $financialYear
 * @var array<string, mixed>            $netChart     Chart spec for net pay by month.
 * @var array<string, mixed>|null       $summary      Company position, for payroll owners.
 */

use App\Core\View;

$latestPublished = $latest !== null && !empty($latest['published_at']);
?>

<?php View::partial('page-header', [
    'title' => 'Payroll',
    'subtitle' => 'Your payslips, what you have earned this year, and the salary behind it.',
    'actions' => '<a href="/payroll/salary" class="btn btn-outline-secondary">'
        . '<i class="fa fa-sitemap"></i> Salary structure</a>'
        . '<a href="/expenses" class="btn btn-outline-secondary">'
        . '<i class="fa fa-receipt"></i> Expense claims</a>',
]) ?>

<?php if ($summary !== null): ?>
    <?php
    $companyLatest = is_array($summary['latest'] ?? null) ? $summary['latest'] : null;
    $pipeline = is_array($summary['pipeline'] ?? null) ? $summary['pipeline'] : [];
    ?>
    <div class="card mb-4">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="fa fa-building"></i> Company payroll</span>
            <a href="/payroll/runs" class="btn btn-sm btn-outline-primary">
                <i class="fa fa-list-check"></i> All runs
            </a>
        </div>
        <div class="card-body">
            <?php if ($companyLatest === null): ?>
                <p class="text-muted mb-0">
                    No payroll run has been approved yet. Open one from the runs screen to get started.
                </p>
            <?php else: ?>
                <div class="row g-3">
                    <div class="col-6 col-lg-3">
                        <div class="section-label">Latest run</div>
                        <div class="fw-semibold"><?= e($companyLatest['period_label'] ?? '—') ?></div>
                        <div class="mt-1"><?= badge($companyLatest['status'] ?? null) ?></div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="section-label">Total cost</div>
                        <div class="fw-semibold tabular"><?= e(money($companyLatest['total_cost_minor'] ?? 0)) ?></div>
                        <div class="tile-hint">Gross plus employer contributions</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="section-label">Employees paid</div>
                        <div class="fw-semibold tabular"><?= e((string) ($companyLatest['employee_count'] ?? 0)) ?></div>
                        <div class="tile-hint">Net <?= e(money($companyLatest['total_net_minor'] ?? 0)) ?></div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="section-label">Current period</div>
                        <div class="fw-semibold"><?= e((string) ($pipeline['current_period'] ?? '—')) ?></div>
                        <div class="tile-hint">
                            <?= !empty($pipeline['current_period_has_run']) ? 'Run opened' : 'No run opened yet' ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-file-invoice-dollar"></i> Latest payslip</div>
            <div class="card-body">
                <?php if ($latest === null): ?>
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-file-invoice',
                        'title' => 'No payslip yet',
                        'message' => 'Your first statement will appear here once payroll has been published for a month you worked.',
                    ]) ?>
                <?php else: ?>
                    <div class="section-label">Pay period</div>
                    <div class="h5 mb-1"><?= e($latest['period_label'] ?? '—') ?></div>
                    <div class="mb-3">
                        <?php if ($latestPublished): ?>
                            <span class="small text-muted">
                                Published <?= e(date_display($latest['published_at'] ?? null)) ?>
                            </span>
                        <?php else: ?>
                            <?= badge('draft') ?>
                            <span class="small text-muted">Not yet released</span>
                        <?php endif; ?>
                    </div>

                    <div class="punch-clock text-success"><?= e(money($latest['net_minor'] ?? 0)) ?></div>
                    <div class="punch-date mb-3">Net pay</div>

                    <div class="stat-row">
                        <span class="stat-key">Gross earnings</span>
                        <span class="stat-val tabular"><?= e(money($latest['gross_minor'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Deductions</span>
                        <span class="stat-val tabular"><?= e(money($latest['total_deductions_minor'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Tax withheld</span>
                        <span class="stat-val tabular"><?= e(money($latest['tax_minor'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Payable days</span>
                        <span class="stat-val tabular"><?= e((string) ($latest['payable_days'] ?? '—')) ?></span>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <a href="/payroll/payslips/<?= e($latest['id'] ?? '') ?>" class="btn btn-primary btn-sm">
                            <i class="fa fa-eye"></i> View payslip
                        </a>
                        <a href="/payroll/payslips/<?= e($latest['id'] ?? '') ?>/download" class="btn btn-outline-secondary btn-sm">
                            <i class="fa fa-download"></i> Download PDF
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="row g-3">
            <div class="col-6 col-xl-3">
                <div class="tile">
                    <div class="tile-icon"><i class="fa fa-coins"></i></div>
                    <div class="tile-label">Gross</div>
                    <div class="tile-value tabular"><?= e(money($yearToDate['gross'])) ?></div>
                    <div class="tile-hint"><?= e($financialYear) ?> to date</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="tile tile-warning">
                    <div class="tile-icon"><i class="fa fa-minus-circle"></i></div>
                    <div class="tile-label">Deductions</div>
                    <div class="tile-value tabular"><?= e(money($yearToDate['deductions'])) ?></div>
                    <div class="tile-hint">Withheld from gross</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="tile tile-success">
                    <div class="tile-icon"><i class="fa fa-wallet"></i></div>
                    <div class="tile-label">Net paid</div>
                    <div class="tile-value tabular"><?= e(money($yearToDate['net'])) ?></div>
                    <div class="tile-hint">
                        Across <?= e((string) $yearToDate['months']) ?>
                        month<?= $yearToDate['months'] === 1 ? '' : 's' ?>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="tile tile-info">
                    <div class="tile-icon"><i class="fa fa-landmark"></i></div>
                    <div class="tile-label">Tax paid</div>
                    <div class="tile-value tabular"><?= e(money($yearToDate['tax'])) ?></div>
                    <div class="tile-hint">Deducted at source</div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fa fa-chart-column"></i> Net pay by month</span>
                        <span class="small text-muted"><?= e($financialYear) ?></span>
                    </div>
                    <div class="card-body">
                        <div data-chart='<?= ejs($netChart) ?>'></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header"><i class="fa fa-clock-rotate-left"></i> Payslip history</div>

    <?php if ($payslips === []): ?>
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-file-invoice-dollar',
                'title' => 'Nothing to show yet',
                'message' => 'Published payslips are listed here, newest first.',
            ]) ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th class="text-end">Gross</th>
                        <th class="text-end">Deductions</th>
                        <th class="text-end">Tax</th>
                        <th class="text-end">Net</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payslips as $slip): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($slip['period_label'] ?? '—') ?></td>
                            <td class="text-end tabular"><?= e(money($slip['gross_minor'] ?? 0)) ?></td>
                            <td class="text-end tabular"><?= e(money($slip['total_deductions_minor'] ?? 0)) ?></td>
                            <td class="text-end tabular"><?= e(money($slip['tax_minor'] ?? 0)) ?></td>
                            <td class="text-end tabular fw-semibold"><?= e(money($slip['net_minor'] ?? 0)) ?></td>
                            <td>
                                <?php if (!empty($slip['published_at'])): ?>
                                    <span class="small text-muted">
                                        <?= e(date_display($slip['published_at'])) ?>
                                    </span>
                                <?php else: ?>
                                    <?= badge('draft') ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="/payroll/payslips/<?= e($slip['id'] ?? '') ?>"
                                   class="btn btn-sm btn-outline-secondary" title="View payslip">
                                    <i class="fa fa-eye"></i>
                                </a>
                                <a href="/payroll/payslips/<?= e($slip['id'] ?? '') ?>/download"
                                   class="btn btn-sm btn-outline-secondary" title="Download PDF">
                                    <i class="fa fa-download"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-body pt-0">
            <?php View::partial('pagination', ['meta' => $meta]) ?>
        </div>
    <?php endif; ?>
</div>
