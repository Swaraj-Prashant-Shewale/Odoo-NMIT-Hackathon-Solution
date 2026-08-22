<?php
/**
 * The learner's home screen.
 *
 * @var array                       $summary       Counts and the quarter's watch time.
 * @var list<array<string, mixed>>  $obligations   Assigned and mandatory courses, most urgent first.
 * @var list<array<string, mixed>>  $others        Courses under way that no other section carries.
 * @var list<array<string, mixed>>  $completed     Recently completed enrolments.
 * @var array<string, array>        $certificates  Certificates keyed by course id.
 * @var array|null                  $continue      The course to carry on with.
 * @var array|null                  $assignment    Courses and people this user may assign to.
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$thumbnail = static function (array $course, array $lessons): ?string {
    $stored = $course['thumbnail_url'] ?? null;

    if (is_string($stored) && $stored !== '') {
        return $stored;
    }

    foreach ($lessons as $lesson) {
        if (isset($lesson['video_id'])) {
            return 'https://img.youtube.com/vi/' . $lesson['video_id'] . '/hqdefault.jpg';
        }
    }

    return null;
};
?>

<?php View::partial('page-header', [
    'title' => 'Learning',
    'subtitle' => 'Your courses, the training you owe, and everything you have earned.',
    'actions' => '<a href="/learning/catalogue" class="btn btn-primary"><i class="fa fa-th-large"></i> Browse catalogue</a>'
        . '<a href="/learning/certificates" class="btn btn-outline-secondary"><i class="fa fa-certificate"></i> Certificates</a>',
]) ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="tile tile-info">
            <div class="tile-icon"><i class="fa fa-play-circle"></i></div>
            <div class="tile-label">In progress</div>
            <div class="tile-value tabular"><?= e((string) (int) ($summary['in_progress'] ?? 0)) ?></div>
            <div class="tile-hint">of <?= e((string) (int) ($summary['enrolled'] ?? 0)) ?> enrolments</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tile tile-success">
            <div class="tile-icon"><i class="fa fa-check-circle"></i></div>
            <div class="tile-label">Completed</div>
            <div class="tile-value tabular"><?= e((string) (int) ($summary['completed'] ?? 0)) ?></div>
            <div class="tile-hint">courses finished</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tile <?= ((int) ($summary['overdue'] ?? 0)) > 0 ? 'tile-danger' : 'tile-success' ?>">
            <div class="tile-icon"><i class="fa fa-exclamation-triangle"></i></div>
            <div class="tile-label">Overdue</div>
            <div class="tile-value tabular"><?= e((string) (int) ($summary['overdue'] ?? 0)) ?></div>
            <div class="tile-hint">
                <?= ((int) ($summary['overdue'] ?? 0)) > 0 ? 'past their due date' : 'nothing is late' ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tile tile-warning">
            <div class="tile-icon"><i class="fa fa-stopwatch"></i></div>
            <div class="tile-label">Learning time</div>
            <div class="tile-value tabular"><?= e((string) (int) ($summary['quarter_minutes'] ?? 0)) ?> min</div>
            <div class="tile-hint">
                <?= e((string) ($summary['quarter_time_label'] ?? '0m')) ?> in <?= e((string) ($summary['quarter'] ?? 'this quarter')) ?>
            </div>
        </div>
    </div>
</div>

<?php if ($continue !== null): ?>
    <?php
    $course = $continue['course'];
    $enrolment = $continue['enrolment'];
    $lessons = $continue['lessons'];
    $next = $continue['next'];
    $cover = $thumbnail($course, $lessons);
    $courseHref = '/learning/courses/' . rawurlencode((string) ($course['id'] ?? ''));
    $watched = 0;

    foreach ($lessons as $lesson) {
        if (!empty($lesson['is_completed'])) {
            $watched++;
        }
    }
    ?>
    <div class="card mb-4">
        <div class="card-header">
            <span><i class="fa fa-redo"></i> Continue where you left off</span>
            <span class="small text-muted"><?= e(relative_time((string) ($enrolment['updated_at'] ?? ''))) ?></span>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-12 col-md-4 col-lg-3">
                    <div class="course-thumb">
                        <?php if ($cover !== null): ?>
                            <img src="<?= e($cover) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="empty-state-icon position-absolute top-50 start-50 translate-middle">
                                <i class="fa fa-graduation-cap"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12 col-md-8 col-lg-9">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                            <?= e(label((string) ($course['level'] ?? ''))) ?>
                        </span>
                        <?php if (!empty($course['is_mandatory'])): ?>
                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">Mandatory</span>
                        <?php endif; ?>
                        <?php if (!empty($enrolment['is_overdue'])): ?>
                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                                Overdue
                            </span>
                        <?php endif; ?>
                    </div>

                    <h2 class="h5 mb-1">
                        <a href="<?= e($courseHref) ?>" class="text-decoration-none"><?= e((string) ($course['title'] ?? 'Course')) ?></a>
                    </h2>
                    <p class="text-muted small mb-3"><?= e((string) ($course['summary'] ?? '')) ?></p>

                    <div class="d-flex justify-content-between align-items-baseline small mb-1">
                        <span class="text-muted">
                            <?= e((string) $watched) ?> of <?= e((string) count($lessons)) ?> lessons watched
                        </span>
                        <span class="fw-semibold tabular"><?= e((string) percent($enrolment['progress_percent'] ?? 0)) ?>%</span>
                    </div>
                    <div class="progress progress-lg mb-3">
                        <div class="progress-bar" role="progressbar"
                             style="width: <?= e((string) percent($enrolment['progress_percent'] ?? 0)) ?>%"
                             aria-valuenow="<?= e((string) percent($enrolment['progress_percent'] ?? 0)) ?>"
                             aria-valuemin="0" aria-valuemax="100"></div>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($next !== null): ?>
                            <a class="btn btn-primary"
                               href="<?= e($courseHref . '/lessons/' . rawurlencode((string) ($next['id'] ?? ''))) ?>">
                                <i class="fa fa-play"></i> Continue: <?= e((string) ($next['title'] ?? 'Next lesson')) ?>
                            </a>
                        <?php endif; ?>
                        <a class="btn btn-outline-secondary" href="<?= e($courseHref) ?>">
                            <i class="fa fa-list-ul"></i> Course outline
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="fa fa-clipboard-check"></i> Assigned and mandatory</span>
                <span class="small text-muted"><?= e((string) count($obligations)) ?> courses</span>
            </div>
            <div class="card-body">
                <?php if ($obligations === []): ?>
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-clipboard-check',
                        'title' => 'Nothing required of you',
                        'message' => 'No training has been assigned to you and no mandatory course is outstanding.',
                        'actionLabel' => 'Browse the catalogue',
                        'actionHref' => '/learning/catalogue',
                    ]) ?>
                <?php else: ?>
                    <?php foreach ($obligations as $row): ?>
                        <?php $href = '/learning/courses/' . rawurlencode((string) ($row['course_id'] ?? '')); ?>
                        <div class="lesson-row <?= !empty($row['is_overdue']) ? 'is-current' : '' ?>">
                            <span class="lesson-index <?= !empty($row['is_overdue']) ? 'bg-danger text-white' : '' ?>">
                                <i class="fa <?= !empty($row['course_is_mandatory']) ? 'fa-shield-alt' : 'fa-user-check' ?>"></i>
                            </span>
                            <div class="flex-grow-1 overflow-hidden">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <a href="<?= e($href) ?>" class="fw-semibold text-decoration-none truncate">
                                        <?= e((string) ($row['course_title'] ?? 'Course')) ?>
                                    </a>
                                    <?php if (!empty($row['course_is_mandatory'])): ?>
                                        <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">Mandatory</span>
                                    <?php endif; ?>
                                    <?= badge((string) ($row['status'] ?? 'not_started')) ?>
                                </div>
                                <div class="small text-muted">
                                    <?php if (!empty($row['due_on'])): ?>
                                        <?php if (!empty($row['is_overdue'])): ?>
                                            <span class="text-danger fw-semibold">
                                                <i class="fa fa-exclamation-circle"></i>
                                                Due <?= e(date_display((string) $row['due_on'])) ?>
                                                &middot; <?= e(relative_time((string) $row['due_on'])) ?>
                                            </span>
                                        <?php else: ?>
                                            Due <?= e(date_display((string) $row['due_on'])) ?>
                                            &middot; <?= e(relative_time((string) $row['due_on'])) ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        No deadline
                                    <?php endif; ?>
                                    &middot; <?= e((string) (int) ($row['course_estimated_minutes'] ?? 0)) ?> min
                                </div>
                            </div>
                            <div class="text-end" style="width: 120px;">
                                <div class="small fw-semibold tabular mb-1"><?= e((string) percent($row['progress_percent'] ?? 0)) ?>%</div>
                                <div class="progress">
                                    <div class="progress-bar <?= !empty($row['is_overdue']) ? 'bg-danger' : '' ?>"
                                         role="progressbar"
                                         style="width: <?= e((string) percent($row['progress_percent'] ?? 0)) ?>%"
                                         aria-valuenow="<?= e((string) percent($row['progress_percent'] ?? 0)) ?>"
                                         aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if ($others !== []): ?>
                <div class="card-footer">
                    <div class="section-label">Also under way</div>
                    <?php foreach ($others as $row): ?>
                        <?php $href = '/learning/courses/' . rawurlencode((string) ($row['course_id'] ?? '')); ?>
                        <div class="d-flex align-items-center gap-3 py-1">
                            <a href="<?= e($href) ?>" class="flex-grow-1 text-decoration-none truncate">
                                <?= e((string) ($row['course_title'] ?? 'Course')) ?>
                            </a>
                            <div class="progress flex-shrink-0" style="width: 90px;">
                                <div class="progress-bar" role="progressbar"
                                     style="width: <?= e((string) percent($row['progress_percent'] ?? 0)) ?>%"
                                     aria-valuenow="<?= e((string) percent($row['progress_percent'] ?? 0)) ?>"
                                     aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <span class="small text-muted tabular flex-shrink-0" style="width: 38px;">
                                <?= e((string) percent($row['progress_percent'] ?? 0)) ?>%
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="card-footer text-muted">
                    Everything else you have started appears in
                    <a href="/learning/catalogue">the catalogue</a> with your progress on it.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="fa fa-award"></i> Recently completed</span>
                <a href="/learning/certificates" class="small">All certificates</a>
            </div>
            <div class="card-body">
                <?php if ($completed === []): ?>
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-award',
                        'title' => 'Nothing finished yet',
                        'message' => 'Complete a course and it will appear here with its certificate.',
                    ]) ?>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($completed as $row): ?>
                            <?php
                            $courseId = (string) ($row['course_id'] ?? '');
                            $certificate = $certificates[$courseId] ?? null;
                            ?>
                            <div class="timeline-item">
                                <a href="<?= e('/learning/courses/' . rawurlencode($courseId)) ?>"
                                   class="fw-semibold text-decoration-none">
                                    <?= e((string) ($row['course_title'] ?? 'Course')) ?>
                                </a>
                                <div class="small text-muted">
                                    Completed <?= e(date_display((string) ($row['completed_at'] ?? ''))) ?>
                                </div>
                                <?php if (is_array($certificate)): ?>
                                    <div class="small mt-1">
                                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                            <?= e((string) ($certificate['certificate_number'] ?? '')) ?>
                                        </span>
                                        <a class="ms-1"
                                           href="<?= e('/learning/certificates/' . rawurlencode((string) ($certificate['id'] ?? '')) . '/download') ?>">
                                            <i class="fa fa-download"></i> Download
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($assignment !== null): ?>
    <div class="card mt-4">
        <div class="card-header">
            <span><i class="fa fa-user-plus"></i> Assign training</span>
            <span class="small text-muted">
                <?= $assignment['scope'] === 'everyone'
                    ? 'You may assign to anyone in the company'
                    : 'You may assign to your direct reports' ?>
            </span>
        </div>
        <form method="post" action="/learning/assign">
            <?= Csrf::field() ?>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-lg-5">
                        <label for="assign_courses" class="form-label">Courses</label>
                        <select class="form-select" id="assign_courses" name="course_ids[]" multiple size="8" required>
                            <?php foreach ($assignment['courses'] as $course): ?>
                                <option value="<?= e((string) ($course['id'] ?? '')) ?>">
                                    <?= e((string) ($course['title'] ?? '')) ?><?= !empty($course['is_mandatory']) ? ' (mandatory)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php View::partial('field-errors', ['name' => 'course_id']) ?>
                        <div class="form-text">Hold Ctrl, or Command, to pick more than one.</div>
                    </div>

                    <div class="col-12 col-lg-5">
                        <label for="assign_people" class="form-label">People</label>
                        <select class="form-select" id="assign_people" name="employee_ids[]" multiple size="8" required>
                            <?php foreach ($assignment['people'] as $person): ?>
                                <option value="<?= e((string) ($person['id'] ?? '')) ?>">
                                    <?= e((string) ($person['full_name'] ?? 'Unnamed')) ?><?php
                                    $department = (string) ($person['department_name'] ?? '');
                                    echo $department === '' ? '' : ' — ' . e($department);
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php View::partial('field-errors', ['name' => 'employee_ids']) ?>
                        <?php if ($assignment['people'] === []): ?>
                            <div class="form-text text-danger">
                                <?= $assignment['scope'] === 'everyone'
                                    ? 'No one could be listed from the directory, so there is nobody to assign to.'
                                    : 'Nobody reports to you at the moment.' ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 col-lg-2">
                        <label for="assign_due" class="form-label">Due date</label>
                        <input type="date" class="form-control" id="assign_due" name="due_on"
                               min="<?= e(date('Y-m-d')) ?>"
                               value="<?= e(Flash::old('due_on')) ?>">
                        <?php View::partial('field-errors', ['name' => 'due_on']) ?>
                        <div class="form-text">Optional.</div>
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
<?php endif; ?>
