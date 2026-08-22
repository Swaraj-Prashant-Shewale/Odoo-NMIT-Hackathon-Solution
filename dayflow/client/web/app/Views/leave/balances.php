<?php
/**
 * The full leave statement: where every day came from and where it went.
 *
 * @var list<array<string, mixed>>  $statements   One row per active leave type.
 * @var array<string, mixed>        $meta
 * @var list<array<string, mixed>>  $adjustments  Manual corrections, newest first.
 * @var array<string, string>       $names
 * @var string                      $subject      Whose statement this is.
 * @var string                      $subjectName
 * @var bool                        $isSelf
 * @var int                         $year
 * @var list<int>                   $years
 * @var bool                        $seesEveryone
 * @var list<array<string, mixed>>  $colleagues
 */

use App\Core\View;

$hex = static fn (mixed $value): string => is_string($value)
    && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1
        ? strtoupper($value)
        : '#64748B';

$days = static fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';

$totalAvailable = (float) ($meta['total_available_days'] ?? 0);
$totalUsed = 0.0;
$totalPending = 0.0;

foreach ($statements as $statement) {
    $totalUsed += (float) ($statement['used_days'] ?? 0);
    $totalPending += (float) ($statement['pending_days'] ?? 0);
}

$chartLabels = [];
$chartValues = [];

foreach ($statements as $statement) {
    $availableDays = (float) ($statement['available_days'] ?? 0);

    if ($availableDays > 0) {
        $chartLabels[] = (string) ($statement['leave_type_name'] ?? 'Leave');
        $chartValues[] = round($availableDays, 2);
    }
}

View::partial('page-header', [
    'title' => 'Leave balances',
    'subtitle' => ($isSelf ? 'Your entitlement' : $subjectName . '\'s entitlement')
        . ' for ' . $year . ', and every movement behind it.',
    'actions' => '<a href="/leave" class="btn btn-outline-secondary btn-sm">'
        . '<i class="fa fa-arrow-left"></i> Back to time off</a>',
]);
?>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" action="/leave-balances" class="row g-3 align-items-end">
            <?php if ($seesEveryone): ?>
                <div class="col-sm-6 col-lg-4">
                    <label for="employee_id" class="form-label">Employee</label>
                    <select class="form-select" id="employee_id" name="employee_id" data-submit-on-change>
                        <option value="">Me</option>
                        <?php foreach ($colleagues as $person): ?>
                            <?php if (!is_array($person) || !isset($person['id'])) {
                                continue;
                            } ?>
                            <option value="<?= e($person['id']) ?>"
                                <?= $subject === (string) $person['id'] ? 'selected' : '' ?>>
                                <?= e($person['full_name'] ?? 'Employee') ?>
                                <?php if (!empty($person['employee_code'])): ?>
                                    &middot; <?= e($person['employee_code']) ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="col-sm-6 col-lg-3">
                <label for="year" class="form-label">Leave year</label>
                <select class="form-select" id="year" name="year" data-submit-on-change>
                    <?php foreach ($years as $option): ?>
                        <option value="<?= e((string) $option) ?>" <?= $year === $option ? 'selected' : '' ?>>
                            <?= e((string) $option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-auto">
                <noscript><button type="submit" class="btn btn-outline-secondary">Show</button></noscript>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="tile tile-success h-100">
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div>
                    <span class="tile-label">Available now</span>
                    <div class="tile-value tabular"><?= e($days($totalAvailable)) ?></div>
                    <div class="tile-hint">days across every type</div>
                </div>
                <span class="tile-icon"><i class="fa fa-piggy-bank"></i></span>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="tile tile-info h-100">
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div>
                    <span class="tile-label">Taken in <?= e((string) $year) ?></span>
                    <div class="tile-value tabular"><?= e($days($totalUsed)) ?></div>
                    <div class="tile-hint">days already approved and spent</div>
                </div>
                <span class="tile-icon"><i class="fa fa-plane-departure"></i></span>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="tile tile-warning h-100">
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div>
                    <span class="tile-label">Held for pending requests</span>
                    <div class="tile-value tabular"><?= e($days($totalPending)) ?></div>
                    <div class="tile-hint">released if a request is turned down</div>
                </div>
                <span class="tile-icon"><i class="fa fa-hourglass-half"></i></span>
            </div>
        </div>
    </div>
</div>

<?php if ($statements === []): ?>
    <div class="card mb-4">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-list-ol',
                'title' => 'Nothing to show for ' . $year,
                'message' => 'No leave type is active for this year, so there is no entitlement to state.',
            ]) ?>
        </div>
    </div>
<?php else: ?>
    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="row g-3">
                <?php foreach ($statements as $statement): ?>
                    <?php
                    $colour = $hex($statement['colour'] ?? null);

                    $opening = (float) ($statement['opening_days'] ?? 0);
                    $accrued = (float) ($statement['accrued_days'] ?? 0);
                    $carried = (float) ($statement['carried_forward_days'] ?? 0);
                    $adjusted = (float) ($statement['adjusted_days'] ?? 0);
                    $used = (float) ($statement['used_days'] ?? 0);
                    $pending = (float) ($statement['pending_days'] ?? 0);
                    $availableDays = (float) ($statement['available_days'] ?? 0);

                    $credited = $opening + $accrued + $carried + $adjusted;
                    $share = $credited > 0.0 ? percent(($used + $pending) / $credited * 100) : 0;
                    ?>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                                <span>
                                    <span class="d-inline-block rounded-circle align-middle me-1"
                                          style="width: 10px; height: 10px; background: <?= e($colour) ?>;"></span>
                                    <strong><?= e($statement['leave_type_name'] ?? 'Leave') ?></strong>
                                </span>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                    <?= e($statement['leave_type_code'] ?? '') ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="stat-row">
                                    <span class="stat-key">Opening</span>
                                    <span class="stat-val tabular"><?= e($days($opening)) ?></span>
                                </div>
                                <div class="stat-row">
                                    <span class="stat-key">Accrued this year</span>
                                    <span class="stat-val tabular"><?= e($days($accrued)) ?></span>
                                </div>
                                <div class="stat-row">
                                    <span class="stat-key">Carried forward</span>
                                    <span class="stat-val tabular"><?= e($days($carried)) ?></span>
                                </div>
                                <div class="stat-row">
                                    <span class="stat-key">Adjusted by HR</span>
                                    <span class="stat-val tabular <?= $adjusted < 0 ? 'text-danger' : ($adjusted > 0 ? 'text-success' : '') ?>">
                                        <?= $adjusted > 0 ? '+' : '' ?><?= e($days($adjusted)) ?>
                                    </span>
                                </div>
                                <div class="stat-row">
                                    <span class="stat-key">Used</span>
                                    <span class="stat-val tabular">&minus;<?= e($days($used)) ?></span>
                                </div>
                                <div class="stat-row">
                                    <span class="stat-key">Held for pending requests</span>
                                    <span class="stat-val tabular">&minus;<?= e($days($pending)) ?></span>
                                </div>
                                <div class="stat-row">
                                    <span class="stat-key fw-semibold">Available</span>
                                    <span class="stat-val tabular fs-6"><?= e($days($availableDays)) ?></span>
                                </div>

                                <div class="progress progress-lg mt-3" role="progressbar"
                                     aria-label="<?= e($statement['leave_type_name'] ?? 'Leave') ?> consumed"
                                     aria-valuenow="<?= e((string) $share) ?>" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar"
                                         style="width: <?= e((string) $share) ?>%; background: <?= e($colour) ?>;"></div>
                                </div>
                                <div class="small text-muted mt-1">
                                    <?= e((string) $share) ?>% of <?= e($days($credited)) ?> credited days committed
                                    <?php if (!empty($statement['last_accrual_period'])): ?>
                                        &middot; accrued to <?= e($statement['last_accrual_period']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header"><strong>What is left, by type</strong></div>
                <div class="card-body">
                    <?php if ($chartValues === []): ?>
                        <p class="text-muted small mb-0">
                            There are no days left on any type, so there is nothing to chart.
                        </p>
                    <?php else: ?>
                        <div data-chart='<?= ejs([
                            'type' => 'donut',
                            'values' => $chartValues,
                            'labels' => $chartLabels,
                            'format' => 'number',
                            'legend' => true,
                            'centreValue' => $days($totalAvailable),
                            'centreLabel' => 'days available',
                        ]) ?>'></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center gap-2">
        <strong>Adjustment history</strong>
        <span class="text-muted small">Manual corrections made by HR in <?= e((string) $year) ?></span>
    </div>

    <?php if ($adjustments === []): ?>
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-balance-scale',
                'title' => 'No corrections this year',
                'message' => 'Every day on this statement came from the policy: opening balance, accrual or carry forward.',
            ]) ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Leave type</th>
                        <th scope="col" class="text-end">Change</th>
                        <th scope="col">Reason</th>
                        <th scope="col">Made by</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($adjustments as $adjustment): ?>
                        <?php
                        if (!is_array($adjustment)) {
                            continue;
                        }

                        $delta = (float) ($adjustment['delta_days'] ?? 0);
                        $typeId = (string) ($adjustment['leave_type_id'] ?? '');

                        $typeName = 'Leave';
                        $typeColour = '#64748B';

                        foreach ($statements as $statement) {
                            if ((string) ($statement['leave_type_id'] ?? '') === $typeId) {
                                $typeName = (string) ($statement['leave_type_name'] ?? 'Leave');
                                $typeColour = $hex($statement['colour'] ?? null);
                                break;
                            }
                        }

                        $author = (string) ($adjustment['adjusted_by'] ?? '');
                        ?>
                        <tr>
                            <td class="small text-muted" title="<?= e(datetime_display($adjustment['created_at'] ?? null)) ?>">
                                <?= e(date_display($adjustment['created_at'] ?? null)) ?>
                            </td>
                            <td>
                                <span class="d-inline-block rounded-circle align-middle me-1"
                                      style="width: 9px; height: 9px; background: <?= e($typeColour) ?>;"></span>
                                <?= e($typeName) ?>
                            </td>
                            <td class="text-end tabular <?= $delta < 0 ? 'text-danger' : 'text-success' ?>">
                                <?= $delta > 0 ? '+' : '' ?><?= e($days($delta)) ?>
                            </td>
                            <td><?= field($adjustment, 'reason', 'No reason recorded') ?></td>
                            <td class="small"><?= e($names[$author] ?? 'HR') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
