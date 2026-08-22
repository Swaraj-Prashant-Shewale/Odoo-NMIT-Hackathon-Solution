<?php
/**
 * Courses still to finish, and goals still to hit.
 *
 * @var array<string, array{available: bool, data: mixed}> $sections
 * @var string   $today
 * @var callable $data
 * @var callable $offline
 * @var callable $rows
 * @var callable $placeholder
 */
?>

<?php if (isset($sections['learning']) || isset($sections['goals'])): ?>
    <div class="row g-4 mb-4">

        <?php if (isset($sections['learning'])): ?>
            <div class="col-lg-6">
                <?php if ($offline('learning')): ?>
                    <?php $placeholder('Your learning', 'The learning service did not answer this time.') ?>
                <?php else: ?>
                    <?php
                    $learning = $data('learning');
                    $courses = $rows($learning['courses'] ?? null);
                    $overdue = (int) ($learning['overdue'] ?? 0);
                    ?>
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="fa fa-graduation-cap"></i> Your learning</span>
                            <?php if ($overdue > 0): ?>
                                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                                    <?= e((string) $overdue) ?> overdue
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-wrap gap-4 mb-3">
                                <div>
                                    <div class="tile-label">Assigned</div>
                                    <div class="fs-4 fw-bold tabular"><?= e((string) (int) ($learning['assigned'] ?? 0)) ?></div>
                                </div>
                                <div>
                                    <div class="tile-label">In progress</div>
                                    <div class="fs-4 fw-bold tabular"><?= e((string) (int) ($learning['in_progress'] ?? 0)) ?></div>
                                </div>
                                <div>
                                    <div class="tile-label">Completed</div>
                                    <div class="fs-4 fw-bold tabular"><?= e((string) (int) ($learning['completed'] ?? 0)) ?></div>
                                </div>
                            </div>

                            <?php if ($courses === []): ?>
                                <p class="text-muted small mb-0 text-center py-3">
                                    <i class="fa fa-check-circle"></i>
                                    Nothing outstanding. Everything assigned to you is finished.
                                </p>
                            <?php else: ?>
                                <?php foreach ($courses as $course): ?>
                                    <?php $progress = percent($course['progress'] ?? 0); ?>
                                    <div class="lesson-row py-2">
                                        <div class="d-flex justify-content-between align-items-baseline gap-2">
                                            <a class="fw-semibold truncate"
                                               href="/learning/courses/<?= e((string) ($course['course_id'] ?? '')) ?>">
                                                <?= e((string) ($course['title'] ?? 'Course')) ?>
                                            </a>
                                            <span class="small text-muted tabular text-nowrap"><?= e((string) $progress) ?>%</span>
                                        </div>
                                        <div class="progress mt-1">
                                            <div class="progress-bar <?= !empty($course['is_overdue']) ? 'bg-danger' : '' ?>"
                                                 style="width: <?= e((string) $progress) ?>%"
                                                 role="progressbar"
                                                 aria-valuenow="<?= e((string) $progress) ?>"
                                                 aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                        <div class="small mt-1">
                                            <?php if (!empty($course['is_mandatory'])): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Mandatory</span>
                                            <?php endif; ?>
                                            <?php if (!empty($course['due_on'])): ?>
                                                <span class="<?= !empty($course['is_overdue']) ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                                    <?= !empty($course['is_overdue']) ? 'Was due' : 'Due' ?>
                                                    <?= e(date_display((string) $course['due_on'])) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">No deadline</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer">
                            <a href="/learning">My learning</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($sections['goals'])): ?>
            <div class="col-lg-6">
                <?php if ($offline('goals')): ?>
                    <?php $placeholder('Your goals', 'The performance service did not answer this time.') ?>
                <?php else: ?>
                    <?php
                    $goalSection = $data('goals');
                    $goals = $rows($goalSection['goals'] ?? null);
                    $average = percent($goalSection['average_progress'] ?? 0);
                    ?>
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="fa fa-bullseye"></i> Your goals</span>
                            <span class="small text-muted tabular"><?= e((string) $average) ?>% average</span>
                        </div>
                        <div class="card-body">
                            <?php if ($goals === []): ?>
                                <p class="text-muted small mb-0 text-center py-3">
                                    You have no active goals. Setting one keeps your next review straightforward.
                                </p>
                            <?php else: ?>
                                <?php foreach ($goals as $goal): ?>
                                    <?php
                                    $progress = percent($goal['progress'] ?? 0);
                                    $dueOn = (string) ($goal['due_on'] ?? '');
                                    $isLate = $dueOn !== '' && $dueOn < $today && $progress < 100;
                                    ?>
                                    <div class="py-2">
                                        <div class="d-flex justify-content-between align-items-baseline gap-2">
                                            <span class="fw-semibold truncate"><?= e((string) ($goal['title'] ?? 'Goal')) ?></span>
                                            <span class="small text-muted tabular text-nowrap"><?= e((string) $progress) ?>%</span>
                                        </div>
                                        <div class="progress mt-1">
                                            <div class="progress-bar <?= $isLate ? 'bg-warning' : '' ?>"
                                                 style="width: <?= e((string) $progress) ?>%"
                                                 role="progressbar"
                                                 aria-valuenow="<?= e((string) $progress) ?>"
                                                 aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                        <div class="small text-muted mt-1">
                                            <?php if ($dueOn !== ''): ?>
                                                <?= $isLate ? 'Overdue since' : 'Due' ?> <?= e(date_display($dueOn)) ?>
                                            <?php else: ?>
                                                No target date
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer">
                            <a href="/performance">My performance</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
<?php endif; ?>
