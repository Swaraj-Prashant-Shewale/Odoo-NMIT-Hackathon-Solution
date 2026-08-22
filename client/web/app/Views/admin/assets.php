<?php
/**
 * Company assets.
 *
 * @var list<array<string, mixed>> $assets
 * @var array<string, mixed>       $meta
 * @var array<string, string>      $filters
 * @var list<string>               $categories
 * @var list<string>               $statuses
 * @var list<string>               $conditions
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
?>

<?php View::partial('page-header', [
    'title' => 'Company assets',
    'subtitle' => 'Equipment the company owns, what it is worth, and who is holding it.',
]) ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="/admin/assets" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label for="search" class="form-label">Search</label>
                <input type="search" class="form-control" id="search" name="search"
                       value="<?= e($filters['search'] ?? '') ?>"
                       placeholder="Tag, name or serial number">
            </div>
            <div class="col-md-3">
                <label for="category" class="form-label">Category</label>
                <select class="form-select" id="category" name="category" data-submit-on-change>
                    <option value="">Any category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e($category) ?>" <?= ($filters['category'] ?? '') === $category ? 'selected' : '' ?>>
                            <?= e(label($category)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status" data-submit-on-change>
                    <option value="">Any status</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= ($filters['status'] ?? '') === $status ? 'selected' : '' ?>>
                            <?= e(label($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-outline-secondary"><i class="fa fa-filter"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header">Register</div>
            <div class="card-body p-0">
                <?php if ($assets === []): ?>
                    <div class="p-3">
                        <?php View::partial('empty-state', [
                            'icon' => 'fa-laptop',
                            'title' => 'No assets match',
                            'message' => 'Nothing here matches those filters. Clear them, or add the first asset with the form beside this list.',
                        ]) ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Tag</th>
                                    <th>Asset</th>
                                    <th>Serial</th>
                                    <th class="text-end">Value</th>
                                    <th>Condition</th>
                                    <th>Held by</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assets as $asset): ?>
                                    <tr>
                                        <td><code><?= field($asset, 'asset_tag') ?></code></td>
                                        <td>
                                            <div class="fw-semibold"><?= field($asset, 'name') ?></div>
                                            <div class="small text-muted"><?= e(label((string) ($asset['category'] ?? ''))) ?></div>
                                        </td>
                                        <td class="small"><?= field($asset, 'serial_number', 'Not recorded') ?></td>
                                        <td class="text-end tabular"><?= e(money($asset['value_minor'] ?? 0)) ?></td>
                                        <td><?= badge((string) ($asset['condition'] ?? '')) ?></td>
                                        <td>
                                            <?php if (!empty($asset['assigned_to_name'])): ?>
                                                <div><?= field($asset, 'assigned_to_name') ?></div>
                                                <div class="small text-muted">
                                                    <?php if (!empty($asset['assigned_on'])): ?>
                                                        Since <?= e(date_display((string) $asset['assigned_on'])) ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($asset['returned_on'])): ?>
                                                        &middot; returned <?= e(date_display((string) $asset['returned_on'])) ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">Nobody</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= badge((string) ($asset['status'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($assets !== []): ?>
                <div class="card-footer">
                    <?php View::partial('pagination', ['meta' => $meta]) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card">
            <div class="card-header">Add an asset</div>
            <form method="post" action="/admin/assets" novalidate>
                <?= Csrf::field() ?>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label for="asset-tag" class="form-label">Tag</label>
                            <input type="text" class="form-control <?= Flash::hasError('asset_tag') ? 'is-invalid' : '' ?>"
                                   id="asset-tag" name="asset_tag" maxlength="30" required
                                   placeholder="DF-LAP-001" value="<?= e(Flash::old('asset_tag')) ?>">
                            <div class="form-text">3 to 30 letters, numbers or hyphens.</div>
                            <?php View::partial('field-errors', ['name' => 'asset_tag']) ?>
                        </div>
                        <div class="col-6">
                            <label for="asset-category" class="form-label">Category</label>
                            <select class="form-select <?= Flash::hasError('category') ? 'is-invalid' : '' ?>"
                                    id="asset-category" name="category" required>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e($category) ?>" <?= Flash::old('category') === $category ? 'selected' : '' ?>>
                                        <?= e(label($category)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'category']) ?>
                        </div>
                    </div>

                    <div class="mt-3 mb-3">
                        <label for="asset-name" class="form-label">Name</label>
                        <input type="text" class="form-control <?= Flash::hasError('name') ? 'is-invalid' : '' ?>"
                               id="asset-name" name="name" maxlength="120" required
                               placeholder="MacBook Pro 14&quot;" value="<?= e(Flash::old('name')) ?>">
                        <?php View::partial('field-errors', ['name' => 'name']) ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <label for="asset-serial" class="form-label">Serial number</label>
                            <input type="text" class="form-control" id="asset-serial"
                                   name="serial_number" maxlength="80" value="<?= e(Flash::old('serial_number')) ?>">
                            <?php View::partial('field-errors', ['name' => 'serial_number']) ?>
                        </div>
                        <div class="col-6">
                            <label for="asset-purchased" class="form-label">Purchased on</label>
                            <input type="date" class="form-control" id="asset-purchased"
                                   name="purchased_on" value="<?= e(Flash::old('purchased_on')) ?>">
                            <?php View::partial('field-errors', ['name' => 'purchased_on']) ?>
                        </div>
                        <div class="col-6">
                            <label for="asset-value" class="form-label">Value</label>
                            <input type="text" inputmode="decimal"
                                   class="form-control <?= Flash::hasError('value') ? 'is-invalid' : '' ?>"
                                   id="asset-value" name="value" placeholder="85000.00"
                                   value="<?= e(Flash::old('value')) ?>">
                            <div class="form-text">The purchase amount, as written on the invoice.</div>
                            <?php View::partial('field-errors', ['name' => 'value']) ?>
                        </div>
                        <div class="col-6">
                            <label for="asset-condition" class="form-label">Condition</label>
                            <select class="form-select" id="asset-condition" name="condition">
                                <?php foreach ($conditions as $condition): ?>
                                    <option value="<?= e($condition) ?>"
                                        <?= (Flash::old('condition') === $condition
                                            || (Flash::old('condition') === '' && $condition === 'good')) ? 'selected' : '' ?>>
                                        <?= e(label($condition)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php View::partial('field-errors', ['name' => 'condition']) ?>
                        </div>
                        <div class="col-6">
                            <label for="asset-status" class="form-label">Status</label>
                            <select class="form-select" id="asset-status" name="status">
                                <?php foreach (['available', 'in_repair', 'retired', 'lost'] as $status): ?>
                                    <option value="<?= e($status) ?>"
                                        <?= (Flash::old('status') === $status
                                            || (Flash::old('status') === '' && $status === 'available')) ? 'selected' : '' ?>>
                                        <?= e(label($status)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Issuing an asset is a separate action with its own dates.</div>
                            <?php View::partial('field-errors', ['name' => 'status']) ?>
                        </div>
                    </div>

                    <div class="mt-3 mb-0">
                        <label for="asset-notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="asset-notes" name="notes"
                                  rows="2" maxlength="1000"><?= e(Flash::old('notes')) ?></textarea>
                        <?php View::partial('field-errors', ['name' => 'notes']) ?>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary btn-sm" data-busy-label="Saving...">
                        <i class="fa fa-plus"></i> Add asset
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
