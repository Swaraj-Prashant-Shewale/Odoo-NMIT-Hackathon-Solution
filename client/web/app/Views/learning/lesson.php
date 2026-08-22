<?php
/**
 * One lesson: the embedded video and the way through the rest of the course.
 *
 * The iframe address is built here from the eleven character video id the
 * service returns, and from nothing else. A stored URL is never placed in a
 * frame source, and the privacy enhanced host is the only one the
 * Content-Security-Policy allows.
 *
 * @var array<string, mixed>       $course
 * @var array<string, mixed>       $lesson
 * @var list<array<string, mixed>> $lessons
 * @var array<string, mixed>|null  $enrolment
 * @var int                        $position   This lesson's place in the course, from one.
 * @var array<string, mixed>|null  $previous
 * @var array<string, mixed>|null  $next
 * @var int                        $lessonsCompleted
 */

use App\Core\Csrf;
use App\Core\View;

$courseId = (string) ($course['id'] ?? '');
$coursePath = '/learning/courses/' . rawurlencode($courseId);
$lessonId = (string) ($lesson['id'] ?? '');
$total = count($lessons);
$complete = !empty($lesson['is_completed']);
?>

<?php View::partial('page-header', [
    'title' => (string) ($lesson['title'] ?? 'Lesson'),
    'subtitle' => sprintf('Lesson %d of %d in %s', $position, $total, (string) ($course['title'] ?? 'this course')),
    'actions' => '<a href="' . e($coursePath) . '" class="btn btn-outline-secondary">'
        . '<i class="fa fa-list-ul"></i> Course outline</a>',
]) ?>

<div class="row g-3">
    <div class="col-12 col-xl-8">

        <?php if (!empty($lesson['video_id'])): ?>
            <div class="video-frame mb-3">
                <iframe src="https://www.youtube-nocookie.com/embed/<?= e((string) $lesson['video_id']) ?>?rel=0&amp;modestbranding=1"
                        title="<?= e((string) ($lesson['title'] ?? 'Lesson video')) ?>"
                        allow="accelerometer; clipboard-write; encrypted-media; picture-in-picture"
                        referrerpolicy="strict-origin-when-cross-origin"
                        allowfullscreen></iframe>
            </div>
        <?php else: ?>
            <div class="card mb-3">
                <div class="card-body">
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-video-slash',
                        'title' => 'This lesson has no video',
                        'message' => 'The material for this lesson is not available at the moment.',
                    ]) ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <h2 class="h5 mb-0"><?= e((string) ($lesson['title'] ?? '')) ?></h2>
                    <div class="d-flex gap-1">
                        <?php if (!empty($lesson['is_preview'])): ?>
                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Preview</span>
                        <?php endif; ?>
                        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                            <i class="fa fa-clock"></i> <?= e((string) ($lesson['duration_label'] ?? '')) ?>
                        </span>
                        <?php if ($complete): ?>
                            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                <i class="fa fa-check"></i> Complete
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($lesson['description'])): ?>
                    <p class="small text-muted mb-0"><?= nl2br(e((string) $lesson['description'])) ?></p>
                <?php endif; ?>
            </div>

            <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="d-flex gap-2">
                    <?php if ($previous !== null && empty($previous['is_locked'])): ?>
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= e($coursePath . '/lessons/' . rawurlencode((string) ($previous['id'] ?? ''))) ?>">
                            <i class="fa fa-chevron-left"></i> Previous
                        </a>
                    <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                            <i class="fa fa-chevron-left"></i> Previous
                        </button>
                    <?php endif; ?>

                    <?php if ($next !== null && empty($next['is_locked'])): ?>
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= e($coursePath . '/lessons/' . rawurlencode((string) ($next['id'] ?? ''))) ?>">
                            Next <i class="fa fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                            Next <i class="fa fa-chevron-right"></i>
                        </button>
                    <?php endif; ?>
                </div>

                <?php if ($enrolment === null): ?>
                    <form method="post" action="<?= e($coursePath . '/enrol') ?>" class="m-0">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-primary" data-busy-label="Enrolling...">
                            <i class="fa fa-plus-circle"></i> Enrol to track your progress
                        </button>
                    </form>
                <?php elseif ($complete): ?>
                    <span class="small text-success fw-semibold">
                        <i class="fa fa-check-circle"></i> Watched
                        <?= e(relative_time((string) ($lesson['completed_at'] ?? ''))) ?>
                    </span>
                <?php else: ?>
                    <form method="post" action="<?= e($coursePath . '/progress') ?>" class="m-0">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="lesson_id" value="<?= e($lessonId) ?>">
                        <input type="hidden" name="watched_seconds" value="<?= e((string) (int) ($lesson['duration_seconds'] ?? 0)) ?>">
                        <button type="submit" class="btn btn-sm btn-success" data-busy-label="Saving...">
                            <i class="fa fa-check"></i> Mark as complete
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card">
            <div class="card-header">
                <span><i class="fa fa-list-ol"></i> <?= e((string) ($course['title'] ?? 'Course')) ?></span>
                <span class="small text-muted"><?= e((string) $lessonsCompleted) ?>/<?= e((string) $total) ?></span>
            </div>
            <div class="card-body">
                <?php foreach ($lessons as $row): ?>
                    <?php
                    $rowId = (string) ($row['id'] ?? '');
                    $rowComplete = !empty($row['is_completed']);
                    $rowLocked = !empty($row['is_locked']);
                    ?>
                    <div class="lesson-row <?= $rowComplete ? 'is-complete' : '' ?> <?= $rowId === $lessonId ? 'is-current' : '' ?>">
                        <span class="lesson-index">
                            <?php if ($rowComplete): ?>
                                <i class="fa fa-check"></i>
                            <?php else: ?>
                                <?= e((string) (int) ($row['sequence'] ?? 0)) ?>
                            <?php endif; ?>
                        </span>
                        <div class="flex-grow-1 overflow-hidden">
                            <?php if ($rowLocked): ?>
                                <span class="small text-muted truncate d-block">
                                    <i class="fa fa-lock"></i> <?= e((string) ($row['title'] ?? '')) ?>
                                </span>
                            <?php else: ?>
                                <a class="small text-decoration-none truncate d-block <?= $rowId === $lessonId ? 'fw-semibold' : '' ?>"
                                   href="<?= e($coursePath . '/lessons/' . rawurlencode($rowId)) ?>">
                                    <?= e((string) ($row['title'] ?? '')) ?>
                                </a>
                            <?php endif; ?>
                        </div>
                        <span class="small text-muted tabular flex-shrink-0"><?= e((string) ($row['duration_label'] ?? '')) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="card-footer">
                <a href="<?= e($coursePath) ?>" class="btn btn-sm btn-outline-secondary w-100">
                    Back to the course
                </a>
            </div>
        </div>
    </div>
</div>
