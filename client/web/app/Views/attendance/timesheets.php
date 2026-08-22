<?php
/**
 * Log effort against a project code, and read back the week already logged.
 *
 * @var string $weekStart
 * @var string $weekEnd
 * @var string $weekPrevious
 * @var string $weekNext
 * @var bool   $weekNextReached
 * @var array<string, array{entries: list<array<string, mixed>>, hours: float}> $byDay
 * @var int    $entryCount
 * @var bool   $truncated  True when the week holds more entries than were fetched.
 * @var array{hours: float, billable: float, approved: float, pending: int} $totals
 * @var string $today
 * @var array<string, mixed> $projectChart
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$actions = '<a href="/attendance" class="btn btn-outline-secondary btn-sm">'
    . '<i class="fa fa-clock"></i> Back to my attendance</a>';

$weekQuery = '?week_of=' . urlencode($weekStart);
?>

<?php View::partial('page-header', [
    'title' => 'Timesheets',
    'subtitle' => 'What the hours on the clock were spent on.',
    'actions' => $actions,
]) ?>

<?php if ($truncated): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="fa fa-exclamation-triangle mt-1"></i>
        <div>
            This week holds more entries than one page can show, so the totals and the
            chart below describe only the first <?= e((string) $entryCount) ?>.
            Narrow the week or ask an approver to clear the backlog.
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="tile h-100">
            <div class="tile-icon"><i class="fa fa-hourglass-half"></i></div>
            <div>
                <div class="tile-label">Logged</div>
                <div class="tile-value tabular"><?= e(number_format($totals['hours'], 2)) ?></div>
                <div class="tile-hint">hours this week</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tile tile-success h-100">
            <div class="tile-icon"><i class="fa fa-briefcase"></i></div>
            <div>
                <div class="tile-label">Billable</div>
                <div class="tile-value tabular"><?= e(number_format($totals['billable'], 2)) ?></div>
                <div class="tile-hint">hours a client can be charged</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tile tile-info h-100">
            <div class="tile-icon"><i class="fa fa-check-circle"></i></div>
            <div>
                <div class="tile-label">Approved</div>
                <div class="tile-value tabular"><?= e(number_format($totals['approved'], 2)) ?></div>
                <div class="tile-hint">hours signed off</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tile tile-warning h-100">
            <div class="tile-icon"><i class="fa fa-hourglass-start"></i></div>
            <div>
                <div class="tile-label">Awaiting</div>
                <div class="tile-value tabular"><?= e((string) $totals['pending']) ?></div>
                <div class="tile-hint">entries not yet approved</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">

    <div class="col-12 col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-plus"></i> Log time</div>
            <div class="card-body">
                <form method="post" action="/attendance/timesheets" novalidate>
                    <?= Csrf::field() ?>

                    <div class="mb-3">
                        <label for="work_date" class="form-label">Date</label>
                        <input type="date"
                               class="form-control <?= Flash::hasError('work_date') ? 'is-invalid' : '' ?>"
                               id="work_date"
                               name="work_date"
                               max="<?= e($today) ?>"
                               value="<?= e(Flash::old('work_date', $today)) ?>"
                               required>
                        <?php View::partial('field-errors', ['name' => 'work_date']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="project_code" class="form-label">Project code</label>
                        <input type="text"
                               class="form-control text-uppercase <?= Flash::hasError('project_code') ? 'is-invalid' : '' ?>"
                               id="project_code"
                               name="project_code"
                               maxlength="40"
                               placeholder="DF-PLATFORM"
                               value="<?= e(Flash::old('project_code')) ?>"
                               required>
                        <?php View::partial('field-errors', ['name' => 'project_code']) ?>
                        <div class="form-text">Letters, numbers, hyphens and underscores.</div>
                    </div>

                    <div class="mb-3">
                        <label for="task_description" class="form-label">What you worked on</label>
                        <textarea class="form-control <?= Flash::hasError('task_description') ? 'is-invalid' : '' ?>"
                                  id="task_description"
                                  name="task_description"
                                  rows="3"
                                  maxlength="500"
                                  placeholder="Migrated the payroll export to the new schema."
                                  required><?= e(Flash::old('task_description')) ?></textarea>
                        <?php View::partial('field-errors', ['name' => 'task_description']) ?>
                        <div class="text-end">
                            <span class="form-text" data-counter-for="task_description"></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="hours" class="form-label">Hours</label>
                        <input type="number"
                               class="form-control <?= Flash::hasError('hours') ? 'is-invalid' : '' ?>"
                               id="hours"
                               name="hours"
                               min="0.25"
                               max="24"
                               step="0.25"
                               value="<?= e(Flash::old('hours')) ?>"
                               required>
                        <?php View::partial('field-errors', ['name' => 'hours']) ?>
                        <div class="form-text">A quarter of an hour at a time, up to 24 in one day.</div>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input"
                               type="checkbox"
                               id="billable"
                               name="billable"
                               value="1"
                            <?= Flash::old('billable', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="billable">Billable to the client</label>
                    </div>

                    <?php /* Not named "submit": a control by that name shadows form.submit(). */ ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input"
                               type="checkbox"
                               id="submit_for_approval"
                               name="submit_for_approval"
                               value="1"
                            <?= Flash::old('submit_for_approval') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="submit_for_approval">Send straight for approval</label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" data-busy-label="Saving...">
                        <i class="fa fa-plus"></i> Log this time
                    </button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fa fa-project-diagram"></i> Where the week went</div>
            <div class="card-body">
                <div data-chart='<?= ejs($projectChart) ?>'></div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>
                    <i class="fa fa-calendar-day"></i>
                    <?= e(date_display($weekStart)) ?> &ndash; <?= e(date_display($weekEnd)) ?>
                </span>
                <span class="btn-group btn-group-sm">
                    <a class="btn btn-outline-secondary"
                       href="/attendance/timesheets?week_of=<?= e($weekPrevious) ?>"
                       aria-label="Previous week">
                        <i class="fa fa-chevron-left"></i>
                    </a>
                    <a class="btn btn-outline-secondary <?= $weekNextReached ? '' : 'disabled' ?>"
                       href="/attendance/timesheets?week_of=<?= e($weekNext) ?>"
                       aria-label="Next week">
                        <i class="fa fa-chevron-right"></i>
                    </a>
                </span>
            </div>
            <div class="card-body">
                <?php if ($entryCount === 0): ?>
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-tasks',
                        'title' => 'Nothing logged this week',
                        'message' => 'Record what you worked on using the form beside this list.',
                    ]) ?>
                <?php else: ?>
                    <?php foreach ($byDay as $date => $day): ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-baseline">
                                <div class="section-label mb-1">
                                    <?= e(date_display($date)) ?>
                                    &middot; <?= e(date('l', (int) strtotime($date))) ?>
                                    <?= $date === $today ? '&middot; today' : '' ?>
                                </div>
                                <div class="small <?= $day['hours'] > 0 ? 'fw-semibold' : 'text-muted' ?> tabular">
                                    <?= e(number_format($day['hours'], 2)) ?> h
                                </div>
                            </div>

                            <?php if ($day['entries'] === []): ?>
                                <div class="small text-muted border rounded p-2">Nothing logged.</div>
                            <?php else: ?>
                                <div class="table-wrap">
                                    <table class="table">
                                        <tbody>
                                            <?php foreach ($day['entries'] as $entry): ?>
                                                <tr>
                                                    <td style="width: 22%;">
                                                        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                                                            <?= e($entry['project_code'] ?? '—') ?>
                                                        </span>
                                                    </td>
                                                    <td><?= e($entry['task_description'] ?? '') ?></td>
                                                    <td class="text-end tabular" style="width: 12%;">
                                                        <?= e(number_format((float) ($entry['hours'] ?? 0), 2)) ?> h
                                                    </td>
                                                    <td style="width: 14%;">
                                                        <?php if (($entry['billable'] ?? false) === true): ?>
                                                            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                                                Billable
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted small">Internal</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="width: 18%;">
                                                        <?= badge($entry['status'] ?? null) ?>
                                                        <?php if (!empty($entry['approved_at'])): ?>
                                                            <span class="small text-muted d-block">
                                                                <?= e(relative_time($entry['approved_at'])) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="d-flex justify-content-between align-items-baseline border-top pt-2">
                        <span class="section-label mb-0">Week total</span>
                        <span class="fw-semibold tabular"><?= e(number_format($totals['hours'], 2)) ?> h</span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer text-muted">
                Entries stay editable until an approver signs them off.
                <a href="<?= e('/attendance/timesheets' . $weekQuery) ?>">Refresh this week</a>.
            </div>
        </div>
    </div>

</div>
