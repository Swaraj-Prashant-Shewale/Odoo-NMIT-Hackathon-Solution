<?php
/**
 * Catalogue management: the whole library, plus the forms that add to it.
 *
 * @var list<array<string, mixed>> $courses
 * @var array                      $meta
 * @var list<array<string, mixed>> $categories
 * @var list<array<string, mixed>> $departments
 * @var list<string>               $levels
 * @var array|null                 $selected   The course being given a lesson.
 * @var string                     $selectedId
 * @var array<string, array>       $enrolmentCounts Keyed by course id, where published.
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$selectedCourse = is_array($selected['course'] ?? null) ? $selected['course'] : null;
$selectedLessons = is_array($selected['lessons'] ?? null) ? $selected['lessons'] : [];
?>

<?php View::partial('page-header', [
    'title' => 'Manage catalogue',
    'subtitle' => 'Create courses, add the videos that make them up, and publish them.',
    'actions' => '<a href="/learning/catalogue" class="btn btn-outline-secondary">'
        . '<i class="fa fa-th-large"></i> View catalogue</a>',
]) ?>

<div class="card mb-4">
    <div class="card-header">
        <span><i class="fa fa-book"></i> Courses</span>
        <input type="search" class="form-control form-control-sm w-auto"
               placeholder="Filter this page" aria-label="Filter courses"
               data-filter-table="courseTable">
    </div>
    <div class="card-body">
        <?php if ($courses === []): ?>
            <?php View::partial('empty-state', [
                'icon' => 'fa-book',
                'title' => 'The catalogue is empty',
                'message' => 'Create the first course with the form below, then add its lessons.',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table" id="courseTable">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Category</th>
                            <th>Level</th>
                            <th class="text-end">Lessons</th>
                            <th class="text-end">Enrolled</th>
                            <th>State</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $course): ?>
                            <?php
                            $id = (string) ($course['id'] ?? '');
                            $counts = $enrolmentCounts[$id] ?? null;
                            ?>
                            <tr>
                                <td>
                                    <a href="<?= e('/learning/courses/' . rawurlencode($id)) ?>"
                                       class="fw-semibold text-decoration-none">
                                        <?= e((string) ($course['title'] ?? '')) ?>
                                    </a>
                                    <?php if (!empty($course['is_mandatory'])): ?>
                                        <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                                            Mandatory
                                        </span>
                                    <?php endif; ?>
                                    <div class="small text-muted truncate"><?= e((string) ($course['summary'] ?? '')) ?></div>
                                </td>
                                <td><?= field($course, 'category_name') ?></td>
                                <td><?= e(label((string) ($course['level'] ?? ''))) ?></td>
                                <td class="text-end tabular">
                                    <?= e((string) (int) ($course['lesson_count'] ?? 0)) ?>
                                    <div class="small text-muted"><?= e((string) ($course['runtime_label'] ?? '')) ?></div>
                                </td>
                                <td class="text-end tabular">
                                    <?php if ($counts === null): ?>
                                        <span class="text-muted">—</span>
                                    <?php else: ?>
                                        <?= e((string) $counts['enrolled']) ?>
                                        <div class="small text-muted"><?= e((string) $counts['completed']) ?> done</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (($course['published_at'] ?? null) !== null && !empty($course['is_active'])): ?>
                                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                            Published
                                        </span>
                                    <?php elseif (empty($course['is_active'])): ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                            Inactive
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                            Draft
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e('/learning/manage?course=' . rawurlencode($id)) ?>">
                                        <i class="fa fa-film"></i> Lessons
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="small text-muted mt-2" data-filter-empty style="display: none;">
                Nothing on this page matches that.
            </div>
            <?php
            // Shown whenever any row on this page carries a dash, not only when
            // every row does: an unexplained dash beside a real number reads as
            // a count of zero.
            $anyWithoutCount = false;
            foreach ($courses as $course) {
                if (!isset($enrolmentCounts[(string) ($course['id'] ?? '')])) {
                    $anyWithoutCount = true;
                    break;
                }
            }
            ?>
            <?php if ($anyWithoutCount): ?>
                <p class="small text-muted mt-2 mb-0">
                    Enrolment totals are published for mandatory training only, so a dash means the
                    service does not report a figure for that course.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php View::partial('pagination', ['meta' => $meta]) ?>

<div class="row g-3 mt-1">
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header"><span><i class="fa fa-plus-circle"></i> Create a course</span></div>
            <form method="post" action="/learning/manage/courses">
                <?= Csrf::field() ?>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="title" class="form-label">Title</label>
                            <input type="text" class="form-control" id="title" name="title" maxlength="180"
                                   value="<?= e(Flash::old('title')) ?>" required>
                            <?php View::partial('field-errors', ['name' => 'title']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="category" class="form-label">Category</label>
                            <select class="form-select" id="category" name="category_id" required>
                                <option value="">Choose a category</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e((string) ($category['id'] ?? '')) ?>"
                                        <?= Flash::old('category_id') === (string) ($category['id'] ?? '') ? 'selected' : '' ?>>
                                        <?= e((string) ($category['name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'category_id']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="course_level" class="form-label">Level</label>
                            <select class="form-select" id="course_level" name="level">
                                <?php foreach ($levels as $level): ?>
                                    <option value="<?= e($level) ?>" <?= Flash::old('level') === $level ? 'selected' : '' ?>>
                                        <?= e(label($level)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label for="summary" class="form-label">Summary</label>
                            <input type="text" class="form-control" id="summary" name="summary" maxlength="400"
                                   value="<?= e(Flash::old('summary')) ?>"
                                   placeholder="One line for the catalogue card">
                            <div class="form-text" data-counter-for="summary"></div>
                            <?php View::partial('field-errors', ['name' => 'summary']) ?>
                        </div>

                        <div class="col-12">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="4"
                                      maxlength="6000"><?= e(Flash::old('description')) ?></textarea>
                            <?php View::partial('field-errors', ['name' => 'description']) ?>
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="estimated_minutes" class="form-label">Estimated minutes</label>
                            <input type="number" class="form-control" id="estimated_minutes" name="estimated_minutes"
                                   min="0" max="10000" value="<?= e(Flash::old('estimated_minutes', '60')) ?>">
                            <?php View::partial('field-errors', ['name' => 'estimated_minutes']) ?>
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="passing_score" class="form-label">Passing score</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="passing_score" name="passing_score"
                                       min="0" max="100" value="<?= e(Flash::old('passing_score', '70')) ?>">
                                <span class="input-group-text">%</span>
                            </div>
                            <?php View::partial('field-errors', ['name' => 'passing_score']) ?>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="mandatory_for_department_id" class="form-label">Mandatory for</label>
                            <select class="form-select" id="mandatory_for_department_id"
                                    name="mandatory_for_department_id">
                                <option value="">Everyone</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= e((string) ($department['id'] ?? '')) ?>"
                                        <?= Flash::old('mandatory_for_department_id') === (string) ($department['id'] ?? '') ? 'selected' : '' ?>>
                                        <?= e((string) ($department['name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Only applies to a mandatory course.</div>
                            <?php View::partial('field-errors', ['name' => 'mandatory_for_department_id']) ?>
                        </div>

                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="is_mandatory"
                                       name="is_mandatory" <?= Flash::old('is_mandatory') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_mandatory">
                                    Mandatory training, and reportable in compliance
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="certificate_enabled"
                                       name="certificate_enabled" <?= Flash::old('certificate_enabled') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="certificate_enabled">
                                    Award a certificate on completion
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="publish"
                                       name="publish" <?= Flash::old('publish') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="publish">
                                    Publish immediately, so it appears in the catalogue
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary" data-busy-label="Creating...">
                        <i class="fa fa-save"></i> Create course
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header"><span><i class="fa fa-film"></i> Add a lesson</span></div>
            <div class="card-body">
                <form method="get" action="/learning/manage" class="mb-3">
                    <label for="course" class="form-label">Course</label>
                    <select class="form-select" id="course" name="course" data-submit-on-change>
                        <option value="">Choose a course</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?= e((string) ($course['id'] ?? '')) ?>"
                                <?= $selectedId === (string) ($course['id'] ?? '') ? 'selected' : '' ?>>
                                <?= e((string) ($course['title'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        A lesson belongs to one course, so pick it before filling the form in.
                    </div>
                </form>

                <?php if ($selectedCourse === null): ?>
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-hand-pointer',
                        'title' => 'No course chosen',
                        'message' => 'Choose a course above and its lessons will appear here.',
                    ]) ?>
                <?php else: ?>
                    <div class="section-label">Lessons already in this course</div>
                    <?php if ($selectedLessons === []): ?>
                        <p class="small text-muted">None yet. This will be the first.</p>
                    <?php else: ?>
                        <?php foreach ($selectedLessons as $lesson): ?>
                            <div class="lesson-row">
                                <span class="lesson-index"><?= e((string) (int) ($lesson['sequence'] ?? 0)) ?></span>
                                <div class="flex-grow-1 overflow-hidden">
                                    <span class="small fw-semibold truncate d-block">
                                        <?= e((string) ($lesson['title'] ?? '')) ?>
                                    </span>
                                </div>
                                <?php if (!empty($lesson['is_preview'])): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                        Preview
                                    </span>
                                <?php endif; ?>
                                <span class="small text-muted tabular flex-shrink-0">
                                    <?= e((string) ($lesson['duration_label'] ?? '')) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <form method="post"
                          action="<?= e('/learning/manage/courses/' . rawurlencode((string) ($selectedCourse['id'] ?? '')) . '/lessons') ?>"
                          class="mt-3">
                        <?= Csrf::field() ?>

                        <div class="row g-3">
                            <div class="col-12">
                                <label for="lesson_title" class="form-label">Lesson title</label>
                                <input type="text" class="form-control" id="lesson_title" name="lesson_title"
                                       maxlength="180" value="<?= e(Flash::old('lesson_title')) ?>" required>
                                <?php View::partial('field-errors', ['name' => 'title']) ?>
                            </div>

                            <div class="col-12">
                                <label for="video_url" class="form-label">YouTube link</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa fa-video"></i></span>
                                    <input type="url" class="form-control" id="video_url" name="video_url"
                                           maxlength="500" value="<?= e(Flash::old('video_url')) ?>"
                                           placeholder="https://www.youtube.com/watch?v=..." required>
                                </div>
                                <div class="form-text">
                                    Only YouTube addresses are accepted. The platform stores the video's
                                    identifier and builds the player itself, so nothing else can be embedded.
                                </div>
                                <?php View::partial('field-errors', ['name' => 'video_url']) ?>
                            </div>

                            <div class="col-12">
                                <label for="lesson_description" class="form-label">Description</label>
                                <textarea class="form-control" id="lesson_description" name="lesson_description"
                                          rows="2" maxlength="2000"><?= e(Flash::old('lesson_description')) ?></textarea>
                                <?php View::partial('field-errors', ['name' => 'description']) ?>
                            </div>

                            <div class="col-6">
                                <label for="duration_minutes" class="form-label">Length in minutes</label>
                                <input type="number" class="form-control" id="duration_minutes" name="duration_minutes"
                                       min="1" max="1440" value="<?= e(Flash::old('duration_minutes', '10')) ?>" required>
                                <?php View::partial('field-errors', ['name' => 'duration_seconds']) ?>
                            </div>

                            <div class="col-6">
                                <label for="sequence" class="form-label">Position</label>
                                <input type="number" class="form-control" id="sequence" name="sequence"
                                       min="1" max="999" value="<?= e(Flash::old('sequence')) ?>"
                                       placeholder="<?= e((string) (count($selectedLessons) + 1)) ?>">
                                <div class="form-text">Leave empty to add it at the end.</div>
                                <?php View::partial('field-errors', ['name' => 'sequence']) ?>
                            </div>

                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="is_preview"
                                           name="is_preview" <?= Flash::old('is_preview') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="is_preview">
                                        Free preview, watchable without enrolling
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn btn-primary" data-busy-label="Adding...">
                                <i class="fa fa-plus"></i> Add lesson
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
