<?php
/**
 * This month in numbers, and the last seven days at a glance.
 *
 * @var array<string, array{available: bool, data: mixed}> $sections
 * @var string   $today
 * @var callable $data
 * @var callable $offline
 * @var callable $rows
 * @var callable $days
 * @var callable $placeholder
 */

// Statuses the week strip has a colour for. Anything else is drawn plain
// rather than trusted into a class name.
$knownStatuses = ['present', 'absent', 'half_day', 'on_leave', 'holiday', 'weekly_off', 'wfh'];
?>

<?php if (isset($sections['attendance_this_month'])): ?>
    <?php if ($offline('attendance_this_month')): ?>
        <div class="mb-4"><?php $placeholder('This month', 'Your monthly totals are not available at the moment.') ?></div>
    <?php else: ?>
        <?php
        $month = $data('attendance_this_month');
        $present = (int) ($month['present'] ?? 0);
        $fromHome = (int) ($month['work_from_home'] ?? 0);
        $workedSeconds = (int) round(((float) ($month['worked_hours'] ?? 0)) * 3600);
        $period = is_array($month['period'] ?? null) ? $month['period'] : [];

        $periodLabel = 'This month';

        if (!empty($period['from']) && !empty($period['to'])) {
            $periodLabel .= ' · ' . date_display((string) $period['from']) . ' to ' . date_display((string) $period['to']);
        }
        ?>
        <div class="section-label"><?= e($periodLabel) ?></div>
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="tile tile-success">
                    <div class="tile-icon"><i class="fa fa-user-check"></i></div>
                    <div class="tile-label">Present</div>
                    <div class="tile-value tabular"><?= e((string) ($present + $fromHome)) ?></div>
                    <div class="tile-hint">
                        <?php if ($fromHome > 0): ?>
                            days, <?= e((string) $fromHome) ?> from home
                        <?php else: ?>
                            days this month
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="tile tile-danger">
                    <div class="tile-icon"><i class="fa fa-user-slash"></i></div>
                    <div class="tile-label">Absent</div>
                    <div class="tile-value tabular"><?= e((string) (int) ($month['absent'] ?? 0)) ?></div>
                    <div class="tile-hint">
                        <?php $halves = (int) ($month['half_days'] ?? 0); ?>
                        <?php if ($halves > 0): ?>
                            days, plus <?= e((string) $halves) ?> half day<?= $halves === 1 ? '' : 's' ?>
                        <?php else: ?>
                            days this month
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="tile tile-info">
                    <div class="tile-icon"><i class="fa fa-umbrella-beach"></i></div>
                    <div class="tile-label">Leave taken</div>
                    <div class="tile-value tabular"><?= e((string) (int) ($month['leave_taken'] ?? 0)) ?></div>
                    <div class="tile-hint">days this month</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="tile">
                    <div class="tile-icon"><i class="fa fa-hourglass-half"></i></div>
                    <div class="tile-label">Hours worked</div>
                    <div class="tile-value tabular"><?= e(hours($workedSeconds)) ?></div>
                    <div class="tile-hint">
                        <?= e($days($month['attendance_rate'] ?? 0)) ?>% attendance
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if (isset($sections['attendance_week'])): ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fa fa-calendar-week"></i> Your last seven days</span>
            <a class="small" href="/attendance/monthly">Full month</a>
        </div>
        <div class="card-body">
            <?php if ($offline('attendance_week')): ?>
                <p class="text-muted small text-center mb-0">
                    <i class="fa fa-plug"></i> The week strip is waiting on the attendance service.
                </p>
            <?php else: ?>
                <?php $week = $rows($data('attendance_week')); ?>
                <?php if ($week === []): ?>
                    <p class="text-muted small text-center mb-0">
                        No attendance has been recorded for you in the last seven days.
                    </p>
                <?php else: ?>
                    <div class="week-strip">
                        <?php foreach ($week as $day): ?>
                            <?php
                            $date = (string) ($day['date'] ?? '');
                            $status = (string) ($day['status'] ?? '');
                            $statusClass = in_array($status, $knownStatuses, true) ? 'status-' . $status : '';
                            $workedHours = (float) ($day['worked_hours'] ?? 0);
                            $timestamp = $date === '' ? false : strtotime($date);
                            ?>
                            <div class="week-day <?= $date === $today ? 'is-today' : '' ?> <?= e($statusClass) ?>"
                                 title="<?= e(date_display($date) . ' — ' . label($status, 'No record')) ?>">
                                <div class="week-day-name"><?= e((string) ($day['weekday'] ?? '')) ?></div>
                                <div class="week-day-date">
                                    <?= e($timestamp === false ? '—' : date('j', $timestamp)) ?>
                                </div>
                                <div class="week-day-hours">
                                    <?php if ($workedHours > 0): ?>
                                        <?= e($days($workedHours)) ?>h
                                    <?php else: ?>
                                        <?= e(label($status, '—')) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
