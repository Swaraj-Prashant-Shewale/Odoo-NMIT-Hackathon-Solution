<?php
/**
 * Workforce insights.
 *
 * Five analyses the analytics service already computed and nothing rendered:
 * attendance, leave, headcount, payroll cost and training. Each is drawn only
 * if the service answered for it, so a section that is missing means either
 * that the caller cannot see it or that the service behind it did not reply —
 * never that a figure was made up to fill the space.
 *
 * @var array<string, array<string, mixed>> $sections
 * @var array<string, array<string, mixed>> $charts
 * @var array<string, string>               $groupings
 * @var string                              $groupBy
 * @var array<string, string>               $range
 * @var string                              $currency
 */

use App\Core\View;

/** @return list<array<string, mixed>> */
$rows = static function (mixed $value): array {
    if (!is_array($value)) {
        return [];
    }

    return array_values(array_filter($value, 'is_array'));
};

$has = static function (string $key) use ($sections): bool {
    return ($sections[$key] ?? []) !== [] && ($sections[$key]['available'] ?? true) !== false;
};

$num = static fn (mixed $value): string => number_format((float) $value, 0, '.', ',');
$dec = static fn (mixed $value, int $places = 1): string => number_format((float) $value, $places, '.', ',');

ob_start(); ?>
<a href="/reports" class="btn btn-outline-secondary"><i class="fa fa-chart-pie"></i> Reports</a>
<?php $actions = ob_get_clean(); ?>

<?php View::partial('page-header', [
    'title' => 'Insights',
    'subtitle' => 'Attendance, time off, headcount, payroll cost and training, computed live by the service that'
        . ' owns each set of records. Nothing on this page is a stored snapshot.',
    'actions' => $actions,
]) ?>

<form method="get" action="/insights" class="card mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="from">From</label>
                <input type="date" class="form-control" id="from" name="from" value="<?= e($range['from']) ?>">
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="to">To</label>
                <input type="date" class="form-control" id="to" name="to" value="<?= e($range['to']) ?>">
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="group_by">Attendance grouping</label>
                <select class="form-select" id="group_by" name="group_by">
                    <?php foreach ($groupings as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $groupBy === $value ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="fa fa-filter"></i> Apply
                </button>
                <a href="/insights" class="btn btn-outline-secondary" title="Clear filters">
                    <i class="fa fa-rotate-right"></i>
                </a>
            </div>
        </div>
    </div>
</form>

<?php if (!$has('attendance') && !$has('leave') && !$has('headcount') && !$has('payroll') && !$has('learning')): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-chart-area',
                'title' => 'No analysis available to you',
                'message' => 'Each analysis names the permission that governs it, and none of them matches what your'
                    . ' role carries.',
            ]) ?>
        </div>
    </div>
<?php endif; ?>

<?php /* ---------------------------------------------------------------- */ ?>
<?php if ($has('attendance')): ?>
    <?php
    $attendance = $sections['attendance'];
    $totals = is_array($attendance['totals'] ?? null) ? $attendance['totals'] : [];
    $series = $rows($attendance['series'] ?? null);
    ?>
    <h2 class="h6 section-label mb-2">Attendance</h2>
    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Attendance rate', $dec($totals['attendance_rate'] ?? 0) . '%', 'fa-chart-line'],
            ['Days present', $num($totals['present'] ?? 0), 'fa-user-check'],
            ['Days absent', $num($totals['absent'] ?? 0), 'fa-user-xmark'],
            ['On leave', $num($totals['on_leave'] ?? 0), 'fa-umbrella-beach'],
            ['Late arrivals', $num($totals['late_arrivals'] ?? 0), 'fa-clock'],
            ['Hours worked', $num($totals['worked_hours'] ?? 0), 'fa-hourglass-half'],
        ] as [$label, $value, $icon]): ?>
            <div class="col-6 col-lg-2">
                <div class="tile h-100">
                    <div class="tile-label"><i class="fa <?= e($icon) ?>"></i> <?= e($label) ?></div>
                    <div class="fs-5 fw-bold tabular"><?= e($value) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mb-4">
        <?php if (isset($charts['attendance_rate'])): ?>
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-chart-line"></i> Attendance rate, <?= e(strtolower($groupings[$groupBy])) ?></div>
                    <div class="card-body"><div data-chart='<?= ejs($charts['attendance_rate']) ?>'></div></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($charts['worked_hours'])): ?>
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-hourglass-half"></i> Hours worked</div>
                    <div class="card-body"><div data-chart='<?= ejs($charts['worked_hours']) ?>'></div></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($series !== []): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-table"></i> <?= e($groupings[$groupBy]) ?></div>
            <div class="table-wrap">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th class="text-end">Present</th>
                            <th class="text-end">Half day</th>
                            <th class="text-end">On leave</th>
                            <th class="text-end">Absent</th>
                            <th class="text-end">Hours</th>
                            <th class="text-end">Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice(array_reverse($series), 0, 40) as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?= e((string) ($row['label'] ?? '')) ?></td>
                                <td class="text-end tabular"><?= e($num($row['present'] ?? 0)) ?></td>
                                <td class="text-end tabular"><?= e($num($row['half_day'] ?? 0)) ?></td>
                                <td class="text-end tabular"><?= e($num($row['on_leave'] ?? 0)) ?></td>
                                <td class="text-end tabular"><?= e($num($row['absent'] ?? 0)) ?></td>
                                <td class="text-end tabular"><?= e($dec($row['worked_hours'] ?? 0)) ?></td>
                                <td class="text-end tabular"><?= e($dec($row['attendance_rate'] ?? 0)) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php /* ---------------------------------------------------------------- */ ?>
<?php if ($has('leave')): ?>
    <?php
    $leave = $sections['leave'];
    $leaveTotals = is_array($leave['totals'] ?? null) ? $leave['totals'] : [];
    $byStatus = is_array($leave['by_status'] ?? null) ? $leave['by_status'] : [];
    ?>
    <h2 class="h6 section-label mb-2">Time off</h2>
    <div class="row g-4 mb-4">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="fa fa-umbrella-beach"></i> Leave taken by type</div>
                <div class="card-body">
                    <?php if (isset($charts['leave_by_type'])): ?>
                        <div data-chart='<?= ejs($charts['leave_by_type']) ?>'></div>
                    <?php else: ?>
                        <p class="text-muted small mb-0">No leave was taken in this window.</p>
                    <?php endif; ?>
                </div>
                <div class="card-footer">
                    <div class="row g-2 text-center">
                        <div class="col-4">
                            <div class="tile-label">Requests</div>
                            <div class="fw-semibold tabular"><?= e($num($leaveTotals['requests'] ?? 0)) ?></div>
                        </div>
                        <div class="col-4">
                            <div class="tile-label">Days approved</div>
                            <div class="fw-semibold tabular"><?= e($dec($leaveTotals['approved_days'] ?? 0)) ?></div>
                        </div>
                        <div class="col-4">
                            <div class="tile-label">Approval rate</div>
                            <div class="fw-semibold tabular"><?= e($dec($leaveTotals['approval_rate'] ?? 0)) ?>%</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><i class="fa fa-chart-column"></i> Days taken by month</div>
                <div class="card-body">
                    <?php if (isset($charts['leave_by_month'])): ?>
                        <div data-chart='<?= ejs($charts['leave_by_month']) ?>'></div>
                    <?php else: ?>
                        <p class="text-muted small mb-0">Nothing to plot for this window.</p>
                    <?php endif; ?>
                </div>
                <?php if ($byStatus !== []): ?>
                    <div class="card-footer d-flex flex-wrap gap-3">
                        <?php foreach ($byStatus as $status => $count): ?>
                            <span class="small">
                                <?= badge((string) $status) ?>
                                <span class="tabular fw-semibold"><?= e($num($count)) ?></span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php /* ---------------------------------------------------------------- */ ?>
<?php if ($has('headcount')): ?>
    <?php
    $headcount = $sections['headcount'];
    $movement = is_array($headcount['movement'] ?? null) ? $headcount['movement'] : [];
    $joiners = $rows($headcount['joiners'] ?? null);
    $leavers = $rows($headcount['leavers'] ?? null);
    $byDepartment = $rows($headcount['by_department'] ?? null);
    ?>
    <h2 class="h6 section-label mb-2">People</h2>
    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Headcount', $num($headcount['headcount'] ?? 0), 'fa-users'],
            ['Joiners', $num($movement['joiners'] ?? 0), 'fa-user-plus'],
            ['Leavers', $num($movement['leavers'] ?? 0), 'fa-user-minus'],
            ['Net change', ((int) ($movement['net_change'] ?? 0) > 0 ? '+' : '') . $num($movement['net_change'] ?? 0), 'fa-arrow-trend-up'],
            ['Attrition', $dec($headcount['attrition_rate'] ?? 0) . '%', 'fa-person-walking-arrow-right'],
            ['Average tenure', $dec($headcount['tenure']['average_years'] ?? 0) . ' yrs', 'fa-hourglass-half'],
        ] as [$label, $value, $icon]): ?>
            <div class="col-6 col-lg-2">
                <div class="tile h-100">
                    <div class="tile-label"><i class="fa <?= e($icon) ?>"></i> <?= e($label) ?></div>
                    <div class="fs-5 fw-bold tabular"><?= e($value) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mb-4">
        <?php if (isset($charts['headcount_trend'])): ?>
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-chart-line"></i> Headcount over the window</div>
                    <div class="card-body"><div data-chart='<?= ejs($charts['headcount_trend']) ?>'></div></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($charts['tenure'])): ?>
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-layer-group"></i> Tenure</div>
                    <div class="card-body"><div data-chart='<?= ejs($charts['tenure']) ?>'></div></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="row g-4 mb-4">
        <?php foreach ([['Joined', $joiners, 'fa-user-plus'], ['Left', $leavers, 'fa-user-minus']] as [$heading, $people, $icon]): ?>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="fa <?= e($icon) ?>"></i> <?= e($heading) ?> in this window</div>
                    <?php if ($people === []): ?>
                        <div class="card-body">
                            <p class="text-muted small mb-0">Nobody <?= e(strtolower($heading)) ?> in this window.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Person</th>
                                        <th>Department</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($people, 0, 12) as $person): ?>
                                        <tr>
                                            <td>
                                                <a href="/people/<?= e((string) ($person['employee_id'] ?? '')) ?>"
                                                   class="fw-semibold"><?= e((string) ($person['name'] ?? 'Unnamed')) ?></a>
                                                <div class="small text-muted"><?= e((string) ($person['designation'] ?? '—')) ?></div>
                                            </td>
                                            <td class="cell-truncate"><?= e((string) ($person['department'] ?? '—')) ?></td>
                                            <td class="text-nowrap"><?= e(date_display($person['date'] ?? null)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($byDepartment !== []): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-sitemap"></i> Headcount by department</div>
            <div class="table-wrap">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Department</th><th class="text-end">People</th><th class="text-end">Share</th></tr></thead>
                    <tbody>
                        <?php $totalPeople = max(1, (int) ($headcount['headcount'] ?? 1)); ?>
                        <?php foreach ($byDepartment as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?= e((string) ($row['label'] ?? 'Unassigned')) ?></td>
                                <td class="text-end tabular"><?= e($num($row['value'] ?? 0)) ?></td>
                                <td class="text-end tabular"><?= e($dec(((int) ($row['value'] ?? 0) / $totalPeople) * 100)) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php /* ---------------------------------------------------------------- */ ?>
<?php if ($has('payroll')): ?>
    <?php
    $payroll = $sections['payroll'];
    $payrollTotals = is_array($payroll['totals'] ?? null) ? $payroll['totals'] : [];
    $byDept = $rows($payroll['by_department'] ?? null);
    ?>
    <h2 class="h6 section-label mb-2">Payroll cost</h2>
    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><i class="fa fa-chart-column"></i> Net payroll by month</div>
                <div class="card-body">
                    <?php if (isset($charts['payroll_cost'])): ?>
                        <div data-chart='<?= ejs($charts['payroll_cost']) ?>'></div>
                    <?php else: ?>
                        <p class="text-muted small mb-0">No payroll run falls inside this window.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="fa fa-sitemap"></i> Cost by department</div>
                <?php if ($byDept === []): ?>
                    <div class="card-body"><p class="text-muted small mb-0">Nothing to break down yet.</p></div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Department</th><th class="text-end">People</th><th class="text-end">Net</th></tr></thead>
                            <tbody>
                                <?php foreach ($byDept as $row): ?>
                                    <tr>
                                        <td class="cell-truncate fw-semibold"><?= e((string) ($row['department'] ?? 'Unassigned')) ?></td>
                                        <td class="text-end tabular"><?= e($num($row['employee_count'] ?? 0)) ?></td>
                                        <td class="text-end tabular text-nowrap"><?= e(money($row['net_minor'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($payrollTotals !== []): ?>
        <div class="row g-3 mb-4">
            <?php foreach ([
                ['Gross', money($payrollTotals['gross_minor'] ?? 0)],
                ['Deductions', money($payrollTotals['deductions_minor'] ?? 0)],
                ['Net paid', money($payrollTotals['net_minor'] ?? 0)],
                ['Runs', $num($payrollTotals['run_count'] ?? 0)],
            ] as [$label, $value]): ?>
                <div class="col-6 col-lg-3">
                    <div class="tile h-100">
                        <div class="tile-label"><?= e($label) ?></div>
                        <div class="fs-5 fw-bold tabular"><?= e($value) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php /* ---------------------------------------------------------------- */ ?>
<?php if ($has('learning')): ?>
    <?php
    $learning = $sections['learning'];
    $learningTotals = is_array($learning['totals'] ?? null) ? $learning['totals'] : [];
    $compliance = is_array($learning['compliance'] ?? null) ? $learning['compliance'] : [];
    ?>
    <h2 class="h6 section-label mb-2">Training</h2>
    <div class="row g-3 mb-4">
        <?php foreach ([
            ['Enrolments', $num($learningTotals['enrolments'] ?? 0), 'fa-graduation-cap'],
            ['Completed', $num($learningTotals['completed'] ?? 0), 'fa-circle-check'],
            ['Overdue', $num($learningTotals['overdue'] ?? 0), 'fa-triangle-exclamation'],
            ['Completion', $dec($learningTotals['completion_rate'] ?? 0) . '%', 'fa-chart-line'],
            ['Mandatory done', $num($compliance['mandatory_completed'] ?? 0) . ' / ' . $num($compliance['mandatory_enrolments'] ?? 0), 'fa-list-check'],
            ['Compliance', $dec($compliance['compliance_rate'] ?? 0) . '%', 'fa-shield-halved'],
        ] as [$label, $value, $icon]): ?>
            <div class="col-6 col-lg-2">
                <div class="tile h-100">
                    <div class="tile-label"><i class="fa <?= e($icon) ?>"></i> <?= e($label) ?></div>
                    <div class="fs-5 fw-bold tabular"><?= e($value) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (isset($charts['course_completion'])): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-chart-column"></i> Completion by course</div>
            <div class="card-body"><div data-chart='<?= ejs($charts['course_completion']) ?>'></div></div>
        </div>
    <?php endif; ?>
<?php endif; ?>
