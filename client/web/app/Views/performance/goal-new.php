<?php
/**
 * Setting a goal for the caller, or for one of their direct reports.
 *
 * @var list<array<string, mixed>>                                 $cycles
 * @var array<string, array{committed: float, available: float}>   $weights
 * @var list<array<string, mixed>>                                 $reports
 * @var float                                                      $weightBudget
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$plain = static fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.') ?: '0';

$categories = [
    'business' => 'Business — what the role is measured on',
    'personal' => 'Personal — how you want to grow',
    'learning' => 'Learning — a skill to acquire',
    'behavioural' => 'Behavioural — how the work gets done',
];

$defaultCycle = Flash::old('goal_cycle_id');
if ($defaultCycle === '' && $cycles !== []) {
    $defaultCycle = (string) ($cycles[0]['id'] ?? '');
}
?>

<?php View::partial('page-header', [
    'title' => 'Set a goal',
    'subtitle' => 'What you are committing to this cycle, and how it will be measured.',
    'actions' => '<a href="/performance" class="btn btn-outline-secondary btn-sm"><i class="fa fa-arrow-left"></i> Back to performance</a>',
]) ?>

<?php if ($cycles === []): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-calendar-times',
                'title' => 'No goal cycle is open',
                'message' => 'Goals are filed against an open cycle. Ask whoever runs the goal calendar to open one.',
                'actionLabel' => 'Back to performance',
                'actionHref' => '/performance',
            ]) ?>
        </div>
    </div>
<?php else: ?>

<div class="row g-4">
    <div class="col-lg-8">
        <form method="post" action="/performance/goals/new" novalidate>
            <?= Csrf::field() ?>

            <div class="card mb-4">
                <div class="card-header"><i class="fa fa-bullseye"></i> The goal</div>
                <div class="card-body">

                    <?php if ($reports !== []): ?>
                        <div class="mb-3">
                            <label for="employee_id" class="form-label">Who is this goal for</label>
                            <select class="form-select" id="employee_id" name="employee_id">
                                <option value="">Myself</option>
                                <?php foreach ($reports as $report): ?>
                                    <option value="<?= e((string) ($report['id'] ?? '')) ?>"
                                        <?= Flash::old('employee_id') === (string) ($report['id'] ?? '') ? 'selected' : '' ?>>
                                        <?= e((string) ($report['full_name'] ?? trim(((string) ($report['first_name'] ?? '')) . ' ' . ((string) ($report['last_name'] ?? ''))))) ?>
                                        <?php if (!empty($report['designation_name'])): ?>
                                            — <?= e((string) $report['designation_name']) ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Only your direct reports appear here. A goal set for somebody else is checked against their own weight budget, not yours.</div>
                            <?php View::partial('field-errors', ['name' => 'employee_id']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="goal_cycle_id" class="form-label">Goal cycle</label>
                        <select class="form-select" id="goal_cycle_id" name="goal_cycle_id" required>
                            <?php foreach ($cycles as $cycle): ?>
                                <?php $cycleId = (string) ($cycle['id'] ?? ''); ?>
                                <option value="<?= e($cycleId) ?>" <?= $defaultCycle === $cycleId ? 'selected' : '' ?>>
                                    <?= e((string) ($cycle['name'] ?? 'Cycle')) ?>
                                    (<?= e(date_display((string) ($cycle['period_start'] ?? ''))) ?>
                                    &ndash; <?= e(date_display((string) ($cycle['period_end'] ?? ''))) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php View::partial('field-errors', ['name' => 'goal_cycle_id']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="title" class="form-label">Title</label>
                        <input type="text"
                               class="form-control"
                               id="title"
                               name="title"
                               maxlength="180"
                               value="<?= e(Flash::old('title')) ?>"
                               placeholder="Cut checkout abandonment to under 20%"
                               required
                               autofocus>
                        <?php View::partial('field-errors', ['name' => 'title']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control"
                                  id="description"
                                  name="description"
                                  rows="4"
                                  maxlength="4000"
                                  placeholder="What good looks like, and why it matters this cycle."><?= e(Flash::old('description')) ?></textarea>
                        <div class="form-text" data-counter-for="description"></div>
                        <?php View::partial('field-errors', ['name' => 'description']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="category" class="form-label">Category</label>
                        <select class="form-select" id="category" name="category" required>
                            <option value="">Choose a category</option>
                            <?php foreach ($categories as $value => $description): ?>
                                <option value="<?= e($value) ?>" <?= Flash::old('category') === $value ? 'selected' : '' ?>>
                                    <?= e($description) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php View::partial('field-errors', ['name' => 'category']) ?>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><i class="fa fa-ruler"></i> How it is measured</div>
                <div class="card-body">

                    <div class="mb-3">
                        <label for="metric" class="form-label">Metric</label>
                        <input type="text"
                               class="form-control"
                               id="metric"
                               name="metric"
                               maxlength="180"
                               value="<?= e(Flash::old('metric')) ?>"
                               placeholder="Checkout abandonment rate">
                        <div class="form-text">The single number this goal is judged on.</div>
                        <?php View::partial('field-errors', ['name' => 'metric']) ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-sm-4">
                            <label for="current_value" class="form-label">Starting value</label>
                            <input type="number"
                                   step="0.01"
                                   min="0"
                                   class="form-control"
                                   id="current_value"
                                   name="current_value"
                                   value="<?= e(Flash::old('current_value')) ?>">
                            <?php View::partial('field-errors', ['name' => 'current_value']) ?>
                        </div>

                        <div class="col-sm-4">
                            <label for="target_value" class="form-label">Target value</label>
                            <input type="number"
                                   step="0.01"
                                   min="0"
                                   class="form-control"
                                   id="target_value"
                                   name="target_value"
                                   value="<?= e(Flash::old('target_value')) ?>">
                            <?php View::partial('field-errors', ['name' => 'target_value']) ?>
                        </div>

                        <div class="col-sm-4">
                            <label for="unit" class="form-label">Unit</label>
                            <input type="text"
                                   class="form-control"
                                   id="unit"
                                   name="unit"
                                   maxlength="40"
                                   value="<?= e(Flash::old('unit')) ?>"
                                   placeholder="%, hours, deals">
                            <?php View::partial('field-errors', ['name' => 'unit']) ?>
                        </div>
                    </div>

                    <div class="form-text mt-2">
                        Leave the target empty for a goal that is not counted, and record progress as a percentage instead.
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><i class="fa fa-calendar-alt"></i> Weight and dates</div>
                <div class="card-body">

                    <div class="row g-3">
                        <div class="col-sm-4">
                            <label for="weight_percent" class="form-label">Weight %</label>
                            <input type="number"
                                   step="0.5"
                                   min="0"
                                   max="100"
                                   class="form-control"
                                   id="weight_percent"
                                   name="weight_percent"
                                   value="<?= e(Flash::old('weight_percent')) ?>"
                                   required>
                            <?php View::partial('field-errors', ['name' => 'weight_percent']) ?>
                        </div>

                        <div class="col-sm-4">
                            <label for="starts_on" class="form-label">Starts on</label>
                            <input type="date"
                                   class="form-control"
                                   id="starts_on"
                                   name="starts_on"
                                   value="<?= e(Flash::old('starts_on')) ?>"
                                   data-range-start="due_on">
                            <?php View::partial('field-errors', ['name' => 'starts_on']) ?>
                        </div>

                        <div class="col-sm-4">
                            <label for="due_on" class="form-label">Due on</label>
                            <input type="date"
                                   class="form-control"
                                   id="due_on"
                                   name="due_on"
                                   value="<?= e(Flash::old('due_on')) ?>">
                            <?php View::partial('field-errors', ['name' => 'due_on']) ?>
                        </div>
                    </div>

                    <div class="form-text mt-2">
                        Left empty, the dates follow the cycle's own period. <span data-range-days></span>
                    </div>

                    <div class="mt-3">
                        <label for="status" class="form-label">Start as</label>
                        <select class="form-select" id="status" name="status">
                            <option value="active" <?= Flash::old('status', 'active') === 'active' ? 'selected' : '' ?>>
                                Active — being tracked from today
                            </option>
                            <option value="draft" <?= Flash::old('status') === 'draft' ? 'selected' : '' ?>>
                                Draft — still being agreed
                            </option>
                        </select>
                        <?php View::partial('field-errors', ['name' => 'status']) ?>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" data-busy-label="Saving...">
                    <i class="fa fa-check"></i> Save goal
                </button>
                <a href="/performance" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="fa fa-balance-scale"></i> Your available weight</div>
            <div class="card-body">
                <p class="small text-muted">
                    Weights say what each goal is worth when the cycle is appraised. They may total no more than
                    <?= e((string) (int) $weightBudget) ?>% in any one cycle, and a goal that would push the total past
                    that is refused.
                </p>

                <?php foreach ($cycles as $cycle): ?>
                    <?php
                    $cycleId = (string) ($cycle['id'] ?? '');
                    $weight = $weights[$cycleId] ?? null;
                    ?>
                    <?php if ($weight !== null): ?>
                        <?php
                        $used = percent($weight['committed'] / max(1.0, $weightBudget) * 100);
                        $tone = $weight['available'] <= 0.0
                            ? 'danger'
                            : ($weight['available'] < 15.0 ? 'warning' : 'success');
                        ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-baseline small">
                                <span class="fw-semibold truncate"><?= e((string) ($cycle['name'] ?? 'Cycle')) ?></span>
                                <span class="tabular text-<?= e($tone) ?>"><?= e($plain($weight['available'])) ?>% free</span>
                            </div>
                            <div class="progress mt-1">
                                <div class="progress-bar bg-<?= e($tone) ?>" style="width: <?= e((string) $used) ?>%"></div>
                            </div>
                            <div class="small text-muted mt-1">
                                <?= e($plain($weight['committed'])) ?>% already committed
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if ($reports !== []): ?>
                    <p class="small text-muted mb-0">
                        <i class="fa fa-info-circle"></i>
                        These figures are your own. When you set a goal for somebody else, their budget is the one that
                        is checked.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>
