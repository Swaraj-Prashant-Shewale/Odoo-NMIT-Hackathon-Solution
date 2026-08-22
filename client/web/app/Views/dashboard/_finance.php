<?php
/**
 * What the workforce costs, for whoever signs it off.
 *
 * @var array<string, array{available: bool, data: mixed}> $sections
 * @var array<string, array<string, mixed>> $charts
 * @var list<string> $financeKeys
 * @var callable $data
 * @var callable $offline
 * @var callable $rows
 * @var callable $anyOf
 * @var callable $placeholder
 */

use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;

if (!$anyOf($financeKeys)) {
    return;
}
?>

<div class="section-label mt-4">Payroll and cost</div>

<div class="row g-4 mb-4">
    <?php if (isset($sections['payroll_cost_trend'])): ?>
        <div class="col-lg-8">
            <?php if ($offline('payroll_cost_trend') || !isset($charts['payroll_cost'])): ?>
                <?php $placeholder('Payroll cost', 'The payroll service did not answer this time.') ?>
            <?php else: ?>
                <?php
                $costTrend = $data('payroll_cost_trend');
                $costMonths = $rows($costTrend['months'] ?? null);

                // The last bucket in the series is always the month containing
                // today, and payroll for the current month has not been run
                // yet - so reading the last element made this footer say
                // "Gross 0.00 / Deductions 0.00 / Net 0.00" every time. The
                // last month that actually has a run is the one to report.
                $latestMonth = [];
                foreach ($costMonths as $month) {
                    if ((int) ($month['employee_count'] ?? 0) > 0 || (int) ($month['net_minor'] ?? 0) > 0) {
                        $latestMonth = $month;
                    }
                }
                ?>
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-chart-bar"></i> Net payroll, last six months</div>
                    <div class="card-body">
                        <div data-chart='<?= ejs($charts['payroll_cost']) ?>'></div>
                    </div>
                    <?php if ($latestMonth !== []): ?>
                        <div class="card-footer">
                            <div class="row g-2 text-center">
                                <div class="col-4">
                                    <div class="tile-label">Gross</div>
                                    <div class="fw-semibold tabular"><?= e(money($latestMonth['gross_minor'] ?? 0)) ?></div>
                                </div>
                                <div class="col-4">
                                    <div class="tile-label">Deductions</div>
                                    <div class="fw-semibold tabular"><?= e(money($latestMonth['deductions_minor'] ?? 0)) ?></div>
                                </div>
                                <div class="col-4">
                                    <div class="tile-label">Net, <?= e((string) ($latestMonth['label'] ?? '')) ?></div>
                                    <div class="fw-semibold tabular"><?= e(money($latestMonth['net_minor'] ?? 0)) ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($sections['expense_claims'])): ?>
        <div class="col-lg-4">
            <?php if ($offline('expense_claims')): ?>
                <?php $placeholder('Expense claims', 'The expense queue did not answer this time.') ?>
            <?php else: ?>
                <?php
                $claims = $data('expense_claims');
                $claimCount = (int) ($claims['pending_count'] ?? 0);
                ?>
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-receipt"></i> Expense claims</div>
                    <div class="card-body">
                        <div class="tile-value tabular <?= $claimCount > 0 ? 'text-warning' : '' ?>">
                            <?= e((string) $claimCount) ?>
                        </div>
                        <div class="tile-hint mb-3">
                            <?= $claimCount === 1 ? 'claim submitted and unpaid' : 'claims submitted and unpaid' ?>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Value outstanding</span>
                            <span class="stat-val tabular"><?= e(money($claims['pending_value_minor'] ?? 0)) ?></span>
                        </div>
                    </div>
                    <div class="card-footer">
                        <a href="/expenses">All expense claims</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($sections['payroll_cost_by_department'])): ?>
    <?php if ($offline('payroll_cost_by_department')): ?>
        <div class="mb-4"><?php $placeholder('Cost by department', 'The last payroll run could not be read this time.') ?></div>
    <?php else: ?>
        <?php
        $byDepartment = $data('payroll_cost_by_department');
        $departments = $rows($byDepartment['departments'] ?? null);
        $runPeriod = (string) ($byDepartment['period'] ?? '');
        $runId = (string) ($byDepartment['run_id'] ?? '');
        ?>
        <div class="card mb-4">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>
                    <i class="fa fa-balance-scale"></i> Cost by department
                    <?php if ($runPeriod !== ''): ?>
                        <span class="text-muted">· <?= e($runPeriod) ?></span>
                    <?php endif; ?>
                </span>
                <?php if ($runId !== '' && Session::can(Permissions::PAYROLL_VIEW_ALL)): ?>
                    <a class="small" href="/payroll/runs/<?= e($runId) ?>">Open the run</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($departments === []): ?>
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-balance-scale',
                        'title' => 'No department costs to show',
                        'message' => 'The latest approved payroll run has not been broken down by department.',
                    ]) ?>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th scope="col">Department</th>
                                    <th scope="col" class="text-end">Employees</th>
                                    <th scope="col" class="text-end">Net cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($departments as $department): ?>
                                    <tr>
                                        <td class="cell-truncate"><?= e((string) ($department['department'] ?? 'Unassigned')) ?></td>
                                        <td class="text-end tabular"><?= e((string) (int) ($department['employee_count'] ?? 0)) ?></td>
                                        <td class="text-end tabular"><?= e(money($department['net_minor'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
