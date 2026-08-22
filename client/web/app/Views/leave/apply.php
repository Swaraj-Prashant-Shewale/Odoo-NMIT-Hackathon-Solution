<?php
/**
 * Apply for time off.
 *
 * Deliberately two steps. The type and the dates are chosen first and travel
 * in the query string, so the page can come back showing the real balance for
 * that type and can offer the half-day option only when the range is a single
 * day. The reason and the contact number are posted rather than put in a URL:
 * why somebody is away is their business and does not belong in a browser
 * history or a proxy log.
 *
 * @var array<string, array<string, mixed>> $options          Leave types keyed by id.
 * @var array<string, mixed>|null           $selected         The chosen type, if any.
 * @var string                              $selectedType
 * @var string                              $startsOn
 * @var string                              $endsOn
 * @var bool                                $isHalfDay
 * @var string                              $halfDayPeriod
 * @var bool                                $rangeIsSingleDay
 * @var int                                 $calendarDays
 * @var int                                 $year
 * @var list<array<string, mixed>>          $documents        The caller's own documents.
 * @var string                              $today
 * @var bool                                $ready            True once step one is complete.
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$hex = static fn (mixed $value): string => is_string($value)
    && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1
        ? strtoupper($value)
        : '#64748B';

$days = static fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';

$available = (float) ($selected['available_days'] ?? 0);
$requested = $isHalfDay ? 0.5 : (float) $calendarDays;
$remaining = $available - $requested;
$isPaid = ($selected['is_paid'] ?? true) !== false;
$documentAfter = $selected['requires_document_after_days'] ?? null;
$needsDocument = $documentAfter !== null && $requested > (float) $documentAfter;

View::partial('page-header', [
    'title' => 'Apply for time off',
    'subtitle' => 'Choose the leave and the dates, then tell your approver what it is for.',
    'actions' => '<a href="/leave" class="btn btn-outline-secondary btn-sm">'
        . '<i class="fa fa-arrow-left"></i> Back to time off</a>',
]);
?>

<div class="row g-4">
    <div class="col-lg-8">

        <!-- Step one: what and when. A plain GET so the server can answer with
             the real balance and the right half-day option. -->
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">1</span>
                <strong>What and when</strong>
            </div>
            <div class="card-body">
                <form method="get" action="/leave/apply" novalidate>

                    <div class="mb-3">
                        <label for="leave_type_id" class="form-label">Type of leave</label>
                        <select class="form-select <?= Flash::hasError('leave_type_id') ? 'is-invalid' : '' ?>"
                                id="leave_type_id" name="leave_type_id" data-submit-on-change required>
                            <option value="">Choose a leave type&hellip;</option>
                            <?php foreach ($options as $id => $option): ?>
                                <option value="<?= e($id) ?>" <?= $selectedType === (string) $id ? 'selected' : '' ?>>
                                    <?= e($option['leave_type_name'] ?? 'Leave') ?>
                                    &middot; <?= e($days($option['available_days'] ?? 0)) ?> day<?= abs((float) ($option['available_days'] ?? 0) - 1.0) < 0.001 ? '' : 's' ?> available
                                    <?= ($option['is_paid'] ?? true) === false ? ' (unpaid)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php View::partial('field-errors', ['name' => 'leave_type_id']) ?>
                        <div class="form-text">
                            Your balance for <?= e((string) $year) ?> is shown beside each type.
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label for="startsOn" class="form-label">First day</label>
                            <input type="date"
                                   class="form-control <?= Flash::hasError('starts_on') ? 'is-invalid' : '' ?>"
                                   id="startsOn" name="starts_on"
                                   value="<?= e($startsOn) ?>"
                                   data-range-start="endsOn"
                                   required>
                            <?php View::partial('field-errors', ['name' => 'starts_on']) ?>
                        </div>
                        <div class="col-sm-6">
                            <label for="endsOn" class="form-label">Last day</label>
                            <input type="date"
                                   class="form-control <?= Flash::hasError('ends_on') ? 'is-invalid' : '' ?>"
                                   id="endsOn" name="ends_on"
                                   value="<?= e($endsOn) ?>"
                                   min="<?= e($startsOn) ?>"
                                   required>
                            <?php View::partial('field-errors', ['name' => 'ends_on']) ?>
                        </div>
                    </div>

                    <p class="form-text mb-3">
                        <i class="fa fa-calendar-day"></i>
                        <span data-range-days><?= $calendarDays > 0
                            ? e($calendarDays . ' calendar day' . ($calendarDays === 1 ? '' : 's'))
                            : 'Pick both dates to see the length.' ?></span>
                        Weekends and public holidays inside the range are removed when the request is counted.
                    </p>

                    <?php if ($rangeIsSingleDay && ($selected['allows_half_day'] ?? false) === true): ?>
                        <details class="mb-3" <?= $isHalfDay ? 'open' : '' ?>>
                            <summary class="cursor-pointer fw-semibold small">
                                Taking only half of <?= e(date_display($startsOn)) ?>?
                            </summary>
                            <div class="mt-2 ps-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1"
                                           id="is_half_day" name="is_half_day"
                                           data-submit-on-change
                                        <?= $isHalfDay ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="is_half_day">
                                        Yes, count this as half a day
                                    </label>
                                </div>

                                <div class="mt-2 d-flex gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="half_day_period"
                                               id="firstHalf" value="first_half"
                                            <?= $halfDayPeriod === 'first_half' || $halfDayPeriod === '' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="firstHalf">First half</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="half_day_period"
                                               id="secondHalf" value="second_half"
                                            <?= $halfDayPeriod === 'second_half' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="secondHalf">Second half</label>
                                    </div>
                                </div>
                                <?php View::partial('field-errors', ['name' => 'half_day_period']) ?>
                            </div>
                        </details>
                    <?php elseif ($rangeIsSingleDay && $selected !== null): ?>
                        <p class="form-text mb-3">
                            <i class="fa fa-info-circle"></i>
                            <?= e($selected['leave_type_name'] ?? 'This leave type') ?> cannot be taken as a half day.
                        </p>
                    <?php else: ?>
                        <p class="form-text mb-3">
                            <i class="fa fa-info-circle"></i>
                            A half day can only be taken when the first and last day are the same date.
                        </p>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-outline-primary" data-allow-repeat>
                        <i class="fa fa-calculator"></i> Check these dates
                    </button>
                    <div class="form-text mt-2">
                        Change anything above and press this. Step two always sends exactly what the
                        summary there shows.
                    </div>
                </form>
            </div>
        </div>

        <!-- Step two: the request itself. -->
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <span class="badge <?= $ready
                    ? 'bg-primary-subtle text-primary-emphasis border border-primary-subtle'
                    : 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle' ?>">2</span>
                <strong>Why, and who to call</strong>
            </div>

            <?php if (!$ready): ?>
                <div class="card-body">
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-hand-point-up',
                        'title' => 'Choose a leave type and both dates first',
                        'message' => 'Once you have, the exact effect on your balance appears here and you can send the request.',
                    ]) ?>
                </div>
            <?php else: ?>
                <div class="card-body">

                    <!-- Everything the approver will see, before it is sent. -->
                    <div class="border rounded p-3 mb-4" style="background: #fafbfe;">
                        <div class="section-label">This request</div>

                        <div class="stat-row">
                            <span class="stat-key">Leave type</span>
                            <span class="stat-val">
                                <span class="d-inline-block rounded-circle align-middle me-1"
                                      style="width: 9px; height: 9px; background: <?= e($hex($selected['colour'] ?? null)) ?>;"></span>
                                <?= e($selected['leave_type_name'] ?? 'Leave') ?>
                                <?php if (!$isPaid): ?>
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

                        <?php if ($isHalfDay): ?>
                            <div class="stat-row">
                                <span class="stat-key">Half day</span>
                                <span class="stat-val"><?= e(label($halfDayPeriod === 'second_half' ? 'second_half' : 'first_half')) ?></span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-key">Days requested</span>
                                <span class="stat-val tabular">0.5</span>
                            </div>
                        <?php else: ?>
                            <div class="stat-row">
                                <span class="stat-key">Calendar days in the range</span>
                                <span class="stat-val tabular"><?= e((string) $calendarDays) ?></span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-key">Working days charged</span>
                                <span class="stat-val text-muted fw-normal">
                                    <?= e((string) $calendarDays) ?> less any weekend or public holiday
                                </span>
                            </div>
                        <?php endif; ?>

                        <div class="stat-row">
                            <span class="stat-key">Balance before</span>
                            <span class="stat-val tabular"><?= e($days($available)) ?> days</span>
                        </div>

                        <div class="stat-row">
                            <span class="stat-key">Balance after<?= $isHalfDay ? '' : ', if every day counts' ?></span>
                            <span class="stat-val tabular <?= $isPaid && $remaining < 0 ? 'text-danger' : '' ?>">
                                <?= e($days($remaining)) ?> days
                            </span>
                        </div>

                        <?php if ($isPaid && $remaining < 0): ?>
                            <p class="small text-danger mt-2 mb-0">
                                <i class="fa fa-exclamation-triangle"></i>
                                That is more than you have. Unless enough of those days fall on a weekend or a
                                public holiday, the request will be refused.
                            </p>
                        <?php elseif (!$isPaid): ?>
                            <p class="small text-muted mt-2 mb-0">
                                <i class="fa fa-info-circle"></i>
                                Unpaid leave is not drawn from an entitlement, so there is no balance to run out of.
                            </p>
                        <?php endif; ?>
                    </div>

                    <form method="post" action="/leave/apply" novalidate>
                        <?= Csrf::field() ?>

                        <input type="hidden" name="leave_type_id" value="<?= e($selectedType) ?>">
                        <input type="hidden" name="starts_on" value="<?= e($startsOn) ?>">
                        <input type="hidden" name="ends_on" value="<?= e($endsOn) ?>">
                        <?php if ($isHalfDay): ?>
                            <input type="hidden" name="is_half_day" value="1">
                            <input type="hidden" name="half_day_period"
                                   value="<?= e($halfDayPeriod === 'second_half' ? 'second_half' : 'first_half') ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="reason" class="form-label">Reason</label>
                            <textarea class="form-control <?= Flash::hasError('reason') ? 'is-invalid' : '' ?>"
                                      id="reason" name="reason" rows="3" maxlength="1000"
                                      placeholder="A sentence is enough. Your approver reads this."
                                      required><?= e(Flash::old('reason')) ?></textarea>
                            <?php View::partial('field-errors', ['name' => 'reason']) ?>
                            <div class="form-text" data-counter-for="reason"></div>
                        </div>

                        <div class="mb-3">
                            <label for="contact_during_leave" class="form-label">
                                Contact while you are away <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <input type="text"
                                   class="form-control <?= Flash::hasError('contact_during_leave') ? 'is-invalid' : '' ?>"
                                   id="contact_during_leave" name="contact_during_leave" maxlength="120"
                                   value="<?= e(Flash::old('contact_during_leave')) ?>"
                                   placeholder="A number or an address for anything urgent">
                            <?php View::partial('field-errors', ['name' => 'contact_during_leave']) ?>
                        </div>

                        <?php if ($documentAfter !== null): ?>
                            <div class="mb-3">
                                <label for="supporting_document_id" class="form-label">
                                    Supporting document
                                    <?= $needsDocument ? '' : '<span class="text-muted fw-normal">(optional here)</span>' ?>
                                </label>

                                <div class="alert alert-<?= $needsDocument ? 'warning' : 'info' ?> py-2 px-3 small mb-2">
                                    <i class="fa fa-paperclip"></i>
                                    <?= e($selected['leave_type_name'] ?? 'This leave type') ?>
                                    needs a supporting document once a request runs beyond
                                    <?= e((string) (int) $documentAfter) ?> day<?= (int) $documentAfter === 1 ? '' : 's' ?>.
                                    <?php if ($needsDocument): ?>
                                        This one does, so please attach the document you have already uploaded.
                                    <?php endif; ?>
                                </div>

                                <?php if ($documents === []): ?>
                                    <p class="form-text mb-0">
                                        You have nothing uploaded yet.
                                        <a href="/profile/documents">Upload it on your documents page</a>, then come back.
                                    </p>
                                <?php else: ?>
                                    <select class="form-select <?= Flash::hasError('supporting_document_id') ? 'is-invalid' : '' ?>"
                                            id="supporting_document_id" name="supporting_document_id">
                                        <option value="">No document attached</option>
                                        <?php foreach ($documents as $document): ?>
                                            <?php if (!is_array($document) || !isset($document['id'])) {
                                                continue;
                                            } ?>
                                            <option value="<?= e($document['id']) ?>"
                                                <?= Flash::old('supporting_document_id') === (string) $document['id'] ? 'selected' : '' ?>>
                                                <?= e($document['title'] ?? 'Document') ?>
                                                &middot; <?= e(label($document['category'] ?? null)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <?php View::partial('field-errors', ['name' => 'supporting_document_id']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary" data-busy-label="Sending...">
                                <i class="fa fa-paper-plane"></i> Send this request
                            </button>
                            <a href="/leave" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><strong>Your balances</strong> <span class="text-muted small">&middot; <?= e((string) $year) ?></span></div>
            <div class="card-body">
                <?php if ($options === []): ?>
                    <p class="text-muted small mb-0">No leave types have been published yet.</p>
                <?php else: ?>
                    <?php foreach ($options as $id => $option): ?>
                        <div class="stat-row">
                            <span class="stat-key">
                                <span class="d-inline-block rounded-circle align-middle me-1"
                                      style="width: 9px; height: 9px; background: <?= e($hex($option['colour'] ?? null)) ?>;"></span>
                                <?= e($option['leave_type_name'] ?? 'Leave') ?>
                            </span>
                            <span class="stat-val tabular"><?= e($days($option['available_days'] ?? 0)) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <a href="/leave-balances">See the full statement <i class="fa fa-arrow-right"></i></a>
            </div>
        </div>

        <?php if ($selected !== null): ?>
            <div class="card">
                <div class="card-header"><strong>Rules for this type</strong></div>
                <div class="card-body">
                    <div class="stat-row">
                        <span class="stat-key">Paid</span>
                        <span class="stat-val"><?= $isPaid ? 'Yes' : 'No' ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Half days</span>
                        <span class="stat-val"><?= ($selected['allows_half_day'] ?? false) === true ? 'Allowed' : 'Not allowed' ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Notice needed</span>
                        <span class="stat-val">
                            <?php $notice = (int) ($selected['min_notice_days'] ?? 0); ?>
                            <?= $notice > 0 ? e($notice . ' day' . ($notice === 1 ? '' : 's')) : 'None' ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Longest single request</span>
                        <span class="stat-val">
                            <?php $longest = $selected['max_consecutive_days'] ?? null; ?>
                            <?= $longest === null || (int) $longest <= 0
                                ? 'No limit'
                                : e((int) $longest . ' days') ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Document needed after</span>
                        <span class="stat-val">
                            <?= $documentAfter === null ? 'Never' : e((int) $documentAfter . ' days') ?>
                        </span>
                    </div>
                </div>
                <div class="card-footer text-muted small">
                    Earliest you may start is checked when the request is filed, against today's date.
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
