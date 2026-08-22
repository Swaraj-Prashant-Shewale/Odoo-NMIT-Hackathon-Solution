<?php
/**
 * My attendance: the punch card, this week, and the daily record.
 *
 * @var array<string, mixed>       $today          The /attendance/today snapshot.
 * @var array<string, mixed>       $week           The weekly grid, or [] if it could not be loaded.
 * @var string                     $weekPrevious   Any date inside the previous week.
 * @var string                     $weekNext       Any date inside the following week.
 * @var bool                       $weekNextReached
 * @var list<array<string, mixed>> $records        Daily attendance rows for the filtered range.
 * @var array<string, mixed>       $meta           Pagination block for those rows.
 * @var array<string, mixed>       $summary        Totals for the month the range ends in.
 * @var string                     $month
 * @var string                     $monthLabel
 * @var array<string, string>      $shiftLabels    Shift id => shift name.
 * @var array{from: string, to: string, status: string} $filters
 * @var list<string>               $statuses
 * @var bool                       $canPunch
 * @var bool                       $canRegularise
 * @var bool                       $canViewTeam
 * @var bool                       $canViewAll
 */

use App\Core\Csrf;
use App\Core\View;

$shift = is_array($today['shift'] ?? null) ? $today['shift'] : [];
$checkInAt = $today['check_in_at'] ?? null;
$checkOutAt = $today['check_out_at'] ?? null;
$holiday = is_array($today['holiday'] ?? null) ? $today['holiday'] : null;
$onLeave = is_array($today['on_leave'] ?? null) ? $today['on_leave'] : null;
$days = is_array($week['days'] ?? null) ? $week['days'] : [];
$weekStart = (string) ($week['week_start'] ?? '');

// Paging the week must not throw away the filters on the table below it, and
// changing those filters must not jump the strip back to the current week.
$filterQuery = ['from' => $filters['from'], 'to' => $filters['to']];

if ($filters['status'] !== '') {
    $filterQuery['status'] = $filters['status'];
}

$weekLink = static fn (string $date): string
    => '/attendance?' . http_build_query($filterQuery + ['week_of' => $date]);

$actions = '<a href="/attendance/monthly?month=' . e($month) . '" class="btn btn-outline-secondary btn-sm">'
    . '<i class="fa fa-calendar-alt"></i> Month view</a>'
    . '<a href="/attendance/timesheets" class="btn btn-outline-secondary btn-sm">'
    . '<i class="fa fa-tasks"></i> Timesheets</a>'
    . '<a href="/attendance/holidays" class="btn btn-outline-secondary btn-sm">'
    . '<i class="fa fa-umbrella-beach"></i> Holidays</a>';

if ($canViewTeam) {
    $actions .= '<a href="/attendance/team" class="btn btn-outline-secondary btn-sm">'
        . '<i class="fa fa-users"></i> Team</a>';
}

if ($canViewAll) {
    $actions .= '<a href="/attendance/register" class="btn btn-outline-secondary btn-sm">'
        . '<i class="fa fa-table"></i> Register</a>';
}

if ($canRegularise) {
    $actions .= '<a href="/attendance/regularise" class="btn btn-primary btn-sm">'
        . '<i class="fa fa-edit"></i> Request a correction</a>';
}
?>

<?php View::partial('page-header', [
    'title' => 'My attendance',
    'subtitle' => 'Your clock, your week and every day on the record.',
    'actions' => $actions,
]) ?>

<div class="row g-3 mb-3">

    <!-- The punch card ------------------------------------------------- -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-body text-center d-flex flex-column">

                <div class="punch-date"><?= e(date_display($today['work_date'] ?? null)) ?></div>
                <div class="punch-clock" data-live-clock>&mdash;&mdash;</div>

                <?php if ($shift !== []): ?>
                    <div class="text-muted small mb-2">
                        <i class="fa fa-business-time"></i>
                        <?= e($shift['name'] ?? 'Shift') ?>
                        &middot; <?= e($shift['starts_at'] ?? '') ?>&ndash;<?= e($shift['ends_at'] ?? '') ?>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <?php if (!empty($today['status'])): ?>
                        <?= badge((string) $today['status']) ?>
                    <?php elseif (!empty($today['is_holiday'])): ?>
                        <?= badge('holiday') ?>
                    <?php elseif (empty($today['is_working_day'])): ?>
                        <?= badge('weekly_off') ?>
                    <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Not started</span>
                    <?php endif; ?>

                    <?php if (!empty($today['is_regularised'])): ?>
                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Regularised</span>
                    <?php endif; ?>
                </div>

                <?php if ($holiday !== null): ?>
                    <div class="alert alert-info py-2 px-3 small mb-3">
                        <i class="fa fa-gift"></i>
                        <?= e($holiday['name'] ?? 'Company holiday') ?>
                        <span class="text-muted">&middot; <?= e(label($holiday['type'] ?? null, 'Holiday')) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($onLeave !== null): ?>
                    <div class="alert alert-info py-2 px-3 small mb-3">
                        <i class="fa fa-umbrella-beach"></i>
                        You are on approved leave today
                        <?php if (!empty($onLeave['leave_type'])): ?>
                            <span class="text-muted">&middot; <?= e($onLeave['leave_type']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($today['is_checked_in']) && $checkInAt !== null): ?>
                    <div class="section-label">On the clock</div>
                    <div class="punch-clock text-success mb-3 tabular"
                         data-elapsed-since="<?= e((string) (strtotime((string) $checkInAt) ?: 0)) ?>">
                        <?= e($today['elapsed_label'] ?? '0h 00m') ?>
                    </div>
                <?php endif; ?>

                <div class="text-start mt-auto">
                    <div class="stat-row">
                        <span class="stat-key">Checked in</span>
                        <span class="stat-val tabular"><?= e(time_display($checkInAt)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Checked out</span>
                        <span class="stat-val tabular"><?= e(time_display($checkOutAt)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Worked</span>
                        <span class="stat-val tabular"><?= e(hours($today['worked_seconds'] ?? 0)) ?></span>
                    </div>
                    <?php if ((int) ($today['late_minutes'] ?? 0) > 0): ?>
                        <div class="stat-row">
                            <span class="stat-key">Late by</span>
                            <span class="stat-val text-warning tabular"><?= e((string) (int) $today['late_minutes']) ?> min</span>
                        </div>
                    <?php endif; ?>
                    <?php if ((int) ($today['overtime_seconds'] ?? 0) > 0): ?>
                        <div class="stat-row">
                            <span class="stat-key">Overtime</span>
                            <span class="stat-val text-success tabular"><?= e(hours($today['overtime_seconds'])) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mt-3">
                    <?php if (!$canPunch): ?>
                        <p class="small text-muted mb-0">
                            Your attendance is recorded for you. Ask HR if a day looks wrong.
                        </p>
                    <?php elseif (!empty($today['can_check_out'])): ?>
                        <form method="post" action="/attendance/check-out" class="d-grid">
                            <?= Csrf::field() ?>
                            <button type="submit"
                                    class="btn btn-danger btn-lg"
                                    data-confirm="End your working day now?"
                                    data-busy-label="Checking out...">
                                <i class="fa fa-sign-out-alt"></i> Check out
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="/attendance/check-in" class="d-grid">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn-primary btn-lg" data-busy-label="Checking in...">
                                <i class="fa fa-sign-in-alt"></i>
                                <?= $checkOutAt !== null ? 'Check in again' : 'Check in' ?>
                            </button>
                        </form>
                        <?php if ($checkOutAt !== null): ?>
                            <p class="small text-muted mt-2 mb-0">
                                You closed the day at <?= e(time_display($checkOutAt)) ?>. Checking in again reopens it.
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <!-- This week ------------------------------------------------------- -->
    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>
                    <i class="fa fa-calendar-day"></i>
                    <?php if (!empty($week['week_start'])): ?>
                        <?= e(date_display($week['week_start'])) ?> &ndash; <?= e(date_display($week['week_end'] ?? null)) ?>
                    <?php else: ?>
                        This week
                    <?php endif; ?>
                </span>
                <span class="btn-group btn-group-sm">
                    <a class="btn btn-outline-secondary" href="<?= e($weekLink($weekPrevious)) ?>" aria-label="Previous week">
                        <i class="fa fa-chevron-left"></i>
                    </a>
                    <a class="btn btn-outline-secondary <?= $weekNextReached ? '' : 'disabled' ?>"
                       href="<?= e($weekLink($weekNext)) ?>" aria-label="Next week">
                        <i class="fa fa-chevron-right"></i>
                    </a>
                </span>
            </div>
            <div class="card-body">
                <?php if ($days === []): ?>
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-calendar-times',
                        'title' => 'This week could not be loaded',
                        'message' => 'The attendance service did not answer. Try again in a moment.',
                    ]) ?>
                <?php else: ?>
                    <div class="week-strip">
                        <?php foreach ($days as $day): ?>
                            <?php
                            $status = (string) ($day['status'] ?? '');
                            $classes = 'week-day' . ($status === '' ? '' : ' status-' . $status);
                            $classes .= !empty($day['is_today']) ? ' is-today' : '';
                            ?>
                            <div class="<?= e($classes) ?>">
                                <div class="week-day-name"><?= e(ucfirst((string) ($day['weekday'] ?? ''))) ?></div>
                                <div class="week-day-date"><?= e((string) (int) substr((string) ($day['date'] ?? '0000-00-00'), 8, 2)) ?></div>
                                <div class="week-day-hours">
                                    <?php if ((int) ($day['worked_seconds'] ?? 0) > 0): ?>
                                        <?= e(hours($day['worked_seconds'])) ?>
                                    <?php elseif (!empty($day['is_future']) || $status === ''): ?>
                                        &mdash;
                                    <?php else: ?>
                                        <?= e(label($status)) ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($day['late_minutes'])): ?>
                                    <div class="week-day-hours text-warning">
                                        +<?= e((string) (int) $day['late_minutes']) ?>m late
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php $weekSummary = is_array($week['summary'] ?? null) ? $week['summary'] : []; ?>
                    <?php if ($weekSummary !== []): ?>
                        <div class="row g-2 mt-3 small text-center">
                            <div class="col-6 col-md-3">
                                <div class="section-label mb-1">Hours</div>
                                <div class="fw-semibold tabular"><?= e(hours($weekSummary['total_worked_seconds'] ?? 0)) ?></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="section-label mb-1">Present</div>
                                <div class="fw-semibold tabular"><?= e((string) (int) ($weekSummary['present_days'] ?? 0)) ?></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="section-label mb-1">Late days</div>
                                <div class="fw-semibold tabular"><?= e((string) (int) ($weekSummary['late_days'] ?? 0)) ?></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="section-label mb-1">Overtime</div>
                                <div class="fw-semibold tabular"><?= e(hours($weekSummary['overtime_seconds'] ?? 0)) ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Month summary ------------------------------------------------------- -->
<div class="section-label"><?= e($monthLabel) ?> so far</div>
<div class="row g-3 mb-4">
    <?php
    $tiles = [
        ['label' => 'Present', 'value' => (string) (int) ($summary['present_days'] ?? 0), 'hint' => 'days on the clock', 'icon' => 'fa-user-check', 'tone' => 'tile-success'],
        ['label' => 'Absent', 'value' => (string) (int) ($summary['absent_days'] ?? 0), 'hint' => 'working days missed', 'icon' => 'fa-user-slash', 'tone' => 'tile-danger'],
        ['label' => 'Half days', 'value' => (string) (int) ($summary['half_days'] ?? 0), 'hint' => 'short of a full shift', 'icon' => 'fa-hourglass-half', 'tone' => 'tile-warning'],
        ['label' => 'On leave', 'value' => (string) (int) ($summary['leave_days'] ?? 0), 'hint' => 'approved time off', 'icon' => 'fa-umbrella-beach', 'tone' => 'tile-info'],
        ['label' => 'Total hours', 'value' => hours($summary['total_worked_seconds'] ?? 0), 'hint' => 'worked this month', 'icon' => 'fa-clock', 'tone' => ''],
        ['label' => 'Average day', 'value' => time_display($summary['average_check_in'] ?? null, '—'), 'hint' => 'out at ' . time_display($summary['average_check_out'] ?? null, '—'), 'icon' => 'fa-stopwatch', 'tone' => ''],
    ];
    ?>
    <?php foreach ($tiles as $tile): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="tile <?= e($tile['tone']) ?> h-100">
                <div class="tile-icon"><i class="fa <?= e($tile['icon']) ?>"></i></div>
                <div>
                    <div class="tile-label"><?= e($tile['label']) ?></div>
                    <div class="tile-value tabular"><?= e($tile['value']) ?></div>
                    <div class="tile-hint"><?= e($tile['hint']) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- The daily record ---------------------------------------------------- -->
<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="fa fa-list-ul"></i> Daily record</span>
        <form method="get" action="/attendance" class="d-flex flex-wrap align-items-end gap-2">
            <?php if ($weekStart !== ''): ?>
                <input type="hidden" name="week_of" value="<?= e($weekStart) ?>">
            <?php endif; ?>
            <div>
                <label for="from" class="form-label mb-0">From</label>
                <input type="date"
                       class="form-control form-control-sm"
                       id="from"
                       name="from"
                       value="<?= e($filters['from']) ?>"
                       data-range-start="to"
                       data-submit-on-change>
            </div>
            <div>
                <label for="to" class="form-label mb-0">To</label>
                <input type="date"
                       class="form-control form-control-sm"
                       id="to"
                       name="to"
                       value="<?= e($filters['to']) ?>"
                       data-submit-on-change>
            </div>
            <div>
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
            <button type="submit" class="btn btn-outline-secondary btn-sm">
                <i class="fa fa-filter"></i> Apply
            </button>
        </form>
    </div>

    <?php if ($records === []): ?>
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-calendar-times',
                'title' => 'Nothing recorded in this range',
                'message' => 'No attendance was found between ' . date_display($filters['from']) . ' and ' . date_display($filters['to']) . '.',
                'actionLabel' => $canRegularise ? 'Request a correction' : null,
                'actionHref' => $canRegularise ? '/attendance/regularise' : null,
            ]) ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Shift</th>
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
                        $workDate = (string) ($record['work_date'] ?? '');
                        $shiftId = (string) ($record['shift_id'] ?? '');
                        $lateMinutes = (int) ($record['late_minutes'] ?? 0);
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= e(date_display($workDate)) ?></td>
                            <td class="text-muted"><?= e($workDate === '' ? '—' : date('D', (int) strtotime($workDate))) ?></td>
                            <td class="text-muted cell-truncate"><?= e($shiftLabels[$shiftId] ?? '—') ?></td>
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
        <?php if ((int) ($meta['total_pages'] ?? 1) > 1): ?>
            <div class="card-footer">
                <?php View::partial('pagination', ['meta' => $meta]) ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
