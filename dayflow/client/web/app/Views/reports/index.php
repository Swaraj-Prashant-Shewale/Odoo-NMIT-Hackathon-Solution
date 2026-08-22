<?php
/**
 * The report catalogue.
 *
 * @var array<string, list<array<string, mixed>>> $grouped Reports keyed by their type.
 * @var int                                       $total
 * @var bool                                      $mayExport
 */

use App\Core\View;

$icons = [
    'attendance' => 'fa-clock',
    'leave' => 'fa-umbrella-beach',
    'people' => 'fa-users',
    'payroll' => 'fa-file-invoice-dollar',
    'expense' => 'fa-receipt',
    'learning' => 'fa-graduation-cap',
    'document' => 'fa-folder-open',
    'performance' => 'fa-bullseye',
];
?>

<?php View::partial('page-header', [
    'title' => 'Reports',
    'subtitle' => 'Every figure here is computed live from the service that owns the records, so nothing is a stale snapshot.',
]) ?>

<?php if ($grouped === []): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-chart-pie',
                'title' => 'No reports available to you',
                'message' => 'Each report names the permission that governs it, and none of them matches what your'
                    . ' role carries. Ask an administrator if you need one of them.',
            ]) ?>
        </div>
    </div>
<?php else: ?>

    <p class="small text-muted">
        <?= e((string) $total) ?> report<?= $total === 1 ? '' : 's' ?> you may run.
        <?php if (!$mayExport): ?>
            Reports can be read on screen; taking a copy off the platform needs the export permission, which your
            role does not carry.
        <?php endif; ?>
    </p>

    <?php foreach ($grouped as $type => $reports): ?>
        <div class="section-label mt-4">
            <i class="fa <?= e($icons[$type] ?? 'fa-chart-bar') ?>"></i>
            <?= e(label((string) $type)) ?>
        </div>

        <div class="row g-3">
            <?php foreach ($reports as $report): ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <h2 class="h6 fw-bold mb-1"><?= field($report, 'name') ?></h2>
                            <p class="small text-muted flex-grow-1"><?= field($report, 'description', '') ?></p>

                            <div class="stat-row">
                                <span class="stat-key">Needs</span>
                                <span class="stat-val"><code><?= field($report, 'required_permission', '—') ?></code></span>
                            </div>

                            <div class="d-flex gap-2 mt-3">
                                <a href="/reports/<?= e($report['slug'] ?? '') ?>" class="btn btn-primary btn-sm">
                                    <i class="fa fa-play"></i> Run
                                </a>
                                <?php if ($mayExport && ($report['can_export'] ?? false) === true): ?>
                                    <a href="/reports/<?= e($report['slug'] ?? '') ?>/export?format=csv"
                                       class="btn btn-outline-secondary btn-sm">
                                        <i class="fa fa-file-csv"></i> CSV
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <p class="small text-muted mt-4">
        Running a report is recorded with the filters you used. Exporting one is recorded again in the audit trail,
        with your name and the number of rows taken, because that is the moment personal data leaves the platform.
    </p>

<?php endif; ?>
