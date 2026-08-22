<?php
/**
 * One course: its outline, the caller's progress and its assessment.
 *
 * @var array<string, mixed>       $course
 * @var list<array<string, mixed>> $lessons
 * @var array<string, mixed>|null  $enrolment       Null when the caller is not enrolled.
 * @var array<string, mixed>|null  $quiz
 * @var array<string, mixed>|null  $attempts        Attempt counts, when there is an enrolment.
 * @var string                     $currentLessonId The lesson to open next.
 * @var int                        $lessonsCompleted
 */

use App\Core\Csrf;
use App\Core\View;

$courseId = (string) ($course['id'] ?? '');
$coursePath = '/learning/courses/' . rawurlencode($courseId);
$cover = (string) ($course['thumbnail_url'] ?? '');
$total = count($lessons);
$everythingWatched = $total > 0 && $lessonsCompleted >= $total;
$attemptsUsed = (int) ($attempts['attempts_used'] ?? 0);
$attemptsRemaining = (int) ($attempts['attempts_remaining'] ?? 0);
?>

<?php View::partial('page-header', [
    'title' => (string) ($course['title'] ?? 'Course'),
    'subtitle' => (string) ($course['summary'] ?? ''),
    'actions' => '<a href="/learning/catalogue" class="btn btn-outline-secondary">'
        . '<i class="fa fa-arrow-left"></i> Catalogue</a>',
]) ?>

<div class="row g-3">
    <div class="col-12 col-xl-8">

        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-5">
                        <div class="course-thumb">
                            <?php if ($cover !== ''): ?>
                                <img src="<?= e($cover) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span class="empty-state-icon position-absolute top-50 start-50 translate-middle">
                                    <i class="fa fa-graduation-cap"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-12 col-md-7">
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            <?php if (!empty($course['category']['name'])): ?>
                                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                                    <?= e((string) $course['category']['name']) ?>
                                </span>
                            <?php endif; ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                <?= e(label((string) ($course['level'] ?? ''))) ?>
                            </span>
                            <?php if (!empty($course['is_mandatory'])): ?>
                                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                                    Mandatory
                                </span>
                            <?php endif; ?>
                            <?php if (($course['published_at'] ?? null) === null): ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                    Not published
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="stat-row">
                            <span class="stat-key">Lessons</span>
                            <span class="stat-val tabular"><?= e((string) $total) ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Total runtime</span>
                            <span class="stat-val"><?= e((string) ($course['runtime_label'] ?? '0m')) ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Estimated effort</span>
                            <span class="stat-val tabular"><?= e((string) (int) ($course['estimated_minutes'] ?? 0)) ?> min</span>
                        </div>
                        <?php if (!empty($course['certificate_enabled'])): ?>
                            <div class="stat-row">
                                <span class="stat-key">On completion</span>
                                <span class="stat-val"><i class="fa fa-certificate text-warning"></i> Certificate</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($course['description'])): ?>
                    <p class="mt-3 mb-0 small"><?= nl2br(e((string) $course['description'])) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span><i class="fa fa-list-ol"></i> Lessons</span>
                <span class="small text-muted">
                    <?= e((string) $lessonsCompleted) ?> of <?= e((string) $total) ?> watched
                </span>
            </div>
            <div class="card-body">
                <?php if ($lessons === []): ?>
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-film',
                        'title' => 'No lessons yet',
                        'message' => 'This course has no video lessons in it at the moment.',
                    ]) ?>
                <?php else: ?>
                    <?php foreach ($lessons as $lesson): ?>
                        <?php
                        $lessonId = (string) ($lesson['id'] ?? '');
                        $complete = !empty($lesson['is_completed']);
                        $current = !$complete && $lessonId === $currentLessonId;
                        $locked = !empty($lesson['is_locked']);
                        ?>
                        <div class="lesson-row <?= $complete ? 'is-complete' : '' ?> <?= $current ? 'is-current' : '' ?>">
                            <span class="lesson-index">
                                <?php if ($complete): ?>
                                    <i class="fa fa-check"></i>
                                <?php else: ?>
                                    <?= e((string) (int) ($lesson['sequence'] ?? 0)) ?>
                                <?php endif; ?>
                            </span>
                            <div class="flex-grow-1 overflow-hidden">
                                <?php if ($locked): ?>
                                    <span class="fw-semibold text-muted">
                                        <i class="fa fa-lock"></i> <?= e((string) ($lesson['title'] ?? '')) ?>
                                    </span>
                                <?php else: ?>
                                    <a class="fw-semibold text-decoration-none"
                                       href="<?= e($coursePath . '/lessons/' . rawurlencode($lessonId)) ?>">
                                        <?= e((string) ($lesson['title'] ?? '')) ?>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($lesson['is_preview'])): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Preview</span>
                                <?php endif; ?>
                                <div class="small text-muted truncate"><?= e((string) ($lesson['description'] ?? '')) ?></div>
                            </div>
                            <span class="small text-muted tabular flex-shrink-0">
                                <i class="fa fa-clock"></i> <?= e((string) ($lesson['duration_label'] ?? '')) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($enrolment === null): ?>
                        <p class="small text-muted mb-0 mt-3">
                            <i class="fa fa-lock"></i>
                            Enrol to unlock every lesson. Anything marked as a preview can be watched without enrolling.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">

        <div class="card mb-3">
            <div class="card-header"><span><i class="fa fa-user-graduate"></i> Your progress</span></div>
            <div class="card-body">
                <?php if ($enrolment === null): ?>
                    <p class="small text-muted">
                        You are not enrolled on this course yet. Enrolling opens every lesson and starts
                        recording what you have watched.
                    </p>
                    <form method="post" action="<?= e($coursePath . '/enrol') ?>">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-primary w-100" data-busy-label="Enrolling...">
                            <i class="fa fa-plus-circle"></i> Enrol on this course
                        </button>
                    </form>
                <?php else: ?>
                    <div class="d-flex justify-content-between align-items-baseline small mb-1">
                        <span class="text-muted"><?= e(label((string) ($enrolment['status'] ?? ''))) ?></span>
                        <span class="fw-semibold tabular"><?= e((string) percent($enrolment['progress_percent'] ?? 0)) ?>%</span>
                    </div>
                    <div class="progress progress-lg mb-3">
                        <div class="progress-bar <?= !empty($enrolment['is_overdue']) ? 'bg-danger' : '' ?>"
                             role="progressbar"
                             style="width: <?= e((string) percent($enrolment['progress_percent'] ?? 0)) ?>%"
                             aria-valuenow="<?= e((string) percent($enrolment['progress_percent'] ?? 0)) ?>"
                             aria-valuemin="0" aria-valuemax="100"></div>
                    </div>

                    <?php if (!empty($enrolment['due_on'])): ?>
                        <div class="stat-row">
                            <span class="stat-key">Due</span>
                            <span class="stat-val <?= !empty($enrolment['is_overdue']) ? 'text-danger' : '' ?>">
                                <?= e(date_display((string) $enrolment['due_on'])) ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($enrolment['completed_at'])): ?>
                        <div class="stat-row">
                            <span class="stat-key">Completed</span>
                            <span class="stat-val"><?= e(date_display((string) $enrolment['completed_at'])) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($currentLessonId !== ''): ?>
                        <a class="btn btn-primary w-100 mt-3"
                           href="<?= e($coursePath . '/lessons/' . rawurlencode($currentLessonId)) ?>">
                            <i class="fa fa-play"></i>
                            <?= $lessonsCompleted === 0 ? 'Start the course' : 'Continue' ?>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($quiz !== null): ?>
            <div class="card">
                <div class="card-header"><span><i class="fa fa-question-circle"></i> Assessment</span></div>
                <div class="card-body">
                    <div class="fw-semibold mb-2"><?= e((string) ($quiz['title'] ?? 'Course quiz')) ?></div>

                    <div class="stat-row">
                        <span class="stat-key">Questions</span>
                        <span class="stat-val tabular"><?= e((string) (int) ($quiz['question_count'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Pass mark</span>
                        <span class="stat-val tabular"><?= e((string) (int) ($quiz['pass_percent'] ?? 0)) ?>%</span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Attempts allowed</span>
                        <span class="stat-val tabular"><?= e((string) (int) ($quiz['max_attempts'] ?? 0)) ?></span>
                    </div>
                    <?php if ($attempts !== null): ?>
                        <div class="stat-row">
                            <span class="stat-key">Attempts used</span>
                            <span class="stat-val tabular"><?= e((string) $attemptsUsed) ?></span>
                        </div>
                        <?php if (($attempts['best_score_percent'] ?? null) !== null): ?>
                            <div class="stat-row">
                                <span class="stat-key">Best score</span>
                                <span class="stat-val tabular"><?= e((string) (int) $attempts['best_score_percent']) ?>%</span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($enrolment === null): ?>
                        <button type="button" class="btn btn-outline-secondary w-100 mt-3" disabled>
                            <i class="fa fa-lock"></i> Enrol to take the quiz
                        </button>
                    <?php elseif (!$everythingWatched): ?>
                        <button type="button" class="btn btn-outline-secondary w-100 mt-3" disabled>
                            <i class="fa fa-lock"></i> Watch every lesson first
                        </button>
                        <p class="small text-muted mt-2 mb-0">
                            <?= e((string) max(0, $total - $lessonsCompleted)) ?> lessons still to watch.
                        </p>
                    <?php elseif ($attempts !== null && $attemptsRemaining < 1): ?>
                        <button type="button" class="btn btn-outline-secondary w-100 mt-3" disabled>
                            <i class="fa fa-ban"></i> No attempts left
                        </button>
                    <?php else: ?>
                        <a class="btn btn-primary w-100 mt-3" href="<?= e($coursePath . '/quiz') ?>">
                            <i class="fa fa-pen-alt"></i> Start the quiz
                        </a>
                        <?php if ($attempts !== null): ?>
                            <p class="small text-muted mt-2 mb-0">
                                <?= e((string) $attemptsRemaining) ?>
                                <?= $attemptsRemaining === 1 ? 'attempt' : 'attempts' ?> remaining.
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
