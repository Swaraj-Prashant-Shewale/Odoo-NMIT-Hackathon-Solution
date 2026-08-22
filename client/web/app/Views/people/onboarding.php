<?php
/**
 * Everyone with an open joiner checklist.
 *
 * The list is driven by the tasks, not by a date: somebody appears here while
 * anything is still outstanding and disappears the moment the last item is
 * ticked off, however long that takes.
 *
 * @var list<array<string, mixed>> $joiners
 * @var array<string, mixed>       $summary
 */

use App\Core\Csrf;
use App\Core\View;

$today = date('Y-m-d');

$totalPeople = (int) ($summary['total'] ?? count($joiners));
$peopleOverdue = (int) ($summary['overdue'] ?? 0);

$outstandingTotal = array_sum(array_map(
    static fn (array $joiner): int => (int) ($joiner['outstanding'] ?? 0),
    $joiners
));

$chartLabels = array_map(
    static fn (array $joiner): string => (string) ($joiner['full_name'] ?? 'Unnamed'),
    array_slice($joiners, 0, 12)
);

$chartValues = array_map(
    static fn (array $joiner): int => (int) ($joiner['outstanding'] ?? 0),
    array_slice($joiners, 0, 12)
);

ob_start(); ?>
<a href="/people" class="btn btn-outline-secondary"><i class="fa fa-users"></i> People</a>
<?php $actions = (string) ob_get_clean();

View::partial('page-header', [
    'title' => 'Onboarding',
    'subtitle' => 'Joiner checklists still in flight, and what is holding each of them up.',
    'actions' => $actions,
]);
?>

<?php if ($joiners === []): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-clipboard-list',
                'title' => 'Every checklist is complete',
                'message' => 'Nobody has an outstanding onboarding task. A new checklist appears here as soon '
                    . 'as a person record is created.',
                'actionLabel' => 'Add employee',
                'actionHref' => '/people/new',
            ]) ?>
        </div>
    </div>
<?php else: ?>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="tile tile-info">
                <div class="tile-icon"><i class="fa fa-user-clock"></i></div>
                <div class="tile-label">Joiners in flight</div>
                <div class="tile-value tabular"><?= e((string) $totalPeople) ?></div>
                <div class="tile-hint">With at least one open task</div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="tile">
                <div class="tile-icon"><i class="fa fa-tasks"></i></div>
                <div class="tile-label">Open tasks</div>
                <div class="tile-value tabular"><?= e((string) $outstandingTotal) ?></div>
                <div class="tile-hint">Across every checklist</div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="tile <?= $peopleOverdue > 0 ? 'tile-danger' : 'tile-success' ?>">
                <div class="tile-icon"><i class="fa fa-exclamation-triangle"></i></div>
                <div class="tile-label">Checklists running late</div>
                <div class="tile-value tabular"><?= e((string) $peopleOverdue) ?></div>
                <div class="tile-hint"><?= $peopleOverdue > 0 ? 'Past their due date' : 'Nothing is overdue' ?></div>
            </div>
        </div>
    </div>

    <?php if (count($chartValues) > 1): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-tasks"></i> Open tasks per joiner</div>
            <div class="card-body">
                <div data-chart='<?= ejs([
                    'type' => 'bar',
                    'values' => $chartValues,
                    'labels' => $chartLabels,
                    'format' => 'number',
                    'colour' => '#d97706',
                ]) ?>'></div>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($joiners as $joiner): ?>
        <?php
        $employeeId = (string) ($joiner['employee_id'] ?? '');
        $total = (int) ($joiner['total'] ?? 0);
        $completed = (int) ($joiner['completed'] ?? 0);
        $overdue = (int) ($joiner['overdue'] ?? 0);
        $progress = $total > 0 ? percent(($completed / $total) * 100) : 0;
        $tasks = is_array($joiner['tasks'] ?? null) ? $joiner['tasks'] : [];
        ?>
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="avatar"><?= e(initials($joiner['full_name'] ?? '')) ?></span>
                    <div>
                        <a href="/people/<?= e($employeeId) ?>" class="fw-semibold">
                            <?= field($joiner, 'full_name', 'Unnamed') ?>
                        </a>
                        <div class="small text-muted">
                            <span class="tabular"><?= field($joiner, 'employee_code') ?></span>
                            &middot; <?= field($joiner, 'designation_name', 'Designation not set') ?>
                            &middot; <?= field($joiner, 'department_name', 'Department not set') ?>
                        </div>
                    </div>
                </div>

                <div class="text-end small text-muted">
                    Joined <?= e(date_display($joiner['joined_on'] ?? null)) ?>
                    <?php if (!empty($joiner['next_due_on'])): ?>
                        <div>Next due <?= e(date_display($joiner['next_due_on'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-1">
                    <span class="section-label mb-0">Checklist</span>
                    <span class="small text-muted">
                        <?= e((string) $completed) ?> of <?= e((string) $total) ?> done
                        <?php if ($overdue > 0): ?>
                            &middot; <span class="text-danger"><?= e((string) $overdue) ?> overdue</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="progress progress-lg mb-3">
                    <div class="progress-bar bg-<?= $overdue > 0 ? 'warning' : 'success' ?>"
                         role="progressbar"
                         style="width: <?= e((string) $progress) ?>%"
                         aria-valuenow="<?= e((string) $progress) ?>"
                         aria-valuemin="0" aria-valuemax="100"></div>
                </div>

                <?php if ($tasks === []): ?>
                    <p class="small text-muted mb-0">
                        The outstanding tasks for this person could not be loaded just now. Their record has
                        the full checklist.
                    </p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table align-middle">
                            <thead>
                            <tr>
                                <th>Task</th>
                                <th>Category</th>
                                <th>Owner</th>
                                <th>Due</th>
                                <th class="text-end">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($tasks as $task): ?>
                                <?php
                                $dueOn = (string) ($task['due_on'] ?? '');
                                $isOverdue = $dueOn !== '' && $dueOn < $today;
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= field($task, 'title', 'Untitled task') ?></div>
                                        <?php if (!empty($task['description'])): ?>
                                            <div class="small text-muted truncate" style="max-width: 420px;">
                                                <?= e($task['description']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e(label($task['category'] ?? null, 'General')) ?></td>
                                    <td><?= e(label($task['owner_role'] ?? null, 'HR')) ?></td>
                                    <td class="tabular">
                                        <?= e(date_display($task['due_on'] ?? null, 'No date')) ?>
                                        <?php if ($isOverdue): ?>
                                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                                                Overdue
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="post"
                                              action="/onboarding/<?= e($task['id'] ?? '') ?>/complete"
                                              class="m-0">
                                            <?= Csrf::field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-success"
                                                    data-busy-label="Saving...">
                                                <i class="fa fa-check"></i> Complete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

<?php endif; ?>
