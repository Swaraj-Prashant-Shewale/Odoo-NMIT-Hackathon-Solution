<?php
/**
 * One person's record.
 *
 * The same tabbed shape as a person's own profile, with the sections that only
 * make sense to somebody looking after the record: the team below them, the
 * equipment issued to them, their paperwork and how far through onboarding
 * they are. Each of those sections is present only when the viewer holds the
 * permission that makes the answer about this person rather than about
 * themselves.
 *
 * @var array<string, mixed>       $employee
 * @var list<array<string, mixed>> $team
 * @var list<array<string, mixed>> $assets
 * @var list<array<string, mixed>> $documents
 * @var list<array<string, mixed>> $onboarding
 * @var array<string, mixed>       $onboardingProgress
 * @var array<string, mixed>|null  $salary
 * @var list<string>               $exitStatuses
 */

use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;

$id = (string) ($employee['id'] ?? '');
$hasLeft = !empty($employee['exit_date']);

$canEdit = Session::can(Permissions::PROFILE_EDIT_ALL);
$canOffboard = Session::can(Permissions::EMPLOYEE_DEACTIVATE) && !$hasLeft;
$canSeeAssets = Session::can(Permissions::ORG_MANAGE);
$canSeeDocuments = Session::canAny(Permissions::DOCUMENT_VIEW_ALL, Permissions::DOCUMENT_MANAGE);
// The full documents page is registered behind document.view.all alone, so the
// links to it are offered on that permission rather than on the wider pair. A
// link that lands on "access denied" is worse than no link.
$canOpenDocumentList = Session::can(Permissions::DOCUMENT_VIEW_ALL);
$canCompleteTasks = Session::can(Permissions::ONBOARDING_MANAGE);
$canSeeSalary = $salary !== null;

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

$total = (int) ($onboardingProgress['total'] ?? 0);
$completed = (int) ($onboardingProgress['completed'] ?? 0);
$overdue = (int) ($onboardingProgress['overdue'] ?? 0);
$progress = $total > 0 ? percent(($completed / $total) * 100) : 0;

$today = date('Y-m-d');

ob_start(); ?>
<?php if ($canOpenDocumentList): ?>
    <a href="/people/<?= e($id) ?>/documents" class="btn btn-outline-secondary">
        <i class="fa fa-folder-open"></i> Documents
    </a>
<?php endif; ?>
<?php if ($canEdit): ?>
    <a href="/people/<?= e($id) ?>/edit" class="btn btn-primary"><i class="fa fa-pen"></i> Edit record</a>
<?php endif; ?>
<?php if ($canOffboard): ?>
    <a href="#offboard" class="btn btn-outline-danger"><i class="fa fa-user-slash"></i> Offboard</a>
<?php endif; ?>
<?php $actions = (string) ob_get_clean();

View::partial('page-header', [
    'title' => (string) ($employee['full_name'] ?? 'Employee'),
    'subtitle' => trim((string) ($employee['designation_name'] ?? '') . ' · ' . (string) ($employee['department_name'] ?? ''), ' ·'),
    'actions' => $actions,
]);
?>

<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-4">
            <span class="avatar avatar-xl"><?= e(initials($employee['full_name'] ?? '')) ?></span>

            <div class="flex-grow-1">
                <h2 class="h4 mb-1"><?= field($employee, 'full_name', 'Unnamed') ?></h2>
                <div class="text-muted mb-2"><?= field($employee, 'work_email') ?></div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle tabular">
                        <?= field($employee, 'employee_code') ?>
                    </span>
                    <?= badge($employee['employment_status'] ?? null) ?>
                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                        <?= e(label($employee['employment_type'] ?? null, 'Type not set')) ?>
                    </span>
                    <?php if ($hasLeft): ?>
                        <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                            Left <?= e(date_display($employee['exit_date'])) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-4">
                <div>
                    <div class="section-label">Joined</div>
                    <div><?= e(date_display($employee['joined_on'] ?? null)) ?></div>
                </div>
                <div>
                    <div class="section-label">Reports to</div>
                    <div>
                        <?php if (!empty($employee['manager_name']) && $managerHref !== ''): ?>
                            <a href="<?= e($managerHref) ?>"><?= e($employee['manager_name']) ?></a>
                        <?php else: ?>
                            <?= field($employee, 'manager_name', 'No manager') ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="section-label">Team below</div>
                    <div><?= e((string) count($team)) ?> direct <?= count($team) === 1 ? 'report' : 'reports' ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
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
        <button class="nav-link" id="tab-team" data-bs-toggle="tab" data-bs-target="#pane-team"
                type="button" role="tab" aria-controls="pane-team" aria-selected="false">
            <i class="fa fa-users"></i> Team
        </button>
    </li>
    <?php if ($canSeeAssets): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-assets" data-bs-toggle="tab" data-bs-target="#pane-assets"
                    type="button" role="tab" aria-controls="pane-assets" aria-selected="false">
                <i class="fa fa-laptop"></i> Assets
            </button>
        </li>
    <?php endif; ?>
    <?php if ($canSeeDocuments): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-documents" data-bs-toggle="tab" data-bs-target="#pane-documents"
                    type="button" role="tab" aria-controls="pane-documents" aria-selected="false">
                <i class="fa fa-folder-open"></i> Documents
            </button>
        </li>
    <?php endif; ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-onboarding" data-bs-toggle="tab" data-bs-target="#pane-onboarding"
                type="button" role="tab" aria-controls="pane-onboarding" aria-selected="false">
            <i class="fa fa-clipboard-list"></i> Onboarding
        </button>
    </li>
    <?php if ($canSeeSalary): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-salary" data-bs-toggle="tab" data-bs-target="#pane-salary"
                    type="button" role="tab" aria-controls="pane-salary" aria-selected="false">
                <i class="fa fa-wallet"></i> Salary
            </button>
        </li>
    <?php endif; ?>
</ul>

<div class="tab-content">

    <div class="tab-pane fade show active" id="pane-personal" role="tabpanel" aria-labelledby="tab-personal">
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-id-card"></i> Personal details</div>
                    <div class="card-body">
                        <div class="stat-row">
                            <span class="stat-key">Date of birth</span>
                            <span class="stat-val"><?= e(date_display($employee['date_of_birth'] ?? null, 'Not recorded')) ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Gender</span>
                            <span class="stat-val"><?= e(label($employee['gender'] ?? null, 'Not recorded')) ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Marital status</span>
                            <span class="stat-val"><?= e(label($employee['marital_status'] ?? null, 'Not recorded')) ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Blood group</span>
                            <span class="stat-val"><?= field($employee, 'blood_group', 'Not recorded') ?></span>
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
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-address-card"></i> Contact</div>
                    <div class="card-body">
                        <div class="stat-row">
                            <span class="stat-key">Work email</span>
                            <span class="stat-val"><?= field($employee, 'work_email') ?></span>
                        </div>
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
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="pane-job" role="tabpanel" aria-labelledby="tab-job">
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-sitemap"></i> Position</div>
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
                                    <?= field($employee, 'manager_name', 'No manager') ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Employment type</span>
                            <span class="stat-val"><?= e(label($employee['employment_type'] ?? null, 'Not set')) ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Status</span>
                            <span class="stat-val"><?= badge($employee['employment_status'] ?? null) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-calendar-day"></i> Dates</div>
                    <div class="card-body">
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
                        <div class="stat-row">
                            <span class="stat-key">Notice started</span>
                            <span class="stat-val"><?= e(date_display($employee['notice_start_on'] ?? null, '—')) ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Last working day</span>
                            <span class="stat-val"><?= e(date_display($employee['exit_date'] ?? null, 'Still employed')) ?></span>
                        </div>
                        <?php if ($hasLeft): ?>
                            <div class="section-label mt-4">Reason for leaving</div>
                            <p class="small mb-0"><?= field($employee, 'exit_reason', 'Not recorded') ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="pane-team" role="tabpanel" aria-labelledby="tab-team">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-users"></i> Direct reports</span>
                <a href="/org-chart" class="btn btn-sm btn-outline-secondary">
                    <i class="fa fa-sitemap"></i> Whole tree
                </a>
            </div>
            <?php if ($team === []): ?>
                <div class="card-body">
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-users',
                        'title' => 'Nobody reports to this person',
                        'message' => 'Set a manager on someone else\'s record to build the reporting line.',
                    ]) ?>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>Designation</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Joined</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($team as $member): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar avatar-sm"><?= e(initials($member['full_name'] ?? '')) ?></span>
                                        <div>
                                            <a href="/people/<?= e($member['id'] ?? '') ?>">
                                                <?= field($member, 'full_name', 'Unnamed') ?>
                                            </a>
                                            <div class="small text-muted tabular"><?= field($member, 'employee_code') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= field($member, 'designation_name', 'Not set') ?></td>
                                <td><?= field($member, 'department_name', 'Not set') ?></td>
                                <td><?= badge($member['employment_status'] ?? null) ?></td>
                                <td class="tabular"><?= e(date_display($member['joined_on'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canSeeAssets): ?>
        <div class="tab-pane fade" id="pane-assets" role="tabpanel" aria-labelledby="tab-assets">
            <div class="card">
                <div class="card-header"><i class="fa fa-laptop"></i> Company property</div>
                <?php if ($assets === []): ?>
                    <div class="card-body">
                        <?php View::partial('empty-state', [
                            'icon' => 'fa-laptop',
                            'title' => 'Nothing issued',
                            'message' => 'No company asset has been assigned to this person.',
                            'actionLabel' => 'Manage assets',
                            'actionHref' => '/admin/assets',
                        ]) ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table align-middle">
                            <thead>
                            <tr>
                                <th>Tag</th>
                                <th>Asset</th>
                                <th>Serial</th>
                                <th>Issued</th>
                                <th>Returned</th>
                                <th>Condition</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($assets as $asset): ?>
                                <tr>
                                    <td class="tabular"><?= field($asset, 'asset_tag') ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= field($asset, 'name') ?></div>
                                        <div class="small text-muted"><?= e(label($asset['category'] ?? null)) ?></div>
                                    </td>
                                    <td class="tabular"><?= field($asset, 'serial_number') ?></td>
                                    <td class="tabular"><?= e(date_display($asset['assigned_on'] ?? null)) ?></td>
                                    <td class="tabular"><?= e(date_display($asset['returned_on'] ?? null, 'Still held')) ?></td>
                                    <td><?= e(label($asset['condition'] ?? null)) ?></td>
                                    <td><?= badge($asset['status'] ?? null) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer text-muted">
                        Anything still held has to be collected before the record is closed.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canSeeDocuments): ?>
        <div class="tab-pane fade" id="pane-documents" role="tabpanel" aria-labelledby="tab-documents">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fa fa-folder-open"></i> Paperwork</span>
                    <?php if ($canOpenDocumentList): ?>
                        <a href="/people/<?= e($id) ?>/documents" class="btn btn-sm btn-outline-secondary">
                            Full list
                        </a>
                    <?php endif; ?>
                </div>
                <?php if ($documents === []): ?>
                    <div class="card-body">
                        <?php View::partial('empty-state', [
                            'icon' => 'fa-folder-open',
                            'title' => 'No documents on file',
                            'message' => 'Nothing has been uploaded against this record yet.',
                        ]) ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table align-middle">
                            <thead>
                            <tr>
                                <th>Document</th>
                                <th>Category</th>
                                <th>Issued</th>
                                <th>Expires</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (array_slice($documents, 0, 10) as $document): ?>
                                <tr>
                                    <td><?= field($document, 'title', 'Untitled') ?></td>
                                    <td><?= e(label($document['category'] ?? null)) ?></td>
                                    <td class="tabular"><?= e(date_display($document['issued_on'] ?? null)) ?></td>
                                    <td class="tabular"><?= e(date_display($document['expires_on'] ?? null, 'No expiry')) ?></td>
                                    <td><?= badge($document['status'] ?? null) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($documents) > 10): ?>
                        <div class="card-footer text-muted">
                            Showing 10 of <?= e((string) count($documents)) ?>.
                            <?php if ($canOpenDocumentList): ?>
                                <a href="/people/<?= e($id) ?>/documents">See them all</a>.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="tab-pane fade" id="pane-onboarding" role="tabpanel" aria-labelledby="tab-onboarding">
        <div class="card">
            <div class="card-header"><i class="fa fa-clipboard-list"></i> Joiner checklist</div>
            <?php if ($onboarding === []): ?>
                <div class="card-body">
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-clipboard-list',
                        'title' => 'No checklist for this person',
                        'message' => 'A checklist is created automatically when a person record is added.',
                    ]) ?>
                </div>
            <?php else: ?>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-baseline mb-1">
                        <span class="section-label mb-0">Progress</span>
                        <span class="small text-muted">
                            <?= e((string) $completed) ?> of <?= e((string) $total) ?> done
                            <?php if ($overdue > 0): ?>
                                &middot; <span class="text-danger"><?= e((string) $overdue) ?> overdue</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="progress progress-lg mb-4">
                        <div class="progress-bar bg-<?= $overdue > 0 ? 'warning' : 'success' ?>"
                             role="progressbar"
                             style="width: <?= e((string) $progress) ?>%"
                             aria-valuenow="<?= e((string) $progress) ?>"
                             aria-valuemin="0" aria-valuemax="100"></div>
                    </div>

                    <div class="timeline">
                        <?php foreach ($onboarding as $task): ?>
                            <?php
                            $done = (string) ($task['status'] ?? '') === 'completed';
                            $dueOn = (string) ($task['due_on'] ?? '');
                            $isOverdue = !$done && $dueOn !== '' && $dueOn < $today;
                            ?>
                            <div class="timeline-item <?= $done ? 'is-muted' : '' ?>">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <div>
                                        <div class="fw-semibold">
                                            <?= field($task, 'title', 'Untitled task') ?>
                                            <?php if ($done): ?>
                                                <i class="fa fa-check text-success"></i>
                                            <?php elseif ($isOverdue): ?>
                                                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                                                    Overdue
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($task['description'])): ?>
                                            <div class="small text-muted"><?= e($task['description']) ?></div>
                                        <?php endif; ?>
                                        <div class="small text-muted">
                                            <?= e(label($task['category'] ?? null, 'General')) ?>
                                            &middot; owned by <?= e(label($task['owner_role'] ?? null, 'HR')) ?>
                                            &middot; due <?= e(date_display($task['due_on'] ?? null, 'no date')) ?>
                                            <?php if ($done): ?>
                                                &middot; completed <?= e(relative_time($task['completed_at'] ?? null)) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if (!$done && $canCompleteTasks): ?>
                                        <form method="post"
                                              action="/onboarding/<?= e($task['id'] ?? '') ?>/complete"
                                              class="m-0">
                                            <?= Csrf::field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-success"
                                                    data-busy-label="Saving...">
                                                <i class="fa fa-check"></i> Complete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canSeeSalary): ?>
        <div class="tab-pane fade" id="pane-salary" role="tabpanel" aria-labelledby="tab-salary">
            <div class="card">
                <div class="card-header"><i class="fa fa-wallet"></i> Current salary structure</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <div class="tile tile-success">
                                <div class="tile-icon"><i class="fa fa-money-bill-wave"></i></div>
                                <div class="tile-label">Annual cost to company</div>
                                <div class="tile-value tabular"><?= e(money($salary['ctc_annual_minor'] ?? 0)) ?></div>
                                <div class="tile-hint">Since <?= e(date_display($salary['effective_from'] ?? null)) ?></div>
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

                    <?php if (!empty($salary['revision_reason'])): ?>
                        <div class="section-label mt-4">Reason for the latest revision</div>
                        <p class="small mb-0"><?= e($salary['revision_reason']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="card-footer text-muted">
                    Payroll owns these figures. Revisions are recorded there and never overwrite the previous
                    structure, so historical payslips still tie back to what was in force at the time.
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php if ($canOffboard): ?>
    <div class="card mt-4 border-danger-subtle" id="offboard">
        <div class="card-header text-danger"><i class="fa fa-user-slash"></i> Record a departure</div>
        <div class="card-body">
            <p class="text-muted small">
                Recording a leaving date creates the exit checklist and, when the date has already passed,
                closes the record. Somebody leaving in the future is moved to notice period and stays active
                until the day arrives, so they keep working normally in the meantime.
            </p>

            <form method="post" action="/people/<?= e($id) ?>/offboard" novalidate>
                <?= Csrf::field() ?>

                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <label for="exit_date" class="form-label">Last working day</label>
                        <input type="date" class="form-control" id="exit_date" name="exit_date" required
                               min="<?= e((string) ($employee['joined_on'] ?? '')) ?>">
                        <?php View::partial('field-errors', ['name' => 'exit_date']) ?>
                    </div>

                    <div class="col-12 col-md-3">
                        <label for="offboard_status" class="form-label">Type of departure</label>
                        <select class="form-select" id="offboard_status" name="employment_status">
                            <?php foreach ($exitStatuses as $status): ?>
                                <option value="<?= e($status) ?>"><?= e(label($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Only applied once the leaving date has arrived.</div>
                        <?php View::partial('field-errors', ['name' => 'employment_status']) ?>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="exit_reason" class="form-label">Reason</label>
                        <textarea class="form-control" id="exit_reason" name="exit_reason" rows="2"
                                  maxlength="500" required
                                  placeholder="Resigned to join another company, end of contract, and so on"></textarea>
                        <div class="form-text" data-counter-for="exit_reason"></div>
                        <?php View::partial('field-errors', ['name' => 'exit_reason']) ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-danger mt-3"
                        data-confirm="Record this departure? An exit checklist will be created and the record will close on the leaving date."
                        data-busy-label="Recording...">
                    <i class="fa fa-user-slash"></i> Record departure
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>
