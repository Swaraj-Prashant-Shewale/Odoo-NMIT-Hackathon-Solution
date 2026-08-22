<?php
/**
 * A calendar month of attendance, with the hours put in each day.
 *
 * @var string                     $month           YYYY-MM.
 * @var string                     $monthLabel
 * @var list<array<string, mixed>> $days
 * @var array<string, mixed>       $summary
 * @var int                        $startsOnWeekday 1 (Monday) to 7 (Sunday).
 * @var string                     $previousMonth
 * @var string                     $nextMonth
 * @var bool                       $nextMonthReached
 * @var array<string, mixed>       $hoursChart
 * @var list<string>               $statuses
 */

use App\Core\View;

$actions = '<a href="/attendance" class="btn btn-outline-secondary btn-sm">'
    . '<i class="fa fa-clock"></i> Back to my attendance</a>';

$weekdayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$leadingBlanks = $startsOnWeekday - 1;
$trailingBlanks = (7 - (($leadingBlanks + count($days)) % 7)) % 7;
?>

<?php View::partial('page-header', [
    'title' => 'Attendance calendar',
    'subtitle' => 'Every day of ' . $monthLabel . ', as the record has it.',
    'actions' => $actions,
]) ?>

<div class="card mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="fa fa-calendar-alt"></i> <?= e($monthLabel) ?></span>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <form method="get" action="/attendance/monthly" class="m-0">
                <label for="month" class="visually-hidden">Month</label>
                <input type="month"
                       class="form-control form-control-sm"
                       id="month"
                       name="month"
                       value="<?= e($month) ?>"
                       data-submit-on-change>
            </form>
            <span class="btn-group btn-group-sm">
                <a class="btn btn-outline-secondary"
                   href="/attendance/monthly?month=<?= e($previousMonth) ?>"
                   aria-label="Previous month">
                    <i class="fa fa-chevron-left"></i>
                </a>
                <a class="btn btn-outline-secondary <?= $nextMonthReached ? '' : 'disabled' ?>"
                   href="/attendance/monthly?month=<?= e($nextMonth) ?>"
                   aria-label="Next month">
                    <i class="fa fa-chevron-right"></i>
                </a>
            </span>
        </div>
    </div>

    <div class="card-body">
        <?php if ($days === []): ?>
            <?php View::partial('empty-state', [
                'icon' => 'fa-calendar-times',
                'title' => 'Nothing to show for ' . $monthLabel,
                'message' => 'No attendance grid was returned for this month.',
            ]) ?>
        <?php else: ?>
            <div class="cal-grid mb-2">
                <?php foreach ($weekdayNames as $name): ?>
                    <div class="cal-head"><?= e($name) ?></div>
                <?php endforeach; ?>
            </div>

            <div class="cal-grid">
                <?php for ($i = 0; $i < $leadingBlanks; $i++): ?>
                    <div class="cal-cell is-outside" aria-hidden="true"></div>
                <?php endfor; ?>

                <?php foreach ($days as $day): ?>
                    <?php
                    $status = (string) ($day['status'] ?? '');
                    $colour = status_colour($status);
                    $isQuiet = !empty($day['is_weekly_off']) || !empty($day['is_holiday']);

                    $classes = 'cal-cell';
                    $classes .= !empty($day['is_today']) ? ' is-today' : '';
                    $classes .= $isQuiet ? ' bg-light' : '';
                    $classes .= !empty($day['is_future']) ? ' text-muted' : '';
                    ?>
                    <div class="<?= e($classes) ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="cal-daynum"><?= e((string) (int) substr((string) ($day['date'] ?? '0000-00-00'), 8, 2)) ?></span>
                            <?php if (!empty($day['is_regularised'])): ?>
                                <i class="fa fa-file-signature text-info" title="Corrected"></i>
                            <?php elseif ((int) ($day['late_minutes'] ?? 0) > 0): ?>
                                <i class="fa fa-exclamation-triangle text-warning"
                                   title="<?= e((string) (int) $day['late_minutes']) ?> minutes late"></i>
                            <?php endif; ?>
                        </div>

                        <?php if ($status !== ''): ?>
                            <span class="cal-pill bg-<?= e($colour) ?>-subtle text-<?= e($colour) ?>-emphasis"
                                  title="<?= e(label($status)) ?><?= !empty($day['holiday']['name']) ? ' — ' . e((string) $day['holiday']['name']) : '' ?>">
                                <?php if (!empty($day['holiday']['name'])): ?>
                                    <?= e((string) $day['holiday']['name']) ?>
                                <?php else: ?>
                                    <?= e(label($status)) ?>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>

                        <?php if ((int) ($day['worked_seconds'] ?? 0) > 0): ?>
                            <span class="cal-pill text-muted tabular"><?= e(hours($day['worked_seconds'])) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php for ($i = 0; $i < $trailingBlanks; $i++): ?>
                    <div class="cal-cell is-outside" aria-hidden="true"></div>
                <?php endfor; ?>
            </div>

            <div class="d-flex flex-wrap gap-3 mt-3 small">
                <span class="section-label mb-0">Legend</span>
                <?php foreach ($statuses as $option): ?>
                    <span class="d-inline-flex align-items-center gap-1">
                        <span class="badge bg-<?= e(status_colour($option)) ?>-subtle text-<?= e(status_colour($option)) ?>-emphasis border border-<?= e(status_colour($option)) ?>-subtle">
                            <?= e(label($option)) ?>
                        </span>
                    </span>
                <?php endforeach; ?>
                <span class="text-muted">
                    <i class="fa fa-file-signature text-info"></i> corrected
                    &middot;
                    <i class="fa fa-exclamation-triangle text-warning"></i> late arrival
                    &middot;
                    a shaded cell is a weekly off or a holiday
                </span>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-chart-bar"></i> <?= e($monthLabel) ?> in numbers</div>
            <div class="card-body">
                <?php if ($summary === []): ?>
                    <p class="text-muted small mb-0">No totals were returned for this month.</p>
                <?php else: ?>
                    <div class="stat-row">
                        <span class="stat-key">Present</span>
                        <span class="stat-val tabular"><?= e((string) (int) ($summary['present_days'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Work from home</span>
                        <span class="stat-val tabular"><?= e((string) (int) ($summary['wfh_days'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Half days</span>
                        <span class="stat-val tabular"><?= e((string) (int) ($summary['half_days'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Absent</span>
                        <span class="stat-val tabular"><?= e((string) (int) ($summary['absent_days'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">On leave</span>
                        <span class="stat-val tabular"><?= e((string) (int) ($summary['leave_days'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Holidays</span>
                        <span class="stat-val tabular"><?= e((string) (int) ($summary['holidays'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Weekly offs</span>
                        <span class="stat-val tabular"><?= e((string) (int) ($summary['weekly_offs'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Payable days</span>
                        <span class="stat-val tabular"><?= e((string) ($summary['payable_days'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Hours worked</span>
                        <span class="stat-val tabular"><?= e(hours($summary['total_worked_seconds'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Overtime</span>
                        <span class="stat-val tabular"><?= e(hours($summary['overtime_seconds'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Late days</span>
                        <span class="stat-val tabular"><?= e((string) (int) ($summary['late_days'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Usual arrival</span>
                        <span class="stat-val tabular"><?= e(time_display($summary['average_check_in'] ?? null)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Usual departure</span>
                        <span class="stat-val tabular"><?= e(time_display($summary['average_check_out'] ?? null)) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-clock"></i> Hours worked, day by day</div>
            <div class="card-body">
                <div data-chart='<?= ejs($hoursChart) ?>'></div>
            </div>
        </div>
    </div>
</div>
