<?php
/**
 * The company attendance register: every recorded day for a chosen window.
 *
 * @var list<array<string, mixed>>        $records
 * @var array<string, mixed>              $meta
 * @var array<string, array<string, mixed>> $peopleById Employee id => directory entry.
 * @var list<array<string, mixed>>        $choices     People offered in the employee filter.
 * @var list<array<string, mixed>>        $departments
 * @var array{from: string, to: string, status: string, department_id: string, employee_id: string} $filters
 * @var list<string>                      $statuses
 * @var bool                              $canExport
 * @var bool                              $canViewTeam
 * @var string                            $exportHref
 */

use App\Core\View;

$actions = '<a href="/attendance" class="btn btn-outline-secondary btn-sm">'
    . '<i class="fa fa-clock"></i> My attendance</a>';

// The live board is a separate permission from reading the register, so the
// link only appears for somebody the board would actually open for.
if ($canViewTeam) {
    $actions .= '<a href="/attendance/team" class="btn btn-outline-secondary btn-sm">'
        . '<i class="fa fa-users"></i> Live board</a>';
}

if ($canExport) {
    $actions .= '<a href="' . e($exportHref) . '" class="btn btn-outline-primary btn-sm">'
        . '<i class="fa fa-file-csv"></i> Export CSV</a>';
}
?>

<?php View::partial('page-header', [
    'title' => 'Attendance register',
    'subtitle' => 'Every attendance record between ' . date_display($filters['from']) . ' and ' . date_display($filters['to']) . '.',
    'actions' => $actions,
]) ?>

<div class="card">
    <div class="card-header">
        <form method="get" action="/attendance/register" class="row g-2 align-items-end">
            <div class="col-6 col-md-3 col-xl-2">
                <label for="from" class="form-label mb-0">From</label>
                <input type="date"
                       class="form-control form-control-sm"
                       id="from"
                       name="from"
                       value="<?= e($filters['from']) ?>"
                       data-range-start="to"
                       data-submit-on-change>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="to" class="form-label mb-0">To</label>
                <input type="date"
                       class="form-control form-control-sm"
                       id="to"
                       name="to"
                       value="<?= e($filters['to']) ?>"
                       data-submit-on-change>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="status" class="form-label mb-0">Status</label>
                <select class="form-select form-select-sm" id="status" name="status" data-submit-on-change>
                    <option value="">Every status</option>
                    <?php foreach ($statuses as $option): ?>
                        <option value="<?= e($option) ?>" <?= $filters['status'] === $option ? 'selected' : '' ?>>
                            <?= e(label($option)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="department_id" class="form-label mb-0">Department</label>
                <select class="form-select form-select-sm" id="department_id" name="department_id" data-submit-on-change>
                    <option value="">Every department</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e($department['id'] ?? '') ?>"
                            <?= $filters['department_id'] === (string) ($department['id'] ?? '') ? 'selected' : '' ?>>
                            <?= e($department['name'] ?? 'Department') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <label for="employee_id" class="form-label mb-0">Employee</label>
                <select class="form-select form-select-sm" id="employee_id" name="employee_id" data-submit-on-change>
                    <option value="">Everyone</option>
                    <?php foreach ($choices as $person): ?>
                        <option value="<?= e($person['id'] ?? '') ?>"
                            <?= $filters['employee_id'] === (string) ($person['id'] ?? '') ? 'selected' : '' ?>>
                            <?= e($person['full_name'] ?? ($person['employee_code'] ?? 'Employee')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-xl-1 d-grid">
                <button type="submit" class="btn btn-outline-secondary btn-sm">
                    <i class="fa fa-filter"></i> Apply
                </button>
            </div>
            <div class="col-12">
                <span class="form-text">
                    Choosing a department narrows the people offered above. The register itself is
                    filtered by employee, date and status.
                </span>
            </div>
        </form>
    </div>

    <?php if ($records === []): ?>
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-table',
                'title' => 'No attendance in this window',
                'message' => 'Nothing was recorded between ' . date_display($filters['from']) . ' and ' . date_display($filters['to']) . ' for the filters you chose.',
            ]) ?>
        </div>
    <?php else: ?>
        <div class="card-body pb-0">
            <div class="row g-2 align-items-center">
                <div class="col-12 col-md-6">
                    <label for="registerSearch" class="visually-hidden">Search these records</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="fa fa-search"></i></span>
                        <input type="search"
                               class="form-control"
                               id="registerSearch"
                               placeholder="Search this page by name, code or status"
                               data-filter-table="registerTable">
                    </div>
                </div>
                <div class="col-12 col-md-6 text-md-end small text-muted">
                    <?= e((string) count($records)) ?> record<?= count($records) === 1 ? '' : 's' ?> on this page
                    of <?= e((string) (int) ($meta['total'] ?? count($records))) ?>
                </div>
            </div>
        </div>

        <div class="table-wrap mt-3">
            <table class="table" id="registerTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>In</th>
                        <th>Out</th>
                        <th class="text-end">Hours</th>
                        <th class="text-end">Late</th>
                        <th>Status</th>
                        <th>Corrected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <?php
                        $employeeId = (string) ($record['employee_id'] ?? '');
                        $person = $peopleById[$employeeId] ?? [];
                        $name = (string) ($person['full_name'] ?? '');
                        $name = $name === '' ? (string) ($person['employee_code'] ?? 'Employee ' . substr($employeeId, 0, 8)) : $name;
                        $lateMinutes = (int) ($record['late_minutes'] ?? 0);
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= e(date_display($record['work_date'] ?? null)) ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar avatar-sm"><?= e(initials($name)) ?></span>
                                    <div class="truncate">
                                        <div class="truncate"><?= e($name) ?></div>
                                        <div class="small text-muted"><?= e($person['employee_code'] ?? '—') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-muted cell-truncate"><?= e($person['department_name'] ?? '—') ?></td>
                            <td class="tabular"><?= e(time_display($record['check_in_at'] ?? null)) ?></td>
                            <td class="tabular"><?= e(time_display($record['check_out_at'] ?? null)) ?></td>
                            <td class="text-end tabular"><?= e(hours($record['worked_seconds'] ?? 0)) ?></td>
                            <td class="text-end tabular <?= $lateMinutes > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>">
                                <?= $lateMinutes > 0 ? e($lateMinutes . 'm') : '&mdash;' ?>
                            </td>
                            <td><?= badge($record['status'] ?? null) ?></td>
                            <td>
                                <?php if (!empty($record['is_regularised'])): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                        <i class="fa fa-check"></i> Yes
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="p-3 text-center text-muted small" data-filter-empty style="display: none;">
            Nothing on this page matches that search.
        </div>

        <?php if ((int) ($meta['total_pages'] ?? 1) > 1): ?>
            <div class="card-footer">
                <?php View::partial('pagination', ['meta' => $meta]) ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
