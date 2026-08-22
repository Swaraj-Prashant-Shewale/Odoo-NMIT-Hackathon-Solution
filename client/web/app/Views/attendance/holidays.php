<?php
/**
 * The company holiday calendar for one year, month by month.
 *
 * @var int                                        $year
 * @var list<int>                                  $years
 * @var array<string, list<array<string, mixed>>>  $byMonth  YYYY-MM => holidays.
 * @var int                                        $total
 * @var array{public: int, restricted: int, company: int} $counts
 * @var array<string, mixed>|null                  $nextHoliday
 * @var int                                        $remaining
 */

use App\Core\View;

$actions = '<a href="/attendance" class="btn btn-outline-secondary btn-sm">'
    . '<i class="fa fa-clock"></i> Back to my attendance</a>';

$typeIcon = static fn (string $type): string => match ($type) {
    'restricted' => 'fa-flag',
    'company' => 'fa-building',
    default => 'fa-gift',
};
?>

<?php View::partial('page-header', [
    'title' => 'Holiday calendar',
    'subtitle' => 'When the office is shut in ' . $year . '.',
    'actions' => $actions,
]) ?>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <form method="get" action="/attendance/holidays" class="d-flex align-items-end gap-2 m-0">
            <div>
                <label for="year" class="form-label mb-0">Year</label>
                <select class="form-select form-select-sm" id="year" name="year" data-submit-on-change>
                    <?php foreach ($years as $option): ?>
                        <option value="<?= e((string) $option) ?>" <?= $option === $year ? 'selected' : '' ?>>
                            <?= e((string) $option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-outline-secondary btn-sm">
                <i class="fa fa-filter"></i> Show
            </button>
        </form>

        <div class="d-flex flex-wrap gap-4 small">
            <div>
                <div class="section-label mb-0">Total</div>
                <div class="fw-semibold tabular"><?= e((string) $total) ?></div>
            </div>
            <div>
                <div class="section-label mb-0">Still to come</div>
                <div class="fw-semibold tabular"><?= e((string) $remaining) ?></div>
            </div>
            <div>
                <div class="section-label mb-0">Public</div>
                <div class="fw-semibold tabular"><?= e((string) $counts['public']) ?></div>
            </div>
            <div>
                <div class="section-label mb-0">Restricted</div>
                <div class="fw-semibold tabular"><?= e((string) $counts['restricted']) ?></div>
            </div>
            <div>
                <div class="section-label mb-0">Company</div>
                <div class="fw-semibold tabular"><?= e((string) $counts['company']) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($nextHoliday !== null): ?>
    <div class="card mb-3 border-primary">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div class="tile-icon"><i class="fa <?= e($typeIcon((string) ($nextHoliday['holiday_type'] ?? 'public'))) ?>"></i></div>
            <div class="flex-grow-1">
                <div class="section-label mb-1">
                    <?= !empty($nextHoliday['is_today']) ? 'Today' : 'Next up' ?>
                </div>
                <div class="h5 mb-1"><?= e($nextHoliday['name'] ?? 'Holiday') ?></div>
                <div class="text-muted small">
                    <?= e(date_display($nextHoliday['holiday_date'] ?? null)) ?>
                    &middot; <?= e(relative_time($nextHoliday['holiday_date'] ?? null)) ?>
                    &middot; <?= e(label($nextHoliday['holiday_type'] ?? null)) ?>
                </div>
                <?php if (!empty($nextHoliday['description'])): ?>
                    <div class="small mt-1"><?= e($nextHoliday['description']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($byMonth === []): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-umbrella-beach',
                'title' => 'No holidays published for ' . $year,
                'message' => 'HR has not put a calendar up for this year yet.',
            ]) ?>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($byMonth as $monthKey => $holidays): ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fa fa-calendar-alt"></i> <?= e(date('F', (int) strtotime($monthKey . '-01'))) ?></span>
                        <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                            <?= e((string) count($holidays)) ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="timeline">
                            <?php foreach ($holidays as $holiday): ?>
                                <?php $type = (string) ($holiday['holiday_type'] ?? 'public'); ?>
                                <div class="timeline-item <?= !empty($holiday['has_passed']) ? 'is-muted' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div class="truncate">
                                            <div class="fw-semibold truncate <?= !empty($holiday['has_passed']) ? 'text-muted' : '' ?>">
                                                <i class="fa <?= e($typeIcon($type)) ?>"></i>
                                                <?= e($holiday['name'] ?? 'Holiday') ?>
                                            </div>
                                            <div class="small text-muted">
                                                <?= e(date_display($holiday['holiday_date'] ?? null)) ?>
                                                &middot;
                                                <?= e(empty($holiday['holiday_date']) ? '—' : date('l', (int) strtotime((string) $holiday['holiday_date']))) ?>
                                            </div>
                                            <?php if (!empty($holiday['description'])): ?>
                                                <div class="small text-muted truncate"><?= e($holiday['description']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-end">
                                            <?= badge($type) ?>
                                            <?php if (!empty($holiday['is_next'])): ?>
                                                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle d-block mt-1">
                                                    <i class="fa fa-thumbtack"></i>
                                                    <?= !empty($holiday['is_today']) ? 'Today' : 'Next' ?>
                                                </span>
                                            <?php elseif (!empty($holiday['has_passed'])): ?>
                                                <span class="small text-muted d-block mt-1">Passed</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
