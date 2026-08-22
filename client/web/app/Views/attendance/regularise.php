<?php
/**
 * Ask for a day to be corrected, and see what happened to earlier requests.
 *
 * @var list<array<string, mixed>> $requests
 * @var array<string, mixed>       $meta
 * @var array<string, mixed>|null  $shift        The caller's shift today, for reference.
 * @var string                     $defaultDate  Prefilled from ?date=, or empty.
 * @var string                     $today
 * @var list<string>               $claimableStatuses
 * @var bool                       $canRequest
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$actions = '<a href="/attendance" class="btn btn-outline-secondary btn-sm">'
    . '<i class="fa fa-clock"></i> Back to my attendance</a>';
?>

<?php View::partial('page-header', [
    'title' => 'Request a correction',
    'subtitle' => 'For a day the clock missed, or got wrong.',
    'actions' => $actions,
]) ?>

<div class="row g-3">

    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header"><i class="fa fa-file-signature"></i> The day being corrected</div>
            <div class="card-body">

                <?php if (!$canRequest): ?>
                    <p class="text-muted small mb-0">
                        Your account cannot raise correction requests. Ask HR to fix a day that looks wrong.
                    </p>
                <?php else: ?>
                    <form method="post" action="/attendance/regularise" novalidate>
                        <?= Csrf::field() ?>

                        <div class="mb-3">
                            <label for="work_date" class="form-label">Date</label>
                            <input type="date"
                                   class="form-control <?= Flash::hasError('work_date') ? 'is-invalid' : '' ?>"
                                   id="work_date"
                                   name="work_date"
                                   max="<?= e($today) ?>"
                                   value="<?= e(Flash::old('work_date', $defaultDate)) ?>"
                                   required>
                            <?php View::partial('field-errors', ['name' => 'work_date']) ?>
                            <div class="form-text">Only a day that has already happened can be corrected.</div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="requested_check_in" class="form-label">Correct check-in</label>
                                <input type="time"
                                       class="form-control <?= Flash::hasError('requested_check_in') ? 'is-invalid' : '' ?>"
                                       id="requested_check_in"
                                       name="requested_check_in"
                                       value="<?= e(Flash::old('requested_check_in')) ?>">
                                <?php View::partial('field-errors', ['name' => 'requested_check_in']) ?>
                            </div>
                            <div class="col-6">
                                <label for="requested_check_out" class="form-label">Correct check-out</label>
                                <input type="time"
                                       class="form-control <?= Flash::hasError('requested_check_out') ? 'is-invalid' : '' ?>"
                                       id="requested_check_out"
                                       name="requested_check_out"
                                       value="<?= e(Flash::old('requested_check_out')) ?>">
                                <?php View::partial('field-errors', ['name' => 'requested_check_out']) ?>
                            </div>
                            <div class="col-12">
                                <div class="form-text">
                                    Both times are read as falling on the date above.
                                    <?php if ($shift !== null): ?>
                                        Your shift is
                                        <?= e($shift['starts_at'] ?? '') ?>&ndash;<?= e($shift['ends_at'] ?? '') ?>.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="requested_status" class="form-label">Status being claimed</label>
                            <select class="form-select <?= Flash::hasError('requested_status') ? 'is-invalid' : '' ?>"
                                    id="requested_status"
                                    name="requested_status">
                                <option value="">Leave the status as it is</option>
                                <?php foreach ($claimableStatuses as $option): ?>
                                    <option value="<?= e($option) ?>"
                                        <?= Flash::old('requested_status') === $option ? 'selected' : '' ?>>
                                        <?= e(label($option)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'requested_status']) ?>
                            <div class="form-text">Give a time, a status, or both — a request needs at least one.</div>
                        </div>

                        <div class="mb-3">
                            <label for="reason" class="form-label">Why the day needs correcting</label>
                            <textarea class="form-control <?= Flash::hasError('reason') ? 'is-invalid' : '' ?>"
                                      id="reason"
                                      name="reason"
                                      rows="4"
                                      minlength="10"
                                      maxlength="1000"
                                      placeholder="Forgot to check out after the client visit on site."
                                      required><?= e(Flash::old('reason')) ?></textarea>
                            <?php View::partial('field-errors', ['name' => 'reason']) ?>
                            <div class="d-flex justify-content-between">
                                <span class="form-text">Your approver reads this, so say what actually happened.</span>
                                <span class="form-text" data-counter-for="reason"></span>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100" data-busy-label="Sending...">
                            <i class="fa fa-paper-plane"></i> Send for approval
                        </button>
                    </form>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-history"></i> Your earlier requests</div>
            <div class="card-body">
                <?php if ($requests === []): ?>
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-inbox',
                        'title' => 'No corrections raised yet',
                        'message' => 'Anything you send for approval will appear here with its decision.',
                    ]) ?>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($requests as $request): ?>
                            <?php $status = (string) ($request['status'] ?? 'pending'); ?>
                            <div class="timeline-item <?= $status === 'pending' ? '' : 'is-muted' ?>">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                    <div>
                                        <div class="fw-semibold">
                                            <?= e(date_display($request['work_date'] ?? null)) ?>
                                        </div>
                                        <div class="small text-muted">
                                            Raised <?= e(relative_time($request['created_at'] ?? null)) ?>
                                        </div>
                                    </div>
                                    <?= badge($status) ?>
                                </div>

                                <div class="small mt-2">
                                    <span class="text-muted">Asked for</span>
                                    <?php if (!empty($request['requested_check_in'])): ?>
                                        in at <span class="tabular"><?= e(time_display($request['requested_check_in'])) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($request['requested_check_out'])): ?>
                                        <?= empty($request['requested_check_in']) ? '' : '&middot;' ?>
                                        out at <span class="tabular"><?= e(time_display($request['requested_check_out'])) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($request['requested_status'])): ?>
                                        <?= empty($request['requested_check_in']) && empty($request['requested_check_out']) ? '' : '&middot;' ?>
                                        status <?= badge((string) $request['requested_status']) ?>
                                    <?php endif; ?>
                                </div>

                                <div class="small text-muted mt-1">
                                    &ldquo;<?= e($request['reason'] ?? '') ?>&rdquo;
                                </div>

                                <?php if (!empty($request['decision_note'])): ?>
                                    <div class="small mt-2">
                                        <span class="section-label d-inline">Decision note</span>
                                        <span><?= e($request['decision_note']) ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($request['decided_at'])): ?>
                                    <div class="small text-muted mt-1">
                                        Decided <?= e(datetime_display($request['decided_at'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php View::partial('pagination', ['meta' => $meta]) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
