<?php
/**
 * Login accounts.
 *
 * @var list<array<string, mixed>>                                       $users
 * @var array<string, mixed>                                             $meta
 * @var array<string, string>                                            $filters
 * @var list<array{value: string, label: string}>                        $roleOptions
 * @var list<array{value: string, label: string, description: string}>   $grantable
 * @var string                                                           $callerRole
 * @var string                                                           $callerUserId
 * @var bool                                                             $mayManageRoles
 */

use App\Core\Csrf;
use App\Core\View;
use Dayflow\Kernel\Security\Roles;
?>

<?php View::partial('page-header', [
    'title' => 'User accounts',
    'subtitle' => 'Who can sign in, what they may do, and the state of each account.',
    'actions' => '<a href="/admin/users/new" class="btn btn-primary btn-sm">'
        . '<i class="fa fa-user-plus"></i> New account</a>',
]) ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="/admin/users" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label for="search" class="form-label">Search</label>
                <input type="search"
                       class="form-control"
                       id="search"
                       name="search"
                       value="<?= e($filters['search'] ?? '') ?>"
                       placeholder="Name, email address or employee code">
            </div>
            <div class="col-md-3">
                <label for="role" class="form-label">Role</label>
                <select class="form-select" id="role" name="role" data-submit-on-change>
                    <option value="">Any role</option>
                    <?php foreach ($roleOptions as $option): ?>
                        <option value="<?= e($option['value']) ?>"
                            <?= ($filters['role'] ?? '') === $option['value'] ? 'selected' : '' ?>>
                            <?= e($option['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status" data-submit-on-change>
                    <option value="">Any status</option>
                    <?php foreach (['active', 'inactive', 'locked', 'verified', 'unverified'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= ($filters['status'] ?? '') === $status ? 'selected' : '' ?>>
                            <?= e(label($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-outline-secondary"><i class="fa fa-filter"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if ($users === []): ?>
            <div class="p-3">
                <?php View::partial('empty-state', [
                    'icon' => 'fa-user-slash',
                    'title' => 'No accounts match',
                    'message' => 'Nothing here matches those filters. Clear them to see every account.',
                    'actionLabel' => 'New account',
                    'actionHref' => '/admin/users/new',
                ]) ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th>Roles</th>
                            <th>Email</th>
                            <th>Account</th>
                            <th>Last signed in</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php
                            $id = (string) ($user['id'] ?? '');
                            $name = (string) ($user['full_name'] ?? '');
                            $isSelf = $id !== '' && $id === $callerUserId;
                            $held = is_array($user['roles'] ?? null) ? $user['roles'] : [];
                            $primary = (string) ($user['primary_role'] ?? '');
                            $outranksCaller = $primary !== '' && !Roles::outranks($callerRole, $primary);
                            $isActive = ($user['is_active'] ?? false) === true;
                            $panelId = 'roles-' . preg_replace('/[^A-Za-z0-9]/', '', $id);
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar avatar-sm"><?= e(initials($name)) ?></span>
                                        <div>
                                            <div class="fw-semibold"><?= e($name) ?></div>
                                            <div class="small text-muted">
                                                <?= field($user, 'employee_code', 'No employee code') ?>
                                                <?php if ($isSelf): ?>
                                                    &middot; <span class="text-primary">this is you</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($held === []): ?>
                                        <span class="text-muted">&mdash;</span>
                                    <?php else: ?>
                                        <?php foreach ($held as $role): ?>
                                            <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                                                <?= e(Roles::label((string) $role)) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="truncate" style="max-width: 220px;" title="<?= e($user['email'] ?? '') ?>">
                                        <?= field($user, 'email') ?>
                                    </div>
                                    <?php if (($user['is_verified'] ?? false) === true): ?>
                                        <span class="small text-success"><i class="fa fa-check-circle"></i> Verified</span>
                                    <?php else: ?>
                                        <span class="small text-warning"><i class="fa fa-exclamation-triangle"></i> Not verified</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $isActive ? badge('active') : badge('inactive') ?>
                                    <?php if (($user['is_locked'] ?? false) === true): ?>
                                        <div class="small text-danger mt-1">
                                            <i class="fa fa-lock"></i>
                                            Locked until <?= e(datetime_display($user['locked_until'] ?? null)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (($user['must_change_password'] ?? false) === true): ?>
                                        <div class="small text-muted mt-1">Must change password</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($user['last_login_at'])): ?>
                                        <div><?= e(datetime_display($user['last_login_at'])) ?></div>
                                        <div class="small text-muted"><?= e(relative_time((string) $user['last_login_at'])) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-1 flex-wrap">
                                        <?php if ($mayManageRoles): ?>
                                            <button class="btn btn-outline-secondary btn-sm"
                                                    type="button"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#<?= e($panelId) ?>"
                                                    aria-expanded="false"
                                                    aria-controls="<?= e($panelId) ?>">
                                                <i class="fa fa-user-shield"></i> Roles
                                            </button>
                                        <?php endif; ?>

                                        <?php if (!$isActive): ?>
                                            <form method="post" action="/admin/users/<?= e($id) ?>/activate" class="d-inline">
                                                <?= Csrf::field() ?>
                                                <button type="submit" class="btn btn-outline-success btn-sm">
                                                    <i class="fa fa-play"></i> Activate
                                                </button>
                                            </form>
                                        <?php elseif (!$isSelf): ?>
                                            <form method="post" action="/admin/users/<?= e($id) ?>/deactivate" class="d-inline">
                                                <?= Csrf::field() ?>
                                                <button type="submit"
                                                        class="btn btn-outline-danger btn-sm"
                                                        data-confirm="Deactivate <?= e($name) ?>? Every session they hold ends immediately.">
                                                    <i class="fa fa-ban"></i> Deactivate
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <?php if ($mayManageRoles): ?>
                                <tr class="collapse" id="<?= e($panelId) ?>">
                                    <td colspan="6" class="bg-light">
                                        <?php if ($isSelf): ?>
                                            <p class="small mb-0">
                                                <i class="fa fa-info-circle"></i>
                                                You cannot change your own roles. Escalation always needs a second
                                                administrator, so ask a colleague to make this change.
                                            </p>
                                        <?php elseif ($outranksCaller): ?>
                                            <p class="small mb-0">
                                                <i class="fa fa-lock"></i>
                                                <?= e($name) ?> holds <?= e(Roles::label($primary)) ?>, which is senior
                                                to your own role of <?= e(Roles::label($callerRole)) ?>. You may neither
                                                grant nor remove a role above your own.
                                            </p>
                                        <?php else: ?>
                                            <form method="post" action="/admin/users/<?= e($id) ?>/roles">
                                                <?= Csrf::field() ?>
                                                <div class="section-label">Roles for <?= e($name) ?></div>
                                                <div class="row g-2">
                                                    <?php foreach ($grantable as $role): ?>
                                                        <div class="col-md-6 col-lg-4">
                                                            <div class="form-check">
                                                                <input class="form-check-input"
                                                                       type="checkbox"
                                                                       name="roles[]"
                                                                       value="<?= e($role['value']) ?>"
                                                                       id="<?= e($panelId . '-' . $role['value']) ?>"
                                                                    <?= in_array($role['value'], $held, true) ? 'checked' : '' ?>>
                                                                <label class="form-check-label"
                                                                       for="<?= e($panelId . '-' . $role['value']) ?>">
                                                                    <span class="fw-semibold"><?= e($role['label']) ?></span>
                                                                    <span class="d-block small text-muted"><?= e($role['description']) ?></span>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>

                                                <p class="small text-muted mt-2 mb-0">
                                                    <i class="fa fa-lock"></i>
                                                    Only roles at or below your own of
                                                    <?= e(Roles::label($callerRole)) ?> are listed. Saving replaces
                                                    the whole set, so an unticked box removes that role.
                                                </p>

                                                <?php View::partial('field-errors', ['name' => 'roles']) ?>

                                                <div class="d-flex align-items-center gap-2 mt-3">
                                                    <button type="submit" class="btn btn-primary btn-sm"
                                                            data-busy-label="Saving...">
                                                        <i class="fa fa-save"></i> Save roles
                                                    </button>
                                                    <span class="small text-muted">
                                                        Saving ends every session this account holds, so the new roles
                                                        take effect at their next sign-in.
                                                    </span>
                                                </div>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($users !== []): ?>
        <div class="card-footer">
            <?php View::partial('pagination', ['meta' => $meta]) ?>
        </div>
    <?php endif; ?>
</div>
