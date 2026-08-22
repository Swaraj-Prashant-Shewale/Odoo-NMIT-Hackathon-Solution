<?php
/**
 * The full record, editable.
 *
 * Reachable only with profile.edit.all, which is the permission that separates
 * "may change my own contact details" from "may change what somebody is paid
 * for and when their leave accrues".
 *
 * @var array<string, mixed>       $employee
 * @var list<array<string, mixed>> $departments
 * @var list<array<string, mixed>> $designations
 * @var list<array<string, mixed>> $locations
 * @var list<array<string, mixed>> $managerCandidates
 * @var list<string>               $employmentTypes
 * @var list<string>               $employmentStatuses
 * @var list<string>               $genders
 * @var list<string>               $bloodGroups
 * @var list<string>               $maritalStatuses
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$id = (string) ($employee['id'] ?? '');

$value = static fn (string $key): string => Flash::old($key, (string) ($employee[$key] ?? ''));
$invalid = static fn (string $key): string => Flash::hasError($key) ? ' is-invalid' : '';

$activeValue = Flash::old('is_active', ($employee['is_active'] ?? true) ? '1' : '0');

View::partial('page-header', [
    'title' => 'Edit ' . (string) ($employee['full_name'] ?? 'employee'),
    'subtitle' => 'Every field on the record, including the ones the employee cannot change themselves.',
]);
?>

<form method="post" action="/people/<?= e($id) ?>/edit" novalidate>
    <?= Csrf::field() ?>

    <div class="row g-3">

        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="fa fa-user"></i> Identity</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="first_name" class="form-label">First name</label>
                            <input type="text" class="form-control<?= $invalid('first_name') ?>"
                                   id="first_name" name="first_name" maxlength="80"
                                   value="<?= e($value('first_name')) ?>">
                            <?php View::partial('field-errors', ['name' => 'first_name']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="last_name" class="form-label">Last name</label>
                            <input type="text" class="form-control<?= $invalid('last_name') ?>"
                                   id="last_name" name="last_name" maxlength="80"
                                   value="<?= e($value('last_name')) ?>">
                            <?php View::partial('field-errors', ['name' => 'last_name']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="work_email" class="form-label">Work email</label>
                            <input type="email" class="form-control<?= $invalid('work_email') ?>"
                                   id="work_email" name="work_email" maxlength="150"
                                   value="<?= e($value('work_email')) ?>">
                            <?php View::partial('field-errors', ['name' => 'work_email']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="personal_email" class="form-label">Personal email</label>
                            <input type="email" class="form-control<?= $invalid('personal_email') ?>"
                                   id="personal_email" name="personal_email" maxlength="150"
                                   value="<?= e($value('personal_email')) ?>">
                            <?php View::partial('field-errors', ['name' => 'personal_email']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="date_of_birth" class="form-label">Date of birth</label>
                            <input type="date" class="form-control<?= $invalid('date_of_birth') ?>"
                                   id="date_of_birth" name="date_of_birth" value="<?= e($value('date_of_birth')) ?>">
                            <?php View::partial('field-errors', ['name' => 'date_of_birth']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="gender" class="form-label">Gender</label>
                            <select class="form-select<?= $invalid('gender') ?>" id="gender" name="gender">
                                <option value="">Not recorded</option>
                                <?php foreach ($genders as $gender): ?>
                                    <option value="<?= e($gender) ?>" <?= $value('gender') === $gender ? 'selected' : '' ?>>
                                        <?= e(label($gender)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'gender']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="marital_status" class="form-label">Marital status</label>
                            <select class="form-select<?= $invalid('marital_status') ?>"
                                    id="marital_status" name="marital_status">
                                <option value="">Not recorded</option>
                                <?php foreach ($maritalStatuses as $status): ?>
                                    <option value="<?= e($status) ?>" <?= $value('marital_status') === $status ? 'selected' : '' ?>>
                                        <?= e(label($status)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'marital_status']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="blood_group" class="form-label">Blood group</label>
                            <select class="form-select<?= $invalid('blood_group') ?>"
                                    id="blood_group" name="blood_group">
                                <option value="">Not recorded</option>
                                <?php foreach ($bloodGroups as $group): ?>
                                    <option value="<?= e($group) ?>" <?= $value('blood_group') === $group ? 'selected' : '' ?>>
                                        <?= e($group) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'blood_group']) ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer text-muted">
                    Employee code <span class="tabular"><?= field($employee, 'employee_code') ?></span> is
                    allocated once and never reissued.
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="fa fa-briefcase"></i> Employment</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="department_id" class="form-label">Department</label>
                            <select class="form-select<?= $invalid('department_id') ?>"
                                    id="department_id" name="department_id">
                                <option value="">Not set</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= e($department['id'] ?? '') ?>"
                                        <?= $value('department_id') === (string) ($department['id'] ?? '') ? 'selected' : '' ?>>
                                        <?= e($department['name'] ?? 'Unnamed') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'department_id']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="designation_id" class="form-label">Designation</label>
                            <select class="form-select<?= $invalid('designation_id') ?>"
                                    id="designation_id" name="designation_id">
                                <option value="">Not set</option>
                                <?php foreach ($designations as $designation): ?>
                                    <option value="<?= e($designation['id'] ?? '') ?>"
                                        <?= $value('designation_id') === (string) ($designation['id'] ?? '') ? 'selected' : '' ?>>
                                        <?= e($designation['title'] ?? 'Untitled') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'designation_id']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="location_id" class="form-label">Location</label>
                            <select class="form-select<?= $invalid('location_id') ?>"
                                    id="location_id" name="location_id">
                                <option value="">Not set</option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?= e($location['id'] ?? '') ?>"
                                        <?= $value('location_id') === (string) ($location['id'] ?? '') ? 'selected' : '' ?>>
                                        <?= e($location['name'] ?? 'Unnamed') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'location_id']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="manager_id" class="form-label">Manager</label>
                            <select class="form-select<?= $invalid('manager_id') ?>"
                                    id="manager_id" name="manager_id">
                                <option value="">No manager</option>
                                <?php foreach ($managerCandidates as $candidate): ?>
                                    <option value="<?= e($candidate['id'] ?? '') ?>"
                                        <?= $value('manager_id') === (string) ($candidate['id'] ?? '') ? 'selected' : '' ?>>
                                        <?= e($candidate['full_name'] ?? 'Unnamed') ?>
                                        (<?= e($candidate['employee_code'] ?? '') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">A reporting line that would loop back on itself is refused.</div>
                            <?php View::partial('field-errors', ['name' => 'manager_id']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="employment_type" class="form-label">Employment type</label>
                            <select class="form-select<?= $invalid('employment_type') ?>"
                                    id="employment_type" name="employment_type">
                                <?php foreach ($employmentTypes as $type): ?>
                                    <option value="<?= e($type) ?>" <?= $value('employment_type') === $type ? 'selected' : '' ?>>
                                        <?= e(label($type)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'employment_type']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="employment_status" class="form-label">Employment status</label>
                            <select class="form-select<?= $invalid('employment_status') ?>"
                                    id="employment_status" name="employment_status">
                                <?php foreach ($employmentStatuses as $status): ?>
                                    <option value="<?= e($status) ?>" <?= $value('employment_status') === $status ? 'selected' : '' ?>>
                                        <?= e(label($status)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'employment_status']) ?>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="joined_on" class="form-label">Joined on</label>
                            <input type="date" class="form-control<?= $invalid('joined_on') ?>"
                                   id="joined_on" name="joined_on" value="<?= e($value('joined_on')) ?>">
                            <?php View::partial('field-errors', ['name' => 'joined_on']) ?>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="probation_end_on" class="form-label">Probation ends</label>
                            <input type="date" class="form-control<?= $invalid('probation_end_on') ?>"
                                   id="probation_end_on" name="probation_end_on"
                                   value="<?= e($value('probation_end_on')) ?>">
                            <?php View::partial('field-errors', ['name' => 'probation_end_on']) ?>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="confirmed_on" class="form-label">Confirmed on</label>
                            <input type="date" class="form-control<?= $invalid('confirmed_on') ?>"
                                   id="confirmed_on" name="confirmed_on" value="<?= e($value('confirmed_on')) ?>">
                            <?php View::partial('field-errors', ['name' => 'confirmed_on']) ?>
                        </div>

                        <div class="col-12">
                            <label for="is_active" class="form-label">Record state</label>
                            <select class="form-select<?= $invalid('is_active') ?>" id="is_active" name="is_active">
                                <option value="1" <?= $activeValue === '1' ? 'selected' : '' ?>>
                                    Active &mdash; counted in headcount and payroll
                                </option>
                                <option value="0" <?= $activeValue === '0' ? 'selected' : '' ?>>
                                    Inactive &mdash; no longer employed
                                </option>
                            </select>
                            <div class="form-text">
                                Somebody leaving is handled by the offboarding form on their record, which sets
                                this along with the leaving date and creates an exit checklist.
                            </div>
                            <?php View::partial('field-errors', ['name' => 'is_active']) ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer text-muted">
                    Payroll and leave read these dates. A change here changes what somebody accrues.
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header"><i class="fa fa-address-card"></i> Contact and address</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6 col-lg-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control<?= $invalid('phone') ?>"
                                   id="phone" name="phone" maxlength="20" value="<?= e($value('phone')) ?>">
                            <?php View::partial('field-errors', ['name' => 'phone']) ?>
                        </div>

                        <div class="col-6 col-lg-3">
                            <label for="alternate_phone" class="form-label">Alternate phone</label>
                            <input type="tel" class="form-control<?= $invalid('alternate_phone') ?>"
                                   id="alternate_phone" name="alternate_phone" maxlength="20"
                                   value="<?= e($value('alternate_phone')) ?>">
                            <?php View::partial('field-errors', ['name' => 'alternate_phone']) ?>
                        </div>

                        <div class="col-12 col-lg-3">
                            <label for="address_line1" class="form-label">Address line 1</label>
                            <input type="text" class="form-control<?= $invalid('address_line1') ?>"
                                   id="address_line1" name="address_line1" maxlength="150"
                                   value="<?= e($value('address_line1')) ?>">
                            <?php View::partial('field-errors', ['name' => 'address_line1']) ?>
                        </div>

                        <div class="col-12 col-lg-3">
                            <label for="address_line2" class="form-label">Address line 2</label>
                            <input type="text" class="form-control<?= $invalid('address_line2') ?>"
                                   id="address_line2" name="address_line2" maxlength="150"
                                   value="<?= e($value('address_line2')) ?>">
                            <?php View::partial('field-errors', ['name' => 'address_line2']) ?>
                        </div>

                        <div class="col-6 col-lg-3">
                            <label for="city" class="form-label">City</label>
                            <input type="text" class="form-control<?= $invalid('city') ?>"
                                   id="city" name="city" maxlength="80" value="<?= e($value('city')) ?>">
                            <?php View::partial('field-errors', ['name' => 'city']) ?>
                        </div>

                        <div class="col-6 col-lg-3">
                            <label for="state" class="form-label">State</label>
                            <input type="text" class="form-control<?= $invalid('state') ?>"
                                   id="state" name="state" maxlength="80" value="<?= e($value('state')) ?>">
                            <?php View::partial('field-errors', ['name' => 'state']) ?>
                        </div>

                        <div class="col-6 col-lg-3">
                            <label for="postal_code" class="form-label">Postal code</label>
                            <input type="text" class="form-control<?= $invalid('postal_code') ?>"
                                   id="postal_code" name="postal_code" maxlength="20"
                                   value="<?= e($value('postal_code')) ?>">
                            <?php View::partial('field-errors', ['name' => 'postal_code']) ?>
                        </div>

                        <div class="col-6 col-lg-3">
                            <label for="country" class="form-label">Country</label>
                            <input type="text" class="form-control<?= $invalid('country') ?>"
                                   id="country" name="country" maxlength="80" value="<?= e($value('country')) ?>">
                            <?php View::partial('field-errors', ['name' => 'country']) ?>
                        </div>

                        <div class="col-12 col-lg-4">
                            <label for="emergency_contact_name" class="form-label">Emergency contact</label>
                            <input type="text" class="form-control<?= $invalid('emergency_contact_name') ?>"
                                   id="emergency_contact_name" name="emergency_contact_name" maxlength="120"
                                   value="<?= e($value('emergency_contact_name')) ?>">
                            <?php View::partial('field-errors', ['name' => 'emergency_contact_name']) ?>
                        </div>

                        <div class="col-6 col-lg-4">
                            <label for="emergency_contact_relation" class="form-label">Relationship</label>
                            <input type="text" class="form-control<?= $invalid('emergency_contact_relation') ?>"
                                   id="emergency_contact_relation" name="emergency_contact_relation" maxlength="60"
                                   value="<?= e($value('emergency_contact_relation')) ?>">
                            <?php View::partial('field-errors', ['name' => 'emergency_contact_relation']) ?>
                        </div>

                        <div class="col-6 col-lg-4">
                            <label for="emergency_contact_phone" class="form-label">Emergency phone</label>
                            <input type="tel" class="form-control<?= $invalid('emergency_contact_phone') ?>"
                                   id="emergency_contact_phone" name="emergency_contact_phone" maxlength="20"
                                   value="<?= e($value('emergency_contact_phone')) ?>">
                            <?php View::partial('field-errors', ['name' => 'emergency_contact_phone']) ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-primary" data-busy-label="Saving...">
                        <i class="fa fa-check"></i> Save changes
                    </button>
                    <a href="/people/<?= e($id) ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </div>

    </div>
</form>
