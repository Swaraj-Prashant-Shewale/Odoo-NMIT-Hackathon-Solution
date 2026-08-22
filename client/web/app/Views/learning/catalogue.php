<?php
/**
 * The browsable course catalogue.
 *
 * @var list<array<string, mixed>> $courses
 * @var array                      $meta       Pagination block from the API.
 * @var list<array<string, mixed>> $categories
 * @var array<string, mixed>       $filters    The filters currently applied.
 * @var list<string>               $levels
 */

use App\Core\View;
?>

<?php View::partial('page-header', [
    'title' => 'Course catalogue',
    'subtitle' => 'Every course open to you, with where you have got to on each.',
    'actions' => '<a href="/learning" class="btn btn-outline-secondary"><i class="fa fa-arrow-left"></i> My learning</a>',
]) ?>

<form method="get" action="/learning/catalogue" class="card mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-4">
                <label for="search" class="form-label">Search</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-search"></i></span>
                    <input type="search" class="form-control" id="search" name="search"
                           value="<?= e((string) $filters['search']) ?>"
                           placeholder="Title or summary"
                           data-submit-on-change>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <label for="category_id" class="form-label">Category</label>
                <select class="form-select" id="category_id" name="category_id" data-submit-on-change>
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string) ($category['id'] ?? '')) ?>"
                            <?= (string) $filters['category_id'] === (string) ($category['id'] ?? '') ? 'selected' : '' ?>>
                            <?= e((string) ($category['name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-lg-3">
                <label for="level" class="form-label">Level</label>
                <select class="form-select" id="level" name="level" data-submit-on-change>
                    <option value="">Any level</option>
                    <?php foreach ($levels as $level): ?>
                        <option value="<?= e($level) ?>" <?= (string) $filters['level'] === $level ? 'selected' : '' ?>>
                            <?= e(label($level)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-lg-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="mandatory" name="mandatory"
                        <?= $filters['mandatory'] ? 'checked' : '' ?> data-submit-on-change>
                    <label class="form-check-label" for="mandatory">Mandatory only</label>
                </div>
                <button type="submit" class="btn btn-outline-secondary btn-sm mt-2 w-100">
                    <i class="fa fa-filter"></i> Apply
                </button>
            </div>
        </div>
    </div>
</form>

<?php if ($courses === []): ?>
    <?php View::partial('empty-state', [
        'icon' => 'fa-graduation-cap',
        'title' => 'No courses match that',
        'message' => 'Try a different category or level, or clear the search to see the whole catalogue.',
        'actionLabel' => 'Clear filters',
        'actionHref' => '/learning/catalogue',
    ]) ?>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($courses as $course): ?>
            <?php
            $href = '/learning/courses/' . rawurlencode((string) ($course['id'] ?? ''));
            $cover = (string) ($course['thumbnail_url'] ?? '');
            $status = (string) ($course['enrolment_status'] ?? '');
            $progress = percent($course['progress_percent'] ?? 0);
            ?>
            <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                <div class="card h-100">
                    <a href="<?= e($href) ?>" class="text-decoration-none">
                        <div class="course-thumb">
                            <?php if ($cover !== ''): ?>
                                <img src="<?= e($cover) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span class="empty-state-icon position-absolute top-50 start-50 translate-middle">
                                    <i class="fa fa-graduation-cap"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </a>
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            <?php if (!empty($course['category_name'])): ?>
                                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                                    <?= e((string) $course['category_name']) ?>
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
                                    Draft
                                </span>
                            <?php endif; ?>
                        </div>

                        <h2 class="h6 mb-1">
                            <a href="<?= e($href) ?>" class="text-decoration-none text-reset">
                                <?= e((string) ($course['title'] ?? 'Untitled course')) ?>
                            </a>
                        </h2>
                        <p class="small text-muted flex-grow-1"><?= e((string) ($course['summary'] ?? '')) ?></p>

                        <div class="d-flex justify-content-between small text-muted mb-2">
                            <span><i class="fa fa-film"></i> <?= e((string) (int) ($course['lesson_count'] ?? 0)) ?> lessons</span>
                            <span><i class="fa fa-clock"></i> <?= e((string) ($course['runtime_label'] ?? '0m')) ?></span>
                            <?php if (!empty($course['has_quiz'])): ?>
                                <span><i class="fa fa-question-circle"></i> Quiz</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($status === 'completed'): ?>
                            <div class="small text-success fw-semibold">
                                <i class="fa fa-check-circle"></i> Completed
                            </div>
                        <?php elseif (!empty($course['is_enrolled'])): ?>
                            <div class="d-flex justify-content-between align-items-baseline small mb-1">
                                <span class="text-muted">In progress</span>
                                <span class="fw-semibold tabular"><?= e((string) $progress) ?>%</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar <?= !empty($course['is_overdue']) ? 'bg-danger' : '' ?>"
                                     role="progressbar"
                                     style="width: <?= e((string) $progress) ?>%"
                                     aria-valuenow="<?= e((string) $progress) ?>"
                                     aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <?php if (!empty($course['due_on'])): ?>
                                <div class="small mt-1 <?= !empty($course['is_overdue']) ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                    Due <?= e(date_display((string) $course['due_on'])) ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="small text-muted"><i class="fa fa-circle-notch"></i> Not enrolled</div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer">
                        <a href="<?= e($href) ?>" class="btn btn-sm btn-outline-primary w-100">
                            <?= !empty($course['is_enrolled']) ? 'Open course' : 'View course' ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php View::partial('pagination', ['meta' => $meta]) ?>
<?php endif; ?>
