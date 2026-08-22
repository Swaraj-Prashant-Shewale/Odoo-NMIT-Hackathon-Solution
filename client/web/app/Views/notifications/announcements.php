<?php
/**
 * The company notice board.
 *
 * @var list<array<string, mixed>>                $announcements
 * @var array<string, string>                     $publishers Account id => display name.
 * @var array<string, mixed>                      $meta
 * @var string                                    $scope
 * @var bool                                      $mayPublish
 * @var list<array<string, mixed>>                $departments
 * @var list<array{value: string, label: string}> $roles
 * @var array<string, string>                     $categories
 * @var list<string>                              $severities
 * @var string                                    $today
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$tones = [
    'critical' => 'danger',
    'warning' => 'warning',
    'success' => 'success',
    'info' => 'info',
];

$departmentNames = [];

foreach ($departments as $department) {
    $departmentNames[(string) ($department['id'] ?? '')] = (string) ($department['name'] ?? '');
}
?>

<?php View::partial('page-header', [
    'title' => 'Announcements',
    'subtitle' => 'Company news, pinned notices first.',
]) ?>

<div class="row g-3">
    <div class="col-lg-<?= $mayPublish ? '7' : '12' ?>">

        <?php if ($mayPublish): ?>
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link <?= $scope === 'visible' ? 'active' : '' ?>" href="/announcements">
                        Live board
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $scope === 'all' ? 'active' : '' ?>" href="/announcements?scope=all">
                        Everything, including expired
                    </a>
                </li>
            </ul>
        <?php endif; ?>

        <?php if ($announcements === []): ?>
            <div class="card">
                <div class="card-body">
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-bullhorn',
                        'title' => 'Nothing on the board',
                        'message' => $mayPublish
                            ? 'Publish the first notice with the form beside this list.'
                            : 'When there is company news, it will appear here.',
                    ]) ?>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($announcements as $announcement): ?>
                <?php
                $severity = (string) ($announcement['severity'] ?? 'info');
                $tone = $tones[$severity] ?? 'secondary';
                $pinned = ($announcement['pinned'] ?? false) === true;
                $expiresOn = (string) ($announcement['expires_on'] ?? '');
                $expired = $expiresOn !== '' && $expiresOn < $today;
                $publishedBy = (string) ($announcement['published_by'] ?? '');
                $targetDepartment = (string) ($announcement['target_department_id'] ?? '');
                ?>
                <div class="card mb-3 <?= $pinned ? 'border-primary' : '' ?>">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <h2 class="h6 fw-bold mb-1">
                                <?php if ($pinned): ?>
                                    <i class="fa fa-thumbtack text-primary" title="Pinned"></i>
                                <?php endif; ?>
                                <?= field($announcement, 'title') ?>
                            </h2>
                            <span class="badge bg-<?= e($tone) ?>-subtle text-<?= e($tone) ?>-emphasis border border-<?= e($tone) ?>-subtle">
                                <?= e(label($severity)) ?>
                            </span>
                        </div>

                        <div class="small text-muted mb-3">
                            <?= e(datetime_display($announcement['published_at'] ?? null)) ?>
                            <?php if ($publishedBy !== '' && isset($publishers[$publishedBy])): ?>
                                &middot; by <?= e($publishers[$publishedBy]) ?>
                            <?php endif; ?>
                            &middot; <?= e(label((string) ($announcement['category'] ?? 'general'))) ?>
                        </div>

                        <p class="mb-0"><?= nl2br(e($announcement['body'] ?? '')) ?></p>
                    </div>

                    <div class="card-footer d-flex flex-wrap gap-2 align-items-center small">
                        <?php if ($targetDepartment !== ''): ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                <i class="fa fa-sitemap"></i>
                                <?= e($departmentNames[$targetDepartment] ?? 'One department') ?>
                            </span>
                        <?php endif; ?>

                        <?php if (!empty($announcement['target_role'])): ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                <i class="fa fa-user-shield"></i>
                                <?= e(label((string) $announcement['target_role'])) ?> only
                            </span>
                        <?php endif; ?>

                        <?php if ($expiresOn !== ''): ?>
                            <span class="text-muted">
                                <i class="fa fa-hourglass-end"></i>
                                <?= $expired ? 'Expired' : 'Expires' ?> <?= e(date_display($expiresOn)) ?>
                            </span>
                        <?php endif; ?>

                        <?php if (($announcement['is_active'] ?? true) === false): ?>
                            <span class="text-danger"><i class="fa fa-eye-slash"></i> Withdrawn</span>
                        <?php endif; ?>

                        <?php if (($announcement['is_read'] ?? false) === true): ?>
                            <span class="text-muted ms-auto"><i class="fa fa-check"></i> Read</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php View::partial('pagination', ['meta' => $meta]) ?>
        <?php endif; ?>
    </div>

    <?php if ($mayPublish): ?>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">Publish a notice</div>
                <form method="post" action="/announcements" novalidate>
                    <?= Csrf::field() ?>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="title" class="form-label">Title</label>
                            <input type="text" class="form-control <?= Flash::hasError('title') ? 'is-invalid' : '' ?>"
                                   id="title" name="title" maxlength="200" required
                                   value="<?= e(Flash::old('title')) ?>">
                            <?php View::partial('field-errors', ['name' => 'title']) ?>
                        </div>

                        <div class="mb-3">
                            <label for="body" class="form-label">Notice</label>
                            <textarea class="form-control <?= Flash::hasError('body') ? 'is-invalid' : '' ?>"
                                      id="body" name="body" rows="6" maxlength="20000"
                                      required><?= e(Flash::old('body')) ?></textarea>
                            <div class="form-text">Plain text. Line breaks are kept.</div>
                            <?php View::partial('field-errors', ['name' => 'body']) ?>
                        </div>

                        <div class="row g-3">
                            <div class="col-6">
                                <label for="category" class="form-label">Category</label>
                                <select class="form-select" id="category" name="category">
                                    <?php foreach ($categories as $value => $text): ?>
                                        <option value="<?= e($value) ?>" <?= Flash::old('category') === $value ? 'selected' : '' ?>>
                                            <?= e($text) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php View::partial('field-errors', ['name' => 'category']) ?>
                            </div>
                            <div class="col-6">
                                <label for="severity" class="form-label">Severity</label>
                                <select class="form-select" id="severity" name="severity">
                                    <?php foreach ($severities as $value): ?>
                                        <option value="<?= e($value) ?>" <?= Flash::old('severity') === $value ? 'selected' : '' ?>>
                                            <?= e(label($value)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php View::partial('field-errors', ['name' => 'severity']) ?>
                            </div>
                        </div>

                        <hr>
                        <div class="section-label">Who sees it</div>

                        <div class="mb-3">
                            <label for="target_department_id" class="form-label">Department</label>
                            <select class="form-select" id="target_department_id" name="target_department_id">
                                <option value="">Everybody</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= e($department['id'] ?? '') ?>"
                                        <?= Flash::old('target_department_id') === (string) ($department['id'] ?? '') ? 'selected' : '' ?>>
                                        <?= e($department['name'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'target_department_id']) ?>
                        </div>

                        <div class="mb-3">
                            <label for="target_role" class="form-label">Role</label>
                            <select class="form-select" id="target_role" name="target_role">
                                <option value="">Every role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= e($role['value']) ?>"
                                        <?= Flash::old('target_role') === $role['value'] ? 'selected' : '' ?>>
                                        <?= e($role['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Narrowing is applied when the board is read, so a targeted notice is never loaded by
                                somebody it was not meant for.
                            </div>
                            <?php View::partial('field-errors', ['name' => 'target_role']) ?>
                        </div>

                        <div class="mb-3">
                            <label for="expires_on" class="form-label">Expires on</label>
                            <input type="date" class="form-control <?= Flash::hasError('expires_on') ? 'is-invalid' : '' ?>"
                                   id="expires_on" name="expires_on" min="<?= e($today) ?>"
                                   value="<?= e(Flash::old('expires_on')) ?>">
                            <div class="form-text">Leave blank for a notice that stays until it is withdrawn.</div>
                            <?php View::partial('field-errors', ['name' => 'expires_on']) ?>
                        </div>

                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="pinned" name="pinned" value="1">
                            <label class="form-check-label" for="pinned">Pin to the top of the board</label>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary btn-sm" data-busy-label="Publishing...">
                            <i class="fa fa-bullhorn"></i> Publish
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
