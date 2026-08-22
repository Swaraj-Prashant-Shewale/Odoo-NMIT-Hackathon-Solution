<?php
/**
 * One review: the form while it is being written, the record once it is not.
 *
 * The service decides what arrives here. Anything it withheld — the identity
 * of an anonymous peer reviewer, a review that has not been submitted — simply
 * is not in $review, and this template never reaches for it elsewhere.
 *
 * @var array<string, mixed>       $review
 * @var array<string, mixed>       $cycle
 * @var list<array<string, mixed>> $competencies
 * @var array<string, array>       $ratings   Keyed by competency identifier.
 * @var list<array<string, mixed>> $goals
 * @var bool                       $isReviewer
 * @var bool                       $isEditable
 * @var bool                       $canAcknowledge
 * @var int                        $scaleMax
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$plain = static fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.') ?: '0';

$reviewId = (string) ($review['id'] ?? '');
$type = (string) ($review['review_type'] ?? '');
$status = (string) ($review['status'] ?? 'draft');
$isSelf = $type === 'self';
$isManagerReview = in_array($type, ['manager', 'skip_level'], true);
$subjectName = (string) ($review['employee_name'] ?? 'This employee');
$reviewerId = $review['reviewer_id'] ?? null;
$reviewerName = $review['reviewer_name'] ?? null;

// The framework drives the form; a score against a competency that has since
// been retired is still shown, because it is part of what was written.
$rows = [];
$seen = [];
foreach ($competencies as $competency) {
    $competencyId = (string) ($competency['id'] ?? '');
    if ($competencyId === '') {
        continue;
    }

    $seen[$competencyId] = true;
    $rows[] = [
        'id' => $competencyId,
        'name' => (string) ($competency['name'] ?? ''),
        'description' => (string) ($competency['description'] ?? ''),
        'category' => (string) ($competency['category'] ?? ''),
    ];
}

foreach ($ratings as $competencyId => $rating) {
    if (isset($seen[(string) $competencyId])) {
        continue;
    }

    $rows[] = [
        'id' => (string) $competencyId,
        'name' => (string) ($rating['competency_name'] ?? 'Retired competency'),
        'description' => '',
        'category' => (string) ($rating['competency_category'] ?? ''),
    ];
}

$heading = $isSelf
    ? 'Your self review'
    : label($type) . ' review of ' . ($isReviewer ? $subjectName : 'you');

$dueOn = $isSelf
    ? (string) ($cycle['self_review_due_on'] ?? '')
    : (string) ($cycle['manager_review_due_on'] ?? '');

// A self review is never released to anybody else, whatever its state, so the
// warning must not promise a reader it will not have.
$submitWarning = $isSelf
    ? 'Submitting locks this review: it cannot be edited afterwards. Submit now?'
    : 'Submitting locks this review: it cannot be edited afterwards, and its subject will be able to read it. Submit now?';
?>

<?php View::partial('page-header', [
    'title' => $heading,
    'subtitle' => (string) ($cycle['name'] ?? 'Review cycle')
        . ' · ' . date_display((string) ($cycle['period_start'] ?? ''))
        . ' – ' . date_display((string) ($cycle['period_end'] ?? '')),
    'actions' => '<a href="/performance/reviews" class="btn btn-outline-secondary btn-sm">'
        . '<i class="fa fa-arrow-left"></i> All reviews</a>',
]) ?>

<div class="row g-4">
    <div class="col-lg-8">

        <?php if ($isEditable): ?>
            <div class="alert alert-info small">
                <i class="fa fa-lock"></i>
                This draft is yours alone. Nobody else can read a word of it until you submit it, and once submitted it
                cannot be edited.
            </div>
        <?php elseif ($isReviewer): ?>
            <div class="alert alert-secondary small">
                <i class="fa fa-check"></i>
                You submitted this review on <?= e(datetime_display($review['submitted_at'] ?? null)) ?>. It is now
                read-only.
            </div>
        <?php elseif ($canAcknowledge): ?>
            <div class="alert alert-warning small">
                <i class="fa fa-signature"></i>
                This review has been submitted and is waiting for you to acknowledge that you have read it.
            </div>
        <?php endif; ?>

        <form method="post" action="/performance/reviews/<?= e($reviewId) ?>" novalidate>
            <?= Csrf::field() ?>

            <div class="card mb-4">
                <div class="card-header"><i class="fa fa-star"></i> Overall</div>
                <div class="card-body">
                    <label for="overall_rating" class="form-label">
                        Overall rating <span class="text-muted">(0 to <?= e((string) $scaleMax) ?>)</span>
                    </label>

                    <?php if ($isEditable): ?>
                        <input type="number"
                               step="0.5"
                               min="0"
                               max="<?= e((string) $scaleMax) ?>"
                               class="form-control <?= Flash::hasError('overall_rating') ? 'is-invalid' : '' ?>"
                               id="overall_rating"
                               name="overall_rating"
                               style="max-width: 8rem;"
                               value="<?= e(Flash::old('overall_rating', (string) ($review['overall_rating'] ?? ''))) ?>">
                        <?php View::partial('field-errors', ['name' => 'overall_rating']) ?>
                        <div class="form-text">An overall rating is required before this review can be submitted.</div>
                    <?php else: ?>
                        <div class="tile-value tabular">
                            <?php if (($review['overall_rating'] ?? null) === null): ?>
                                &mdash;
                            <?php else: ?>
                                <?= e($plain($review['overall_rating'])) ?><span class="fs-6 text-muted"> / <?= e((string) $scaleMax) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><i class="fa fa-list-check"></i> Competencies</div>
                <div class="card-body">
                    <?php if ($rows === []): ?>
                        <p class="small text-muted mb-0">
                            No competency framework applies to this review, so the written sections below are what counts.
                        </p>
                    <?php else: ?>
                        <?php foreach ($rows as $index => $row): ?>
                            <?php
                            $existing = $ratings[$row['id']] ?? [];
                            $existingRating = $existing['rating'] ?? null;
                            $existingComment = (string) ($existing['comment'] ?? '');
                            ?>
                            <div class="<?= $index === 0 ? '' : 'border-top pt-3 mt-3' ?>">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                    <div>
                                        <div class="fw-semibold"><?= e($row['name']) ?></div>
                                        <?php if ($row['category'] !== ''): ?>
                                            <div class="section-label mb-0"><?= e(label($row['category'])) ?></div>
                                        <?php endif; ?>
                                        <?php if ($row['description'] !== ''): ?>
                                            <div class="small text-muted"><?= e($row['description']) ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($isEditable): ?>
                                        <div>
                                            <label class="form-label small mb-1"
                                                   for="rating-<?= e($row['id']) ?>">Rating</label>
                                            <input type="number"
                                                   step="0.5"
                                                   min="0"
                                                   max="<?= e((string) $scaleMax) ?>"
                                                   class="form-control form-control-sm tabular"
                                                   style="width: 6.5rem;"
                                                   id="rating-<?= e($row['id']) ?>"
                                                   name="ratings[<?= e($row['id']) ?>][rating]"
                                                   value="<?= e($existingRating === null ? '' : $plain($existingRating)) ?>">
                                        </div>
                                    <?php else: ?>
                                        <div class="text-end">
                                            <div class="fw-semibold tabular">
                                                <?php if ($existingRating === null): ?>
                                                    <span class="text-muted">Not scored</span>
                                                <?php else: ?>
                                                    <?= e($plain($existingRating)) ?>
                                                    <span class="text-muted">/ <?= e((string) $scaleMax) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($isEditable): ?>
                                    <textarea class="form-control form-control-sm mt-2"
                                              rows="2"
                                              maxlength="1000"
                                              name="ratings[<?= e($row['id']) ?>][comment]"
                                              placeholder="Evidence for this score"><?= e($existingComment) ?></textarea>
                                <?php elseif ($existingComment !== ''): ?>
                                    <p class="small text-muted mt-2 mb-0"><?= e($existingComment) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <?php View::partial('field-errors', ['name' => 'ratings']) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><i class="fa fa-pen-nib"></i> In writing</div>
                <div class="card-body">

                    <div class="mb-3">
                        <label for="strengths" class="form-label">Strengths</label>
                        <?php if ($isEditable): ?>
                            <textarea class="form-control"
                                      id="strengths"
                                      name="strengths"
                                      rows="4"
                                      maxlength="4000"
                                      placeholder="What was done well, with examples."><?= e(Flash::old('strengths', (string) ($review['strengths'] ?? ''))) ?></textarea>
                            <div class="form-text" data-counter-for="strengths"></div>
                            <?php View::partial('field-errors', ['name' => 'strengths']) ?>
                        <?php else: ?>
                            <p class="mb-0 small"><?= nl2br(e((string) ($review['strengths'] ?? '—'))) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="improvements" class="form-label">Areas to improve</label>
                        <?php if ($isEditable): ?>
                            <textarea class="form-control"
                                      id="improvements"
                                      name="improvements"
                                      rows="4"
                                      maxlength="4000"
                                      placeholder="What to work on next, and what support it needs."><?= e(Flash::old('improvements', (string) ($review['improvements'] ?? ''))) ?></textarea>
                            <div class="form-text" data-counter-for="improvements"></div>
                            <?php View::partial('field-errors', ['name' => 'improvements']) ?>
                        <?php else: ?>
                            <p class="mb-0 small"><?= nl2br(e((string) ($review['improvements'] ?? '—'))) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="achievements" class="form-label">Achievements</label>
                        <?php if ($isEditable): ?>
                            <textarea class="form-control"
                                      id="achievements"
                                      name="achievements"
                                      rows="4"
                                      maxlength="4000"
                                      placeholder="The things this period should be remembered for."><?= e(Flash::old('achievements', (string) ($review['achievements'] ?? ''))) ?></textarea>
                            <div class="form-text" data-counter-for="achievements"></div>
                            <?php View::partial('field-errors', ['name' => 'achievements']) ?>
                        <?php else: ?>
                            <p class="mb-0 small"><?= nl2br(e((string) ($review['achievements'] ?? '—'))) ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if ($isManagerReview && ($isEditable || !empty($review['manager_comments']))): ?>
                        <div class="mb-0">
                            <label for="manager_comments" class="form-label">Manager comments</label>
                            <?php if ($isEditable): ?>
                                <textarea class="form-control"
                                          id="manager_comments"
                                          name="manager_comments"
                                          rows="3"
                                          maxlength="4000"
                                          placeholder="Anything the rest of the form does not cover."><?= e(Flash::old('manager_comments', (string) ($review['manager_comments'] ?? ''))) ?></textarea>
                                <?php View::partial('field-errors', ['name' => 'manager_comments']) ?>
                            <?php else: ?>
                                <p class="mb-0 small"><?= nl2br(e((string) ($review['manager_comments'] ?? '—'))) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($type === 'peer' && $isEditable): ?>
                        <div class="border-top pt-3 mt-3">
                            <input type="hidden" name="anonymity_offered" value="1">
                            <div class="form-check">
                                <input class="form-check-input"
                                       type="checkbox"
                                       value="1"
                                       id="is_anonymous"
                                       name="is_anonymous"
                                    <?= !empty($review['is_anonymous']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_anonymous">
                                    Submit this peer review without my name
                                </label>
                            </div>
                            <div class="form-text">
                                Your name is withheld from the person being reviewed and from their manager. It is still
                                recorded against the review so that abuse can be traced, so this is not the same as
                                writing something nobody can ever attribute.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isEditable): ?>
                <div class="d-flex flex-wrap gap-2">
                    <button type="submit"
                            class="btn btn-outline-primary"
                            formaction="/performance/reviews/<?= e($reviewId) ?>"
                            data-busy-label="Saving...">
                        <i class="fa fa-save"></i> Save draft
                    </button>

                    <button type="submit"
                            class="btn btn-primary"
                            formaction="/performance/reviews/<?= e($reviewId) ?>/submit"
                            data-confirm="<?= e($submitWarning) ?>">
                        <i class="fa fa-paper-plane"></i> Submit review
                    </button>

                    <a href="/performance/reviews" class="btn btn-link">Cancel</a>
                </div>
            <?php endif; ?>
        </form>

        <?php if ($canAcknowledge): ?>
            <div class="card mt-4">
                <div class="card-header"><i class="fa fa-signature"></i> Acknowledge this review</div>
                <form method="post" action="/performance/reviews/<?= e($reviewId) ?>/acknowledge">
                    <?= Csrf::field() ?>
                    <div class="card-body">
                        <label for="employee_comments" class="form-label">Your comments</label>
                        <textarea class="form-control"
                                  id="employee_comments"
                                  name="employee_comments"
                                  rows="4"
                                  maxlength="4000"
                                  placeholder="Optional. Anything you would like recorded alongside this review."><?= e(Flash::old('employee_comments', (string) ($review['employee_comments'] ?? ''))) ?></textarea>
                        <div class="form-text" data-counter-for="employee_comments"></div>
                        <?php View::partial('field-errors', ['name' => 'employee_comments']) ?>
                    </div>
                    <div class="card-footer">
                        <button type="submit"
                                class="btn btn-primary"
                                data-confirm="Acknowledging records that you have read this review. Continue?"
                                data-busy-label="Recording...">
                            <i class="fa fa-check"></i> Acknowledge
                        </button>
                    </div>
                </form>
            </div>
        <?php elseif ($status === 'acknowledged' && !empty($review['employee_comments'])): ?>
            <div class="card mt-4">
                <div class="card-header"><i class="fa fa-comment"></i> Employee comments</div>
                <div class="card-body">
                    <p class="small mb-0"><?= nl2br(e((string) $review['employee_comments'])) ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-circle-info"></i> This review</div>
            <div class="card-body">
                <div class="stat-row">
                    <span class="stat-key">About</span>
                    <span class="stat-val"><?= e($isReviewer && !$isSelf ? $subjectName : 'You') ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Reviewer</span>
                    <span class="stat-val">
                        <?php if ($isSelf): ?>
                            Yourself
                        <?php elseif ($reviewerId === null): ?>
                            <i class="fa fa-user-secret"></i> Anonymous
                        <?php elseif ($reviewerName !== null && $reviewerName !== ''): ?>
                            <?= e((string) $reviewerName) ?>
                        <?php else: ?>
                            A colleague
                        <?php endif; ?>
                    </span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Type</span>
                    <span class="stat-val"><?= e(label($type)) ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Status</span>
                    <span class="stat-val"><?= badge($status) ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Due</span>
                    <span class="stat-val"><?= e(date_display($dueOn)) ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Submitted</span>
                    <span class="stat-val"><?= e(datetime_display($review['submitted_at'] ?? null)) ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Acknowledged</span>
                    <span class="stat-val"><?= e(datetime_display($review['acknowledged_at'] ?? null)) ?></span>
                </div>

                <?php if (!empty($cycle['instructions'])): ?>
                    <div class="section-label mt-3">Instructions for this cycle</div>
                    <p class="small text-muted mb-0"><?= e((string) $cycle['instructions']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fa fa-bullseye"></i> Goals for this period</div>
            <div class="card-body">
                <?php if ($goals === []): ?>
                    <p class="small text-muted mb-0">
                        No goals are visible alongside this review.
                    </p>
                <?php else: ?>
                    <?php foreach ($goals as $goal): ?>
                        <?php $progress = percent($goal['progress_percent'] ?? 0); ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <span class="small fw-semibold truncate"><?= e((string) ($goal['title'] ?? '')) ?></span>
                                <?= badge((string) ($goal['status'] ?? '')) ?>
                            </div>
                            <div class="d-flex justify-content-between small text-muted">
                                <span>Weight <?= e($plain($goal['weight_percent'] ?? 0)) ?>%</span>
                                <span class="tabular"><?= e((string) $progress) ?>%</span>
                            </div>
                            <div class="progress mt-1">
                                <div class="progress-bar" style="width: <?= e((string) $progress) ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
