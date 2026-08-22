<?php
/**
 * The signed-in person's own record.
 *
 * The page is organised around one distinction: the details somebody keeps up
 * to date themselves, and the details HR maintains. The second group decides
 * pay and entitlement, so it is shown as text rather than offered as inputs
 * that the service would refuse.
 *
 * @var array<string, mixed>      $employee
 * @var array<string, mixed>|null $salary
 * @var int                       $documentCount
 * @var int                       $expiringCount
 * @var list<string>              $selfEditable
 */

use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;

$managerId = (string) ($employee['manager_id'] ?? '');
$managerIsMe = $managerId !== '' && $managerId === (string) Session::employeeId();
$managerHref = $managerIsMe
    ? '/profile'
    : (Session::can(Permissions::PROFILE_VIEW_ALL) ? '/people/' . rawurlencode($managerId) : '');

$address = array_values(array_filter([
    $employee['address_line1'] ?? null,
    $employee['address_line2'] ?? null,
    $employee['city'] ?? null,
    $employee['state'] ?? null,
    $employee['postal_code'] ?? null,
    $employee['country'] ?? null,
], static fn (mixed $part): bool => is_string($part) && trim($part) !== ''));

ob_start(); ?>
<a href="/profile/edit" class="btn btn-primary"><i class="fa fa-pen"></i> Edit my details</a>
<a href="/profile/security" class="btn btn-outline-secondary"><i class="fa fa-shield-alt"></i> Security</a>
<?php $actions = (string) ob_get_clean();

View::partial('page-header', [
    'title' => 'My profile',
    'subtitle' => 'Your record as the company holds it.',
    'actions' => $actions,
]);
?>

<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-4">
            <span class="avatar avatar-xl"><?= e(initials($employee['full_name'] ?? '')) ?></span>

            <div class="flex-grow-1">
                <h2 class="h4 mb-1"><?= field($employee, 'full_name', 'Your name') ?></h2>
                <div class="text-muted mb-2">
                    <?= field($employee, 'designation_name', 'Designation not set') ?>
                    &middot;
                    <?= field($employee, 'department_name', 'Department not set') ?>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle tabular">
                        <?= field($employee, 'employee_code') ?>
                    </span>
                    <?= badge($employee['employment_status'] ?? null) ?>
                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                        <?= e(label($employee['employment_type'] ?? null, 'Employment type not set')) ?>
                    </span>
                </div>
            </div>

            <div class="text-lg-end">
                <div class="section-label">Work email</div>
                <div class="mb-2"><?= field($employee, 'work_email') ?></div>
                <div class="section-label">Joined</div>
                <div><?= e(date_display($employee['joined_on'] ?? null)) ?></div>
            </div>
        </div>
    </div>

    <div class="card-footer">
        <form method="post" action="/profile/photo" enctype="multipart/form-data"
              class="row g-2 align-items-end">
            <?= Csrf::field() ?>

            <div class="col-12 col-md-6">
                <label for="photo" class="form-label">
                    <?= empty($employee['photo_document_id']) ? 'Add a photograph' : 'Replace your photograph' ?>
                </label>
                <input type="file" class="form-control" id="photo" name="photo"
                       accept="image/jpeg,image/png" required>
                <div class="form-text">
                    JPG or PNG, up to 5&nbsp;MB. The file is checked before it is stored, and only you
                    and HR can replace it.
                    <?= empty($employee['photo_document_id'])
                        ? 'You have no photograph on file, so your initials are shown instead.'
                        : 'A photograph is on file.' ?>
                </div>
            </div>

            <div class="col-12 col-md-3">
                <button type="submit" class="btn btn-outline-primary w-100" data-busy-label="Uploading...">
                    <i class="fa fa-camera"></i> Upload
                </button>
            </div>
        </form>
    </div>
</div>

<ul class="nav nav-tabs mb-3" id="profileTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-personal" data-bs-toggle="tab" data-bs-target="#pane-personal"
                type="button" role="tab" aria-controls="pane-personal" aria-selected="true">
            <i class="fa fa-user"></i> Personal
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-job" data-bs-toggle="tab" data-bs-target="#pane-job"
                type="button" role="tab" aria-controls="pane-job" aria-selected="false">
            <i class="fa fa-briefcase"></i> Job
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-salary" data-bs-toggle="tab" data-bs-target="#pane-salary"
                type="button" role="tab" aria-controls="pane-salary" aria-selected="false">
            <i class="fa fa-wallet"></i> Salary
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-documents" data-bs-toggle="tab" data-bs-target="#pane-documents"
                type="button" role="tab" aria-controls="pane-documents" aria-selected="false">
            <i class="fa fa-folder-open"></i> Documents
        </button>
    </li>
</ul>

<div class="tab-content">

    <div class="tab-pane fade show active" id="pane-personal" role="tabpanel" aria-labelledby="tab-personal">
        <div class="row g-3">

            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fa fa-pen"></i> Yours to keep up to date</span>
                        <a href="/profile/edit" class="btn btn-sm btn-outline-primary">Edit</a>
                    </div>
                    <div class="card-body">
                        <div class="stat-row">
                            <span class="stat-key">Personal email</span>
                            <span class="stat-val"><?= field($employee, 'personal_email', 'Not given') ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Phone</span>
                            <span class="stat-val"><?= field($employee, 'phone', 'Not given') ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Alternate phone</span>
                            <span class="stat-val"><?= field($employee, 'alternate_phone', 'Not given') ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Marital status</span>
                            <span class="stat-val"><?= e(label($employee['marital_status'] ?? null, 'Not given')) ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Blood group</span>
                            <span class="stat-val"><?= field($employee, 'blood_group', 'Not given') ?></span>
                        </div>

                        <div class="section-label mt-4">Home address</div>
                        <?php if ($address === []): ?>
                            <p class="text-muted small mb-0">No address on file.</p>
                        <?php else: ?>
                            <address class="mb-0 small">
                                <?php foreach ($address as $line): ?>
                                    <?= e($line) ?><br>
                                <?php endforeach; ?>
                            </address>
                        <?php endif; ?>

                        <div class="section-label mt-4">Emergency contact</div>
                        <div class="stat-row">
                            <span class="stat-key">Name</span>
                            <span class="stat-val"><?= field($employee, 'emergency_contact_name', 'Not given') ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Relationship</span>
                            <span class="stat-val"><?= field($employee, 'emergency_contact_relation', 'Not given') ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Phone</span>
                            <span class="stat-val"><?= field($employee, 'emergency_contact_phone', 'Not given') ?></span>
                        </div>
                    </div>
                    <div class="card-footer text-muted">
                        <?= e(count($selfEditable)) ?> fields on this card are yours to change at any time.
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-lock"></i> Held by HR</div>
                    <div class="card-body">
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
                    </div>
                    <div class="card-footer text-muted">
                        These are part of your employment record. Ask HR if any of them needs correcting.
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="tab-pane fade" id="pane-job" role="tabpanel" aria-labelledby="tab-job">
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-sitemap"></i> Where you sit</div>
                    <div class="card-body">
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
                            <span class="stat-val">
                                <?php if (!empty($employee['manager_name']) && $managerHref !== ''): ?>
                                    <a href="<?= e($managerHref) ?>"><?= e($employee['manager_name']) ?></a>
                                <?php else: ?>
                                    <?= field($employee, 'manager_name', 'No manager recorded') ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Organisation chart</span>
                            <span class="stat-val"><a href="/org-chart">See the reporting tree</a></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-calendar-check"></i> Your employment</div>
                    <div class="card-body">
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
                        <div class="stat-row">
                            <span class="stat-key">Probation ends</span>
                            <span class="stat-val"><?= e(date_display($employee['probation_end_on'] ?? null, 'Not applicable')) ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Confirmed on</span>
                            <span class="stat-val"><?= e(date_display($employee['confirmed_on'] ?? null, 'Not yet confirmed')) ?></span>
                        </div>
                        <?php if (!empty($employee['exit_date'])): ?>
                            <div class="stat-row">
                                <span class="stat-key">Last working day</span>
                                <span class="stat-val"><?= e(date_display($employee['exit_date'])) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer text-muted">
                        HR maintains everything on this tab. It decides leave entitlement and pay, so it is
                        not editable here.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="pane-salary" role="tabpanel" aria-labelledby="tab-salary">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-wallet"></i> Current salary structure</span>
                <a href="/payroll/salary" class="btn btn-sm btn-outline-primary">Full breakdown</a>
            </div>
            <div class="card-body">
                <?php if ($salary === null): ?>
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-wallet',
                        'title' => 'No salary structure recorded yet',
                        'message' => 'Payroll will add one before your first pay run. Nothing is missing on your side.',
                    ]) ?>
                <?php else: ?>
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <div class="tile tile-success">
                                <div class="tile-icon"><i class="fa fa-money-bill-wave"></i></div>
                                <div class="tile-label">Annual cost to company</div>
                                <div class="tile-value tabular"><?= e(money($salary['ctc_annual_minor'] ?? 0)) ?></div>
                                <div class="tile-hint">In force since <?= e(date_display($salary['effective_from'] ?? null)) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="tile tile-info">
                                <div class="tile-icon"><i class="fa fa-calendar-day"></i></div>
                                <div class="tile-label">Monthly gross</div>
                                <div class="tile-value tabular"><?= e(money($salary['gross_monthly_minor'] ?? 0)) ?></div>
                                <div class="tile-hint">Before deductions</div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="tile">
                                <div class="tile-icon"><i class="fa fa-layer-group"></i></div>
                                <div class="tile-label">Monthly basic</div>
                                <div class="tile-value tabular"><?= e(money($salary['basic_monthly_minor'] ?? 0)) ?></div>
                                <div class="tile-hint"><?= field($salary, 'currency', 'INR') ?></div>
                            </div>
                        </div>
                    </div>

                    <p class="text-muted small mt-3 mb-0">
                        A read-only summary. Every component, every deduction and the history of revisions are
                        on the <a href="/payroll/salary">salary page</a>. Payroll owns these figures; nothing on
                        this page can change them.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><i class="fa fa-university"></i> Where your salary is paid</div>
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <p class="mb-0 text-muted small">
                    Your bank details are stored encrypted and are only ever shown as the last four digits.
                </p>
                <a href="/profile/bank" class="btn btn-outline-primary btn-sm">
                    <i class="fa fa-university"></i> Bank details
                </a>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="pane-documents" role="tabpanel" aria-labelledby="tab-documents">
        <div class="card">
            <div class="card-header"><i class="fa fa-folder-open"></i> Your paperwork</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="tile">
                            <div class="tile-icon"><i class="fa fa-file-alt"></i></div>
                            <div class="tile-label">On file</div>
                            <div class="tile-value tabular"><?= e((string) $documentCount) ?></div>
                            <div class="tile-hint">Identity, education, contracts and more</div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="tile <?= $expiringCount > 0 ? 'tile-warning' : '' ?>">
                            <div class="tile-icon"><i class="fa fa-hourglass-half"></i></div>
                            <div class="tile-label">Expiring within 30 days</div>
                            <div class="tile-value tabular"><?= e((string) $expiringCount) ?></div>
                            <div class="tile-hint">
                                <?= $expiringCount > 0 ? 'Please upload a replacement' : 'Nothing needs attention' ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <a href="/profile/documents" class="btn btn-primary">
                        <i class="fa fa-folder-open"></i> Open my documents
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>
