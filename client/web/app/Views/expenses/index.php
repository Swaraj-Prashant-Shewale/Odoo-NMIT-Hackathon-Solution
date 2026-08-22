<?php
/**
 * Expense claims: the caller's own, or every claim for whoever settles them.
 *
 * @var list<array<string, mixed>>              $claims
 * @var array{submitted: int, approved: int, reimbursed: int, pending: int, count: int} $totals
 * @var bool                                    $truncated     True when the listing hit its ceiling.
 * @var bool                                    $seesEveryone
 * @var bool                                    $mayReimburse
 * @var array<string, array<string, mixed>>     $employees     People records, keyed by employee id.
 * @var list<array<string, mixed>>              $departments
 * @var string                                  $departmentId
 * @var string                                  $status
 * @var string                                  $category
 * @var string                                  $search
 * @var list<string>                            $categories
 * @var list<string>                            $statuses
 */

use App\Core\Csrf;
use App\Core\View;
?>

<?php View::partial('page-header', [
    'title' => $seesEveryone ? 'Expense claims' : 'My expense claims',
    'subtitle' => $seesEveryone
        ? 'Everything claimed across the company, and what still has to be paid back.'
        : 'What you have claimed back, and where each claim has got to.',
    'actions' => '<a href="/expenses/new" class="btn btn-primary">'
        . '<i class="fa fa-plus"></i> New claim</a>',
]) ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="tile">
            <div class="tile-icon"><i class="fa fa-receipt"></i></div>
            <div class="tile-label">Total submitted</div>
            <div class="tile-value tabular"><?= e(money($totals['submitted'])) ?></div>
            <div class="tile-hint">
                Across <?= e((string) $totals['count']) ?> claim<?= $totals['count'] === 1 ? '' : 's' ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tile tile-success">
            <div class="tile-icon"><i class="fa fa-check"></i></div>
            <div class="tile-label">Approved</div>
            <div class="tile-value tabular"><?= e(money($totals['approved'])) ?></div>
            <div class="tile-hint">Waiting to be paid back</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tile tile-info">
            <div class="tile-icon"><i class="fa fa-money-bill-transfer"></i></div>
            <div class="tile-label">Reimbursed</div>
            <div class="tile-value tabular"><?= e(money($totals['reimbursed'])) ?></div>
            <div class="tile-hint">Settled</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tile tile-warning">
            <div class="tile-icon"><i class="fa fa-hourglass-half"></i></div>
            <div class="tile-label">Pending</div>
            <div class="tile-value tabular"><?= e(money($totals['pending'])) ?></div>
            <div class="tile-hint">Awaiting a decision</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <form method="get" action="/expenses" class="row g-2 align-items-end m-0">
            <div class="col-6 col-lg-3">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select form-select-sm" data-submit-on-change>
                    <option value="">All statuses</option>
                    <?php foreach ($statuses as $option): ?>
                        <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>>
                            <?= e(label($option)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-lg-3">
                <label for="category" class="form-label">Category</label>
                <select id="category" name="category" class="form-select form-select-sm" data-submit-on-change>
                    <option value="">All categories</option>
                    <?php foreach ($categories as $option): ?>
                        <option value="<?= e($option) ?>" <?= $category === $option ? 'selected' : '' ?>>
                            <?= e(label($option)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($seesEveryone): ?>
                <div class="col-6 col-lg-3">
                    <label for="department_id" class="form-label">Department</label>
                    <select id="department_id" name="department_id" class="form-select form-select-sm"
                            data-submit-on-change>
                        <option value="">All departments</option>
                        <?php foreach ($departments as $department): ?>
                            <?php $id = (string) ($department['id'] ?? ''); ?>
                            <option value="<?= e($id) ?>" <?= $departmentId === $id ? 'selected' : '' ?>>
                                <?= field($department, 'name') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="col-6 col-lg-<?= $seesEveryone ? '3' : '6' ?>">
                <label for="search" class="form-label">Search</label>
                <div class="input-group input-group-sm">
                    <input type="search" class="form-control" id="search" name="search"
                           maxlength="120" value="<?= e($search) ?>"
                           placeholder="Title or claim number">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="fa fa-magnifying-glass"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <?php if ($truncated): ?>
        <div class="card-body pb-0">
            <div class="alert alert-info mb-0">
                <i class="fa fa-circle-info"></i>
                Only the most recent claims are shown. Narrow the filters to reach the older ones.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($claims === []): ?>
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-receipt',
                'title' => $seesEveryone ? 'No claims match' : 'No claims yet',
                'message' => $seesEveryone
                    ? 'Nothing has been claimed that matches these filters.'
                    : 'Money you spend on the company\'s behalf can be claimed back here.',
                'actionLabel' => $seesEveryone ? null : 'New claim',
                'actionHref' => $seesEveryone ? null : '/expenses/new',
            ]) ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Claim</th>
                        <?php if ($seesEveryone): ?>
                            <th>Claimed by</th>
                        <?php endif; ?>
                        <th>Category</th>
                        <th>Incurred</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Decision</th>
                        <?php if ($mayReimburse): ?>
                            <th style="min-width: 15rem;">Reimburse</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($claims as $claim): ?>
                        <?php
                        $claimId = (string) ($claim['id'] ?? '');
                        $employeeId = (string) ($claim['employee_id'] ?? '');
                        $claimant = $employees[$employeeId] ?? [];
                        $claimStatus = (string) ($claim['status'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold truncate"><?= field($claim, 'title') ?></div>
                                <div class="small text-muted tabular"><?= field($claim, 'claim_number', '') ?></div>
                            </td>

                            <?php if ($seesEveryone): ?>
                                <td>
                                    <?php if ($claimant === []): ?>
                                        <span class="text-muted small">Record unavailable</span>
                                    <?php else: ?>
                                        <div class="truncate"><?= field($claimant, 'full_name') ?></div>
                                        <div class="small text-muted truncate">
                                            <?= field($claimant, 'department_name', 'No department') ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>

                            <td><?= e(label((string) ($claim['category'] ?? ''))) ?></td>
                            <td><?= e(date_display($claim['incurred_on'] ?? null)) ?></td>
                            <td class="text-end tabular fw-semibold"><?= e(money($claim['amount_minor'] ?? 0)) ?></td>
                            <td><?= badge($claimStatus) ?></td>
                            <td class="small">
                                <?php if (!empty($claim['decision_note'])): ?>
                                    <div class="truncate" title="<?= e($claim['decision_note']) ?>">
                                        <?= e($claim['decision_note']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($claim['reimbursed_reference'])): ?>
                                    <div class="text-muted tabular">
                                        Ref <?= e($claim['reimbursed_reference']) ?>
                                    </div>
                                <?php elseif (empty($claim['decision_note'])): ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <?php if ($mayReimburse): ?>
                                <td>
                                    <?php if ($claimStatus === 'approved'): ?>
                                        <form method="post" action="/expenses/<?= e($claimId) ?>/reimburse"
                                              class="input-group input-group-sm m-0">
                                            <?= Csrf::field() ?>
                                            <input type="text"
                                                   class="form-control"
                                                   name="reference"
                                                   required
                                                   maxlength="64"
                                                   pattern="[A-Za-z0-9/\-]{4,64}"
                                                   placeholder="Payment reference"
                                                   aria-label="Payment reference for claim <?= field($claim, 'claim_number', '') ?>">
                                            <button type="submit" class="btn btn-outline-success"
                                                    data-busy-label="Saving..."
                                                    data-confirm="Mark this claim as reimbursed? It cannot be undone.">
                                                <i class="fa fa-money-bill-transfer"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
