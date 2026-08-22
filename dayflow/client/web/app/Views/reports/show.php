<?php
/**
 * One report: its filter panel, its chart where the shape suits one, and its rows.
 *
 * Both the filters and the table headings are built from what analytics-service
 * describes, so a report added to the catalogue renders correctly without this
 * template being changed.
 *
 * @var array<string, mixed>       $report
 * @var string                     $slug
 * @var list<array<string, mixed>> $columns
 * @var list<array<string, mixed>> $rows
 * @var array<string, mixed>       $summary
 * @var array<string, mixed>       $applied    Filters the service resolved and ran with.
 * @var array<string, string>      $requested  Filters that came from the query string.
 * @var list<string>               $fields     Which filter controls to draw.
 * @var list<array<string, mixed>> $departments
 * @var list<array<string, mixed>> $cycles
 * @var list<string>               $statuses
 * @var array<string, mixed>|null  $chart
 * @var int                        $rowCount
 * @var int                        $durationMs
 * @var string                     $generatedAt
 * @var bool                       $mayExport
 */

use App\Core\View;

$departmentNames = [];

foreach ($departments as $department) {
    $departmentNames[(string) ($department['id'] ?? '')] = (string) ($department['name'] ?? '');
}

$cycleNames = [];

foreach ($cycles as $cycle) {
    $cycleNames[(string) ($cycle['id'] ?? '')] = (string) ($cycle['name'] ?? $cycle['id'] ?? '');
}

/** The value a control should show: what was asked for, else what was run. */
$current = static function (string $key) use ($requested, $applied): string {
    if (isset($requested[$key])) {
        return (string) $requested[$key];
    }

    $value = $applied[$key] ?? '';

    return is_scalar($value) ? (string) $value : '';
};

/**
 * Renders one cell, already escaped.
 *
 * A money column arrives from analytics-service as a decimal amount rather
 * than as minor units, so it is put back into minor units before display.
 * Every amount in this product is formatted by one helper, and this is how
 * that stays true here too.
 */
$cell = static function (array $column, array $row): string {
    $key = (string) ($column['key'] ?? '');
    $value = $row[$key] ?? null;

    if ($value === null || $value === '') {
        return '<span class="text-muted">&mdash;</span>';
    }

    return match ((string) ($column['type'] ?? 'text')) {
        'money' => e(money((int) round(((float) $value) * 100))),
        'date' => e(date_display((string) $value)),
        'number' => e((string) $value),
        default => e((string) $value),
    };
};

$exportQuery = static function (string $slug, array $requested, string $format): string {
    return '/reports/' . rawurlencode($slug) . '/export?' . http_build_query($requested + ['format' => $format]);
};

$exportButtons = '';

if ($mayExport) {
    $exportButtons =
        '<a href="' . e($exportQuery($slug, $requested, 'csv')) . '" class="btn btn-outline-secondary btn-sm">'
        . '<i class="fa fa-file-csv"></i> CSV</a>'
        . '<a href="' . e($exportQuery($slug, $requested, 'pdf')) . '" class="btn btn-outline-secondary btn-sm">'
        . '<i class="fa fa-file-pdf"></i> PDF</a>';
}

$exportButtons .= '<a href="/reports" class="btn btn-outline-secondary btn-sm">'
    . '<i class="fa fa-arrow-left"></i> All reports</a>';
?>

<?php View::partial('page-header', [
    'title' => (string) ($report['name'] ?? 'Report'),
    'subtitle' => (string) ($report['description'] ?? ''),
    'actions' => $exportButtons,
]) ?>

<?php if ($fields !== []): ?>
    <div class="card mb-3">
        <div class="card-body">
            <form method="get" action="/reports/<?= e($slug) ?>" class="row g-2 align-items-end">

                <?php if (in_array('range', $fields, true)): ?>
                    <div class="col-md-3">
                        <label for="from" class="form-label">From</label>
                        <input type="date" class="form-control" id="from" name="from"
                               value="<?= e($current('from')) ?>" data-range-start="to">
                    </div>
                    <div class="col-md-3">
                        <label for="to" class="form-label">To</label>
                        <input type="date" class="form-control" id="to" name="to" value="<?= e($current('to')) ?>">
                    </div>
                <?php endif; ?>

                <?php if (in_array('days', $fields, true)): ?>
                    <div class="col-md-3">
                        <label for="days" class="form-label">Days ahead</label>
                        <input type="number" min="1" max="365" class="form-control" id="days" name="days"
                               value="<?= e($current('days')) ?>">
                    </div>
                <?php endif; ?>

                <?php if (in_array('department', $fields, true)): ?>
                    <div class="col-md-3">
                        <label for="department_id" class="form-label">Department</label>
                        <select class="form-select" id="department_id" name="department_id">
                            <option value="">Every department</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= e($department['id'] ?? '') ?>"
                                    <?= $current('department_id') === (string) ($department['id'] ?? '') ? 'selected' : '' ?>>
                                    <?= e($department['name'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if (in_array('status', $fields, true)): ?>
                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">Any status</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= e($status) ?>" <?= $current('status') === $status ? 'selected' : '' ?>>
                                    <?= e(label($status)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if (in_array('cycle', $fields, true)): ?>
                    <div class="col-md-3">
                        <label for="cycle_id" class="form-label">Review cycle</label>
                        <select class="form-select" id="cycle_id" name="cycle_id">
                            <option value="">Every cycle</option>
                            <?php foreach ($cycles as $cycle): ?>
                                <option value="<?= e($cycle['id'] ?? '') ?>"
                                    <?= $current('cycle_id') === (string) ($cycle['id'] ?? '') ? 'selected' : '' ?>>
                                    <?= e($cycle['name'] ?? $cycle['id'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary" data-busy-label="Running...">
                        <i class="fa fa-play"></i> Run
                    </button>
                    <a href="/reports/<?= e($slug) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3 small text-muted">
    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
        <?= e((string) $rowCount) ?> row<?= $rowCount === 1 ? '' : 's' ?>
    </span>

    <?php foreach ($applied as $key => $value): ?>
        <?php if (!is_scalar($value)) {
            continue;
        } ?>
        <?php
        $shown = (string) $value;

        if ($key === 'department_id') {
            $shown = $departmentNames[$shown] ?? $shown;
        } elseif ($key === 'cycle_id') {
            $shown = $cycleNames[$shown] ?? $shown;
        } elseif (in_array($key, ['from', 'to'], true)) {
            $shown = date_display($shown);
        }
        ?>
        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
            <?= e(label((string) $key)) ?>: <?= e($shown) ?>
        </span>
    <?php endforeach; ?>

    <?php if ($generatedAt !== ''): ?>
        <span>Generated <?= e(datetime_display($generatedAt)) ?> in <?= e((string) $durationMs) ?>ms</span>
    <?php endif; ?>
</div>

<?php if ($chart !== null): ?>
    <div class="card mb-3">
        <div class="card-header"><?= e($chart['title'] ?? 'At a glance') ?></div>
        <div class="card-body">
            <div data-chart='<?= ejs($chart) ?>'></div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <?php if ($rows === [] || $columns === []): ?>
            <div class="p-3">
                <?php View::partial('empty-state', [
                    'icon' => 'fa-table',
                    'title' => 'No rows for those filters',
                    'message' => 'The report ran and found nothing in that window. Widen the dates, or clear the'
                        . ' filters to run it over its own defaults.',
                    'actionLabel' => 'Reset filters',
                    'actionHref' => '/reports/' . $slug,
                ]) ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <?php foreach ($columns as $column): ?>
                                <?php $numeric = in_array((string) ($column['type'] ?? 'text'), ['number', 'money'], true); ?>
                                <th class="<?= $numeric ? 'text-end' : '' ?>"><?= field($column, 'label') ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <?php foreach ($columns as $column): ?>
                                    <?php $numeric = in_array((string) ($column['type'] ?? 'text'), ['number', 'money'], true); ?>
                                    <td class="<?= $numeric ? 'text-end tabular' : '' ?>"><?= $cell($column, $row) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($summary !== []): ?>
        <div class="card-footer">
            <div class="section-label">Summary</div>
            <div class="row g-2">
                <?php foreach ($summary as $key => $value): ?>
                    <?php if (!is_scalar($value)) {
                        continue;
                    } ?>
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-row">
                            <span class="stat-key"><?= e(label((string) $key)) ?></span>
                            <span class="stat-val"><?= e((string) $value) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($mayExport): ?>
    <p class="small text-muted mt-3">
        An export is recorded in the audit trail with your name, the filters above and the number of rows taken.
        A report is capped at 5,000 rows however wide the filters are.
    </p>
<?php endif; ?>
