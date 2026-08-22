<?php
/**
 * What a manager needs before their own day: who is in, what is waiting for a
 * decision, and who is away soon.
 *
 * @var array<string, array{available: bool, data: mixed}> $sections
 * @var array<string, array<string, mixed>> $charts
 * @var list<string> $teamKeys
 * @var callable $data
 * @var callable $offline
 * @var callable $rows
 * @var callable $anyOf
 * @var callable $placeholder
 */

if (!$anyOf($teamKeys)) {
    return;
}
?>

<div class="section-label mt-4">Your team</div>

<?php if (isset($sections['team_today'])): ?>
    <?php if ($offline('team_today')): ?>
        <div class="mb-4"><?php $placeholder('Your team today', 'The attendance service did not answer this time.') ?></div>
    <?php else: ?>
        <?php
        $team = $data('team_today');
        $teamSize = (int) ($team['team_size'] ?? 0);
        $fromHome = (int) ($team['working_from_home'] ?? 0);
        ?>
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-4">
                <div class="tile tile-success">
                    <div class="tile-icon"><i class="fa fa-users"></i></div>
                    <div class="tile-label">In today</div>
                    <div class="tile-value tabular"><?= e((string) (int) ($team['present'] ?? 0)) ?></div>
                    <div class="tile-hint">
                        of <?= e((string) $teamSize) ?>
                        <?php if ($fromHome > 0): ?>
                            · <?= e((string) $fromHome) ?> from home
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-4">
                <div class="tile tile-danger">
                    <div class="tile-icon"><i class="fa fa-user-slash"></i></div>
                    <div class="tile-label">Unaccounted for</div>
                    <div class="tile-value tabular"><?= e((string) (int) ($team['absent'] ?? 0)) ?></div>
                    <div class="tile-hint">no punch and no leave</div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="tile tile-info">
                    <div class="tile-icon"><i class="fa fa-umbrella-beach"></i></div>
                    <div class="tile-label">On leave</div>
                    <div class="tile-value tabular"><?= e((string) (int) ($team['on_leave'] ?? 0)) ?></div>
                    <div class="tile-hint">
                        <?php $halfDays = (int) ($team['half_day'] ?? 0); ?>
                        <?php if ($halfDays > 0): ?>
                            plus <?= e((string) $halfDays) ?> on a half day
                        <?php else: ?>
                            approved and away
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="row g-4 mb-4">

    <?php if (isset($sections['pending_approvals'])): ?>
        <div class="col-lg-4">
            <?php if ($offline('pending_approvals')): ?>
                <?php $placeholder('Waiting on you', 'The approval queues did not answer this time.') ?>
            <?php else: ?>
                <?php
                $approvals = $data('pending_approvals');
                $queues = is_array($approvals['queues'] ?? null) ? $approvals['queues'] : [];
                $totalWaiting = (int) ($approvals['total'] ?? 0);
                ?>
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-check-double"></i> Waiting on you</div>
                    <div class="card-body">
                        <div class="tile-value tabular <?= $totalWaiting > 0 ? 'text-warning' : '' ?>">
                            <?= e((string) $totalWaiting) ?>
                        </div>
                        <div class="tile-hint mb-3">
                            <?= $totalWaiting === 1 ? 'request needs a decision' : 'requests need a decision' ?>
                        </div>
                        <?php foreach ($queues as $queue => $count): ?>
                            <div class="stat-row">
                                <span class="stat-key"><?= e(label((string) $queue)) ?></span>
                                <span class="stat-val tabular"><?= e((string) (int) $count) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="card-footer">
                        <a href="/approvals">Open the approval queue</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($sections['team_leave_calendar'])): ?>
        <div class="col-lg-<?= isset($sections['team_goals']) ? '5' : '8' ?>">
            <?php if ($offline('team_leave_calendar')): ?>
                <?php $placeholder('Away in the next fortnight', 'The leave service did not answer this time.') ?>
            <?php else: ?>
                <?php
                $calendar = $data('team_leave_calendar');
                $allDays = $rows($calendar['days'] ?? null);
                $awayDays = array_values(array_filter(
                    $allDays,
                    static fn (array $day): bool => (int) ($day['away_count'] ?? 0) > 0
                ));
                $shown = array_slice($awayDays, 0, 5);
                ?>
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fa fa-calendar-alt"></i> Away in the next fortnight</span>
                        <a class="small" href="/leave-calendar">Calendar</a>
                    </div>
                    <div class="card-body">
                        <?php if ($shown === []): ?>
                            <p class="text-muted small mb-0 text-center py-3">
                                <i class="fa fa-check-circle"></i>
                                Nobody on your team is booked off in the next fortnight.
                            </p>
                        <?php else: ?>
                            <?php foreach ($shown as $day): ?>
                                <?php $people = $rows($day['people'] ?? null); ?>
                                <div class="stat-row align-items-start gap-2">
                                    <span class="stat-key text-nowrap">
                                        <?= e((string) ($day['weekday'] ?? '')) ?>
                                        <?= e(date_display((string) ($day['date'] ?? ''))) ?>
                                    </span>
                                    <span class="stat-val text-end">
                                        <?php foreach (array_slice($people, 0, 3) as $person): ?>
                                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                                <?= e((string) ($person['employee_name'] ?? 'Team member')) ?>
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (count($people) > 3): ?>
                                            <span class="small text-muted">and <?= e((string) (count($people) - 3)) ?> more</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($awayDays) > count($shown)): ?>
                                <p class="small text-muted mb-0 mt-2">
                                    <?= e((string) (count($awayDays) - count($shown))) ?> further days have somebody away.
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($sections['team_goals'])): ?>
        <div class="col-lg-3">
            <?php if ($offline('team_goals') || !isset($charts['team_goals'])): ?>
                <?php $placeholder('Team goals', 'Goal progress is not available at the moment.') ?>
            <?php else: ?>
                <?php $teamGoals = $data('team_goals'); ?>
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-bullseye"></i> Team goals</div>
                    <div class="card-body">
                        <div data-chart='<?= ejs($charts['team_goals']) ?>'></div>
                        <p class="small text-muted mb-0 mt-2 text-center">
                            <?= e((string) (int) ($teamGoals['achieved'] ?? 0)) ?> of
                            <?= e((string) ((int) ($teamGoals['achieved'] ?? 0) + (int) ($teamGoals['active'] ?? 0))) ?>
                            goals achieved.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>
