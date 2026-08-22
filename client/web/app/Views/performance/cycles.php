<?php
/**
 * Review cycles: the calendar the whole appraisal process runs on.
 *
 * Participation and completion are aggregate figures and only appear for a
 * reader entitled to them; the individual appraisals behind them are never
 * reachable from this page.
 *
 * @var list<array<string, mixed>>   $cycles
 * @var array<string, array>         $summaries
 * @var array<string, int>           $meta
 * @var list<array<string, mixed>>   $departments
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$scales = [3, 4, 5, 6, 7, 10];
?>

<?php View::partial('page-header', [
    'title' => 'Review cycles',
    'subtitle' => 'Create a cycle, then open it to enrol people and create their review forms.',
    'actions' => '<a href="/performance" class="btn btn-outline-secondary btn-sm"><i class="fa fa-arrow-left"></i> Back to performance</a>',
]) ?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-calendar-alt"></i> Cycles</span>
        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
            <?= e((string) count($cycles)) ?> on this page
        </span>
    </div>

    <?php if ($cycles === []): ?>
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-calendar-plus',
                'title' => 'No review cycles yet',
                'message' => 'Create one below. It starts as a draft, so nobody sees it until you open it.',
            ]) ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th scope="col">Cycle</th>
                        <th scope="col">Period</th>
                        <th scope="col">Due dates</th>
                        <th scope="col">Status</th>
                        <th scope="col">Participants</th>
                        <th scope="col">Completion</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cycles as $cycle): ?>
                        <?php
                        $cycleId = (string) ($cycle['id'] ?? '');
                        $status = (string) ($cycle['status'] ?? '');
                        $summary = $summaries[$cycleId] ?? null;
                        $participants = $summary === null ? null : (int) ($summary['participants'] ?? 0);
                        $completion = $summary === null ? null : (float) ($summary['completion_rate'] ?? 0);
                        $reviewCounts = is_array($summary['reviews'] ?? null) ? $summary['reviews'] : [];
                        // Acknowledging does not un-complete a review, so both states count as done.
                        $done = (int) ($reviewCounts['submitted'] ?? 0) + (int) ($reviewCounts['acknowledged'] ?? 0);
                        $totalReviews = (int) ($reviewCounts['total'] ?? 0);
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e((string) ($cycle['name'] ?? '')) ?></div>
                                <div class="small text-muted">
                                    Scale 0 &ndash; <?= e((string) (int) ($cycle['rating_scale_max'] ?? 5)) ?>
                                </div>
                            </td>
                            <td class="small">
                                <?= e(date_display((string) ($cycle['period_start'] ?? ''))) ?>
                                <br>
                                <span class="text-muted">to <?= e(date_display((string) ($cycle['period_end'] ?? ''))) ?></span>
                            </td>
                            <td class="small">
                                Self: <?= e(date_display((string) ($cycle['self_review_due_on'] ?? ''))) ?>
                                <br>
                                <span class="text-muted">Manager: <?= e(date_display((string) ($cycle['manager_review_due_on'] ?? ''))) ?></span>
                            </td>
                            <td><?= badge($status) ?></td>
                            <td class="tabular">
                                <?= $participants === null ? '<span class="text-muted">&mdash;</span>' : e((string) $participants) ?>
                            </td>
                            <td style="min-width: 9rem;">
                                <?php if ($completion === null): ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php else: ?>
                                    <div class="d-flex justify-content-between small">
                                        <span class="text-muted">
                                            <?= e((string) $done) ?> of <?= e((string) $totalReviews) ?>
                                        </span>
                                        <span class="tabular fw-semibold"><?= e((string) percent($completion)) ?>%</span>
                                    </div>
                                    <div class="progress mt-1">
                                        <div class="progress-bar bg-success" style="width: <?= e((string) percent($completion)) ?>%"></div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($status === 'draft'): ?>
                                    <form method="post"
                                          action="/performance/cycles/<?= e($cycleId) ?>/open"
                                          class="d-flex flex-wrap gap-1 justify-content-end align-items-center">
                                        <?= Csrf::field() ?>

                                        <label class="visually-hidden" for="scope-<?= e($cycleId) ?>">Who to enrol</label>
                                        <select class="form-select form-select-sm"
                                                style="width: auto;"
                                                id="scope-<?= e($cycleId) ?>"
                                                name="scope">
                                            <option value="all">Everyone</option>
                                            <option value="department">One department</option>
                                        </select>

                                        <?php if ($departments !== []): ?>
                                            <label class="visually-hidden" for="department-<?= e($cycleId) ?>">Department</label>
                                            <select class="form-select form-select-sm"
                                                    style="width: auto;"
                                                    id="department-<?= e($cycleId) ?>"
                                                    name="department_id">
                                                <option value="">Choose department</option>
                                                <?php foreach ($departments as $department): ?>
                                                    <option value="<?= e((string) ($department['id'] ?? '')) ?>">
                                                        <?= e((string) ($department['name'] ?? '')) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>

                                        <button type="submit"
                                                class="btn btn-primary btn-sm"
                                                data-busy-label="Opening..."
                                                data-confirm="Opening this cycle enrols every person in the chosen scope and creates their self review and their manager's review of them. It cannot be undone. Open it now?">
                                            <i class="fa fa-lock-open"></i> Open
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="small text-muted">
                                        <?= $status === 'closed' ? 'Closed' : 'Already open' ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            <?php View::partial('pagination', ['meta' => $meta]) ?>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header"><i class="fa fa-plus"></i> New review cycle</div>
    <form method="post" action="/performance/cycles" novalidate>
        <?= Csrf::field() ?>
        <div class="card-body">

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Name</label>
                    <input type="text"
                           class="form-control"
                           id="name"
                           name="name"
                           maxlength="120"
                           value="<?= e(Flash::old('name')) ?>"
                           placeholder="H1 2026 appraisal"
                           required>
                    <?php View::partial('field-errors', ['name' => 'name']) ?>
                </div>

                <div class="col-md-3">
                    <label for="period_start" class="form-label">Period starts</label>
                    <input type="date"
                           class="form-control"
                           id="period_start"
                           name="period_start"
                           value="<?= e(Flash::old('period_start')) ?>"
                           data-range-start="period_end"
                           required>
                    <?php View::partial('field-errors', ['name' => 'period_start']) ?>
                </div>

                <div class="col-md-3">
                    <label for="period_end" class="form-label">Period ends</label>
                    <input type="date"
                           class="form-control"
                           id="period_end"
                           name="period_end"
                           value="<?= e(Flash::old('period_end')) ?>"
                           required>
                    <?php View::partial('field-errors', ['name' => 'period_end']) ?>
                </div>

                <div class="col-md-4">
                    <label for="self_review_due_on" class="form-label">Self review due</label>
                    <input type="date"
                           class="form-control"
                           id="self_review_due_on"
                           name="self_review_due_on"
                           value="<?= e(Flash::old('self_review_due_on')) ?>">
                    <?php View::partial('field-errors', ['name' => 'self_review_due_on']) ?>
                </div>

                <div class="col-md-4">
                    <label for="manager_review_due_on" class="form-label">Manager review due</label>
                    <input type="date"
                           class="form-control"
                           id="manager_review_due_on"
                           name="manager_review_due_on"
                           value="<?= e(Flash::old('manager_review_due_on')) ?>">
                    <?php View::partial('field-errors', ['name' => 'manager_review_due_on']) ?>
                </div>

                <div class="col-md-4">
                    <label for="rating_scale_max" class="form-label">Rating scale maximum</label>
                    <select class="form-select" id="rating_scale_max" name="rating_scale_max">
                        <?php foreach ($scales as $scale): ?>
                            <option value="<?= e((string) $scale) ?>"
                                <?= Flash::old('rating_scale_max', '5') === (string) $scale ? 'selected' : '' ?>>
                                0 &ndash; <?= e((string) $scale) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php View::partial('field-errors', ['name' => 'rating_scale_max']) ?>
                </div>

                <div class="col-12">
                    <label for="instructions" class="form-label">Instructions</label>
                    <textarea class="form-control"
                              id="instructions"
                              name="instructions"
                              rows="4"
                              maxlength="4000"
                              placeholder="What everyone should keep in mind while writing this cycle's reviews."><?= e(Flash::old('instructions')) ?></textarea>
                    <div class="form-text" data-counter-for="instructions"></div>
                    <?php View::partial('field-errors', ['name' => 'instructions']) ?>
                </div>
            </div>

            <p class="small text-muted mt-3 mb-0">
                Both due dates must fall on or after the end of the period. A new cycle is created as a draft: nobody is
                enrolled and no forms exist until you open it.
            </p>
        </div>

        <div class="card-footer">
            <button type="submit" class="btn btn-primary" data-busy-label="Creating...">
                <i class="fa fa-check"></i> Create cycle
            </button>
        </div>
    </form>
</div>
