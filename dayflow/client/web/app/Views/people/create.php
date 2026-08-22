<?php
/**
 * Add a person to the register.
 *
 * The employee code is not asked for: employee-service allocates the next one
 * in the DF-nnnn series under a lock, so two people created at the same moment
 * cannot end up with the same number.
 *
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

$old = static fn (string $key, string $default = ''): string => Flash::old($key, $default);
$invalid = static fn (string $key): string => Flash::hasError($key) ? ' is-invalid' : '';

View::partial('page-header', [
    'title' => 'Add an employee',
    'subtitle' => 'Personal details, how to reach them, and where they sit in the organisation.',
]);
?>

<div class="alert alert-info d-flex gap-2 align-items-start">
    <i class="fa fa-clipboard-list mt-1"></i>
    <div>
        Saving this form creates the person record and, with it, an onboarding checklist built from the
        standard joiner template. The tasks are dated from the joining date and appear on the
        <a href="/onboarding">onboarding page</a> straight away. The employee code is allocated automatically.
    </div>
</div>

<form method="post" action="/people/new" novalidate>
    <?= Csrf::field() ?>

    <div class="row g-3">

        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="fa fa-user"></i> Personal details</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="first_name" class="form-label">First name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control<?= $invalid('first_name') ?>"
                                   id="first_name" name="first_name" maxlength="80" required
                                   value="<?= e($old('first_name')) ?>" autofocus>
                            <?php View::partial('field-errors', ['name' => 'first_name']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="last_name" class="form-label">Last name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control<?= $invalid('last_name') ?>"
                                   id="last_name" name="last_name" maxlength="80" required
                                   value="<?= e($old('last_name')) ?>">
                            <?php View::partial('field-errors', ['name' => 'last_name']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="date_of_birth" class="form-label">Date of birth</label>
                            <input type="date" class="form-control<?= $invalid('date_of_birth') ?>"
                                   id="date_of_birth" name="date_of_birth" value="<?= e($old('date_of_birth')) ?>">
                            <?php View::partial('field-errors', ['name' => 'date_of_birth']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="gender" class="form-label">Gender</label>
                            <select class="form-select<?= $invalid('gender') ?>" id="gender" name="gender">
                                <option value="">Not recorded</option>
                                <?php foreach ($genders as $gender): ?>
                                    <option value="<?= e($gender) ?>" <?= $old('gender') === $gender ? 'selected' : '' ?>>
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
                                    <option value="<?= e($status) ?>" <?= $old('marital_status') === $status ? 'selected' : '' ?>>
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
                                    <option value="<?= e($group) ?>" <?= $old('blood_group') === $group ? 'selected' : '' ?>>
                                        <?= e($group) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'blood_group']) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="fa fa-briefcase"></i> Job details</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="department_id" class="form-label">Department <span class="text-danger">*</span></label>
                            <select class="form-select<?= $invalid('department_id') ?>"
                                    id="department_id" name="department_id" required>
                                <option value="">Choose a department</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= e($department['id'] ?? '') ?>"
                                        <?= $old('department_id') === (string) ($department['id'] ?? '') ? 'selected' : '' ?>>
                                        <?= e($department['name'] ?? 'Unnamed') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'department_id']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="designation_id" class="form-label">Designation <span class="text-danger">*</span></label>
                            <select class="form-select<?= $invalid('designation_id') ?>"
                                    id="designation_id" name="designation_id" required>
                                <option value="">Choose a designation</option>
                                <?php foreach ($designations as $designation): ?>
                                    <option value="<?= e($designation['id'] ?? '') ?>"
                                        <?= $old('designation_id') === (string) ($designation['id'] ?? '') ? 'selected' : '' ?>>
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
                                        <?= $old('location_id') === (string) ($location['id'] ?? '') ? 'selected' : '' ?>>
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
                                        <?= $old('manager_id') === (string) ($candidate['id'] ?? '') ? 'selected' : '' ?>>
                                        <?= e($candidate['full_name'] ?? 'Unnamed') ?>
                                        (<?= e($candidate['employee_code'] ?? '') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Decides who approves their leave and sees their attendance.</div>
                            <?php View::partial('field-errors', ['name' => 'manager_id']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="employment_type" class="form-label">Employment type <span class="text-danger">*</span></label>
                            <select class="form-select<?= $invalid('employment_type') ?>"
                                    id="employment_type" name="employment_type" required>
                                <?php foreach ($employmentTypes as $type): ?>
                                    <option value="<?= e($type) ?>"
                                        <?= $old('employment_type', 'full_time') === $type ? 'selected' : '' ?>>
                                        <?= e(label($type)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'employment_type']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="employment_status" class="form-label">Status on joining</label>
                            <select class="form-select<?= $invalid('employment_status') ?>"
                                    id="employment_status" name="employment_status">
                                <?php foreach (['probation', 'confirmed'] as $status): ?>
                                    <option value="<?= e($status) ?>"
                                        <?= $old('employment_status', 'probation') === $status ? 'selected' : '' ?>>
                                        <?= e(label($status)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'employment_status']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="joined_on" class="form-label">Joined on <span class="text-danger">*</span></label>
                            <input type="date" class="form-control<?= $invalid('joined_on') ?>"
                                   id="joined_on" name="joined_on" required
                                   value="<?= e($old('joined_on', date('Y-m-d'))) ?>"
                                   data-range-start="probation_end_on">
                            <?php View::partial('field-errors', ['name' => 'joined_on']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="probation_end_on" class="form-label">Probation ends</label>
                            <input type="date" class="form-control<?= $invalid('probation_end_on') ?>"
                                   id="probation_end_on" name="probation_end_on"
                                   value="<?= e($old('probation_end_on')) ?>">
                            <div class="form-text">Left empty, six months from the joining date is used.</div>
                            <?php View::partial('field-errors', ['name' => 'probation_end_on']) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header"><i class="fa fa-address-card"></i> Contact details</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6 col-lg-4">
                            <label for="work_email" class="form-label">Work email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control<?= $invalid('work_email') ?>"
                                   id="work_email" name="work_email" maxlength="150" required
                                   value="<?= e($old('work_email')) ?>">
                            <div class="form-text">Must be unique across the company.</div>
                            <?php View::partial('field-errors', ['name' => 'work_email']) ?>
                        </div>

                        <div class="col-12 col-md-6 col-lg-4">
                            <label for="personal_email" class="form-label">Personal email</label>
                            <input type="email" class="form-control<?= $invalid('personal_email') ?>"
                                   id="personal_email" name="personal_email" maxlength="150"
                                   value="<?= e($old('personal_email')) ?>">
                            <?php View::partial('field-errors', ['name' => 'personal_email']) ?>
                        </div>

                        <div class="col-12 col-md-6 col-lg-2">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control<?= $invalid('phone') ?>"
                                   id="phone" name="phone" maxlength="20" value="<?= e($old('phone')) ?>">
                            <?php View::partial('field-errors', ['name' => 'phone']) ?>
                        </div>

                        <div class="col-12 col-md-6 col-lg-2">
                            <label for="alternate_phone" class="form-label">Alternate phone</label>
                            <input type="tel" class="form-control<?= $invalid('alternate_phone') ?>"
                                   id="alternate_phone" name="alternate_phone" maxlength="20"
                                   value="<?= e($old('alternate_phone')) ?>">
                            <?php View::partial('field-errors', ['name' => 'alternate_phone']) ?>
                        </div>

                        <div class="col-12 col-lg-6">
                            <label for="address_line1" class="form-label">Address line 1</label>
                            <input type="text" class="form-control<?= $invalid('address_line1') ?>"
                                   id="address_line1" name="address_line1" maxlength="150"
                                   value="<?= e($old('address_line1')) ?>">
                            <?php View::partial('field-errors', ['name' => 'address_line1']) ?>
                        </div>

                        <div class="col-12 col-lg-6">
                            <label for="address_line2" class="form-label">Address line 2</label>
                            <input type="text" class="form-control<?= $invalid('address_line2') ?>"
                                   id="address_line2" name="address_line2" maxlength="150"
                                   value="<?= e($old('address_line2')) ?>">
                            <?php View::partial('field-errors', ['name' => 'address_line2']) ?>
                        </div>

                        <div class="col-6 col-lg-3">
                            <label for="city" class="form-label">City</label>
                            <input type="text" class="form-control<?= $invalid('city') ?>"
                                   id="city" name="city" maxlength="80" value="<?= e($old('city')) ?>">
                            <?php View::partial('field-errors', ['name' => 'city']) ?>
                        </div>

                        <div class="col-6 col-lg-3">
                            <label for="state" class="form-label">State</label>
                            <input type="text" class="form-control<?= $invalid('state') ?>"
                                   id="state" name="state" maxlength="80" value="<?= e($old('state')) ?>">
                            <?php View::partial('field-errors', ['name' => 'state']) ?>
                        </div>

                        <div class="col-6 col-lg-3">
                            <label for="postal_code" class="form-label">Postal code</label>
                            <input type="text" class="form-control<?= $invalid('postal_code') ?>"
                                   id="postal_code" name="postal_code" maxlength="20"
                                   value="<?= e($old('postal_code')) ?>">
                            <?php View::partial('field-errors', ['name' => 'postal_code']) ?>
                        </div>

                        <div class="col-6 col-lg-3">
                            <label for="country" class="form-label">Country</label>
                            <input type="text" class="form-control<?= $invalid('country') ?>"
                                   id="country" name="country" maxlength="80" value="<?= e($old('country')) ?>">
                            <?php View::partial('field-errors', ['name' => 'country']) ?>
                        </div>

                        <div class="col-12 col-lg-4">
                            <label for="emergency_contact_name" class="form-label">Emergency contact</label>
                            <input type="text" class="form-control<?= $invalid('emergency_contact_name') ?>"
                                   id="emergency_contact_name" name="emergency_contact_name" maxlength="120"
                                   value="<?= e($old('emergency_contact_name')) ?>">
                            <?php View::partial('field-errors', ['name' => 'emergency_contact_name']) ?>
                        </div>

                        <div class="col-6 col-lg-4">
                            <label for="emergency_contact_relation" class="form-label">Relationship</label>
                            <input type="text" class="form-control<?= $invalid('emergency_contact_relation') ?>"
                                   id="emergency_contact_relation" name="emergency_contact_relation" maxlength="60"
                                   value="<?= e($old('emergency_contact_relation')) ?>">
                            <?php View::partial('field-errors', ['name' => 'emergency_contact_relation']) ?>
                        </div>

                        <div class="col-6 col-lg-4">
                            <label for="emergency_contact_phone" class="form-label">Emergency phone</label>
                            <input type="tel" class="form-control<?= $invalid('emergency_contact_phone') ?>"
                                   id="emergency_contact_phone" name="emergency_contact_phone" maxlength="20"
                                   value="<?= e($old('emergency_contact_phone')) ?>">
                            <?php View::partial('field-errors', ['name' => 'emergency_contact_phone']) ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-primary" data-busy-label="Creating...">
                        <i class="fa fa-user-plus"></i> Create employee
                    </button>
                    <a href="/people" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </div>

    </div>
</form>
