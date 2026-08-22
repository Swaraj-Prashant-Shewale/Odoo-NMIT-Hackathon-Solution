<?php
/**
 * Mandatory training compliance across the organisation.
 *
 * @var array                      $summary
 * @var string                     $asOf
 * @var list<array<string, mixed>> $courses     Per-course breakdown with its audience.
 * @var list<array<string, mixed>> $outstanding Everyone who still owes a course.
 * @var list<string>               $departments Department names present in the report.
 * @var string                     $department  The department currently filtered on.
 * @var list<array<string, mixed>> $people      Distinct people in the outstanding list.
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$rate = (float) ($summary['completion_rate'] ?? 0);
$completed = (int) ($summary['completed'] ?? 0);
$obligations = (int) ($summary['obligations'] ?? 0);
$outstandingCount = (int) ($summary['outstanding'] ?? 0);
?>

<?php View::partial('page-header', [
    'title' => 'Training compliance',
    'subtitle' => 'Who owes which mandatory course, and how far past its deadline they are.',
    'actions' => '<a href="/learning/manage" class="btn btn-outline-secondary">'
        . '<i class="fa fa-book"></i> Manage catalogue</a>',
]) ?>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="fa fa-chart-pie"></i> Overall compliance</span>
                <span class="small text-muted">as at <?= e(date_display($asOf)) ?></span>
            </div>
            <div class="card-body">
                <div data-chart='<?= ejs([
                    'type' => 'donut',
                    'values' => [$completed, $outstandingCount],
                    'labels' => ['Completed', 'Outstanding'],
                    'centreValue' => round($rate) . '%',
                    'centreLabel' => 'compliant',
                ]) ?>'></div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="row g-3">
            <div class="col-6 col-xl-3">
                <div class="tile tile-info">
                    <div class="tile-icon"><i class="fa fa-shield-alt"></i></div>
                    <div class="tile-label">Mandatory courses</div>
                    <div class="tile-value tabular"><?= e((string) (int) ($summary['mandatory_courses'] ?? 0)) ?></div>
                    <div class="tile-hint">in the catalogue</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="tile">
                    <div class="tile-icon"><i class="fa fa-clipboard-list"></i></div>
                    <div class="tile-label">Obligations</div>
                    <div class="tile-value tabular"><?= e((string) $obligations) ?></div>
                    <div class="tile-hint">across <?= e((string) (int) ($summary['employees'] ?? 0)) ?> people</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="tile tile-warning">
                    <div class="tile-icon"><i class="fa fa-hourglass-half"></i></div>
                    <div class="tile-label">Outstanding</div>
                    <div class="tile-value tabular"><?= e((string) $outstandingCount) ?></div>
                    <div class="tile-hint">still to complete</div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="tile <?= ((int) ($summary['overdue'] ?? 0)) > 0 ? 'tile-danger' : 'tile-success' ?>">
                    <div class="tile-icon"><i class="fa fa-exclamation-triangle"></i></div>
                    <div class="tile-label">Overdue</div>
                    <div class="tile-value tabular"><?= e((string) (int) ($summary['overdue'] ?? 0)) ?></div>
                    <div class="tile-hint">past their due date</div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header"><span><i class="fa fa-chart-bar"></i> Completion by course</span></div>
                    <div class="card-body">
                        <div data-chart='<?= ejs([
                            'type' => 'bar',
                            'format' => 'percent',
                            'colourByIndex' => true,
                            'values' => array_map(
                                static fn (array $course): float => (float) ($course['completion_rate'] ?? 0),
                                $courses
                            ),
                            'labels' => array_map(
                                static fn (array $course): string => (string) ($course['title'] ?? ''),
                                $courses
                            ),
                        ]) ?>'></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><span><i class="fa fa-list"></i> Course breakdown</span></div>
    <div class="card-body">
        <?php if ($courses === []): ?>
            <?php View::partial('empty-state', [
                'icon' => 'fa-shield-alt',
                'title' => 'No mandatory training',
                'message' => 'No published course is marked as mandatory, so there is nothing to report on.',
                'actionLabel' => 'Manage the catalogue',
                'actionHref' => '/learning/manage',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th class="text-end">Audience</th>
                            <th class="text-end">Completed</th>
                            <th class="text-end">In progress</th>
                            <th class="text-end">Not started</th>
                            <th class="text-end">Overdue</th>
                            <th style="min-width: 160px;">Completion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $course): ?>
                            <?php
                            $totals = is_array($course['totals'] ?? null) ? $course['totals'] : [];
                            $courseRate = percent($course['completion_rate'] ?? 0);
                            $notStarted = (int) ($totals['not_started'] ?? 0) + (int) ($totals['not_enrolled'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <a href="<?= e('/learning/courses/' . rawurlencode((string) ($course['course_id'] ?? ''))) ?>"
                                       class="fw-semibold text-decoration-none">
                                        <?= e((string) ($course['title'] ?? '')) ?>
                                    </a>
                                    <div class="small text-muted">
                                        <?= e(label((string) ($course['level'] ?? ''))) ?>
                                        &middot; <?= e((string) (int) ($course['estimated_minutes'] ?? 0)) ?> min
                                    </div>
                                </td>
                                <td class="text-end tabular"><?= e((string) (int) ($totals['audience'] ?? 0)) ?></td>
                                <td class="text-end tabular text-success"><?= e((string) (int) ($totals['completed'] ?? 0)) ?></td>
                                <td class="text-end tabular"><?= e((string) (int) ($totals['in_progress'] ?? 0)) ?></td>
                                <td class="text-end tabular"><?= e((string) $notStarted) ?></td>
                                <td class="text-end tabular <?= (int) ($totals['overdue'] ?? 0) > 0 ? 'text-danger fw-semibold' : '' ?>">
                                    <?= e((string) (int) ($totals['overdue'] ?? 0)) ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1">
                                            <div class="progress-bar <?= $courseRate >= 90 ? 'bg-success' : ($courseRate >= 60 ? '' : 'bg-danger') ?>"
                                                 role="progressbar"
                                                 style="width: <?= e((string) $courseRate) ?>%"
                                                 aria-valuenow="<?= e((string) $courseRate) ?>"
                                                 aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                        <span class="small fw-semibold tabular"><?= e((string) $courseRate) ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <span>
            <i class="fa fa-user-clock"></i> Outstanding
            <?= $department === '' ? '' : '&middot; ' . e($department) ?>
        </span>
        <form method="get" action="/learning/compliance" class="m-0">
            <label class="visually-hidden" for="department">Department</label>
            <select class="form-select form-select-sm" id="department" name="department" data-submit-on-change>
                <option value="">Every department</option>
                <?php foreach ($departments as $name): ?>
                    <option value="<?= e($name) ?>" <?= $department === $name ? 'selected' : '' ?>>
                        <?= e($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="card-body">
        <?php if ($outstanding === []): ?>
            <?php View::partial('empty-state', [
                'icon' => 'fa-check-double',
                'title' => 'Everyone is compliant',
                'message' => $department === ''
                    ? 'Every person in scope has completed every mandatory course.'
                    : 'Nobody in that department has mandatory training outstanding.',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table" id="outstandingTable">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th>Department</th>
                            <th>Course</th>
                            <th>Status</th>
                            <th style="min-width: 120px;">Progress</th>
                            <th>Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($outstanding as $row): ?>
                            <?php $overdue = !empty($row['is_overdue']); ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar avatar-sm">
                                            <?= e(initials((string) ($row['full_name'] ?? ''))) ?>
                                        </span>
                                        <div class="overflow-hidden">
                                            <div class="fw-semibold truncate">
                                                <?= e((string) ($row['full_name'] ?? 'Unknown employee')) ?>
                                            </div>
                                            <div class="small text-muted"><?= field($row, 'employee_code') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= field($row, 'department_name') ?></td>
                                <td class="cell-truncate"><?= e((string) ($row['course_title'] ?? '')) ?></td>
                                <td><?= badge((string) ($row['status'] ?? '')) ?></td>
                                <td>
                                    <div class="progress">
                                        <div class="progress-bar <?= $overdue ? 'bg-danger' : '' ?>" role="progressbar"
                                             style="width: <?= e((string) percent($row['progress_percent'] ?? 0)) ?>%"
                                             aria-valuenow="<?= e((string) percent($row['progress_percent'] ?? 0)) ?>"
                                             aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <span class="small text-muted tabular"><?= e((string) percent($row['progress_percent'] ?? 0)) ?>%</span>
                                </td>
                                <td>
                                    <?php if (empty($row['due_on'])): ?>
                                        <span class="text-muted">No deadline</span>
                                    <?php else: ?>
                                        <span class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>">
                                            <?= e(date_display((string) $row['due_on'])) ?>
                                        </span>
                                        <div class="small <?= $overdue ? 'text-danger' : 'text-muted' ?>">
                                            <?php if ($overdue): ?>
                                                <i class="fa fa-exclamation-circle"></i>
                                            <?php endif; ?>
                                            due <?= e(relative_time((string) $row['due_on'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-footer text-muted">
        <?= e((string) count($outstanding)) ?> outstanding
        <?= count($outstanding) === 1 ? 'obligation' : 'obligations' ?> listed.
        Someone with no enrolment at all still appears here, because the obligation is theirs either way.
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span><i class="fa fa-user-plus"></i> Assign a course</span>
        <span class="small text-muted">Picked from the people listed above</span>
    </div>
    <form method="post" action="/learning/assign">
        <?= Csrf::field() ?>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-lg-5">
                    <label for="assign_courses" class="form-label">Courses</label>
                    <select class="form-select" id="assign_courses" name="course_ids[]" multiple size="6" required>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?= e((string) ($course['course_id'] ?? '')) ?>">
                                <?= e((string) ($course['title'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($courses === []): ?>
                        <div class="form-text text-danger">
                            No course is marked as mandatory, so there is nothing to assign from here.
                        </div>
                    <?php endif; ?>
                    <?php View::partial('field-errors', ['name' => 'course_id']) ?>
                </div>

                <div class="col-12 col-lg-5">
                    <label for="assign_people" class="form-label">People</label>
                    <select class="form-select" id="assign_people" name="employee_ids[]" multiple size="6" required>
                        <?php foreach ($people as $person): ?>
                            <option value="<?= e((string) ($person['id'] ?? '')) ?>">
                                <?= e((string) ($person['full_name'] ?? 'Unknown employee')) ?><?php
                                echo ($person['department_name'] ?? '') === ''
                                    ? ''
                                    : ' — ' . e((string) $person['department_name']);
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php View::partial('field-errors', ['name' => 'employee_ids']) ?>
                    <?php if ($people === []): ?>
                        <div class="form-text text-danger">
                            <?= $department === ''
                                ? 'Nobody has mandatory training outstanding, so there is nobody listed to assign to.'
                                : 'Nobody in that department has training outstanding. Clear the filter to reach everyone else.' ?>
                        </div>
                    <?php else: ?>
                        <div class="form-text">Hold Ctrl, or Command, to pick more than one.</div>
                    <?php endif; ?>
                </div>

                <div class="col-12 col-lg-2">
                    <label for="assign_due" class="form-label">Due date</label>
                    <input type="date" class="form-control" id="assign_due" name="due_on"
                           min="<?= e(date('Y-m-d')) ?>" value="<?= e(Flash::old('due_on')) ?>">
                    <?php View::partial('field-errors', ['name' => 'due_on']) ?>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end">
            <button type="submit" class="btn btn-primary" data-busy-label="Assigning...">
                <i class="fa fa-paper-plane"></i> Assign
            </button>
        </div>
    </form>
</div>
