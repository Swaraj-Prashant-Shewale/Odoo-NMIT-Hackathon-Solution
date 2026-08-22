<?php
/**
 * Who is away, day by day.
 *
 * The leave service decides whose absences a caller may see, and it strips the
 * reason from everybody else's before the data ever reaches this page. That is
 * why the reason is only rendered when the entry belongs to the person
 * looking: it is simply not present on anyone else's.
 *
 * @var string                                          $month          "YYYY-MM".
 * @var string                                          $monthLabel
 * @var string                                          $previousMonth
 * @var string                                          $nextMonth
 * @var list<array<string, mixed>>                      $cells          One per calendar square.
 * @var array<string, list<array<string, mixed>>>       $entries        Absences keyed by date.
 * @var list<array<string, mixed>>                      $types          The legend.
 * @var array<string, string>                           $names
 * @var string                                          $scope          "organisation" or "team".
 * @var string|null                                     $employeeId
 */

use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;

$canApply = Session::can(Permissions::LEAVE_APPLY);

$hex = static fn (mixed $value): string => is_string($value)
    && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1
        ? strtoupper($value)
        : '#64748B';

/** How many absences fit in a square before the rest are counted instead. */
$visiblePerDay = 3;

$weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

ob_start(); ?>
    <div class="btn-group" role="group" aria-label="Change month">
        <a class="btn btn-outline-secondary btn-sm" href="/leave-calendar?month=<?= e(urlencode($previousMonth)) ?>"
           aria-label="Previous month"><i class="fa fa-chevron-left"></i></a>
        <a class="btn btn-outline-secondary btn-sm" href="/leave-calendar">Today</a>
        <a class="btn btn-outline-secondary btn-sm" href="/leave-calendar?month=<?= e(urlencode($nextMonth)) ?>"
           aria-label="Next month"><i class="fa fa-chevron-right"></i></a>
    </div>
    <form method="get" action="/leave-calendar" class="d-flex gap-2">
        <label class="visually-hidden" for="month">Month</label>
        <input type="month" class="form-control form-control-sm" id="month" name="month"
               value="<?= e($month) ?>" data-submit-on-change style="width: auto;">
        <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Go</button></noscript>
    </form>
    <a href="/leave" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-list"></i> My requests
    </a>
<?php
$actions = (string) ob_get_clean();

View::partial('page-header', [
    'title' => 'Leave calendar · ' . $monthLabel,
    'subtitle' => $scope === 'organisation'
        ? 'Everybody in the company, so you can see the whole picture before you plan.'
        : 'The colleagues you work alongside. Only you can see the reason behind your own entries.',
    'actions' => $actions,
]);
?>

<?php if ($entries === []): ?>
    <div class="card mb-4">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-sun',
                'title' => 'Nobody is away in ' . $monthLabel,
                'message' => 'Every working day this month is fully staffed as far as leave records go.',
                'actionLabel' => $canApply ? 'Apply for time off' : null,
                'actionHref' => $canApply ? '/leave/apply' : null,
            ]) ?>
        </div>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
      <div class="cal-scroll">
        <div class="cal-grid mb-1">
            <?php foreach ($weekdays as $weekday): ?>
                <div class="cal-head"><?= e($weekday) ?></div>
            <?php endforeach; ?>
        </div>

        <div class="cal-grid">
            <?php foreach ($cells as $cell): ?>
                <?php
                $dayEntries = is_array($cell['entries']) ? $cell['entries'] : [];
                $overflow = max(0, count($dayEntries) - $visiblePerDay);
                ?>
                <div class="cal-cell <?= $cell['outside'] ? 'is-outside' : '' ?> <?= $cell['today'] ? 'is-today' : '' ?>"
                    <?= !$cell['outside'] && $cell['weekend'] ? 'style="background: #f7f8fb;"' : '' ?>>

                    <div class="cal-daynum"><?= e($cell['day']) ?></div>

                    <?php foreach (array_slice($dayEntries, 0, $visiblePerDay) as $entry): ?>
                        <?php
                        if (!is_array($entry)) {
                            continue;
                        }

                        $person = (string) ($entry['employee_id'] ?? '');
                        $isSelf = $employeeId !== null && $employeeId === $person;
                        $colour = $hex($entry['colour'] ?? null);

                        $who = $isSelf ? 'You' : ($names[$person] ?? 'A colleague');

                        $tooltip = $who . ' · ' . (string) ($entry['leave_type_name'] ?? 'Leave')
                            . ' · ' . date_display($entry['starts_on'] ?? null)
                            . ' to ' . date_display($entry['ends_on'] ?? null);

                        if (($entry['is_half_day'] ?? false) === true) {
                            $tooltip .= ' · ' . label($entry['half_day_period'] ?? 'half day');
                        }

                        if ((string) ($entry['status'] ?? '') === 'pending') {
                            $tooltip .= ' · awaiting approval';
                        }

                        // Only ever present on the caller's own entries.
                        $entryReason = trim((string) ($entry['reason'] ?? ''));

                        if ($isSelf && $entryReason !== '') {
                            $tooltip .= ' — ' . $entryReason;
                        }
                        ?>
                        <span class="cal-pill"
                              title="<?= e($tooltip) ?>"
                              style="background: <?= e($colour) ?>1A; color: <?= e($colour) ?>; border-left: 3px solid <?= e($colour) ?>;<?= (string) ($entry['status'] ?? '') === 'pending' ? ' opacity: .65;' : '' ?>">
                            <?= e($who) ?>
                        </span>
                    <?php endforeach; ?>

                    <?php if ($overflow > 0): ?>
                        <span class="cal-pill text-muted" style="background: #eef1f6;">
                            +<?= e((string) $overflow) ?> more
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card-footer d-flex flex-wrap gap-3 align-items-center">
        <span class="section-label mb-0">Leave types</span>
        <?php if ($types === []): ?>
            <span class="small text-muted">No leave types have been published yet.</span>
        <?php else: ?>
            <?php foreach ($types as $type): ?>
                <?php if (!is_array($type)) {
                    continue;
                } ?>
                <span class="small d-inline-flex align-items-center gap-1">
                    <span class="d-inline-block rounded-circle"
                          style="width: 9px; height: 9px; background: <?= e($hex($type['colour'] ?? null)) ?>;"></span>
                    <?= e($type['name'] ?? 'Leave') ?>
                </span>
            <?php endforeach; ?>
        <?php endif; ?>
        <span class="small text-muted ms-auto">
            <i class="fa fa-circle-notch"></i> A faded entry is still waiting for approval.
        </span>
    </div>
</div>

<?php if ($entries !== []): ?>
    <div class="card">
        <div class="card-header"><strong>Everyone away this month</strong></div>
        <?php
        // The calendar arrives one row per person per day, which is what the
        // grid needs and what a list must not repeat. Folding it back onto the
        // request gives one line per absence.
        $absences = [];

        foreach ($entries as $onDate => $dayEntries) {
            foreach ($dayEntries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $key = (string) ($entry['request_id'] ?? ($onDate . '|' . ($entry['employee_id'] ?? '')));

                if (!isset($absences[$key])) {
                    $absences[$key] = ['entry' => $entry, 'days' => 0];
                }

                $absences[$key]['days']++;
            }
        }
        ?>
        <div class="table-wrap">
            <table class="table align-middle" id="awayList">
                <thead>
                    <tr>
                        <th scope="col">Who</th>
                        <th scope="col">Leave type</th>
                        <th scope="col">Dates</th>
                        <th scope="col" class="text-end">Days in <?= e($monthLabel) ?></th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($absences as $absence): ?>
                        <?php
                        $entry = $absence['entry'];
                        $person = (string) ($entry['employee_id'] ?? '');
                        $isSelf = $employeeId !== null && $employeeId === $person;
                        $colour = $hex($entry['colour'] ?? null);
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="avatar avatar-sm">
                                        <?= e(initials($isSelf ? 'You' : ($names[$person] ?? '?'))) ?>
                                    </span>
                                    <span class="truncate">
                                        <?= e($isSelf ? 'You' : ($names[$person] ?? 'A colleague')) ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="d-inline-block rounded-circle align-middle me-1"
                                      style="width: 9px; height: 9px; background: <?= e($colour) ?>;"></span>
                                <?= e($entry['leave_type_name'] ?? 'Leave') ?>
                                <?php if (($entry['is_half_day'] ?? false) === true): ?>
                                    <span class="small text-muted">(<?= e(label($entry['half_day_period'] ?? 'half day')) ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted tabular">
                                <?= e(date_display($entry['starts_on'] ?? null)) ?>
                                &ndash; <?= e(date_display($entry['ends_on'] ?? null)) ?>
                            </td>
                            <td class="text-end tabular"><?= e((string) $absence['days']) ?></td>
                            <td><?= badge($entry['status'] ?? null) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
