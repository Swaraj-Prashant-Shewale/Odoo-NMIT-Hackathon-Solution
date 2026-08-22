<?php
/**
 * The people register.
 *
 * What this list contains is decided by employee-service, not here: HR sees
 * everyone, a manager sees their own team, anybody else sees themselves. The
 * page is written once and is correct for all three.
 *
 * @var list<array<string, mixed>> $employees
 * @var array<string, mixed>       $meta
 * @var array<string, string>      $filters
 * @var list<array<string, mixed>> $departments
 * @var list<array<string, mixed>> $managers
 * @var list<string>               $statuses
 */

use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;

$hasFilters = array_filter($filters, static fn (string $value): bool => $value !== '') !== [];

ob_start(); ?>
<a href="/directory" class="btn btn-outline-secondary"><i class="fa fa-address-book"></i> Directory</a>
<a href="/org-chart" class="btn btn-outline-secondary"><i class="fa fa-sitemap"></i> Org chart</a>
<?php if (Session::can(Permissions::ONBOARDING_MANAGE)): ?>
    <a href="/onboarding" class="btn btn-outline-secondary"><i class="fa fa-clipboard-list"></i> Onboarding</a>
<?php endif; ?>
<?php if (Session::can(Permissions::EMPLOYEE_CREATE)): ?>
    <a href="/people/new" class="btn btn-primary"><i class="fa fa-user-plus"></i> Add employee</a>
<?php endif; ?>
<?php $actions = (string) ob_get_clean();

View::partial('page-header', [
    'title' => 'People',
    'subtitle' => Session::can(Permissions::PROFILE_VIEW_ALL)
        ? 'Everyone on the payroll, with the record behind each of them.'
        : 'The people whose records you are able to see.',
    'actions' => $actions,
]);
?>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="/people" class="row g-2 align-items-end">
            <div class="col-12 col-lg-4">
                <label for="search" class="form-label">Search</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-search"></i></span>
                    <input type="search" class="form-control" id="search" name="search"
                           value="<?= e($filters['search']) ?>"
                           placeholder="Name, employee code or work email">
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <label for="department_id" class="form-label">Department</label>
                <select class="form-select" id="department_id" name="department_id" data-submit-on-change>
                    <option value="">All departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e($department['id'] ?? '') ?>"
                            <?= $filters['department_id'] === (string) ($department['id'] ?? '') ? 'selected' : '' ?>>
                            <?= e($department['name'] ?? 'Unnamed') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-lg-2">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status" data-submit-on-change>
                    <option value="">Any status</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= e($status) ?>"
                            <?= $filters['status'] === $status ? 'selected' : '' ?>>
                            <?= e(label($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($managers !== []): ?>
                <div class="col-6 col-lg-2">
                    <label for="manager_id" class="form-label">Reports to</label>
                    <select class="form-select" id="manager_id" name="manager_id" data-submit-on-change>
                        <option value="">Any manager</option>
                        <?php foreach ($managers as $manager): ?>
                            <option value="<?= e($manager['id'] ?? '') ?>"
                                <?= $filters['manager_id'] === (string) ($manager['id'] ?? '') ? 'selected' : '' ?>>
                                <?= e($manager['full_name'] ?? 'Unnamed') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="col-6 col-lg-1 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1" data-allow-repeat>
                    <i class="fa fa-filter"></i>
                </button>
                <?php if ($hasFilters): ?>
                    <a href="/people" class="btn btn-outline-secondary" title="Clear filters">
                        <i class="fa fa-undo"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($employees === []): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-users',
                'title' => $hasFilters ? 'Nobody matches those filters' : 'No people to show',
                'message' => $hasFilters
                    ? 'Try a wider search, or clear the filters to see everyone you have access to.'
                    : 'People appear here once HR has created their records.',
                'actionLabel' => Session::can(Permissions::EMPLOYEE_CREATE) && !$hasFilters ? 'Add employee' : null,
                'actionHref' => Session::can(Permissions::EMPLOYEE_CREATE) && !$hasFilters ? '/people/new' : null,
            ]) ?>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fa fa-users"></i> People</span>
            <span class="small text-muted"><?= e((string) ($meta['total'] ?? count($employees))) ?> records</span>
        </div>
        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Designation</th>
                    <th>Department</th>
                    <th>Location</th>
                    <th>Reports to</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th class="text-end">Record</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($employees as $employee): ?>
                    <?php $id = (string) ($employee['id'] ?? ''); ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="avatar"><?= e(initials($employee['full_name'] ?? '')) ?></span>
                                <div>
                                    <div class="fw-semibold">
                                        <a href="/people/<?= e($id) ?>"><?= field($employee, 'full_name', 'Unnamed') ?></a>
                                    </div>
                                    <div class="small text-muted tabular"><?= field($employee, 'employee_code') ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?= field($employee, 'designation_name', 'Not set') ?></td>
                        <td><?= field($employee, 'department_name', 'Not set') ?></td>
                        <td><?= field($employee, 'location_name', 'Not set') ?></td>
                        <td><?= field($employee, 'manager_name', 'No manager') ?></td>
                        <td>
                            <?= badge($employee['employment_status'] ?? null) ?>
                            <?php if (isset($employee['is_active']) && $employee['is_active'] === false): ?>
                                <div class="small text-muted">Left <?= e(date_display($employee['exit_date'] ?? null, '')) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="tabular"><?= e(date_display($employee['joined_on'] ?? null)) ?></td>
                        <td class="text-end">
                            <a href="/people/<?= e($id) ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="fa fa-eye"></i> Open
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php View::partial('pagination', ['meta' => $meta]) ?>
<?php endif; ?>
