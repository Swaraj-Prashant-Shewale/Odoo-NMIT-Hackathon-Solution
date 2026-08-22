<?php
/**
 * Salary structures: find a person, read what they are on, record a revision.
 *
 * @var string                     $search
 * @var list<array<string, mixed>> $matches
 * @var string                     $employeeId
 * @var array<string, mixed>|null  $employee
 * @var array<string, mixed>|null  $structure
 * @var list<array<string, mixed>> $components  Priced lines of the current structure.
 * @var list<array{structure: array<string, mixed>, previous: array<string, mixed>|null}> $revisions
 * @var list<array<string, mixed>> $catalogue   Active pay components.
 * @var array<string, mixed>       $defaults    Starting values for the form.
 * @var string                     $currencySymbol
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$lineDefaults = is_array($defaults['lines'] ?? null) ? $defaults['lines'] : [];
?>

<?php View::partial('page-header', [
    'title' => 'Salary structures',
    'subtitle' => 'What each person is contracted to be paid, and every revision behind it.',
    'actions' => '<a href="/payroll/runs" class="btn btn-outline-secondary">'
        . '<i class="fa fa-list-check"></i> Payroll runs</a>',
]) ?>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" action="/payroll/structures" class="row g-2 align-items-end m-0">
            <div class="col-md-8">
                <label for="search" class="form-label">Find an employee</label>
                <input type="search"
                       class="form-control"
                       id="search"
                       name="search"
                       value="<?= e($search) ?>"
                       maxlength="120"
                       placeholder="Name, employee code or work email">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fa fa-magnifying-glass"></i> Search
                </button>
            </div>
        </form>

        <?php if ($search !== ''): ?>
            <hr class="my-3">
            <?php if ($matches === []): ?>
                <p class="text-muted mb-0">Nobody matches &ldquo;<?= e($search) ?>&rdquo;.</p>
            <?php else: ?>
                <div class="section-label">Results</div>
                <div class="row g-2">
                    <?php foreach ($matches as $match): ?>
                        <div class="col-md-6 col-xl-4">
                            <a class="tile d-flex align-items-center gap-2 text-decoration-none"
                               href="/payroll/structures?employee_id=<?= e(urlencode((string) ($match['id'] ?? ''))) ?>">
                                <span class="avatar"><?= e(initials($match['full_name'] ?? '')) ?></span>
                                <span class="truncate">
                                    <span class="d-block fw-semibold truncate"><?= field($match, 'full_name') ?></span>
                                    <span class="d-block small text-muted truncate">
                                        <?= field($match, 'employee_code', '') ?>
                                        &middot; <?= field($match, 'designation_name', 'No designation') ?>
                                    </span>
                                </span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($employeeId === ''): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-user-tag',
                'title' => 'Choose an employee',
                'message' => 'Search above to see somebody\'s current structure and record a revision against it.',
            ]) ?>
        </div>
    </div>
<?php else: ?>

    <div class="row g-4">

        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="fa fa-id-card"></i> Current structure</div>
                <div class="card-body">
                    <?php if ($employee !== null): ?>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <span class="avatar avatar-lg"><?= e(initials($employee['full_name'] ?? '')) ?></span>
                            <div class="truncate">
                                <div class="fw-semibold truncate"><?= field($employee, 'full_name') ?></div>
                                <div class="small text-muted truncate">
                                    <?= field($employee, 'employee_code', '') ?>
                                    &middot; <?= field($employee, 'designation_name', 'No designation') ?>
                                </div>
                                <div class="small text-muted truncate"><?= field($employee, 'department_name', '') ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($structure === null): ?>
                        <?php View::partial('empty-state', [
                            'icon' => 'fa-sitemap',
                            'title' => 'No structure yet',
                            'message' => 'This person has never had a salary structure recorded. The form alongside creates their first one.',
                        ]) ?>
                    <?php else: ?>
                        <div class="stat-row">
                            <span class="stat-key">Effective from</span>
                            <span class="stat-val"><?= e(date_display($structure['effective_from'] ?? null)) ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Cost to company</span>
                            <span class="stat-val tabular"><?= e(money($structure['ctc_annual_minor'] ?? 0)) ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Monthly gross</span>
                            <span class="stat-val tabular"><?= e(money($structure['gross_monthly_minor'] ?? 0)) ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-key">Monthly basic</span>
                            <span class="stat-val tabular"><?= e(money($structure['basic_monthly_minor'] ?? 0)) ?></span>
                        </div>

                        <div class="section-label mt-3">Components</div>
                        <div class="table-wrap">
                            <table class="table">
                                <tbody>
                                    <?php foreach ($components as $line): ?>
                                        <tr>
                                            <td>
                                                <?= e($line['component_name']) ?>
                                                <div class="small text-muted"><?= e(label($line['component_type'])) ?></div>
                                            </td>
                                            <td class="text-end tabular">
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
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><i class="fa fa-pen-to-square"></i> Record a revision</div>

                <?php if ($catalogue === []): ?>
                    <div class="card-body">
                        <?php View::partial('empty-state', [
                            'icon' => 'fa-list-ul',
                            'title' => 'No pay components available',
                            'message' => 'A structure is built from the pay component catalogue, and none could be read.',
                        ]) ?>
                    </div>
                <?php else: ?>
                    <form method="post" action="/payroll/structures" class="card-body">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="employee_id" value="<?= e($employeeId) ?>">

                        <?php if ($structure !== null): ?>
                            <div class="alert alert-warning d-flex gap-2">
                                <i class="fa fa-triangle-exclamation mt-1"></i>
                                <div>
                                    Saving this closes the structure effective from
                                    <strong><?= e(date_display($structure['effective_from'] ?? null)) ?></strong>
                                    the day before the new one starts. The old figures are kept, so every payslip
                                    already issued still reconciles.
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="effective_from" class="form-label">Effective from</label>
                                <input type="date"
                                       class="form-control <?= Flash::hasError('effective_from') ? 'is-invalid' : '' ?>"
                                       id="effective_from"
                                       name="effective_from"
                                       value="<?= e(Flash::old('effective_from')) ?>"
                                       required>
                                <?php View::partial('field-errors', ['name' => 'effective_from']) ?>
                                <div class="form-text">Must be later than the current structure's start date.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="ctc_annual" class="form-label">Annual cost to company</label>
                                <div class="input-group">
                                    <span class="input-group-text"><?= e($currencySymbol) ?></span>
                                    <input type="number"
                                           class="form-control <?= Flash::hasError('ctc_annual') ? 'is-invalid' : '' ?>"
                                           id="ctc_annual"
                                           name="ctc_annual"
                                           step="0.01"
                                           min="0"
                                           value="<?= e(Flash::old('ctc_annual', (string) $defaults['ctc_annual'])) ?>"
                                           required>
                                </div>
                                <?php View::partial('field-errors', ['name' => 'ctc_annual']) ?>
                            </div>

                            <div class="col-md-6">
                                <label for="gross_monthly" class="form-label">Monthly gross</label>
                                <div class="input-group">
                                    <span class="input-group-text"><?= e($currencySymbol) ?></span>
                                    <input type="number"
                                           class="form-control <?= Flash::hasError('gross_monthly') ? 'is-invalid' : '' ?>"
                                           id="gross_monthly"
                                           name="gross_monthly"
                                           step="0.01"
                                           min="0"
                                           value="<?= e(Flash::old('gross_monthly', (string) $defaults['gross_monthly'])) ?>"
                                           required>
                                </div>
                                <?php View::partial('field-errors', ['name' => 'gross_monthly']) ?>
                                <div class="form-text">The earning components below have to add up to exactly this.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="basic_monthly" class="form-label">Monthly basic</label>
                                <div class="input-group">
                                    <span class="input-group-text"><?= e($currencySymbol) ?></span>
                                    <input type="number"
                                           class="form-control <?= Flash::hasError('basic_monthly') ? 'is-invalid' : '' ?>"
                                           id="basic_monthly"
                                           name="basic_monthly"
                                           step="0.01"
                                           min="0"
                                           value="<?= e(Flash::old('basic_monthly', (string) $defaults['basic_monthly'])) ?>"
                                           required>
                                </div>
                                <?php View::partial('field-errors', ['name' => 'basic_monthly']) ?>
                            </div>

                            <div class="col-12">
                                <label for="revision_reason" class="form-label">Reason for the revision</label>
                                <textarea class="form-control <?= Flash::hasError('revision_reason') ? 'is-invalid' : '' ?>"
                                          id="revision_reason"
                                          name="revision_reason"
                                          rows="2"
                                          maxlength="500"
                                          placeholder="Annual review, promotion, correction..."><?= e(Flash::old('revision_reason')) ?></textarea>
                                <div class="form-text" data-counter-for="revision_reason"></div>
                                <?php View::partial('field-errors', ['name' => 'revision_reason']) ?>
                            </div>
                        </div>

                        <div class="section-label mt-4">Components</div>
                        <p class="small text-muted">
                            Tick each component that applies. A fixed component needs a monthly amount; a
                            percentage component uses the percentage, and leaving it blank keeps the catalogue rate.
                        </p>

                        <div class="table-wrap">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 3rem;">Use</th>
                                        <th>Component</th>
                                        <th style="width: 10rem;">Monthly amount</th>
                                        <th style="width: 8rem;">Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($catalogue as $component): ?>
                                        <?php
                                        $componentId = (string) ($component['id'] ?? '');
                                        $existing = $lineDefaults[$componentId] ?? null;
                                        $calculation = (string) ($component['calculation'] ?? 'fixed');
                                        $isPercentage = in_array($calculation, ['percent_of_basic', 'percent_of_ctc'], true);
                                        ?>
                                        <tr>
                                            <td>
                                                <input class="form-check-input"
                                                       type="checkbox"
                                                       id="use-<?= e($componentId) ?>"
                                                       name="lines[<?= e($componentId) ?>][include]"
                                                       value="1"
                                                       <?= $existing !== null ? 'checked' : '' ?>>
                                            </td>
                                            <td>
                                                <label class="fw-semibold mb-0" for="use-<?= e($componentId) ?>">
                                                    <?= field($component, 'name') ?>
                                                </label>
                                                <div class="small text-muted">
                                                    <?= e(label((string) ($component['component_type'] ?? ''))) ?>
                                                    &middot; <?= e(label($calculation)) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="number"
                                                       class="form-control form-control-sm"
                                                       name="lines[<?= e($componentId) ?>][amount_monthly]"
                                                       step="0.01"
                                                       min="0"
                                                       aria-label="Monthly amount for <?= e((string) ($component['name'] ?? '')) ?>"
                                                       value="<?= e($existing['amount'] ?? '') ?>"
                                                       <?= $isPercentage || $calculation === 'slab' ? 'disabled' : '' ?>>
                                            </td>
                                            <td>
                                                <input type="number"
                                                       class="form-control form-control-sm"
                                                       name="lines[<?= e($componentId) ?>][percentage]"
                                                       step="0.001"
                                                       min="0"
                                                       max="100"
                                                       aria-label="Percentage for <?= e((string) ($component['name'] ?? '')) ?>"
                                                       value="<?= e($existing['percentage'] ?? '') ?>"
                                                       <?= $isPercentage ? '' : 'disabled' ?>>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php View::partial('field-errors', ['name' => 'lines']) ?>

                        <button type="submit" class="btn btn-primary mt-3" data-busy-label="Saving..."
                                data-confirm="Save this revision? The structure in force will be closed the day before it starts.">
                            <i class="fa fa-floppy-disk"></i> Save revision
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header"><i class="fa fa-clock-rotate-left"></i> Revision history</div>
        <div class="card-body">
            <?php if ($revisions === []): ?>
                <?php View::partial('empty-state', [
                    'icon' => 'fa-clock-rotate-left',
                    'title' => 'No revisions recorded',
                    'message' => 'Once a structure is saved, every change to it is listed here.',
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
                                    <?php if (empty($current['effective_to'])): ?>
                                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Open</span>
                                    <?php else: ?>
                                        <span class="small text-muted">
                                            to <?= e(date_display($current['effective_to'])) ?>
                                        </span>
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
                                <span class="text-muted">&middot; monthly gross</span>
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

<?php endif; ?>
