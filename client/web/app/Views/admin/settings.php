<?php
/**
 * Company settings.
 *
 * @var array<string, mixed>                      $settings  Effective value for every key.
 * @var array<string, string>                     $labels    The service's own label for each key.
 * @var array<string, mixed>                      $isDefault Whether a key still holds its default.
 * @var list<array{value: string, label: string}> $weekdays
 */

use App\Core\Csrf;
use App\Core\View;

$workingDays = is_array($settings['company.working_days'] ?? null) ? $settings['company.working_days'] : [];
$workHours = is_array($settings['company.work_hours'] ?? null) ? $settings['company.work_hours'] : [];
$currency = is_array($settings['company.currency'] ?? null) ? $settings['company.currency'] : [];

/** A small "still the default" marker, so an untouched value is obvious. */
$marker = static function (string $key) use ($isDefault): string {
    return ($isDefault[$key] ?? false) === true
        ? '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle ms-1">default</span>'
        : '';
};
?>

<?php View::partial('page-header', [
    'title' => 'Company settings',
    'subtitle' => 'The defaults every other service reads. What a working day is, how long it lasts, and what money looks like.',
]) ?>

<form method="post" action="/admin/settings" novalidate>
    <?= Csrf::field() ?>

    <div class="row g-3">
        <div class="col-lg-7">

            <div class="card">
                <div class="card-header">Identity</div>
                <div class="card-body">
                    <label for="company_name" class="form-label">
                        <?= e($labels['company.name'] ?? 'Registered company name') ?>
                        <?= $marker('company.name') ?>
                    </label>
                    <input type="text" class="form-control" id="company_name" name="company_name"
                           maxlength="150" required
                           value="<?= e($settings['company.name'] ?? '') ?>">
                    <div class="form-text">
                        Printed on every payslip and on the header of every exported report.
                    </div>
                    <?php View::partial('field-errors', ['name' => 'company.name']) ?>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">The working week</div>
                <div class="card-body">
                    <div class="mb-3">
                        <span class="form-label d-block">
                            <?= e($labels['company.working_days'] ?? 'Working days') ?>
                            <?= $marker('company.working_days') ?>
                        </span>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($weekdays as $day): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           name="working_days[]" value="<?= e($day['value']) ?>"
                                           id="day-<?= e($day['value']) ?>"
                                        <?= in_array($day['value'], $workingDays, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="day-<?= e($day['value']) ?>">
                                        <?= e($day['label']) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">
                            Any day left unticked is treated as a weekly off: attendance is not expected and leave is
                            not deducted for it.
                        </div>
                        <?php View::partial('field-errors', ['name' => 'company.working_days']) ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="work_hours_start" class="form-label">
                                Standard start <?= $marker('company.work_hours') ?>
                            </label>
                            <input type="time" class="form-control" id="work_hours_start" name="work_hours_start"
                                   required value="<?= e($workHours['start'] ?? '09:30') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="work_hours_end" class="form-label">Standard end</label>
                            <input type="time" class="form-control" id="work_hours_end" name="work_hours_end"
                                   required value="<?= e($workHours['end'] ?? '18:30') ?>">
                        </div>
                        <div class="col-12">
                            <div class="form-text">
                                The company-wide default. A person on a shift pattern is measured against that
                                pattern instead, so this is what applies to everybody who is not.
                            </div>
                            <?php View::partial('field-errors', ['name' => 'company.work_hours']) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">What counts as a day</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="full_day_hours" class="form-label">
                                Full day <?= $marker('company.full_day_hours') ?>
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.5" min="0.5" max="24" class="form-control"
                                       id="full_day_hours" name="full_day_hours" required
                                       value="<?= e($settings['company.full_day_hours'] ?? 8) ?>">
                                <span class="input-group-text">hours</span>
                            </div>
                            <?php View::partial('field-errors', ['name' => 'company.full_day_hours']) ?>
                        </div>
                        <div class="col-md-4">
                            <label for="half_day_hours" class="form-label">
                                Half day <?= $marker('company.half_day_hours') ?>
                            </label>
                            <div class="input-group">
                                <input type="number" step="0.5" min="0.5" max="24" class="form-control"
                                       id="half_day_hours" name="half_day_hours" required
                                       value="<?= e($settings['company.half_day_hours'] ?? 4) ?>">
                                <span class="input-group-text">hours</span>
                            </div>
                            <?php View::partial('field-errors', ['name' => 'company.half_day_hours']) ?>
                        </div>
                        <div class="col-md-4">
                            <label for="late_grace_minutes" class="form-label">
                                Late grace <?= $marker('company.late_grace_minutes') ?>
                            </label>
                            <div class="input-group">
                                <input type="number" min="0" max="240" class="form-control"
                                       id="late_grace_minutes" name="late_grace_minutes" required
                                       value="<?= e($settings['company.late_grace_minutes'] ?? 15) ?>">
                                <span class="input-group-text">minutes</span>
                            </div>
                            <?php View::partial('field-errors', ['name' => 'company.late_grace_minutes']) ?>
                        </div>
                        <div class="col-12">
                            <div class="form-text">
                                Attendance is marked present, half day or absent by comparing hours worked against
                                these two numbers, and an arrival is only late once the grace period has passed.
                                Changing them changes how future days are judged, not days already recorded.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">Money and the financial year</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="currency_code" class="form-label">
                                Currency code <?= $marker('company.currency') ?>
                            </label>
                            <input type="text" class="form-control text-uppercase" id="currency_code"
                                   name="currency_code" maxlength="3" required
                                   value="<?= e($currency['code'] ?? 'INR') ?>">
                            <div class="form-text">Three letters, such as INR.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="currency_symbol" class="form-label">Symbol</label>
                            <input type="text" class="form-control" id="currency_symbol"
                                   name="currency_symbol" maxlength="5" required
                                   value="<?= e($currency['symbol'] ?? '') ?>">
                            <?php View::partial('field-errors', ['name' => 'company.currency']) ?>
                        </div>
                        <div class="col-md-4">
                            <label for="financial_year_start" class="form-label">
                                Financial year starts <?= $marker('company.financial_year_start') ?>
                            </label>
                            <input type="text" class="form-control tabular" id="financial_year_start"
                                   name="financial_year_start" required placeholder="04-01"
                                   pattern="(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])"
                                   value="<?= e($settings['company.financial_year_start'] ?? '04-01') ?>">
                            <div class="form-text">Month and day, as MM-DD.</div>
                            <?php View::partial('field-errors', ['name' => 'company.financial_year_start']) ?>
                        </div>
                        <div class="col-12">
                            <div class="form-text">
                                Every amount in the platform is stored in minor units, so changing the symbol changes
                                how figures are drawn and never what they are worth. The financial year start is what
                                leave accrual and year-end reporting count from, and it must be a date that exists in
                                every year.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-primary" data-busy-label="Saving...">
                        <i class="fa fa-save"></i> Save settings
                    </button>
                    <a href="/admin" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">In force right now</div>
                <div class="card-body">
                    <div class="stat-row">
                        <span class="stat-key">Company</span>
                        <span class="stat-val"><?= e($settings['company.name'] ?? '—') ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Working week</span>
                        <span class="stat-val">
                            <?= $workingDays === []
                                ? '<span class="text-muted">Not set</span>'
                                : e(implode(', ', array_map('ucfirst', array_map('strval', $workingDays)))) ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Hours</span>
                        <span class="stat-val tabular">
                            <?= e($workHours['start'] ?? '—') ?> &ndash; <?= e($workHours['end'] ?? '—') ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Full day</span>
                        <span class="stat-val tabular"><?= e($settings['company.full_day_hours'] ?? '—') ?> hours</span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Half day</span>
                        <span class="stat-val tabular"><?= e($settings['company.half_day_hours'] ?? '—') ?> hours</span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Late after</span>
                        <span class="stat-val tabular"><?= e($settings['company.late_grace_minutes'] ?? '—') ?> minutes</span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Currency</span>
                        <span class="stat-val">
                            <?= e($currency['symbol'] ?? '') ?> <?= e($currency['code'] ?? '—') ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Financial year</span>
                        <span class="stat-val tabular"><?= e($settings['company.financial_year_start'] ?? '—') ?></span>
                    </div>
                </div>
                <div class="card-footer small text-muted">
                    These values are read by eight other services. A malformed entry is refused here, where it
                    produces a clear message, rather than three services away in the middle of a payroll run.
                </div>
            </div>
        </div>
    </div>
</form>
