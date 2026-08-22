<?php
/**
 * The live board: who is in today, who is not, and who is away.
 *
 * @var string               $date
 * @var bool                 $isHoliday
 * @var array<string, mixed>|null $holiday
 * @var string               $scope     "team" or "organisation".
 * @var int                  $headcount
 * @var array<string, mixed> $summary
 * @var array<string, array{label: string, icon: string, tone: string, people: list<array<string, mixed>>}> $groups
 */

use App\Core\View;

$actions = '<a href="/attendance" class="btn btn-outline-secondary btn-sm">'
    . '<i class="fa fa-clock"></i> My attendance</a>';

$tiles = [
    ['label' => 'On the clock', 'value' => (int) ($summary['checked_in'] ?? 0), 'icon' => 'fa-user-check', 'tone' => 'tile-success'],
    ['label' => 'Done for today', 'value' => (int) ($summary['checked_out'] ?? 0), 'icon' => 'fa-sign-out-alt', 'tone' => 'tile-info'],
    ['label' => 'Not in yet', 'value' => (int) ($summary['not_in'] ?? 0), 'icon' => 'fa-user-clock', 'tone' => 'tile-warning'],
    ['label' => 'On leave', 'value' => (int) ($summary['on_leave'] ?? 0), 'icon' => 'fa-umbrella-beach', 'tone' => 'tile-info'],
    ['label' => 'Arrived late', 'value' => (int) ($summary['late'] ?? 0), 'icon' => 'fa-exclamation-triangle', 'tone' => 'tile-danger'],
    ['label' => 'Headcount', 'value' => $headcount, 'icon' => 'fa-users', 'tone' => ''],
];

$hasAnyone = false;

foreach ($groups as $group) {
    if ($group['people'] !== []) {
        $hasAnyone = true;
        break;
    }
}
?>

<?php View::partial('page-header', [
    'title' => $scope === 'organisation' ? 'Everyone today' : 'My team today',
    'subtitle' => date_display($date) . ' — updated each time this page is opened.',
    'actions' => $actions,
]) ?>

<?php if ($isHoliday): ?>
    <div class="alert alert-info d-flex align-items-center gap-2">
        <i class="fa fa-gift"></i>
        <div>
            The office is closed today<?= $holiday !== null && !empty($holiday['name']) ? ' for ' . e((string) $holiday['name']) : '' ?>.
            Anyone shown as in is working through the holiday.
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach ($tiles as $tile): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="tile <?= e($tile['tone']) ?> h-100">
                <div class="tile-icon"><i class="fa <?= e($tile['icon']) ?>"></i></div>
                <div>
                    <div class="tile-label"><?= e($tile['label']) ?></div>
                    <div class="tile-value tabular"><?= e((string) $tile['value']) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!$hasAnyone): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-users',
                'title' => 'Nobody to show',
                'message' => 'No one reports to you yet, so there is no board to draw.',
            ]) ?>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($groups as $group): ?>
            <?php if ($group['people'] === []) {
                continue;
            } ?>
            <div class="col-12 col-xl-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>
                            <i class="fa <?= e($group['icon']) ?> text-<?= e($group['tone']) ?>"></i>
                            <?= e($group['label']) ?>
                        </span>
                        <span class="badge bg-<?= e($group['tone']) ?>-subtle text-<?= e($group['tone']) ?>-emphasis border border-<?= e($group['tone']) ?>-subtle">
                            <?= e((string) count($group['people'])) ?>
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Person</th>
                                        <th>Status</th>
                                        <th>In</th>
                                        <th class="text-end">Hours so far</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['people'] as $person): ?>
                                        <?php
                                        $name = (string) ($person['full_name'] ?? '');
                                        $name = $name === '' ? (string) ($person['employee_code'] ?? 'Unnamed employee') : $name;
                                        $presence = (string) ($person['presence'] ?? '');
                                        $lateMinutes = (int) ($person['late_minutes'] ?? 0);
                                        $checkInAt = $person['check_in_at'] ?? null;
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="avatar avatar-sm"><?= e(initials($name)) ?></span>
                                                    <div class="truncate">
                                                        <div class="fw-semibold truncate"><?= e($name) ?></div>
                                                        <div class="small text-muted truncate">
                                                            <?= e($person['designation_name'] ?? ($person['department_name'] ?? '—')) ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($presence === 'checked_in'): ?>
                                                    <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">In</span>
                                                <?php elseif ($presence === 'checked_out'): ?>
                                                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                                        Left <?= e(time_display($person['check_out_at'] ?? null, '')) ?>
                                                    </span>
                                                <?php elseif ($presence === 'on_leave'): ?>
                                                    <?= badge('on_leave') ?>
                                                <?php elseif ($presence === 'holiday'): ?>
                                                    <?= badge('holiday') ?>
                                                <?php elseif ($presence === 'weekly_off'): ?>
                                                    <?= badge('weekly_off') ?>
                                                <?php else: ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Not in</span>
                                                <?php endif; ?>

                                                <?php if ($lateMinutes > 0): ?>
                                                    <span class="small text-warning d-block"><?= e((string) $lateMinutes) ?> min late</span>
                                                <?php endif; ?>

                                                <?php if ($presence === 'on_leave' && !empty($person['leave']['leave_type'])): ?>
                                                    <span class="small text-muted d-block truncate"><?= e((string) $person['leave']['leave_type']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="tabular"><?= e(time_display($checkInAt)) ?></td>
                                            <td class="text-end tabular">
                                                <?php if ($presence === 'checked_in' && $checkInAt !== null): ?>
                                                    <span data-elapsed-since="<?= e((string) (strtotime((string) $checkInAt) ?: 0)) ?>">&mdash;</span>
                                                <?php elseif ((int) ($person['worked_seconds'] ?? 0) > 0): ?>
                                                    <?= e(hours($person['worked_seconds'])) ?>
                                                <?php else: ?>
                                                    <span class="text-muted">&mdash;</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
