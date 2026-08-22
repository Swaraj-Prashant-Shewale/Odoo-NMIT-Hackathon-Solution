<?php
/**
 * Two lists: the reviews the caller has to write, and the reviews about them.
 *
 * The second list contains only what the talent service released. A review
 * somebody else is still writing is not listed at all, and the note under the
 * heading says so rather than leaving a gap for the reader to misread.
 *
 * @var list<array<string, mixed>>     $toComplete
 * @var list<array<string, mixed>>     $aboutMe
 * @var array<string, array>           $cycles
 * @var array<string, string>          $names
 * @var array<string, int>             $meta
 * @var string                         $me
 */

use App\Core\View;

$displayName = static function (mixed $employeeId) use ($names): string {
    if (!is_string($employeeId) || $employeeId === '') {
        return 'Anonymous';
    }

    return $names[$employeeId] ?? 'A colleague';
};

/** A review inherits its deadline from its cycle, by type. */
$dueDate = static function (array $review) use ($cycles): string {
    $cycle = $cycles[(string) ($review['review_cycle_id'] ?? '')] ?? [];

    return (string) ($review['review_type'] ?? '') === 'self'
        ? (string) ($cycle['self_review_due_on'] ?? '')
        : (string) ($cycle['manager_review_due_on'] ?? '');
};

$cycleName = static function (array $review) use ($cycles): string {
    if (!empty($review['cycle_name'])) {
        return (string) $review['cycle_name'];
    }

    $cycle = $cycles[(string) ($review['review_cycle_id'] ?? '')] ?? [];

    return (string) ($cycle['name'] ?? 'Review cycle');
};

$today = date('Y-m-d');
?>

<?php View::partial('page-header', [
    'title' => 'Reviews',
    'subtitle' => 'Appraisals you are writing, and the ones that have been shared with you.',
    'actions' => '<a href="/performance" class="btn btn-outline-secondary btn-sm"><i class="fa fa-arrow-left"></i> Back to performance</a>',
]) ?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-pen-to-square"></i> Reviews I must complete</span>
        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
            <?= e((string) count($toComplete)) ?>
        </span>
    </div>

    <?php if ($toComplete === []): ?>
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-clipboard-check',
                'title' => 'Nothing to write',
                'message' => 'No review form is waiting for you. One appears here as soon as a cycle is opened.',
            ]) ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th scope="col">About</th>
                        <th scope="col">Type</th>
                        <th scope="col">Cycle</th>
                        <th scope="col">Due</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($toComplete as $review): ?>
                        <?php
                        $reviewId = (string) ($review['id'] ?? '');
                        $type = (string) ($review['review_type'] ?? '');
                        $status = (string) ($review['status'] ?? '');
                        $subjectId = (string) ($review['employee_id'] ?? '');
                        $isSelf = $type === 'self';
                        $due = $dueDate($review);
                        $isOverdue = $due !== '' && $due < $today && $status === 'draft';
                        $started = ($review['overall_rating'] ?? null) !== null
                            || !empty($review['strengths'])
                            || !empty($review['improvements'])
                            || !empty($review['achievements']);
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar avatar-sm">
                                        <?= e($isSelf ? initials($names[$me] ?? 'Me') : initials($displayName($subjectId))) ?>
                                    </span>
                                    <span class="fw-semibold">
                                        <?= e($isSelf ? 'Yourself' : $displayName($subjectId)) ?>
                                    </span>
                                </div>
                            </td>
                            <td><?= e(label($type)) ?></td>
                            <td class="cell-truncate"><?= e($cycleName($review)) ?></td>
                            <td class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>">
                                <?= e(date_display($due)) ?>
                                <?php if ($isOverdue): ?>
                                    <div class="small">overdue</div>
                                <?php endif; ?>
                            </td>
                            <td><?= badge($status) ?></td>
                            <td class="text-end">
                                <a href="/performance/reviews/<?= e($reviewId) ?>"
                                   class="btn btn-sm <?= $status === 'draft' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                    <?php if ($status !== 'draft'): ?>
                                        <i class="fa fa-eye"></i> View
                                    <?php elseif ($started): ?>
                                        <i class="fa fa-pen"></i> Continue
                                    <?php else: ?>
                                        <i class="fa fa-play"></i> Start
                                    <?php endif; ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-inbox"></i> Reviews about me</span>
        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
            <?= e((string) count($aboutMe)) ?>
        </span>
    </div>

    <div class="card-body pb-0">
        <p class="small text-muted mb-3">
            <i class="fa fa-lock"></i>
            A review written about you becomes readable once its reviewer submits it. Until then it is not shown here,
            and its contents are not available to anyone but the person writing it.
        </p>
    </div>

    <?php if ($aboutMe === []): ?>
        <div class="card-body pt-0">
            <?php View::partial('empty-state', [
                'icon' => 'fa-inbox',
                'title' => 'Nothing shared with you yet',
                'message' => 'Submitted appraisals of your work will appear here.',
            ]) ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th scope="col">Cycle</th>
                        <th scope="col">Reviewer</th>
                        <th scope="col">Type</th>
                        <th scope="col">Overall rating</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($aboutMe as $review): ?>
                        <?php
                        $reviewId = (string) ($review['id'] ?? '');
                        $type = (string) ($review['review_type'] ?? '');
                        $status = (string) ($review['status'] ?? '');
                        $cycleId = (string) ($review['review_cycle_id'] ?? '');
                        $scaleMax = (int) ($cycles[$cycleId]['rating_scale_max'] ?? 5);
                        $rating = $review['overall_rating'] ?? null;
                        $isDraft = $status === 'draft';
                        $reviewerId = $review['reviewer_id'] ?? null;
                        $isMine = is_string($reviewerId) && $reviewerId === $me;
                        ?>
                        <tr>
                            <td class="cell-truncate"><?= e($cycleName($review)) ?></td>
                            <td>
                                <?php if ($type === 'self'): ?>
                                    Yourself
                                <?php elseif ($reviewerId === null): ?>
                                    <i class="fa fa-user-secret text-muted"></i> Anonymous
                                <?php else: ?>
                                    <?= e($displayName($reviewerId)) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e(label($type)) ?></td>
                            <td class="tabular">
                                <?php if ($isDraft || $rating === null): ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php else: ?>
                                    <?= e((string) $rating) ?>
                                    <span class="text-muted">/ <?= e((string) $scaleMax) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isDraft): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                        In progress
                                    </span>
                                <?php else: ?>
                                    <?= badge($status) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($isDraft): ?>
                                    <span class="small text-muted">
                                        <?= $isMine ? 'Yours to finish' : 'Not yet submitted' ?>
                                    </span>
                                <?php else: ?>
                                    <a href="/performance/reviews/<?= e($reviewId) ?>" class="btn btn-sm btn-outline-primary">
                                        <?php if ($status === 'submitted' && in_array($type, ['manager', 'skip_level'], true)): ?>
                                            <i class="fa fa-signature"></i> Read and acknowledge
                                        <?php else: ?>
                                            <i class="fa fa-eye"></i> Open
                                        <?php endif; ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php View::partial('pagination', ['meta' => $meta]) ?>
