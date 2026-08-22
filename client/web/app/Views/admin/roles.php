<?php
/**
 * The role-to-permission matrix.
 *
 * @var list<array<string, mixed>> $roles       One entry per role, most senior first.
 * @var list<array<string, mixed>> $groups      Permissions grouped by area.
 * @var list<string>               $selfService Roles a visitor may choose at sign-up.
 */

use App\Core\View;

$columns = array_values(array_filter(array_map(
    static fn (array $role): string => (string) ($role['role'] ?? ''),
    $roles
), static fn (string $role): bool => $role !== ''));

$permissionTotal = 0;

foreach ($groups as $group) {
    $permissionTotal += is_array($group['permissions'] ?? null) ? count($group['permissions']) : 0;
}
?>

<?php View::partial('page-header', [
    'title' => 'Roles and permissions',
    'subtitle' => 'The complete access model. Roles and their permissions are declared in code, so widening'
        . ' somebody\'s access is a reviewed change rather than an edit to a table.',
]) ?>

<?php if ($roles === []): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-user-shield',
                'title' => 'The access model could not be read',
                'message' => 'The identity service did not return the role catalogue. Check system health and try again.',
                'actionLabel' => 'System health',
                'actionHref' => '/admin/health',
            ]) ?>
        </div>
    </div>
<?php else: ?>

    <div class="row g-3 mb-4">
        <?php foreach ($roles as $role): ?>
            <?php
            $key = (string) ($role['role'] ?? '');
            $count = (int) ($role['permission_count'] ?? 0);
            $share = $permissionTotal > 0 ? percent(($count / $permissionTotal) * 100) : 0;
            ?>
            <div class="col-md-6 col-xl-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold"><?= e($role['label'] ?? $key) ?></div>
                                <div class="small text-muted"><code><?= e($key) ?></code></div>
                            </div>
                            <?php if (($role['is_administrative'] ?? false) === true): ?>
                                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                                    Administrative
                                </span>
                            <?php endif; ?>
                        </div>

                        <p class="small text-muted mt-2 mb-3"><?= e($role['description'] ?? '') ?></p>

                        <div class="stat-row">
                            <span class="stat-key">Permissions</span>
                            <span class="stat-val tabular"><?= e((string) $count) ?> of <?= e((string) $permissionTotal) ?></span>
                        </div>
                        <div class="progress mt-2">
                            <div class="progress-bar bg-primary" style="width: <?= e((string) $share) ?>%"></div>
                        </div>

                        <?php if (in_array($key, $selfService, true)): ?>
                            <p class="small text-muted mt-3 mb-0">
                                <i class="fa fa-user-plus"></i>
                                Can be chosen during self-registration.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>Permission matrix</span>
            <input type="search"
                   class="form-control form-control-sm"
                   style="max-width: 260px;"
                   data-filter-table="permission-matrix"
                   placeholder="Filter permissions"
                   aria-label="Filter permissions">
        </div>
        <div class="card-body p-0">
            <div class="table-wrap">
                <table class="table align-middle" id="permission-matrix">
                    <thead>
                        <tr>
                            <th style="min-width: 260px;">Permission</th>
                            <?php foreach ($roles as $role): ?>
                                <th class="text-center" style="min-width: 96px;">
                                    <div class="small"><?= e($role['label'] ?? $role['role'] ?? '') ?></div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groups as $group): ?>
                            <tr>
                                <td colspan="<?= e((string) (count($columns) + 1)) ?>" class="bg-light">
                                    <span class="section-label d-inline-block mb-0"><?= e($group['group'] ?? '') ?></span>
                                </td>
                            </tr>
                            <?php foreach ((is_array($group['permissions'] ?? null) ? $group['permissions'] : []) as $permission): ?>
                                <?php $holders = is_array($permission['held_by'] ?? null) ? $permission['held_by'] : []; ?>
                                <tr>
                                    <td>
                                        <div><?= e($permission['label'] ?? '') ?></div>
                                        <div class="small text-muted"><code><?= e($permission['key'] ?? '') ?></code></div>
                                    </td>
                                    <?php foreach ($columns as $column): ?>
                                        <td class="text-center">
                                            <?php if (in_array($column, $holders, true)): ?>
                                                <i class="fa fa-check text-success"
                                                   title="Granted to <?= e($column) ?>"></i>
                                                <span class="visually-hidden">Granted</span>
                                            <?php else: ?>
                                                <span class="text-muted">&middot;</span>
                                                <span class="visually-hidden">Not granted</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-3" data-filter-empty style="display: none;">
                <p class="small text-muted mb-0">No permission matches that text.</p>
            </div>
        </div>
        <div class="card-footer small text-muted">
            A tick means the role ends up with that permission once inheritance is resolved, which is exactly what
            the platform enforces on every request. Nobody may grant or remove a role more senior than their own.
        </div>
    </div>
<?php endif; ?>
