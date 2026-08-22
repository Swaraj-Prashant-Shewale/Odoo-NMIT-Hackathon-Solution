<?php
/**
 * Performance overview: goals, the cycle in flight and feedback received.
 *
 * @var array<string, mixed>|null       $goalCycle
 * @var array<string, mixed>            $goalSummary
 * @var list<array<string, mixed>>      $goals
 * @var array<string, mixed>|null       $latestReview
 * @var array<string, int>              $feedbackCounts
 * @var list<array<string, mixed>>      $feedback
 * @var array<string, string>           $names
 * @var list<array<string, mixed>>      $outstanding
 * @var array<string, array>            $cycles
 * @var array<string, mixed>|null       $currentCycle
 * @var float                           $committedWeight
 * @var float                           $availableWeight
 * @var float                           $weightBudget
 * @var float                           $weightWarnAt
 * @var bool                            $canSetGoals
 * @var bool                            $canManageCycles
 */

use App\Core\Csrf;
use App\Core\View;

$displayName = static function (?string $employeeId) use ($names): string {
    if ($employeeId === null || $employeeId === '') {
        return 'Anonymous';
    }

    return $names[$employeeId] ?? 'A colleague';
};

/** Weights and targets are decimals in the database; the trailing zeros are noise. */
$plain = static fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.') ?: '0';

$today = date('Y-m-d');

$actions = '';
if ($canSetGoals) {
    $actions .= '<a href="/performance/goals/new" class="btn btn-primary btn-sm"><i class="fa fa-bullseye"></i> Set a goal</a>';
}
$actions .= '<a href="/performance/reviews" class="btn btn-outline-secondary btn-sm"><i class="fa fa-clipboard-check"></i> Reviews</a>';
$actions .= '<a href="/performance/feedback" class="btn btn-outline-secondary btn-sm"><i class="fa fa-comments"></i> Feedback</a>';
if ($canManageCycles) {
    $actions .= '<a href="/performance/cycles" class="btn btn-outline-secondary btn-sm"><i class="fa fa-calendar-alt"></i> Review cycles</a>';
}

$ratingScale = $latestReview === null ? null : ($latestReview['rating_scale_max'] ?? null);
$ratingValue = $latestReview === null ? null : ($latestReview['overall_rating'] ?? null);

$chartValues = [];
$chartLabels = [];
foreach ($goals as $goal) {
    $chartValues[] = round((float) ($goal['progress_percent'] ?? 0), 1);
    $chartLabels[] = mb_substr((string) ($goal['title'] ?? ''), 0, 22);
}

$weightPercent = percent($committedWeight / max(1.0, $weightBudget) * 100);
$weightTone = $committedWeight >= $weightBudget
    ? 'danger'
    : ($committedWeight >= $weightWarnAt ? 'warning' : 'success');
?>

<?php View::partial('page-header', [
    'title' => 'Performance',
    'subtitle' => $goalCycle === null
        ? 'Your goals, appraisals and the feedback people have written for you.'
        : 'Goal cycle: ' . (string) ($goalCycle['name'] ?? ''),
    'actions' => $actions,
]) ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="tile tile-info">
            <div class="tile-icon"><i class="fa fa-bullseye"></i></div>
            <div class="tile-label mt-3">Active goals</div>
            <div class="tile-value tabular"><?= e((string) (int) ($goalSummary['active'] ?? 0)) ?></div>
            <div class="tile-hint">
                <?= e((string) (int) ($goalSummary['achieved'] ?? 0)) ?> achieved &middot;
                <?= e((string) (int) ($goalSummary['at_risk'] ?? 0)) ?> at risk
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="tile tile-success">
            <div class="tile-icon"><i class="fa fa-chart-line"></i></div>
            <div class="tile-label mt-3">Average progress</div>
            <div class="tile-value tabular"><?= e((string) percent($goalSummary['average_progress'] ?? 0)) ?>%</div>
            <div class="tile-hint">
                Across <?= e((string) (int) ($goalSummary['total'] ?? 0)) ?> goals<?= e($goalCycle === null ? '' : ' this cycle') ?>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="tile tile-warning">
            <div class="tile-icon"><i class="fa fa-star"></i></div>
            <div class="tile-label mt-3">Latest review</div>
            <div class="tile-value tabular">
                <?php if ($ratingValue === null): ?>
                    &mdash;
                <?php else: ?>
                    <?= e((string) $ratingValue) ?><?php if ($ratingScale !== null): ?><span class="fs-6 text-muted"> / <?= e((string) $ratingScale) ?></span><?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="tile-hint">
                <?php if ($latestReview === null): ?>
                    No appraisal has been shared with you yet
                <?php else: ?>
                    <?= e(label((string) ($latestReview['review_type'] ?? ''))) ?> review &middot;
                    <?= e((string) ($latestReview['cycle_name'] ?? 'Cycle')) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="tile">
            <div class="tile-icon"><i class="fa fa-comments"></i></div>
            <div class="tile-label mt-3">Feedback received</div>
            <div class="tile-value tabular"><?= e((string) (int) ($feedbackCounts['received'] ?? 0)) ?></div>
            <div class="tile-hint">
                <?= e((string) (int) ($feedbackCounts['appreciation'] ?? 0)) ?> appreciation &middot;
                <?= e((string) (int) ($feedbackCounts['constructive'] ?? 0)) ?> constructive
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">

        <div class="card mb-4">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="fa fa-balance-scale"></i> Committed weight</span>
                <span class="badge bg-<?= e($weightTone) ?>-subtle text-<?= e($weightTone) ?>-emphasis border border-<?= e($weightTone) ?>-subtle">
                    <?= e($plain($committedWeight)) ?>% of <?= e((string) (int) $weightBudget) ?>%
                </span>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-baseline justify-content-between mb-2">
                    <div>
                        <div class="tile-value tabular"><?= e($plain($committedWeight)) ?>%</div>
                        <div class="tile-hint">
                            committed <?= e($goalCycle === null ? 'across your goals' : 'across every goal in this cycle') ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="fw-semibold tabular"><?= e($plain($availableWeight)) ?>%</div>
                        <div class="tile-hint">still available</div>
                    </div>
                </div>

                <div class="progress progress-lg">
                    <div class="progress-bar bg-<?= e($weightTone) ?>" style="width: <?= e((string) $weightPercent) ?>%"></div>
                </div>

                <?php if ($committedWeight >= $weightBudget): ?>
                    <p class="small text-danger mt-2 mb-0">
                        <i class="fa fa-exclamation-circle"></i>
                        The full <?= e((string) (int) $weightBudget) ?>% is committed. A new goal will be refused until weight is freed up elsewhere.
                    </p>
                <?php elseif ($committedWeight >= $weightWarnAt): ?>
                    <p class="small text-warning-emphasis mt-2 mb-0">
                        <i class="fa fa-exclamation-triangle"></i>
                        Close to the cap. Only <?= e($plain($availableWeight)) ?>% remains for further goals in this cycle.
                    </p>
                <?php else: ?>
                    <p class="small text-muted mt-2 mb-0">
                        Weights express what each goal is worth at appraisal time. They may total no more than <?= e((string) (int) $weightBudget) ?>% in a cycle.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h5 mb-0">Active goals</h2>
            <?php if ($canSetGoals): ?>
                <a href="/performance/goals/new" class="btn btn-outline-primary btn-sm">
                    <i class="fa fa-plus"></i> Set a goal
                </a>
            <?php endif; ?>
        </div>

        <?php if ($goals === []): ?>
            <div class="card">
                <div class="card-body">
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-bullseye',
                        'title' => 'No active goals',
                        'message' => 'Nothing is being tracked for you in this cycle yet.',
                        'actionLabel' => $canSetGoals ? 'Set your first goal' : null,
                        'actionHref' => $canSetGoals ? '/performance/goals/new' : null,
                    ]) ?>
                </div>
            </div>
        <?php else: ?>

            <?php if (count($chartValues) > 1): ?>
                <div class="card mb-3">
                    <div class="card-header"><i class="fa fa-chart-bar"></i> Progress across goals</div>
                    <div class="card-body">
                        <div data-chart='<?= ejs([
                            'type' => 'bar',
                            'values' => $chartValues,
                            'labels' => $chartLabels,
                            'format' => 'percent',
                            'colourByIndex' => true,
                        ]) ?>'></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($goals as $goal): ?>
                <?php
                $goalId = (string) ($goal['id'] ?? '');
                $progress = percent($goal['progress_percent'] ?? 0);
                $weight = (float) ($goal['weight_percent'] ?? 0);
                $target = $goal['target_value'] ?? null;
                $current = $goal['current_value'] ?? null;
                $unit = trim((string) ($goal['unit'] ?? ''));
                $dueOn = (string) ($goal['due_on'] ?? '');
                $isOverdue = $dueOn !== '' && $dueOn < $today && $progress < 100;
                $contribution = round($weight * $progress / 100, 2);
                ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <h3 class="h6 mb-1"><?= e((string) ($goal['title'] ?? 'Untitled goal')) ?></h3>
                                <div class="small text-muted">
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                        <?= e(label((string) ($goal['category'] ?? ''))) ?>
                                    </span>
                                    <span class="ms-2">
                                        <i class="fa fa-weight-hanging"></i>
                                        Weight <?= e($plain($weight)) ?>%
                                    </span>
                                </div>
                            </div>
                            <div class="text-end">
                                <?= badge((string) ($goal['status'] ?? '')) ?>
                                <div class="small <?= $isOverdue ? 'text-danger' : 'text-muted' ?> mt-1">
                                    <i class="fa fa-flag-checkered"></i>
                                    Due <?= e(date_display($dueOn)) ?>
                                    <?php if ($isOverdue): ?>&middot; overdue<?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($goal['description'])): ?>
                            <p class="small text-muted mt-2 mb-2"><?= e((string) $goal['description']) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($goal['metric'])): ?>
                            <div class="stat-row">
                                <span class="stat-key"><?= e((string) $goal['metric']) ?></span>
                                <span class="stat-val tabular">
                                    <?= e($current === null ? '—' : $plain($current)) ?>
                                    <?php if ($target !== null): ?>
                                        of <?= e($plain($target)) ?>
                                    <?php endif; ?>
                                    <?php if ($unit !== ''): ?><span class="text-muted"><?= e($unit) ?></span><?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between align-items-baseline mt-3 mb-1 small">
                            <span class="text-muted">Progress</span>
                            <span class="fw-semibold tabular"><?= e((string) $progress) ?>%</span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar" style="width: <?= e((string) $progress) ?>%"></div>
                        </div>
                        <div class="small text-muted mt-1">
                            Contributing <?= e($plain($contribution)) ?>
                            of <?= e($plain($weight)) ?> weighted points.
                        </div>
                    </div>

                    <div class="card-footer">
                        <form method="post" action="/performance/goals/<?= e($goalId) ?>/progress"
                              class="row g-2 align-items-end">
                            <?= Csrf::field() ?>

                            <div class="col-12 col-sm-4">
                                <?php if ($target !== null): ?>
                                    <label class="form-label small mb-1" for="value-<?= e($goalId) ?>">
                                        Latest figure<?php if ($unit !== ''): ?> (<?= e($unit) ?>)<?php endif; ?>
                                    </label>
                                    <input type="number"
                                           step="0.01"
                                           min="0"
                                           class="form-control form-control-sm"
                                           id="value-<?= e($goalId) ?>"
                                           name="current_value"
                                           value=""
                                           placeholder="<?= e($current === null ? '0' : (string) $current) ?>">
                                <?php else: ?>
                                    <label class="form-label small mb-1" for="value-<?= e($goalId) ?>">Progress %</label>
                                    <input type="number"
                                           step="1"
                                           min="0"
                                           max="100"
                                           class="form-control form-control-sm"
                                           id="value-<?= e($goalId) ?>"
                                           name="progress_percent"
                                           value=""
                                           placeholder="<?= e((string) $progress) ?>">
                                <?php endif; ?>
                            </div>

                            <div class="col-12 col-sm-6">
                                <label class="form-label small mb-1" for="note-<?= e($goalId) ?>">Note</label>
                                <input type="text"
                                       class="form-control form-control-sm"
                                       id="note-<?= e($goalId) ?>"
                                       name="note"
                                       maxlength="1000"
                                       placeholder="What moved since the last update?">
                            </div>

                            <div class="col-12 col-sm-2 d-grid">
                                <button type="submit" class="btn btn-primary btn-sm" data-busy-label="Saving...">
                                    <i class="fa fa-save"></i> Update
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">

        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-clipboard-check"></i> Current review cycle</div>
            <div class="card-body">
                <?php if ($currentCycle === null): ?>
                    <p class="small text-muted mb-0">
                        No review cycle is open at the moment. You will be told when one starts and a form is waiting for you.
                    </p>
                <?php else: ?>
                    <div class="section-label"><?= e((string) ($currentCycle['name'] ?? 'Review cycle')) ?></div>
                    <div class="stat-row">
                        <span class="stat-key">Period</span>
                        <span class="stat-val">
                            <?= e(date_display((string) ($currentCycle['period_start'] ?? ''))) ?>
                            &ndash;
                            <?= e(date_display((string) ($currentCycle['period_end'] ?? ''))) ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Self review due</span>
                        <span class="stat-val"><?= e(date_display((string) ($currentCycle['self_review_due_on'] ?? ''))) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Manager review due</span>
                        <span class="stat-val"><?= e(date_display((string) ($currentCycle['manager_review_due_on'] ?? ''))) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Rating scale</span>
                        <span class="stat-val tabular">0 &ndash; <?= e((string) (int) ($currentCycle['rating_scale_max'] ?? 5)) ?></span>
                    </div>

                    <?php if (!empty($currentCycle['instructions'])): ?>
                        <p class="small text-muted mt-3 mb-0"><?= e((string) $currentCycle['instructions']) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($outstanding !== []): ?>
                <div class="card-body border-top">
                    <div class="section-label">Waiting on you</div>
                    <?php foreach (array_slice($outstanding, 0, 5) as $review): ?>
                        <?php
                        $reviewId = (string) ($review['id'] ?? '');
                        $type = (string) ($review['review_type'] ?? '');
                        $cycleId = (string) ($review['review_cycle_id'] ?? '');
                        $cycle = $cycles[$cycleId] ?? [];
                        $due = $type === 'self'
                            ? (string) ($cycle['self_review_due_on'] ?? '')
                            : (string) ($cycle['manager_review_due_on'] ?? '');
                        $subject = (string) ($review['employee_id'] ?? '');
                        ?>
                        <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                            <div>
                                <div class="fw-semibold small">
                                    <?php if ($type === 'self'): ?>
                                        Your self review
                                    <?php else: ?>
                                        <?= e(label($type)) ?> review of <?= e($displayName($subject)) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-muted">
                                    <?= e((string) ($review['cycle_name'] ?? ($cycle['name'] ?? 'Review cycle'))) ?>
                                    <?php if ($due !== ''): ?>
                                        &middot; due <?= e(date_display($due)) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="/performance/reviews/<?= e($reviewId) ?>" class="btn btn-outline-primary btn-sm">
                                Continue
                            </a>
                        </div>
                    <?php endforeach; ?>

                    <a href="/performance/reviews" class="btn btn-link btn-sm px-0 mt-2">
                        All reviews <i class="fa fa-arrow-right"></i>
                    </a>
                </div>
            <?php elseif ($currentCycle !== null): ?>
                <div class="card-body border-top">
                    <p class="small text-muted mb-2">
                        <i class="fa fa-check-circle text-success"></i>
                        Nothing is waiting on you in this cycle.
                    </p>
                    <a href="/performance/reviews" class="btn btn-outline-secondary btn-sm">
                        <i class="fa fa-clipboard-list"></i> View my reviews
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-comment-dots"></i> Recent feedback</span>
                <a href="/performance/feedback" class="small">See all</a>
            </div>
            <div class="card-body">
                <?php if ($feedback === []): ?>
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-comment-dots',
                        'title' => 'No feedback yet',
                        'message' => 'Feedback colleagues write for you appears here.',
                        'actionLabel' => 'Send feedback to a colleague',
                        'actionHref' => '/performance/feedback',
                    ]) ?>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($feedback as $item): ?>
                            <?php
                            $sender = $item['from_employee_id'] ?? null;
                            $isAnonymous = !empty($item['is_anonymous']) || $sender === null;
                            $type = (string) ($item['feedback_type'] ?? '');
                            $tone = match ($type) {
                                'appreciation' => 'success',
                                'constructive' => 'warning',
                                'request' => 'info',
                                default => 'secondary',
                            };
                            ?>
                            <div class="timeline-item">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <span class="badge bg-<?= e($tone) ?>-subtle text-<?= e($tone) ?>-emphasis border border-<?= e($tone) ?>-subtle">
                                        <?= e(label($type)) ?>
                                    </span>
                                    <span class="small text-muted"><?= e(relative_time($item['created_at'] ?? null)) ?></span>
                                </div>
                                <div class="fw-semibold small mt-1"><?= e((string) ($item['subject'] ?? '')) ?></div>
                                <div class="small text-muted">
                                    <?php if ($isAnonymous): ?>
                                        <i class="fa fa-user-secret"></i> Anonymous
                                    <?php else: ?>
                                        <?= e($displayName((string) $sender)) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <a href="/performance/feedback" class="btn btn-outline-primary btn-sm w-100 mt-2">
                        <i class="fa fa-paper-plane"></i> Send feedback to a colleague
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
