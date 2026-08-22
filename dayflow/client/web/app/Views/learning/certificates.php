<?php
/**
 * Every certificate this person has earned.
 *
 * @var list<array<string, mixed>> $certificates
 * @var array                      $meta Pagination block from the API.
 */

use App\Core\View;
?>

<?php View::partial('page-header', [
    'title' => 'My certificates',
    'subtitle' => 'Proof of the courses you have completed, ready to download as a PDF.',
    'actions' => '<a href="/learning" class="btn btn-outline-secondary">'
        . '<i class="fa fa-arrow-left"></i> My learning</a>',
]) ?>

<?php if ($certificates === []): ?>
    <?php View::partial('empty-state', [
        'icon' => 'fa-certificate',
        'title' => 'No certificates yet',
        'message' => 'Complete a course that awards one and it will appear here.',
        'actionLabel' => 'Browse the catalogue',
        'actionHref' => '/learning/catalogue',
    ]) ?>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($certificates as $certificate): ?>
            <?php $id = (string) ($certificate['id'] ?? ''); ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-3 mb-3">
                            <span class="tile-icon flex-shrink-0"><i class="fa fa-certificate"></i></span>
                            <div class="overflow-hidden">
                                <h2 class="h6 mb-1"><?= e((string) ($certificate['course_title'] ?? 'Course')) ?></h2>
                                <div class="small text-muted">
                                    <?= e(label((string) ($certificate['course_level'] ?? ''))) ?>
                                </div>
                            </div>
                        </div>

                        <div class="stat-row">
                            <span class="stat-key">Certificate number</span>
                            <span class="stat-val tabular truncate">
                                <?= e((string) ($certificate['certificate_number'] ?? '')) ?>
                            </span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Issued</span>
                            <span class="stat-val"><?= e(date_display((string) ($certificate['issued_on'] ?? ''))) ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Score</span>
                            <span class="stat-val tabular"><?= e((string) (int) ($certificate['score_percent'] ?? 0)) ?>%</span>
                        </div>
                    </div>
                    <div class="card-footer d-flex gap-2">
                        <a class="btn btn-sm btn-primary flex-grow-1"
                           href="<?= e('/learning/certificates/' . rawurlencode($id) . '/download') ?>">
                            <i class="fa fa-download"></i> Download
                        </a>
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= e('/learning/courses/' . rawurlencode((string) ($certificate['course_id'] ?? ''))) ?>">
                            Course
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php View::partial('pagination', ['meta' => $meta]) ?>
<?php endif; ?>
