<?php
/**
 * One leave request in full, with everything that has happened to it.
 *
 * @var array<string, mixed>   $record
 * @var array<string, string>  $names     employee_id => display name.
 * @var list<array{icon: string, colour: string, title: string, who: string, at: ?string, note: ?string, muted: bool}> $timeline
 * @var bool                   $isMine
 * @var bool                   $canCancel
 * @var bool                   $canDecide
 */

use App\Core\Csrf;
use App\Core\View;

$hex = static fn (mixed $value): string => is_string($value)
    && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1
        ? strtoupper($value)
        : '#64748B';

$days = static fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';

$id = (string) ($record['id'] ?? '');
$type = is_array($record['leave_type'] ?? null) ? $record['leave_type'] : [];
$owner = (string) ($record['employee_id'] ?? '');
$approver = (string) ($record['approver_id'] ?? '');
$status = (string) ($record['status'] ?? '');
$startsOn = (string) ($record['starts_on'] ?? '');
$endsOn = (string) ($record['ends_on'] ?? '');

ob_start(); ?>
    <a href="/leave" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-arrow-left"></i> Back to time off
    </a>
    <?php if ($canCancel): ?>
        <form method="post" action="/leave/<?= e(urlencode($id)) ?>/cancel" class="m-0 d-inline">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-outline-danger btn-sm"
                    data-busy-label="Cancelling..."
                    data-confirm="Cancel this leave request? The days go back on your balance.">
                <i class="fa fa-times"></i> Cancel this request
            </button>
        </form>
    <?php endif; ?>
<?php
$actions = (string) ob_get_clean();

View::partial('page-header', [
    'title' => ($type['name'] ?? 'Leave') . ' · ' . date_display($startsOn),
    'subtitle' => $isMine
        ? 'Your request, and where it has got to.'
        : ($names[$owner] ?? 'An employee') . '\'s request.',
    'actions' => $actions,
]);
?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                <strong>The request</strong>
                <?= badge($status) ?>
            </div>
            <div class="card-body">

                <div class="stat-row">
                    <span class="stat-key">Employee</span>
                    <span class="stat-val d-flex align-items-center gap-2">
                        <span class="avatar avatar-sm"><?= e(initials($names[$owner] ?? '?')) ?></span>
                        <?= e($names[$owner] ?? 'Unnamed employee') ?>
                    </span>
                </div>

                <div class="stat-row">
                    <span class="stat-key">Leave type</span>
                    <span class="stat-val">
                        <span class="d-inline-block rounded-circle align-middle me-1"
                              style="width: 9px; height: 9px; background: <?= e($hex($type['colour'] ?? null)) ?>;"></span>
                        <?= e($type['name'] ?? 'Leave') ?>
                        <?php if (($type['is_paid'] ?? true) === false): ?>
                            <span class="text-muted fw-normal">(unpaid)</span>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="stat-row">
                    <span class="stat-key">Dates</span>
                    <span class="stat-val tabular">
                        <?= e(date_display($startsOn)) ?>
                        <?php if ($endsOn !== $startsOn): ?>
                            &ndash; <?= e(date_display($endsOn)) ?>
                        <?php endif; ?>
                    </span>
                </div>

                <?php if (($record['is_half_day'] ?? false) === true): ?>
                    <div class="stat-row">
                        <span class="stat-key">Half day</span>
                        <span class="stat-val"><?= e(label($record['half_day_period'] ?? null)) ?></span>
                    </div>
                <?php endif; ?>

                <div class="stat-row">
                    <span class="stat-key">Working days charged</span>
                    <span class="stat-val tabular"><?= e($days($record['day_count'] ?? 0)) ?></span>
                </div>

                <div class="stat-row">
                    <span class="stat-key">Holiday calendar applied</span>
                    <span class="stat-val">
                        <?= ($record['holiday_calendar_applied'] ?? false) === true
                            ? 'Yes, public holidays were excluded'
                            : 'No, weekends only' ?>
                    </span>
                </div>

                <div class="stat-row">
                    <span class="stat-key">Applied</span>
                    <span class="stat-val"><?= e(datetime_display($record['applied_at'] ?? null)) ?></span>
                </div>

                <div class="stat-row">
                    <span class="stat-key">Routed to</span>
                    <span class="stat-val">
                        <?= $approver === ''
                            ? '<span class="text-muted fw-normal">Nobody yet &mdash; HR will pick it up</span>'
                            : e($names[$approver] ?? 'Unnamed approver') ?>
                    </span>
                </div>

                <div class="stat-row">
                    <span class="stat-key">Contact while away</span>
                    <span class="stat-val"><?= field($record, 'contact_during_leave', 'Not given') ?></span>
                </div>

                <?php if (!empty($record['supporting_document_id'])): ?>
                    <div class="stat-row">
                        <span class="stat-key">Supporting document</span>
                        <span class="stat-val">
                            <?php if ($isMine): ?>
                                <a href="/profile/documents/<?= e(urlencode((string) $record['supporting_document_id'])) ?>/download">
                                    <i class="fa fa-paperclip"></i> Download
                                </a>
                            <?php else: ?>
                                <i class="fa fa-paperclip"></i> Attached by the employee
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php $reason = trim((string) ($record['reason'] ?? '')); ?>
                <div class="section-label mt-4">Reason given</div>
                <p class="mb-0">
                    <?php if ($reason === ''): ?>
                        <span class="text-muted">No reason was recorded.</span>
                    <?php else: ?>
                        <?= nl2br(e($reason)) ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php if ($canDecide): ?>
            <div class="card mt-4">
                <div class="card-header"><strong>Your decision</strong></div>
                <div class="card-body">
                    <form method="post" action="/approvals/leave/<?= e(urlencode($id)) ?>" novalidate>
                        <?= Csrf::field() ?>

                        <div class="mb-3">
                            <label for="note" class="form-label">
                                Note <span class="text-muted fw-normal">(required if you turn this down)</span>
                            </label>
                            <textarea class="form-control" id="note" name="note" rows="2" maxlength="500"
                                      placeholder="What <?= e($names[$owner] ?? 'the employee') ?> should know about this decision."></textarea>
                            <div class="form-text" data-counter-for="note"></div>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" name="decision" value="approve"
                                    class="btn btn-success" data-busy-label="Approving...">
                                <i class="fa fa-check"></i> Approve
                            </button>
                            <button type="submit" name="decision" value="reject"
                                    class="btn btn-outline-danger" data-busy-label="Rejecting..."
                                    data-confirm="Reject this leave request?">
                                <i class="fa fa-times"></i> Reject
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><strong>What has happened</strong></div>
            <div class="card-body">
                <div class="timeline">
                    <?php foreach ($timeline as $event): ?>
                        <div class="timeline-item <?= $event['muted'] ? 'is-muted' : '' ?>">
                            <div class="d-flex justify-content-between align-items-baseline gap-2">
                                <span class="fw-semibold">
                                    <i class="fa <?= e($event['icon']) ?> text-<?= e($event['colour']) ?>"></i>
                                    <?= e($event['title']) ?>
                                </span>
                                <span class="small text-muted flex-shrink-0" <?= $event['at'] === null ? '' : 'title="' . e(datetime_display($event['at'])) . '"' ?>>
                                    <?= $event['at'] === null ? 'Not yet' : e(relative_time($event['at'])) ?>
                                </span>
                            </div>
                            <div class="small text-muted"><?= e($event['who']) ?></div>
                            <?php if ($event['note'] !== null): ?>
                                <div class="small mt-1 border-start ps-2"><?= nl2br(e($event['note'])) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($status === 'pending'): ?>
                <div class="card-footer text-muted small">
                    <i class="fa fa-hourglass-half"></i>
                    The days are already held against your balance and are released if this is turned down.
                </div>
            <?php endif; ?>
        </div>

        <?php if ($isMine && !$canCancel && ($status === 'pending' || $status === 'approved')): ?>
            <div class="alert alert-info mt-4 small mb-0">
                <i class="fa fa-info-circle"></i>
                Approved leave can only be cancelled before it starts. Speak to HR if this needs to change.
            </div>
        <?php endif; ?>
    </div>
</div>
