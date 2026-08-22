<?php
/**
 * Everything waiting on this person's signature, in one queue.
 *
 * A section is not rendered at all when the caller does not hold the
 * permission behind it, and it collapses away when there is nothing in it, so
 * the page is never a wall of things somebody cannot act on. Each service
 * still checks for itself that this particular record was theirs to decide.
 *
 * @var list<array<string, mixed>> $leave           Pending leave requests.
 * @var list<array<string, mixed>> $corrections     Pending attendance corrections.
 * @var list<array<string, mixed>> $expenses        Submitted expense claims.
 * @var bool                       $seesLeave
 * @var bool                       $seesCorrections
 * @var bool                       $seesExpenses
 * @var array<string, string>      $names
 * @var int                        $leaveTotal       Waiting, which may exceed the rows sent.
 * @var int                        $correctionsTotal
 * @var int                        $expensesTotal
 * @var int                        $total
 */

use App\Core\Csrf;
use App\Core\View;

$hex = static fn (mixed $value): string => is_string($value)
    && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1
        ? strtoupper($value)
        : '#64748B';

$days = static fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';

$who = static fn (string $id): string => $names[$id] ?? 'Unnamed employee';

/** Says so when a section is showing only the first page of a longer queue. */
$remainder = static function (int $waiting, int $shown): string {
    if ($waiting <= $shown) {
        return '';
    }

    return sprintf(
        '<span class="small text-muted ms-auto">Showing %d of %d &middot; decide these to see the rest</span>',
        $shown,
        $waiting
    );
};

View::partial('page-header', [
    'title' => 'Approvals',
    'subtitle' => $total === 0
        ? 'Nothing is waiting on you.'
        : $total . ' item' . ($total === 1 ? '' : 's') . ' waiting for your decision.',
    'actions' => '<a href="/approvals/delegations" class="btn btn-outline-secondary btn-sm">'
        . '<i class="fa fa-user-friends"></i> Delegate my approvals</a>',
]);
?>

<?php if ($total === 0): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-check-double',
                'title' => 'Your queue is clear',
                'message' => 'Nothing is waiting for your decision right now. Anything routed to you will appear here, '
                    . 'including work handed over by a colleague who delegated their queue.',
                'actionLabel' => 'Delegate my approvals',
                'actionHref' => '/approvals/delegations',
            ]) ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($seesLeave && $leave !== []): ?>
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="fa fa-umbrella-beach text-primary"></i>
            <strong>Leave requests</strong>
            <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                <?= e((string) $leaveTotal) ?>
            </span>
            <?= $remainder($leaveTotal, count($leave)) ?>
        </div>
        <div class="card-body p-0">
            <?php foreach ($leave as $request): ?>
                <?php
                if (!is_array($request)) {
                    continue;
                }

                $id = (string) ($request['id'] ?? '');
                $employee = (string) ($request['employee_id'] ?? '');
                $type = is_array($request['leave_type'] ?? null) ? $request['leave_type'] : [];
                $colour = $hex($type['colour'] ?? null);

                $dayCount = (float) ($request['day_count'] ?? 0);
                $ifApproved = (float) ($request['available_days'] ?? 0);
                $ifRejected = $ifApproved + $dayCount;
                $delegatedFrom = (string) ($request['delegated_from'] ?? '');
                $reason = trim((string) ($request['reason'] ?? ''));
                ?>
                <div class="border-bottom p-3">
                    <div class="row g-3">
                        <div class="col-lg-7">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="avatar avatar-sm"><?= e(initials($who($employee))) ?></span>
                                <div style="min-width: 0;">
                                    <a class="fw-semibold text-decoration-none" href="/leave/<?= e(urlencode($id)) ?>">
                                        <?= e($who($employee)) ?>
                                    </a>
                                    <div class="small text-muted">
                                        Applied <?= e(relative_time($request['applied_at'] ?? null)) ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($delegatedFrom !== ''): ?>
                                <div class="small text-muted mb-2">
                                    <i class="fa fa-user-friends"></i>
                                    You are standing in for <?= e($who($delegatedFrom)) ?> on this one.
                                </div>
                            <?php endif; ?>

                            <div class="stat-row">
                                <span class="stat-key">Leave type</span>
                                <span class="stat-val">
                                    <span class="d-inline-block rounded-circle align-middle me-1"
                                          style="width: 9px; height: 9px; background: <?= e($colour) ?>;"></span>
                                    <?= e($type['name'] ?? 'Leave') ?>
                                    <?php if (($type['is_paid'] ?? true) === false): ?>
                                        <span class="text-muted fw-normal">(unpaid)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-key">Dates</span>
                                <span class="stat-val tabular">
                                    <?= e(date_display($request['starts_on'] ?? null)) ?>
                                    <?php if ((string) ($request['ends_on'] ?? '') !== (string) ($request['starts_on'] ?? '')): ?>
                                        &ndash; <?= e(date_display($request['ends_on'] ?? null)) ?>
                                    <?php endif; ?>
                                    <?php if (($request['is_half_day'] ?? false) === true): ?>
                                        <span class="text-muted fw-normal">(<?= e(label($request['half_day_period'] ?? 'half day')) ?>)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-key">Working days</span>
                                <span class="stat-val tabular"><?= e($days($dayCount)) ?></span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-key">Balance if you approve</span>
                                <span class="stat-val tabular <?= $ifApproved < 0 ? 'text-danger' : '' ?>">
                                    <?= e($days($ifApproved)) ?> days
                                    <span class="text-muted fw-normal">(<?= e($days($ifRejected)) ?> if you turn it down)</span>
                                </span>
                            </div>

                            <div class="section-label mt-3">Their reason</div>
                            <p class="small mb-0">
                                <?= $reason === ''
                                    ? '<span class="text-muted">No reason was given.</span>'
                                    : nl2br(e($reason)) ?>
                            </p>
                        </div>

                        <div class="col-lg-5 divider-y">
                            <form method="post" action="/approvals/leave/<?= e(urlencode($id)) ?>" novalidate>
                                <?= Csrf::field() ?>

                                <label class="form-label" for="leave-note-<?= e($id) ?>">
                                    Note <span class="text-muted fw-normal">(required to reject)</span>
                                </label>
                                <textarea class="form-control" rows="3" maxlength="500"
                                          id="leave-note-<?= e($id) ?>" name="note"
                                          placeholder="What <?= e($who($employee)) ?> should know."></textarea>
                                <div class="form-text mb-2" data-counter-for="leave-note-<?= e($id) ?>"></div>

                                <div class="d-flex flex-wrap gap-2">
                                    <button type="submit" name="decision" value="approve"
                                            class="btn btn-success btn-sm" data-busy-label="Approving...">
                                        <i class="fa fa-check"></i> Approve
                                    </button>
                                    <button type="submit" name="decision" value="reject"
                                            class="btn btn-outline-danger btn-sm" data-busy-label="Rejecting..."
                                            data-confirm="Reject this leave request?">
                                        <i class="fa fa-times"></i> Reject
                                    </button>
                                    <a class="btn btn-link btn-sm" href="/leave/<?= e(urlencode($id)) ?>">Full detail</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($seesCorrections && $corrections !== []): ?>
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="fa fa-clock text-warning"></i>
            <strong>Attendance corrections</strong>
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                <?= e((string) $correctionsTotal) ?>
            </span>
            <?= $remainder($correctionsTotal, count($corrections)) ?>
        </div>
        <div class="card-body p-0">
            <?php foreach ($corrections as $correction): ?>
                <?php
                if (!is_array($correction)) {
                    continue;
                }

                $id = (string) ($correction['id'] ?? '');
                $employee = (string) ($correction['employee_id'] ?? '');
                $reason = trim((string) ($correction['reason'] ?? ''));

                $claims = [];

                if (!empty($correction['requested_check_in'])) {
                    $claims[] = 'started at ' . time_display($correction['requested_check_in']);
                }

                if (!empty($correction['requested_check_out'])) {
                    $claims[] = 'finished at ' . time_display($correction['requested_check_out']);
                }

                if (!empty($correction['requested_status'])) {
                    $claims[] = 'the day should read ' . label($correction['requested_status']);
                }
                ?>
                <div class="border-bottom p-3">
                    <div class="row g-3">
                        <div class="col-lg-7">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="avatar avatar-sm"><?= e(initials($who($employee))) ?></span>
                                <div style="min-width: 0;">
                                    <span class="fw-semibold"><?= e($who($employee)) ?></span>
                                    <div class="small text-muted">
                                        Raised <?= e(relative_time($correction['created_at'] ?? null)) ?>
                                    </div>
                                </div>
                            </div>

                            <div class="stat-row">
                                <span class="stat-key">Day being corrected</span>
                                <span class="stat-val tabular"><?= e(date_display($correction['work_date'] ?? null)) ?></span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-key">What they are claiming</span>
                                <span class="stat-val">
                                    <?= $claims === []
                                        ? '<span class="text-muted fw-normal">Nothing specific was asked for</span>'
                                        : e(ucfirst(implode(', ', $claims))) ?>
                                </span>
                            </div>

                            <div class="section-label mt-3">Their reason</div>
                            <p class="small mb-0">
                                <?= $reason === ''
                                    ? '<span class="text-muted">No reason was given.</span>'
                                    : nl2br(e($reason)) ?>
                            </p>
                        </div>

                        <div class="col-lg-5 divider-y">
                            <form method="post" action="/approvals/regularisation/<?= e(urlencode($id)) ?>" novalidate>
                                <?= Csrf::field() ?>

                                <label class="form-label" for="fix-note-<?= e($id) ?>">
                                    Note <span class="text-muted fw-normal">(required to reject)</span>
                                </label>
                                <textarea class="form-control" rows="3" maxlength="1000"
                                          id="fix-note-<?= e($id) ?>" name="note"
                                          placeholder="Why this correction is or is not accepted."></textarea>
                                <div class="form-text mb-2" data-counter-for="fix-note-<?= e($id) ?>"></div>

                                <div class="d-flex flex-wrap gap-2">
                                    <button type="submit" name="decision" value="approve"
                                            class="btn btn-success btn-sm" data-busy-label="Approving..."
                                            data-confirm="Approve this correction? The attendance record for that day will be rewritten.">
                                        <i class="fa fa-check"></i> Approve
                                    </button>
                                    <button type="submit" name="decision" value="reject"
                                            class="btn btn-outline-danger btn-sm" data-busy-label="Rejecting..."
                                            data-confirm="Reject this correction?">
                                        <i class="fa fa-times"></i> Reject
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($seesExpenses && $expenses !== []): ?>
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="fa fa-receipt text-info"></i>
            <strong>Expense claims</strong>
            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                <?= e((string) $expensesTotal) ?>
            </span>
            <?= $remainder($expensesTotal, count($expenses)) ?>
        </div>
        <div class="card-body p-0">
            <?php foreach ($expenses as $claim): ?>
                <?php
                if (!is_array($claim)) {
                    continue;
                }

                $id = (string) ($claim['id'] ?? '');
                $employee = (string) ($claim['employee_id'] ?? '');
                $description = trim((string) ($claim['description'] ?? ''));
                ?>
                <div class="border-bottom p-3">
                    <div class="row g-3">
                        <div class="col-lg-7">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="avatar avatar-sm"><?= e(initials($who($employee))) ?></span>
                                <div style="min-width: 0;">
                                    <span class="fw-semibold"><?= e($who($employee)) ?></span>
                                    <div class="small text-muted">
                                        Claim <?= e($claim['claim_number'] ?? '—') ?>
                                        &middot; submitted <?= e(relative_time($claim['created_at'] ?? null)) ?>
                                    </div>
                                </div>
                            </div>

                            <div class="stat-row">
                                <span class="stat-key">Category</span>
                                <span class="stat-val"><?= e(label($claim['category'] ?? null)) ?></span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-key">Amount</span>
                                <span class="stat-val tabular">
                                    <?= e(money($claim['amount_minor'] ?? 0)) ?>
                                    <span class="text-muted fw-normal"><?= e($claim['currency'] ?? '') ?></span>
                                </span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-key">Incurred on</span>
                                <span class="stat-val tabular"><?= e(date_display($claim['incurred_on'] ?? null)) ?></span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-key">Receipt</span>
                                <span class="stat-val">
                                    <?= empty($claim['receipt_document_id'])
                                        ? '<span class="text-danger fw-normal">None attached</span>'
                                        : '<i class="fa fa-paperclip"></i> Attached' ?>
                                </span>
                            </div>

                            <div class="section-label mt-3"><?= field($claim, 'title', 'Untitled claim') ?></div>
                            <p class="small mb-0">
                                <?= $description === ''
                                    ? '<span class="text-muted">No description was given.</span>'
                                    : nl2br(e($description)) ?>
                            </p>
                        </div>

                        <div class="col-lg-5 divider-y">
                            <form method="post" action="/approvals/expense/<?= e(urlencode($id)) ?>" novalidate>
                                <?= Csrf::field() ?>

                                <label class="form-label" for="claim-note-<?= e($id) ?>">
                                    Note <span class="text-muted fw-normal">(required to reject)</span>
                                </label>
                                <textarea class="form-control" rows="3" maxlength="1000"
                                          id="claim-note-<?= e($id) ?>" name="note"
                                          placeholder="What the claimant should know about this decision."></textarea>
                                <div class="form-text mb-2" data-counter-for="claim-note-<?= e($id) ?>"></div>

                                <div class="d-flex flex-wrap gap-2">
                                    <button type="submit" name="decision" value="approve"
                                            class="btn btn-success btn-sm" data-busy-label="Approving...">
                                        <i class="fa fa-check"></i> Approve
                                    </button>
                                    <button type="submit" name="decision" value="reject"
                                            class="btn btn-outline-danger btn-sm" data-busy-label="Rejecting..."
                                            data-confirm="Reject this expense claim?">
                                        <i class="fa fa-times"></i> Reject
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($total > 0): ?>
    <p class="small text-muted">
        <i class="fa fa-shield-alt"></i>
        You cannot decide your own request, whatever else you are entitled to approve, and each service
        checks again that a record was routed to you before it records your decision.
    </p>
<?php endif; ?>
