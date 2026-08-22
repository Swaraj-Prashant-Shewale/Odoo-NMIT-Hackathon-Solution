<?php
/**
 * The caller's own paperwork, and the form that adds to it.
 *
 * @var list<array<string, mixed>> $documents
 * @var array<string, mixed>       $meta
 * @var list<string>               $expiringIds
 * @var list<string>               $categories
 * @var array<string, string>      $filters
 * @var int                        $warningDays
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$statuses = ['pending', 'verified', 'rejected', 'expired'];

/** Human-sized file size; the service records exact bytes. */
$readableSize = static function (mixed $bytes): string {
    $size = (int) $bytes;

    if ($size <= 0) {
        return '—';
    }

    if ($size < 1024) {
        return $size . ' B';
    }

    if ($size < 1048576) {
        return number_format($size / 1024, 1) . ' KB';
    }

    return number_format($size / 1048576, 1) . ' MB';
};

View::partial('page-header', [
    'title' => 'My documents',
    'subtitle' => 'Identity, education and employment paperwork held against your record.',
]);
?>

<div class="row g-3">

    <div class="col-12 col-lg-8">

        <div class="card mb-3">
            <div class="card-body">
                <form method="get" action="/profile/documents" class="row g-2 align-items-end">
                    <div class="col-6 col-md-5">
                        <label for="category" class="form-label">Category</label>
                        <select class="form-select" id="category" name="category" data-submit-on-change>
                            <option value="">All categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= e($category) ?>"
                                    <?= ($filters['category'] ?? '') === $category ? 'selected' : '' ?>>
                                    <?= e(label($category)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6 col-md-5">
                        <label for="status" class="form-label">Verification</label>
                        <select class="form-select" id="status" name="status" data-submit-on-change>
                            <option value="">Any status</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= e($status) ?>"
                                    <?= ($filters['status'] ?? '') === $status ? 'selected' : '' ?>>
                                    <?= e(label($status)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-2 d-grid">
                        <a href="/profile/documents" class="btn btn-outline-secondary" title="Clear filters">
                            <i class="fa fa-undo"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($documents === []): ?>
            <div class="card">
                <div class="card-body">
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-folder-open',
                        'title' => 'Nothing here yet',
                        'message' => 'Upload your identity and education documents so HR can verify them.',
                    ]) ?>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fa fa-folder-open"></i> On file</span>
                    <span class="small text-muted"><?= e((string) ($meta['total'] ?? count($documents))) ?> documents</span>
                </div>
                <div class="table-wrap">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th>Document</th>
                            <th>Category</th>
                            <th>Issued</th>
                            <th>Expires</th>
                            <th>Status</th>
                            <th class="text-end">File</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($documents as $document): ?>
                            <?php $expiringSoon = in_array((string) ($document['id'] ?? ''), $expiringIds, true); ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= field($document, 'title', 'Untitled') ?></div>
                                    <div class="small text-muted truncate" style="max-width: 260px;">
                                        <?= field($document, 'original_filename') ?>
                                        &middot; <?= e($readableSize($document['size_bytes'] ?? 0)) ?>
                                    </div>
                                </td>
                                <td><?= e(label($document['category'] ?? null)) ?></td>
                                <td><?= e(date_display($document['issued_on'] ?? null)) ?></td>
                                <td>
                                    <?= e(date_display($document['expires_on'] ?? null, 'No expiry')) ?>
                                    <?php if ($expiringSoon): ?>
                                        <div class="small text-warning">
                                            <i class="fa fa-exclamation-triangle"></i>
                                            Expires <?= e(relative_time($document['expires_on'] ?? null)) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= badge($document['status'] ?? null) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="/profile/documents/<?= e($document['id'] ?? '') ?>/download">
                                        <i class="fa fa-download"></i> Download
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php View::partial('pagination', ['meta' => $meta]) ?>
        <?php endif; ?>

    </div>

    <div class="col-12 col-lg-4">
        <div class="card">
            <div class="card-header"><i class="fa fa-upload"></i> Upload a document</div>
            <div class="card-body">
                <form method="post" action="/profile/documents" enctype="multipart/form-data" novalidate>
                    <?= Csrf::field() ?>

                    <div class="mb-3">
                        <label for="upload_category" class="form-label">Category</label>
                        <select class="form-select<?= Flash::hasError('category') ? ' is-invalid' : '' ?>"
                                id="upload_category" name="category" required>
                            <option value="">Choose a category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= e($category) ?>"
                                    <?= Flash::old('category') === $category ? 'selected' : '' ?>>
                                    <?= e(label($category)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php View::partial('field-errors', ['name' => 'category']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="title" class="form-label">Title</label>
                        <input type="text" class="form-control<?= Flash::hasError('title') ? ' is-invalid' : '' ?>"
                               id="title" name="title" maxlength="150" required
                               value="<?= e(Flash::old('title')) ?>"
                               placeholder="Passport, degree certificate, tenancy agreement">
                        <?php View::partial('field-errors', ['name' => 'title']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="file" class="form-label">File</label>
                        <input type="file" class="form-control<?= Flash::hasError('file') ? ' is-invalid' : '' ?>"
                               id="file" name="file" required
                               accept=".pdf,.jpg,.jpeg,.png,.docx">
                        <div class="form-text">PDF, JPG, PNG or DOCX, up to 5&nbsp;MB.</div>
                        <?php View::partial('field-errors', ['name' => 'file']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="issued_on" class="form-label">Issued on</label>
                        <input type="date" class="form-control<?= Flash::hasError('issued_on') ? ' is-invalid' : '' ?>"
                               id="issued_on" name="issued_on" value="<?= e(Flash::old('issued_on')) ?>"
                               data-range-start="expires_on">
                        <?php View::partial('field-errors', ['name' => 'issued_on']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="expires_on" class="form-label">Expires on</label>
                        <input type="date" class="form-control<?= Flash::hasError('expires_on') ? ' is-invalid' : '' ?>"
                               id="expires_on" name="expires_on" value="<?= e(Flash::old('expires_on')) ?>">
                        <div class="form-text">
                            Leave empty for a document that does not expire. Anything falling due within
                            <?= e((string) $warningDays) ?> days is flagged for you here.
                        </div>
                        <?php View::partial('field-errors', ['name' => 'expires_on']) ?>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" data-busy-label="Uploading...">
                        <i class="fa fa-upload"></i> Upload
                    </button>
                </form>
            </div>
            <div class="card-footer text-muted">
                Uploads arrive as "pending" and stay that way until HR verifies them. Files are stored outside
                the web root and can only be read back through this page.
            </div>
        </div>
    </div>

</div>
