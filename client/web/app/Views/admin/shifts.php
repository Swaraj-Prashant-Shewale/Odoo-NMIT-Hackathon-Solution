<?php
/**
 * Shift patterns.
 *
 * @var list<array<string, mixed>>                $shifts
 * @var array<string, mixed>                      $meta
 * @var list<array{value: string, label: string}> $weekdays
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

// A shift time is a bare TIME with no date and no zone, so it is rendered as
// written rather than pushed through a timezone conversion that could move it.
$clock = static fn (mixed $value): string => substr((string) $value, 0, 5);
?>

<?php View::partial('page-header', [
    'title' => 'Shift patterns',
    'subtitle' => 'What a working day looks like: when it starts, how long it lasts and how late is late.',
]) ?>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header">Patterns</div>
            <div class="card-body p-0">
                <?php if ($shifts === []): ?>
                    <div class="p-3">
                        <?php View::partial('empty-state', [
                            'icon' => 'fa-business-time',
                            'title' => 'No shift patterns yet',
                            'message' => 'Attendance is measured against a shift, so add the standard working day before anybody punches in.',
                        ]) ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Shift</th>
                                    <th>Hours</th>
                                    <th class="text-end">Break</th>
                                    <th class="text-end">Grace</th>
                                    <th class="text-end">Full / half day</th>
                                    <th>Working days</th>
                                    <th>State</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shifts as $shift): ?>
                                    <?php $days = is_array($shift['working_days'] ?? null) ? $shift['working_days'] : []; ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= field($shift, 'name') ?></div>
                                            <div class="small text-muted"><code><?= field($shift, 'code', '—') ?></code></div>
                                        </td>
                                        <td class="tabular">
                                            <?= e($clock($shift['starts_at'] ?? '')) ?>
                                            &ndash;
                                            <?= e($clock($shift['ends_at'] ?? '')) ?>
                                            <?php if (($shift['is_night_shift'] ?? false) === true): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-1">
                                                    <i class="fa fa-moon"></i> Night
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end tabular"><?= e((string) (int) ($shift['break_minutes'] ?? 0)) ?>m</td>
                                        <td class="text-end tabular"><?= e((string) (int) ($shift['grace_minutes'] ?? 0)) ?>m</td>
                                        <td class="text-end tabular">
                                            <?= e(rtrim(rtrim(number_format((float) ($shift['full_day_hours'] ?? 0), 2, '.', ''), '0'), '.')) ?>h
                                            /
                                            <?= e(rtrim(rtrim(number_format((float) ($shift['half_day_hours'] ?? 0), 2, '.', ''), '0'), '.')) ?>h
                                        </td>
                                        <td>
                                            <?php if ($days === []): ?>
                                                <span class="text-muted">&mdash;</span>
                                            <?php else: ?>
                                                <?php foreach ($days as $day): ?>
                                                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                                        <?= e(ucfirst((string) $day)) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= ($shift['is_active'] ?? true) === false ? badge('inactive') : badge('active') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($shifts !== []): ?>
                <div class="card-footer">
                    <?php View::partial('pagination', ['meta' => $meta]) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card">
            <div class="card-header">Add a shift pattern</div>
            <form method="post" action="/admin/shifts" novalidate>
                <?= Csrf::field() ?>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="shift-name" class="form-label">Name</label>
                        <input type="text" class="form-control <?= Flash::hasError('name') ? 'is-invalid' : '' ?>"
                               id="shift-name" name="name" maxlength="80" required
                               placeholder="General shift" value="<?= e(Flash::old('name')) ?>">
                        <?php View::partial('field-errors', ['name' => 'name']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="shift-code" class="form-label">Code</label>
                        <input type="text" class="form-control <?= Flash::hasError('code') ? 'is-invalid' : '' ?>"
                               id="shift-code" name="code" maxlength="20" required placeholder="GEN"
                               value="<?= e(Flash::old('code')) ?>">
                        <div class="form-text">2 to 20 letters, numbers, hyphens or underscores.</div>
                        <?php View::partial('field-errors', ['name' => 'code']) ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <label for="shift-starts" class="form-label">Starts at</label>
                            <input type="time" class="form-control <?= Flash::hasError('starts_at') ? 'is-invalid' : '' ?>"
                                   id="shift-starts" name="starts_at" required
                                   value="<?= e(Flash::old('starts_at', '09:30')) ?>">
                            <?php View::partial('field-errors', ['name' => 'starts_at']) ?>
                        </div>
                        <div class="col-6">
                            <label for="shift-ends" class="form-label">Ends at</label>
                            <input type="time" class="form-control <?= Flash::hasError('ends_at') ? 'is-invalid' : '' ?>"
                                   id="shift-ends" name="ends_at" required
                                   value="<?= e(Flash::old('ends_at', '18:30')) ?>">
                            <?php View::partial('field-errors', ['name' => 'ends_at']) ?>
                        </div>
                        <div class="col-6">
                            <label for="shift-break" class="form-label">Break (minutes)</label>
                            <input type="number" min="0" max="480" class="form-control"
                                   id="shift-break" name="break_minutes"
                                   value="<?= e(Flash::old('break_minutes', '60')) ?>">
                            <?php View::partial('field-errors', ['name' => 'break_minutes']) ?>
                        </div>
                        <div class="col-6">
                            <label for="shift-grace" class="form-label">Grace (minutes)</label>
                            <input type="number" min="0" max="240" class="form-control"
                                   id="shift-grace" name="grace_minutes"
                                   value="<?= e(Flash::old('grace_minutes', '15')) ?>">
                            <div class="form-text">Before an arrival counts as late.</div>
                            <?php View::partial('field-errors', ['name' => 'grace_minutes']) ?>
                        </div>
                        <div class="col-6">
                            <label for="shift-full" class="form-label">Full day (hours)</label>
                            <input type="number" step="0.25" min="0.5" max="24" class="form-control"
                                   id="shift-full" name="full_day_hours"
                                   value="<?= e(Flash::old('full_day_hours', '8')) ?>">
                            <?php View::partial('field-errors', ['name' => 'full_day_hours']) ?>
                        </div>
                        <div class="col-6">
                            <label for="shift-half" class="form-label">Half day (hours)</label>
                            <input type="number" step="0.25" min="0.5" max="24" class="form-control"
                                   id="shift-half" name="half_day_hours"
                                   value="<?= e(Flash::old('half_day_hours', '4')) ?>">
                            <?php View::partial('field-errors', ['name' => 'half_day_hours']) ?>
                        </div>
                    </div>

                    <div class="mt-3">
                        <span class="section-label">Working days</span>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($weekdays as $day): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           name="working_days[]" value="<?= e($day['value']) ?>"
                                           id="shift-day-<?= e($day['value']) ?>"
                                        <?= in_array($day['value'], ['sat', 'sun'], true) ? '' : 'checked' ?>>
                                    <label class="form-check-label" for="shift-day-<?= e($day['value']) ?>">
                                        <?= e(substr($day['label'], 0, 3)) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php View::partial('field-errors', ['name' => 'working_days']) ?>
                    </div>

                    <div class="form-check mt-3 mb-0">
                        <input class="form-check-input" type="checkbox" id="shift-night"
                               name="is_night_shift" value="1">
                        <label class="form-check-label" for="shift-night">
                            This shift crosses midnight
                        </label>
                        <div class="form-text">Left unticked, the service works it out from the two times.</div>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary btn-sm" data-busy-label="Saving...">
                        <i class="fa fa-plus"></i> Add shift pattern
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
