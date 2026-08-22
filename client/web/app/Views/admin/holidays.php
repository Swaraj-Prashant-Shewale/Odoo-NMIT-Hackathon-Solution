<?php
/**
 * The holiday calendar for one year.
 *
 * @var list<array<string, mixed>> $holidays
 * @var int                        $year
 * @var list<int>                  $years
 * @var list<array<string, mixed>> $locations
 * @var string                     $today
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$locationNames = [];

foreach ($locations as $location) {
    $locationNames[(string) ($location['id'] ?? '')] = (string) ($location['name'] ?? '');
}

/** @var array<string, list<array<string, mixed>>> $byMonth */
$byMonth = [];

foreach ($holidays as $holiday) {
    $date = (string) ($holiday['holiday_date'] ?? '');

    if ($date === '') {
        continue;
    }

    $byMonth[substr($date, 0, 7)][] = $holiday;
}

ksort($byMonth);

$passed = 0;

foreach ($holidays as $holiday) {
    if ((string) ($holiday['holiday_date'] ?? '') < $today) {
        $passed++;
    }
}
?>

<?php View::partial('page-header', [
    'title' => 'Holiday calendar',
    'subtitle' => 'When the office is shut. Attendance, leave and payroll all read from this.',
]) ?>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>
                    <?= e((string) count($holidays)) ?> holiday<?= count($holidays) === 1 ? '' : 's' ?> in <?= e((string) $year) ?>
                    <?php if ($passed > 0): ?>
                        <span class="small text-muted">&middot; <?= e((string) $passed) ?> already passed</span>
                    <?php endif; ?>
                </span>
                <form method="get" action="/admin/holidays" class="d-flex align-items-center gap-2">
                    <label for="year" class="form-label mb-0 small">Year</label>
                    <select class="form-select form-select-sm" id="year" name="year"
                            style="width: auto;" data-submit-on-change>
                        <?php foreach ($years as $option): ?>
                            <option value="<?= e((string) $option) ?>" <?= $option === $year ? 'selected' : '' ?>>
                                <?= e((string) $option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="card-body">
                <?php if ($byMonth === []): ?>
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-calendar-day',
                        'title' => 'Nothing on the calendar for ' . $year,
                        'message' => 'Add the first holiday with the form beside this list, or choose another year.',
                    ]) ?>
                <?php else: ?>
                    <?php foreach ($byMonth as $month => $entries): ?>
                        <div class="section-label mt-3"><?= e(date('F Y', (int) strtotime($month . '-01'))) ?></div>
                        <?php foreach ($entries as $holiday): ?>
                            <?php
                            $date = (string) ($holiday['holiday_date'] ?? '');
                            $isPast = $date !== '' && $date < $today;
                            $locationId = (string) ($holiday['location_id'] ?? '');
                            $type = (string) ($holiday['holiday_type'] ?? 'public');
                            $tone = match ($type) {
                                'restricted' => 'info',
                                'company' => 'primary',
                                default => 'success',
                            };
                            ?>
                            <div class="stat-row align-items-start <?= $isPast ? 'opacity-75' : '' ?>">
                                <span class="stat-key">
                                    <span class="d-inline-block tabular" style="min-width: 108px;">
                                        <?= e(date_display($date)) ?>
                                    </span>
                                    <span class="fw-semibold text-body"><?= field($holiday, 'name') ?></span>
                                    <?php if (!empty($holiday['description'])): ?>
                                        <span class="d-block small ms-0 ms-sm-5 ps-0 ps-sm-4">
                                            <?= field($holiday, 'description') ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                                <span class="stat-val text-end">
                                    <span class="badge bg-<?= e($tone) ?>-subtle text-<?= e($tone) ?>-emphasis border border-<?= e($tone) ?>-subtle">
                                        <?= e(label($type)) ?>
                                    </span>
                                    <?php if ($locationId !== ''): ?>
                                        <span class="d-block small text-muted mt-1">
                                            <i class="fa fa-map-marker-alt"></i>
                                            <?= e($locationNames[$locationId] ?? 'One location only') ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="d-block small text-muted mt-1">Every office</span>
                                    <?php endif; ?>
                                    <?php if (($holiday['is_active'] ?? true) === false): ?>
                                        <span class="d-block small text-danger mt-1">Withdrawn</span>
                                    <?php elseif ($isPast): ?>
                                        <span class="d-block small text-muted mt-1">Passed</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card">
            <div class="card-header">Add a holiday</div>
            <form method="post" action="/admin/holidays" novalidate>
                <?= Csrf::field() ?>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="holiday-name" class="form-label">Name</label>
                        <input type="text" class="form-control <?= Flash::hasError('name') ? 'is-invalid' : '' ?>"
                               id="holiday-name" name="name" maxlength="120" required
                               value="<?= e(Flash::old('name')) ?>">
                        <?php View::partial('field-errors', ['name' => 'name']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="holiday-date" class="form-label">Date</label>
                        <input type="date" class="form-control <?= Flash::hasError('holiday_date') ? 'is-invalid' : '' ?>"
                               id="holiday-date" name="holiday_date" required
                               value="<?= e(Flash::old('holiday_date')) ?>">
                        <div class="form-text">The year is taken from the date itself.</div>
                        <?php View::partial('field-errors', ['name' => 'holiday_date']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="holiday-type" class="form-label">Type</label>
                        <select class="form-select" id="holiday-type" name="holiday_type">
                            <?php foreach (['public' => 'Public holiday', 'restricted' => 'Restricted (optional)', 'company' => 'Company holiday'] as $value => $text): ?>
                                <option value="<?= e($value) ?>" <?= Flash::old('holiday_type') === $value ? 'selected' : '' ?>>
                                    <?= e($text) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">A restricted holiday is one people may choose to take, not one the office closes for.</div>
                        <?php View::partial('field-errors', ['name' => 'holiday_type']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="holiday-location" class="form-label">Applies to</label>
                        <select class="form-select" id="holiday-location" name="location_id">
                            <option value="">Every office</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?= e($location['id'] ?? '') ?>"
                                    <?= Flash::old('location_id') === (string) ($location['id'] ?? '') ? 'selected' : '' ?>>
                                    <?= e($location['name'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php View::partial('field-errors', ['name' => 'location_id']) ?>
                    </div>

                    <div class="mb-0">
                        <label for="holiday-description" class="form-label">Description</label>
                        <textarea class="form-control" id="holiday-description" name="description"
                                  rows="2" maxlength="500"><?= e(Flash::old('description')) ?></textarea>
                        <?php View::partial('field-errors', ['name' => 'description']) ?>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary btn-sm" data-busy-label="Saving...">
                        <i class="fa fa-plus"></i> Add holiday
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
