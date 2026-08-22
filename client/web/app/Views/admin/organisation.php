<?php
/**
 * Departments, designations and locations.
 *
 * @var list<array<string, mixed>> $departments
 * @var list<array<string, mixed>> $designations
 * @var list<array<string, mixed>> $locations
 * @var list<array<string, mixed>> $people
 * @var bool                       $peopleComplete Whether the picker holds everybody.
 * @var list<string>               $timezones
 * @var string                     $tab
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$tabs = [
    'departments' => ['label' => 'Departments', 'icon' => 'fa-sitemap', 'count' => count($departments)],
    'designations' => ['label' => 'Designations', 'icon' => 'fa-id-badge', 'count' => count($designations)],
    'locations' => ['label' => 'Locations', 'icon' => 'fa-map-marker-alt', 'count' => count($locations)],
];
?>

<?php View::partial('page-header', [
    'title' => 'Organisation',
    'subtitle' => 'The structure every other screen reads from: who sits where, what they are called, and where they work.',
]) ?>

<ul class="nav nav-tabs mb-3">
    <?php foreach ($tabs as $key => $item): ?>
        <li class="nav-item">
            <a class="nav-link <?= $tab === $key ? 'active' : '' ?>"
               href="/admin/organisation?tab=<?= e($key) ?>">
                <i class="fa <?= e($item['icon']) ?>"></i>
                <?= e($item['label']) ?>
                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle ms-1">
                    <?= e((string) $item['count']) ?>
                </span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($people === []): ?>
    <div class="alert alert-warning small">
        <i class="fa fa-exclamation-triangle"></i>
        The people directory could not be read, so the department head picker below is empty.
    </div>
<?php endif; ?>

<?php if ($tab === 'departments'): ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">Departments</div>
                <div class="card-body p-0">
                    <?php if ($departments === []): ?>
                        <div class="p-3">
                            <?php View::partial('empty-state', [
                                'icon' => 'fa-sitemap',
                                'title' => 'No departments yet',
                                'message' => 'Add the first one with the form beside this list. Everything else in the product hangs off it.',
                            ]) ?>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th>Reports into</th>
                                        <th>Head</th>
                                        <th class="text-end">People</th>
                                        <th>State</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($departments as $department): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?= field($department, 'name') ?></div>
                                                <div class="small text-muted">
                                                    <code><?= field($department, 'code', '—') ?></code>
                                                    <?php if (!empty($department['cost_centre'])): ?>
                                                        &middot; cost centre <?= field($department, 'cost_centre') ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><?= field($department, 'parent_name', 'Top level') ?></td>
                                            <td><?= field($department, 'head_employee_name', 'Not set') ?></td>
                                            <td class="text-end tabular"><?= e((string) (int) ($department['employee_count'] ?? 0)) ?></td>
                                            <td><?= ($department['is_active'] ?? true) === false ? badge('inactive') : badge('active') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">Add a department</div>
                <form method="post" action="/admin/organisation/departments" novalidate>
                    <?= Csrf::field() ?>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="dept-name" class="form-label">Name</label>
                            <input type="text" class="form-control <?= Flash::hasError('name') ? 'is-invalid' : '' ?>"
                                   id="dept-name" name="name" maxlength="100" required
                                   value="<?= e(Flash::old('name')) ?>">
                            <?php View::partial('field-errors', ['name' => 'name']) ?>
                        </div>

                        <div class="row g-3">
                            <div class="col-6">
                                <label for="dept-code" class="form-label">Code</label>
                                <input type="text" class="form-control <?= Flash::hasError('code') ? 'is-invalid' : '' ?>"
                                       id="dept-code" name="code" maxlength="20" required
                                       placeholder="ENG" value="<?= e(Flash::old('code')) ?>">
                                <div class="form-text">Letters and numbers only.</div>
                                <?php View::partial('field-errors', ['name' => 'code']) ?>
                            </div>
                            <div class="col-6">
                                <label for="dept-cost-centre" class="form-label">Cost centre</label>
                                <input type="text" class="form-control" id="dept-cost-centre"
                                       name="cost_centre" maxlength="40" value="<?= e(Flash::old('cost_centre')) ?>">
                                <?php View::partial('field-errors', ['name' => 'cost_centre']) ?>
                            </div>
                        </div>

                        <div class="mb-3 mt-3">
                            <label for="dept-parent" class="form-label">Reports into</label>
                            <select class="form-select" id="dept-parent" name="parent_id">
                                <option value="">Top level</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= e($department['id'] ?? '') ?>"
                                        <?= Flash::old('parent_id') === (string) ($department['id'] ?? '') ? 'selected' : '' ?>>
                                        <?= e($department['name'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">A department cannot be made its own ancestor.</div>
                            <?php View::partial('field-errors', ['name' => 'parent_id']) ?>
                        </div>

                        <div class="mb-3">
                            <label for="dept-head" class="form-label">Head of department</label>
                            <select class="form-select" id="dept-head" name="head_employee_id">
                                <option value="">Not set</option>
                                <?php foreach ($people as $person): ?>
                                    <option value="<?= e($person['id'] ?? '') ?>"
                                        <?= Flash::old('head_employee_id') === (string) ($person['id'] ?? '') ? 'selected' : '' ?>>
                                        <?= e($person['full_name'] ?? '') ?><?= empty($person['employee_code']) ? '' : ' (' . e($person['employee_code']) . ')' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$peopleComplete && $people !== []): ?>
                                <div class="form-text">
                                    Showing the first <?= e((string) count($people)) ?> people in the directory,
                                    in alphabetical order. There are more beyond that.
                                </div>
                            <?php endif; ?>
                            <?php View::partial('field-errors', ['name' => 'head_employee_id']) ?>
                        </div>

                        <div class="mb-0">
                            <label for="dept-description" class="form-label">Description</label>
                            <textarea class="form-control" id="dept-description" name="description"
                                      rows="2" maxlength="500"><?= e(Flash::old('description')) ?></textarea>
                            <?php View::partial('field-errors', ['name' => 'description']) ?>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary btn-sm" data-busy-label="Saving...">
                            <i class="fa fa-plus"></i> Add department
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'designations'): ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">Designations</div>
                <div class="card-body p-0">
                    <?php if ($designations === []): ?>
                        <div class="p-3">
                            <?php View::partial('empty-state', [
                                'icon' => 'fa-id-badge',
                                'title' => 'No designations yet',
                                'message' => 'Job titles carry a level, so seniority can be compared without reading the title.',
                            ]) ?>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Department</th>
                                        <th class="text-end">Level</th>
                                        <th>State</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($designations as $designation): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?= field($designation, 'title') ?></div>
                                                <div class="small text-muted"><code><?= field($designation, 'code', '—') ?></code></div>
                                            </td>
                                            <td><?= field($designation, 'department_name', 'Any department') ?></td>
                                            <td class="text-end tabular"><?= field($designation, 'level', '—') ?></td>
                                            <td><?= ($designation['is_active'] ?? true) === false ? badge('inactive') : badge('active') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">Add a designation</div>
                <form method="post" action="/admin/organisation/designations" novalidate>
                    <?= Csrf::field() ?>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="desig-title" class="form-label">Title</label>
                            <input type="text" class="form-control <?= Flash::hasError('title') ? 'is-invalid' : '' ?>"
                                   id="desig-title" name="title" maxlength="100" required
                                   value="<?= e(Flash::old('title')) ?>">
                            <?php View::partial('field-errors', ['name' => 'title']) ?>
                        </div>

                        <div class="row g-3">
                            <div class="col-6">
                                <label for="desig-code" class="form-label">Code</label>
                                <input type="text" class="form-control <?= Flash::hasError('code') ? 'is-invalid' : '' ?>"
                                       id="desig-code" name="code" maxlength="20" required
                                       placeholder="SSE" value="<?= e(Flash::old('code')) ?>">
                                <?php View::partial('field-errors', ['name' => 'code']) ?>
                            </div>
                            <div class="col-6">
                                <label for="desig-level" class="form-label">Level</label>
                                <input type="number" class="form-control <?= Flash::hasError('level') ? 'is-invalid' : '' ?>"
                                       id="desig-level" name="level" min="1" max="20"
                                       value="<?= e(Flash::old('level')) ?>">
                                <div class="form-text">1 is the most junior, 20 the most senior.</div>
                                <?php View::partial('field-errors', ['name' => 'level']) ?>
                            </div>
                        </div>

                        <div class="mb-3 mt-3">
                            <label for="desig-department" class="form-label">Department</label>
                            <select class="form-select" id="desig-department" name="department_id">
                                <option value="">Any department</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= e($department['id'] ?? '') ?>"
                                        <?= Flash::old('department_id') === (string) ($department['id'] ?? '') ? 'selected' : '' ?>>
                                        <?= e($department['name'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'department_id']) ?>
                        </div>

                        <div class="mb-0">
                            <label for="desig-description" class="form-label">Description</label>
                            <textarea class="form-control" id="desig-description" name="description"
                                      rows="2" maxlength="500"><?= e(Flash::old('description')) ?></textarea>
                            <?php View::partial('field-errors', ['name' => 'description']) ?>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary btn-sm" data-busy-label="Saving...">
                            <i class="fa fa-plus"></i> Add designation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php else: ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">Locations</div>
                <div class="card-body p-0">
                    <?php if ($locations === []): ?>
                        <div class="p-3">
                            <?php View::partial('empty-state', [
                                'icon' => 'fa-map-marker-alt',
                                'title' => 'No locations yet',
                                'message' => 'Attendance is judged against the working day where a person actually is, so each office carries its own timezone.',
                            ]) ?>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Location</th>
                                        <th>Address</th>
                                        <th>Timezone</th>
                                        <th>State</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($locations as $location): ?>
                                        <?php
                                        $address = array_filter([
                                            (string) ($location['address_line1'] ?? ''),
                                            (string) ($location['address_line2'] ?? ''),
                                            (string) ($location['city'] ?? ''),
                                            (string) ($location['state'] ?? ''),
                                            (string) ($location['postal_code'] ?? ''),
                                            (string) ($location['country'] ?? ''),
                                        ], static fn (string $part): bool => $part !== '');
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?= field($location, 'name') ?></div>
                                                <?php if (($location['is_remote'] ?? false) === true): ?>
                                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                                        Remote base
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small"><?= $address === [] ? '<span class="text-muted">&mdash;</span>' : e(implode(', ', $address)) ?></td>
                                            <td class="small"><?= field($location, 'timezone', 'Company default') ?></td>
                                            <td><?= ($location['is_active'] ?? true) === false ? badge('inactive') : badge('active') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">Add a location</div>
                <form method="post" action="/admin/organisation/locations" novalidate>
                    <?= Csrf::field() ?>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="loc-name" class="form-label">Name</label>
                            <input type="text" class="form-control <?= Flash::hasError('name') ? 'is-invalid' : '' ?>"
                                   id="loc-name" name="name" maxlength="100" required
                                   value="<?= e(Flash::old('name')) ?>">
                            <?php View::partial('field-errors', ['name' => 'name']) ?>
                        </div>

                        <div class="mb-3">
                            <label for="loc-address1" class="form-label">Address</label>
                            <input type="text" class="form-control mb-2" id="loc-address1"
                                   name="address_line1" maxlength="150" placeholder="Street address"
                                   value="<?= e(Flash::old('address_line1')) ?>">
                            <input type="text" class="form-control" id="loc-address2"
                                   name="address_line2" maxlength="150" placeholder="Building, floor or unit"
                                   aria-label="Second address line"
                                   value="<?= e(Flash::old('address_line2')) ?>">
                            <?php View::partial('field-errors', ['name' => 'address_line1']) ?>
                        </div>

                        <div class="row g-3">
                            <div class="col-6">
                                <label for="loc-city" class="form-label">City</label>
                                <input type="text" class="form-control" id="loc-city" name="city"
                                       maxlength="80" value="<?= e(Flash::old('city')) ?>">
                            </div>
                            <div class="col-6">
                                <label for="loc-state" class="form-label">State</label>
                                <input type="text" class="form-control" id="loc-state" name="state"
                                       maxlength="80" value="<?= e(Flash::old('state')) ?>">
                            </div>
                            <div class="col-6">
                                <label for="loc-postal" class="form-label">Postal code</label>
                                <input type="text" class="form-control" id="loc-postal" name="postal_code"
                                       maxlength="20" value="<?= e(Flash::old('postal_code')) ?>">
                            </div>
                            <div class="col-6">
                                <label for="loc-country" class="form-label">Country</label>
                                <input type="text" class="form-control" id="loc-country" name="country"
                                       maxlength="80" value="<?= e(Flash::old('country')) ?>">
                            </div>
                        </div>

                        <div class="mt-3 mb-3">
                            <label for="loc-timezone" class="form-label">Timezone</label>
                            <select class="form-select <?= Flash::hasError('timezone') ? 'is-invalid' : '' ?>"
                                    id="loc-timezone" name="timezone">
                                <option value="">Company default</option>
                                <?php foreach ($timezones as $zone): ?>
                                    <option value="<?= e($zone) ?>" <?= Flash::old('timezone') === $zone ? 'selected' : '' ?>>
                                        <?= e($zone) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Attendance for everybody based here is judged against this zone, so a wrong entry
                                shifts every arrival time.
                            </div>
                            <?php View::partial('field-errors', ['name' => 'timezone']) ?>
                        </div>

                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="loc-remote"
                                   name="is_remote" value="1">
                            <label class="form-check-label" for="loc-remote">
                                This is a remote working base rather than an office
                            </label>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary btn-sm" data-busy-label="Saving...">
                            <i class="fa fa-plus"></i> Add location
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php endif; ?>
