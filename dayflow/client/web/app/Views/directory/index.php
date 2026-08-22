<?php
/**
 * The company directory.
 *
 * Deliberately narrow: name, role, team, work email and office. Nothing on
 * this page is private, which is why it is the one listing every employee may
 * open.
 *
 * @var list<array<string, mixed>>   $people
 * @var array<string, mixed>         $meta
 * @var list<array<string, mixed>>   $departments
 * @var list<array<string, mixed>>   $locations
 * @var array<string, string>        $filters
 */

use App\Core\View;

View::partial('page-header', [
    'title' => 'Company directory',
    'subtitle' => 'Everyone at the company, with the details colleagues need to reach them.',
]);
?>

<div class="card mb-3">
    <div class="card-body row g-2 align-items-end">

        <?php /* Outside the filter form on purpose: this box narrows the rows already on
                 the page, so pressing Enter in it should do nothing rather than reload. */ ?>
        <div class="col-12 col-lg-5">
            <label for="directorySearch" class="form-label">Search this page</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fa fa-search"></i></span>
                <input type="search"
                       class="form-control"
                       id="directorySearch"
                       data-filter-table="directoryTable"
                       placeholder="Name, role, team or email"
                       autocomplete="off">
            </div>
            <div class="form-text">Narrows the list as you type. Nothing is sent to the server.</div>
        </div>

        <form method="get" action="/directory" class="col-12 col-lg-7 row g-2 align-items-end">
            <div class="col-6 col-lg-5">
                <label for="department_id" class="form-label">Department</label>
                <select class="form-select" id="department_id" name="department_id" data-submit-on-change>
                    <option value="">All departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e($department['id'] ?? '') ?>"
                            <?= ($filters['department_id'] ?? '') === (string) ($department['id'] ?? '') ? 'selected' : '' ?>>
                            <?= e($department['name'] ?? 'Unnamed') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6 col-lg-5">
                <label for="location_id" class="form-label">Office</label>
                <select class="form-select" id="location_id" name="location_id" data-submit-on-change>
                    <option value="">All offices</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= e($location['id'] ?? '') ?>"
                            <?= ($filters['location_id'] ?? '') === (string) ($location['id'] ?? '') ? 'selected' : '' ?>>
                            <?= e($location['name'] ?? 'Unnamed') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-lg-2 d-grid">
                <a href="/directory" class="btn btn-outline-secondary" title="Clear filters">
                    <i class="fa fa-undo"></i> Clear
                </a>
            </div>
        </form>

    </div>
</div>

<?php if ($people === []): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-address-book',
                'title' => 'Nobody matches those filters',
                'message' => 'Try a different department or office.',
            ]) ?>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fa fa-users"></i> Colleagues</span>
            <span class="small text-muted"><?= e((string) ($meta['total'] ?? count($people))) ?> people</span>
        </div>
        <div class="table-wrap">
            <table class="table align-middle" id="directoryTable">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Department</th>
                    <th>Work email</th>
                    <th>Office</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($people as $person): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="avatar"><?= e(initials($person['full_name'] ?? '')) ?></span>
                                <div>
                                    <div class="fw-semibold"><?= field($person, 'full_name', 'Unnamed') ?></div>
                                    <div class="small text-muted tabular"><?= field($person, 'employee_code') ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?= field($person, 'designation_name', 'Not set') ?></td>
                        <td><?= field($person, 'department_name', 'Not set') ?></td>
                        <td>
                            <?php if (!empty($person['work_email'])): ?>
                                <a href="mailto:<?= e($person['work_email']) ?>" class="truncate d-inline-block"
                                   style="max-width: 260px;"><?= e($person['work_email']) ?></a>
                            <?php else: ?>
                                <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td><?= field($person, 'location_name', 'Not set') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer text-muted" data-filter-empty style="display: none;">
            Nobody on this page matches what you typed.
        </div>
    </div>

    <?php View::partial('pagination', ['meta' => $meta]) ?>
<?php endif; ?>
