<?php
/**
 * The signed-in person's own salary structure and how it got here.
 *
 * @var array<string, mixed>|null  $structure   The structure in force, if any.
 * @var list<array<string, mixed>> $components  Priced structure lines.
 * @var list<array{structure: array<string, mixed>, previous: array<string, mixed>|null}> $revisions
 * @var array<string, mixed>       $earningsChart
 */

use App\Core\View;

$groups = [
    'earning' => ['label' => 'Earnings', 'icon' => 'fa-plus-circle', 'lines' => []],
    'deduction' => ['label' => 'Deductions', 'icon' => 'fa-minus-circle', 'lines' => []],
    'employer_contribution' => ['label' => 'Employer contributions', 'icon' => 'fa-building', 'lines' => []],
];

foreach ($components as $line) {
    if (isset($groups[$line['component_type']])) {
        $groups[$line['component_type']]['lines'][] = $line;
    }
}
?>

<?php View::partial('page-header', [
    'title' => 'Salary structure',
    'subtitle' => 'What you are contracted to be paid, component by component.',
    'actions' => '<a href="/payroll" class="btn btn-outline-secondary">'
        . '<i class="fa fa-arrow-left"></i> Back to payroll</a>',
]) ?>

<?php if ($structure === null): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-sitemap',
                'title' => 'No salary structure on record',
                'message' => 'Your structure is recorded by finance as part of onboarding. It will appear here as soon as it is.',
            ]) ?>
        </div>
    </div>
<?php else: ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="tile tile-success">
                <div class="tile-icon"><i class="fa fa-briefcase"></i></div>
                <div class="tile-label">Annual cost to company</div>
                <div class="tile-value tabular"><?= e(money($structure['ctc_annual_minor'] ?? 0)) ?></div>
                <div class="tile-hint">
                    Effective from <?= e(date_display($structure['effective_from'] ?? null)) ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="tile">
                <div class="tile-icon"><i class="fa fa-calendar-day"></i></div>
                <div class="tile-label">Monthly gross</div>
                <div class="tile-value tabular"><?= e(money($structure['gross_monthly_minor'] ?? 0)) ?></div>
                <div class="tile-hint">Before deductions</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="tile tile-info">
                <div class="tile-icon"><i class="fa fa-cube"></i></div>
                <div class="tile-label">Monthly basic</div>
                <div class="tile-value tabular"><?= e(money($structure['basic_monthly_minor'] ?? 0)) ?></div>
                <div class="tile-hint">Percentage components are worked out from this</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fa fa-list-ul"></i> Components</span>
                    <span class="small text-muted">
                        <?= e($structure['currency'] ?? 'INR') ?>
                        <?php if (!empty($structure['effective_to'])): ?>
                            &middot; closed <?= e(date_display($structure['effective_to'])) ?>
                        <?php else: ?>
                            &middot; currently in force
                        <?php endif; ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if ($components === []): ?>
                        <?php View::partial('empty-state', [
                            'icon' => 'fa-list-ul',
                            'title' => 'No components recorded',
                            'message' => 'This structure has no pay components against it yet.',
                        ]) ?>
                    <?php else: ?>
                        <?php foreach ($groups as $group): ?>
                            <?php if ($group['lines'] === []) {
                                continue;
                            } ?>
                            <div class="section-label mt-3">
                                <i class="fa <?= e($group['icon']) ?>"></i> <?= e($group['label']) ?>
                            </div>
                            <div class="table-wrap">
                                <table class="table align-middle">
                                    <thead>
                                        <tr>
                                            <th>Component</th>
                                            <th>Basis</th>
                                            <th class="text-end">Share of gross</th>
                                            <th class="text-end">Monthly</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($group['lines'] as $line): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-semibold"><?= e($line['component_name']) ?></span>
                                                    <?php if ($line['is_statutory']): ?>
                                                        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Statutory</span>
                                                    <?php endif; ?>
                                                    <?php if ($line['component_code'] !== ''): ?>
                                                        <div class="small text-muted"><?= e($line['component_code']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="small text-muted">
                                                    <?= e(label($line['calculation'])) ?>
                                                    <?php if ($line['percentage'] !== null): ?>
                                                        &middot; <?= e(rtrim(rtrim(number_format($line['percentage'], 3, '.', ''), '0'), '.')) ?>%
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end" style="min-width: 140px;">
                                                    <div class="progress progress-lg">
                                                        <div class="progress-bar" role="progressbar"
                                                             style="width: <?= e((string) $line['share']) ?>%"
                                                             aria-valuenow="<?= e((string) $line['share']) ?>"
                                                             aria-valuemin="0" aria-valuemax="100"></div>
                                                    </div>
                                                    <span class="small text-muted"><?= e((string) $line['share']) ?>%</span>
                                                </td>
                                                <td class="text-end tabular fw-semibold">
                                                    <?php if ($line['calculation'] === 'slab'): ?>
                                                        <span class="text-muted">On calculation</span>
                                                    <?php else: ?>
                                                        <?= e(money($line['amount_minor'])) ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header"><i class="fa fa-chart-pie"></i> Earnings split</div>
                <div class="card-body">
                    <div data-chart='<?= ejs($earningsChart) ?>'></div>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<div class="card mt-4">
    <div class="card-header"><i class="fa fa-clock-rotate-left"></i> Revision history</div>
    <div class="card-body">
        <?php if ($revisions === []): ?>
            <?php View::partial('empty-state', [
                'icon' => 'fa-clock-rotate-left',
                'title' => 'No revisions yet',
                'message' => 'Every change to your salary is recorded here with the date it took effect.',
            ]) ?>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($revisions as $index => $revision): ?>
                    <?php
                    $current = $revision['structure'];
                    $previous = $revision['previous'];
                    ?>
                    <div class="timeline-item <?= $index === 0 ? '' : 'is-muted' ?>">
                        <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2">
                            <span class="fw-semibold">
                                <?= e(date_display($current['effective_from'] ?? null)) ?>
                                <?php if ($index === 0 && empty($current['effective_to'])): ?>
                                    <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">In force</span>
                                <?php endif; ?>
                            </span>
                            <span class="small text-muted">
                                <?= e(relative_time($current['effective_from'] ?? null)) ?>
                            </span>
                        </div>

                        <div class="small mt-1">
                            <span class="text-muted">Cost to company</span>
                            <?php if ($previous !== null): ?>
                                <span class="tabular text-muted text-decoration-line-through">
                                    <?= e(money($previous['ctc_annual_minor'] ?? 0)) ?>
                                </span>
                                <i class="fa fa-arrow-right text-muted"></i>
                            <?php endif; ?>
                            <span class="tabular fw-semibold"><?= e(money($current['ctc_annual_minor'] ?? 0)) ?></span>
                        </div>

                        <div class="small">
                            <span class="text-muted">Monthly gross</span>
                            <?php if ($previous !== null): ?>
                                <span class="tabular text-muted text-decoration-line-through">
                                    <?= e(money($previous['gross_monthly_minor'] ?? 0)) ?>
                                </span>
                                <i class="fa fa-arrow-right text-muted"></i>
                            <?php endif; ?>
                            <span class="tabular fw-semibold"><?= e(money($current['gross_monthly_minor'] ?? 0)) ?></span>
                        </div>

                        <?php if (!empty($current['revision_reason'])): ?>
                            <div class="small text-muted mt-1">
                                <i class="fa fa-quote-left"></i> <?= e($current['revision_reason']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
