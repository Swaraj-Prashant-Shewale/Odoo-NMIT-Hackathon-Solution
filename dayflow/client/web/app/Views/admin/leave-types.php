<?php
/**
 * Leave policy: every leave type and the rules attached to it.
 *
 * @var list<array<string, mixed>> $types
 * @var list<string>               $categories
 * @var list<string>               $frequencies
 * @var list<string>               $genders
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

/**
 * A colour is only ever written into a style attribute after it has been
 * proven to be a plain hex value. Escaping alone is not enough inside CSS,
 * where a crafted value could otherwise change more than one swatch.
 */
$swatch = static function (mixed $colour): string {
    $value = is_string($colour) ? trim($colour) : '';

    return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? $value : '#94a3b8';
};
?>

<?php View::partial('page-header', [
    'title' => 'Leave policy',
    'subtitle' => 'What kinds of leave exist, how much of each people are entitled to, and how it accrues.',
]) ?>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header">Leave types</div>
            <div class="card-body p-0">
                <?php if ($types === []): ?>
                    <div class="p-3">
                        <?php View::partial('empty-state', [
                            'icon' => 'fa-umbrella-beach',
                            'title' => 'No leave types yet',
                            'message' => 'Nobody can apply for leave until at least one type exists. Add the first one with the form beside this list.',
                        ]) ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th class="text-end">Annual quota</th>
                                    <th>Accrual</th>
                                    <th class="text-end">Carry forward</th>
                                    <th>Rules</th>
                                    <th>State</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($types as $type): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <span style="display:inline-block;width:10px;height:24px;border-radius:3px;background:<?= e($swatch($type['colour'] ?? null)) ?>"></span>
                                                <div>
                                                    <div class="fw-semibold"><?= field($type, 'name') ?></div>
                                                    <div class="small text-muted">
                                                        <code><?= field($type, 'code', '—') ?></code>
                                                        &middot; <?= e(label((string) ($type['category'] ?? ''))) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-end tabular">
                                            <?= e(rtrim(rtrim(number_format((float) ($type['annual_quota_days'] ?? 0), 2, '.', ''), '0'), '.')) ?>
                                            <span class="small text-muted">days</span>
                                        </td>
                                        <td class="small">
                                            <?php $frequency = (string) ($type['accrual_frequency'] ?? 'none'); ?>
                                            <?php if ($frequency === 'none'): ?>
                                                <span class="text-muted">Credited in full</span>
                                            <?php else: ?>
                                                <?= e(rtrim(rtrim(number_format((float) ($type['accrual_days'] ?? 0), 2, '.', ''), '0'), '.')) ?>
                                                days <?= e(strtolower(label($frequency))) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end tabular">
                                            <?= e(rtrim(rtrim(number_format((float) ($type['max_carry_forward_days'] ?? 0), 2, '.', ''), '0'), '.')) ?>
                                        </td>
                                        <td class="small">
                                            <?php if (($type['is_paid'] ?? false) === true): ?>
                                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Paid</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Unpaid</span>
                                            <?php endif; ?>
                                            <?php if (($type['allows_half_day'] ?? false) === true): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Half days</span>
                                            <?php endif; ?>
                                            <?php if ((string) ($type['applies_to_gender'] ?? 'any') !== 'any'): ?>
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                                    <?= e(label((string) $type['applies_to_gender'])) ?> only
                                                </span>
                                            <?php endif; ?>
                                            <div class="text-muted mt-1">
                                                <?php if ((int) ($type['min_notice_days'] ?? 0) > 0): ?>
                                                    <?= e((string) (int) $type['min_notice_days']) ?> days' notice.
                                                <?php endif; ?>
                                                <?php if (!empty($type['max_consecutive_days'])): ?>
                                                    Max <?= e((string) (int) $type['max_consecutive_days']) ?> in a row.
                                                <?php endif; ?>
                                                <?php if (!empty($type['requires_document_after_days'])): ?>
                                                    Document after <?= e((string) (int) $type['requires_document_after_days']) ?> days.
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?= ($type['is_active'] ?? true) === false ? badge('withdrawn', 'Retired') : badge('active') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer small text-muted">
                A retired type is never deleted. Historical requests and balances still reference it, and leave that
                cannot say what kind it was is worthless to payroll or to an audit years later.
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card">
            <div class="card-header">Add a leave type</div>
            <form method="post" action="/admin/leave-types" novalidate>
                <?= Csrf::field() ?>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="lt-name" class="form-label">Name</label>
                        <input type="text" class="form-control <?= Flash::hasError('name') ? 'is-invalid' : '' ?>"
                               id="lt-name" name="name" maxlength="80" required value="<?= e(Flash::old('name')) ?>">
                        <?php View::partial('field-errors', ['name' => 'name']) ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <label for="lt-code" class="form-label">Code</label>
                            <input type="text" class="form-control <?= Flash::hasError('code') ? 'is-invalid' : '' ?>"
                                   id="lt-code" name="code" maxlength="20" required placeholder="AL"
                                   value="<?= e(Flash::old('code')) ?>">
                            <div class="form-text">Shown on payslips and reports.</div>
                            <?php View::partial('field-errors', ['name' => 'code']) ?>
                        </div>
                        <div class="col-6">
                            <label for="lt-colour" class="form-label">Colour</label>
                            <input type="color" class="form-control form-control-color w-100"
                                   id="lt-colour" name="colour"
                                   value="<?= e($swatch(Flash::old('colour', '#2563EB'))) ?>">
                            <div class="form-text">Used on the leave calendar.</div>
                            <?php View::partial('field-errors', ['name' => 'colour']) ?>
                        </div>
                    </div>

                    <div class="row g-3 mt-0">
                        <div class="col-6">
                            <label for="lt-category" class="form-label">Category</label>
                            <select class="form-select <?= Flash::hasError('category') ? 'is-invalid' : '' ?>"
                                    id="lt-category" name="category" required>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e($category) ?>" <?= Flash::old('category') === $category ? 'selected' : '' ?>>
                                        <?= e(label($category)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'category']) ?>
                        </div>
                        <div class="col-6">
                            <label for="lt-gender" class="form-label">Applies to</label>
                            <select class="form-select" id="lt-gender" name="applies_to_gender">
                                <?php foreach ($genders as $gender): ?>
                                    <option value="<?= e($gender) ?>" <?= Flash::old('applies_to_gender') === $gender ? 'selected' : '' ?>>
                                        <?= $gender === 'any' ? 'Everybody' : e(label($gender)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'applies_to_gender']) ?>
                        </div>
                    </div>

                    <hr>
                    <div class="section-label">Entitlement</div>

                    <div class="row g-3">
                        <div class="col-6">
                            <label for="lt-quota" class="form-label">Annual quota (days)</label>
                            <input type="number" step="0.5" min="0" max="366"
                                   class="form-control <?= Flash::hasError('annual_quota_days') ? 'is-invalid' : '' ?>"
                                   id="lt-quota" name="annual_quota_days" value="<?= e(Flash::old('annual_quota_days')) ?>">
                            <?php View::partial('field-errors', ['name' => 'annual_quota_days']) ?>
                        </div>
                        <div class="col-6">
                            <label for="lt-carry" class="form-label">Max carry forward</label>
                            <input type="number" step="0.5" min="0" max="366"
                                   class="form-control <?= Flash::hasError('max_carry_forward_days') ? 'is-invalid' : '' ?>"
                                   id="lt-carry" name="max_carry_forward_days"
                                   value="<?= e(Flash::old('max_carry_forward_days')) ?>">
                            <?php View::partial('field-errors', ['name' => 'max_carry_forward_days']) ?>
                        </div>
                        <div class="col-6">
                            <label for="lt-frequency" class="form-label">Accrual</label>
                            <select class="form-select" id="lt-frequency" name="accrual_frequency">
                                <?php foreach ($frequencies as $frequency): ?>
                                    <option value="<?= e($frequency) ?>" <?= Flash::old('accrual_frequency') === $frequency ? 'selected' : '' ?>>
                                        <?= $frequency === 'none' ? 'Credited in full' : e(label($frequency)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'accrual_frequency']) ?>
                        </div>
                        <div class="col-6">
                            <label for="lt-accrual-days" class="form-label">Days per period</label>
                            <input type="number" step="0.25" min="0" max="31"
                                   class="form-control <?= Flash::hasError('accrual_days') ? 'is-invalid' : '' ?>"
                                   id="lt-accrual-days" name="accrual_days" value="<?= e(Flash::old('accrual_days')) ?>">
                            <div class="form-text">Required when it accrues.</div>
                            <?php View::partial('field-errors', ['name' => 'accrual_days']) ?>
                        </div>
                    </div>

                    <hr>
                    <div class="section-label">Rules</div>

                    <div class="row g-3">
                        <div class="col-6">
                            <label for="lt-notice" class="form-label">Minimum notice (days)</label>
                            <input type="number" min="0" max="365" class="form-control"
                                   id="lt-notice" name="min_notice_days" value="<?= e(Flash::old('min_notice_days')) ?>">
                            <?php View::partial('field-errors', ['name' => 'min_notice_days']) ?>
                        </div>
                        <div class="col-6">
                            <label for="lt-consecutive" class="form-label">Max consecutive days</label>
                            <input type="number" min="1" max="366" class="form-control"
                                   id="lt-consecutive" name="max_consecutive_days"
                                   value="<?= e(Flash::old('max_consecutive_days')) ?>">
                            <?php View::partial('field-errors', ['name' => 'max_consecutive_days']) ?>
                        </div>
                        <div class="col-12">
                            <label for="lt-document" class="form-label">Document required after (days)</label>
                            <input type="number" min="0" max="366" class="form-control"
                                   id="lt-document" name="requires_document_after_days"
                                   value="<?= e(Flash::old('requires_document_after_days')) ?>">
                            <div class="form-text">Leave blank when no supporting document is ever needed.</div>
                            <?php View::partial('field-errors', ['name' => 'requires_document_after_days']) ?>
                        </div>
                    </div>

                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="lt-half-day"
                               name="allows_half_day" value="1" checked>
                        <label class="form-check-label" for="lt-half-day">Half days may be taken</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="lt-paid" name="is_paid" value="1" checked>
                        <label class="form-check-label" for="lt-paid">Paid leave</label>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary btn-sm" data-busy-label="Saving...">
                        <i class="fa fa-plus"></i> Add leave type
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
