<?php
/**
 * Self-service edit of the fields an employee owns.
 *
 * The job and salary fields appear here as text, never as inputs.
 * Employee-service refuses an HR-only field outright rather than dropping it,
 * so an input for one would fail on submit and blame the person for a field
 * they were never allowed to change.
 *
 * @var array<string, mixed> $employee
 * @var list<string>         $bloodGroups
 * @var list<string>         $maritalStatuses
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

/** Remembered input wins, so a rejected form comes back as it was typed. */
$value = static fn (string $key): string => Flash::old($key, (string) ($employee[$key] ?? ''));

$invalid = static fn (string $key): string => Flash::hasError($key) ? ' is-invalid' : '';

View::partial('page-header', [
    'title' => 'Edit my details',
    'subtitle' => 'Contact details, address and emergency contact.',
]);
?>

<form method="post" action="/profile/edit" novalidate>
    <?= Csrf::field() ?>

    <div class="row g-3">

        <div class="col-12 col-lg-7">

            <div class="card mb-3">
                <div class="card-header"><i class="fa fa-address-card"></i> How we reach you</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control<?= $invalid('phone') ?>" id="phone" name="phone"
                                   value="<?= e($value('phone')) ?>" maxlength="20"
                                   placeholder="+91 98765 43210" autocomplete="tel">
                            <?php View::partial('field-errors', ['name' => 'phone']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="alternate_phone" class="form-label">Alternate phone</label>
                            <input type="tel" class="form-control<?= $invalid('alternate_phone') ?>"
                                   id="alternate_phone" name="alternate_phone"
                                   value="<?= e($value('alternate_phone')) ?>" maxlength="20"
                                   placeholder="Optional">
                            <?php View::partial('field-errors', ['name' => 'alternate_phone']) ?>
                        </div>

                        <div class="col-12">
                            <label for="personal_email" class="form-label">Personal email</label>
                            <input type="email" class="form-control<?= $invalid('personal_email') ?>"
                                   id="personal_email" name="personal_email"
                                   value="<?= e($value('personal_email')) ?>" maxlength="150"
                                   placeholder="you@example.com">
                            <div class="form-text">Used to reach you if your work account is unavailable.</div>
                            <?php View::partial('field-errors', ['name' => 'personal_email']) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><i class="fa fa-home"></i> Home address</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="address_line1" class="form-label">Address line 1</label>
                            <input type="text" class="form-control<?= $invalid('address_line1') ?>"
                                   id="address_line1" name="address_line1"
                                   value="<?= e($value('address_line1')) ?>" maxlength="150">
                            <?php View::partial('field-errors', ['name' => 'address_line1']) ?>
                        </div>

                        <div class="col-12">
                            <label for="address_line2" class="form-label">Address line 2</label>
                            <input type="text" class="form-control<?= $invalid('address_line2') ?>"
                                   id="address_line2" name="address_line2"
                                   value="<?= e($value('address_line2')) ?>" maxlength="150">
                            <?php View::partial('field-errors', ['name' => 'address_line2']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="city" class="form-label">City</label>
                            <input type="text" class="form-control<?= $invalid('city') ?>" id="city" name="city"
                                   value="<?= e($value('city')) ?>" maxlength="80">
                            <?php View::partial('field-errors', ['name' => 'city']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="state" class="form-label">State</label>
                            <input type="text" class="form-control<?= $invalid('state') ?>" id="state" name="state"
                                   value="<?= e($value('state')) ?>" maxlength="80">
                            <?php View::partial('field-errors', ['name' => 'state']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="postal_code" class="form-label">Postal code</label>
                            <input type="text" class="form-control<?= $invalid('postal_code') ?>"
                                   id="postal_code" name="postal_code"
                                   value="<?= e($value('postal_code')) ?>" maxlength="20">
                            <?php View::partial('field-errors', ['name' => 'postal_code']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="country" class="form-label">Country</label>
                            <input type="text" class="form-control<?= $invalid('country') ?>"
                                   id="country" name="country"
                                   value="<?= e($value('country')) ?>" maxlength="80">
                            <?php View::partial('field-errors', ['name' => 'country']) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><i class="fa fa-phone"></i> Emergency contact</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="emergency_contact_name" class="form-label">Name</label>
                            <input type="text" class="form-control<?= $invalid('emergency_contact_name') ?>"
                                   id="emergency_contact_name" name="emergency_contact_name"
                                   value="<?= e($value('emergency_contact_name')) ?>" maxlength="120">
                            <?php View::partial('field-errors', ['name' => 'emergency_contact_name']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="emergency_contact_relation" class="form-label">Relationship</label>
                            <input type="text" class="form-control<?= $invalid('emergency_contact_relation') ?>"
                                   id="emergency_contact_relation" name="emergency_contact_relation"
                                   value="<?= e($value('emergency_contact_relation')) ?>" maxlength="60"
                                   placeholder="Spouse, parent, friend">
                            <?php View::partial('field-errors', ['name' => 'emergency_contact_relation']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="emergency_contact_phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control<?= $invalid('emergency_contact_phone') ?>"
                                   id="emergency_contact_phone" name="emergency_contact_phone"
                                   value="<?= e($value('emergency_contact_phone')) ?>" maxlength="20">
                            <?php View::partial('field-errors', ['name' => 'emergency_contact_phone']) ?>
                        </div>
                    </div>
                    <div class="form-text mt-2">
                        Reached only if something happens to you at work. Please keep it current.
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><i class="fa fa-notes-medical"></i> Personal details</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="marital_status" class="form-label">Marital status</label>
                            <select class="form-select<?= $invalid('marital_status') ?>"
                                    id="marital_status" name="marital_status">
                                <option value="">Prefer not to say</option>
                                <?php foreach ($maritalStatuses as $status): ?>
                                    <option value="<?= e($status) ?>"
                                        <?= $value('marital_status') === $status ? 'selected' : '' ?>>
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
                                    <option value="<?= e($group) ?>"
                                        <?= $value('blood_group') === $group ? 'selected' : '' ?>>
                                        <?= e($group) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Only used by first aid and the emergency contact process.</div>
                            <?php View::partial('field-errors', ['name' => 'blood_group']) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" data-busy-label="Saving...">
                    <i class="fa fa-check"></i> Save changes
                </button>
                <a href="/profile" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header"><i class="fa fa-lock"></i> Maintained by HR</div>
                <div class="card-body">
                    <p class="text-muted small">
                        These decide leave entitlement and pay, so they are changed by HR rather than here.
                        If any of them is wrong, raise it with them and it will be corrected at the source.
                    </p>

                    <div class="stat-row">
                        <span class="stat-key">Full name</span>
                        <span class="stat-val"><?= field($employee, 'full_name') ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Employee code</span>
                        <span class="stat-val tabular"><?= field($employee, 'employee_code') ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Work email</span>
                        <span class="stat-val"><?= field($employee, 'work_email') ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Date of birth</span>
                        <span class="stat-val"><?= e(date_display($employee['date_of_birth'] ?? null, 'Not recorded')) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Gender</span>
                        <span class="stat-val"><?= e(label($employee['gender'] ?? null, 'Not recorded')) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Designation</span>
                        <span class="stat-val"><?= field($employee, 'designation_name', 'Not set') ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Department</span>
                        <span class="stat-val"><?= field($employee, 'department_name', 'Not set') ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Location</span>
                        <span class="stat-val"><?= field($employee, 'location_name', 'Not set') ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Manager</span>
                        <span class="stat-val"><?= field($employee, 'manager_name', 'None recorded') ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Employment type</span>
                        <span class="stat-val"><?= e(label($employee['employment_type'] ?? null, 'Not set')) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Status</span>
                        <span class="stat-val"><?= badge($employee['employment_status'] ?? null) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Joined on</span>
                        <span class="stat-val"><?= e(date_display($employee['joined_on'] ?? null)) ?></span>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="/payroll/salary" class="small">Your salary structure is on the payroll page</a>
                </div>
            </div>
        </div>

    </div>
</form>
