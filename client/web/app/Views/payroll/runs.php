<?php
/**
 * Every payroll cycle the company has opened.
 *
 * @var list<array<string, mixed>> $runs
 * @var array<string, int>         $meta
 * @var string                     $status        The status filter in force.
 * @var list<string>               $statuses
 * @var array<string, string>      $userNames     Account id to display name.
 * @var string                     $currentPeriod Today's month, as YYYY-MM.
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;

$mayRun = Session::can(Permissions::PAYROLL_RUN);
?>

<?php View::partial('page-header', [
    'title' => 'Payroll runs',
    'subtitle' => 'One run per month, from draft through to published payslips.',
    'actions' => '<a href="/payroll" class="btn btn-outline-secondary">'
        . '<i class="fa fa-arrow-left"></i> My payroll</a>',
]) ?>

<div class="row g-4">

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="fa fa-list-check"></i> All runs</span>
                <form method="get" action="/payroll/runs" class="d-flex align-items-center gap-2 m-0">
                    <label for="status" class="form-label m-0 small text-muted">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm"
                            data-submit-on-change style="width: auto;">
                        <option value="">All</option>
                        <?php foreach ($statuses as $option): ?>
                            <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>>
                                <?= e(label($option)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if ($runs === []): ?>
                <div class="card-body">
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-calendar-plus',
                        'title' => $status === '' ? 'No payroll runs yet' : 'No runs with that status',
                        'message' => $status === ''
                            ? 'Open a run for a month to calculate that month\'s payslips.'
                            : 'Try clearing the filter to see the other runs.',
                    ]) ?>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Status</th>
                                <th class="text-end">Employees</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end">Deductions</th>
                                <th class="text-end">Net</th>
                                <th>Processed by</th>
                                <th>Approved by</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($runs as $run): ?>
                                <?php
                                $processedBy = (string) ($run['processed_by'] ?? '');
                                $approvedBy = (string) ($run['approved_by'] ?? '');
                                ?>
                                <tr>
                                    <td>
                                        <a href="/payroll/runs/<?= e($run['id'] ?? '') ?>" class="fw-semibold">
                                            <?= e($run['period_label'] ?? ($run['period'] ?? '—')) ?>
                                        </a>
                                        <div class="small text-muted truncate"><?= field($run, 'run_label', '') ?></div>
                                    </td>
                                    <td><?= badge($run['status'] ?? null) ?></td>
                                    <td class="text-end tabular"><?= e((string) ($run['employee_count'] ?? 0)) ?></td>
                                    <td class="text-end tabular"><?= e(money($run['total_gross_minor'] ?? 0)) ?></td>
                                    <td class="text-end tabular"><?= e(money($run['total_deductions_minor'] ?? 0)) ?></td>
                                    <td class="text-end tabular fw-semibold"><?= e(money($run['total_net_minor'] ?? 0)) ?></td>
                                    <td class="small">
                                        <?php if ($processedBy === ''): ?>
                                            <span class="text-muted">—</span>
                                        <?php else: ?>
                                            <div><?= e($userNames[$processedBy] ?? 'An account') ?></div>
                                            <div class="text-muted"><?= e(date_display($run['processed_at'] ?? null)) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?php if ($approvedBy === ''): ?>
                                            <span class="text-muted">—</span>
                                        <?php else: ?>
                                            <div><?= e($userNames[$approvedBy] ?? 'An account') ?></div>
                                            <div class="text-muted"><?= e(date_display($run['approved_at'] ?? null)) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="/payroll/runs/<?= e($run['id'] ?? '') ?>"
                                           class="btn btn-sm btn-outline-secondary">
                                            <i class="fa fa-arrow-right"></i>
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
    </div>

    <div class="col-lg-4">
        <?php if ($mayRun): ?>
            <div class="card">
                <div class="card-header"><i class="fa fa-calendar-plus"></i> New run</div>
                <form method="post" action="/payroll/runs" class="card-body">
                    <?= Csrf::field() ?>

                    <div class="mb-3">
                        <label for="period" class="form-label">Pay period</label>
                        <input type="month"
                               class="form-control <?= Flash::hasError('period') ? 'is-invalid' : '' ?>"
                               id="period"
                               name="period"
                               value="<?= e(Flash::old('period', $currentPeriod)) ?>"
                               max="<?= e($currentPeriod) ?>"
                               required>
                        <?php View::partial('field-errors', ['name' => 'period']) ?>
                        <div class="form-text">A run cannot be opened for a month that has not started.</div>
                    </div>

                    <div class="mb-3">
                        <label for="run_label" class="form-label">Label <span class="text-muted">(optional)</span></label>
                        <input type="text"
                               class="form-control <?= Flash::hasError('run_label') ? 'is-invalid' : '' ?>"
                               id="run_label"
                               name="run_label"
                               maxlength="120"
                               value="<?= e(Flash::old('run_label')) ?>"
                               placeholder="April 2026 payroll">
                        <?php View::partial('field-errors', ['name' => 'run_label']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes <span class="text-muted">(optional)</span></label>
                        <textarea class="form-control <?= Flash::hasError('notes') ? 'is-invalid' : '' ?>"
                                  id="notes"
                                  name="notes"
                                  rows="3"
                                  maxlength="1000"
                                  placeholder="Anything the approver should know."><?= e(Flash::old('notes')) ?></textarea>
                        <div class="form-text" data-counter-for="notes"></div>
                        <?php View::partial('field-errors', ['name' => 'notes']) ?>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" data-busy-label="Opening...">
                        <i class="fa fa-plus"></i> Open run
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <div class="card mt-4">
            <div class="card-header"><i class="fa fa-route"></i> How a run moves</div>
            <div class="card-body small">
                <div class="timeline">
                    <div class="timeline-item">
                        <div class="fw-semibold">Draft</div>
                        <div class="text-muted">The month is open. Nothing has been calculated.</div>
                    </div>
                    <div class="timeline-item">
                        <div class="fw-semibold">Processing</div>
                        <div class="text-muted">
                            Payslips are calculated from each salary structure and that month's attendance.
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="fw-semibold">Approved</div>
                        <div class="text-muted">
                            Signed off by somebody other than whoever processed it.
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="fw-semibold">Paid</div>
                        <div class="text-muted">Payslips are published and visible to employees.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
