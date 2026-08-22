<?php
/**
 * One person's paperwork, seen by somebody who holds document.view.all.
 *
 * Files are never linked by path. The download route re-checks the caller
 * against the row that owns the file every single time, so the link below is
 * an instruction to employee-service rather than a location on disk.
 *
 * @var array<string, mixed>       $employee
 * @var list<array<string, mixed>> $documents
 * @var array<string, mixed>       $meta
 * @var array<string, string>      $filters
 */

use App\Core\View;

$id = (string) ($employee['id'] ?? '');
$categories = ['identity', 'address', 'education', 'employment', 'contract', 'payroll', 'medical', 'photo', 'other'];
$statuses = ['pending', 'verified', 'rejected', 'expired'];

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

$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+30 days'));

ob_start(); ?>
<a href="/people/<?= e($id) ?>" class="btn btn-outline-secondary">
    <i class="fa fa-user"></i> Back to the record
</a>
<?php $actions = (string) ob_get_clean();

View::partial('page-header', [
    'title' => 'Documents',
    'subtitle' => (string) ($employee['full_name'] ?? 'Employee')
        . ' · ' . (string) ($employee['employee_code'] ?? ''),
    'actions' => $actions,
]);
?>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="/people/<?= e($id) ?>/documents" class="row g-2 align-items-end">
            <div class="col-6 col-lg-4">
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

            <div class="col-6 col-lg-4">
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

            <div class="col-12 col-lg-2 d-grid">
                <a href="/people/<?= e($id) ?>/documents" class="btn btn-outline-secondary" title="Clear filters">
                    <i class="fa fa-undo"></i> Clear
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
                'title' => 'No documents match',
                'message' => 'Nothing has been uploaded against this record under those filters.',
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
                    <th>Uploaded</th>
                    <th>Status</th>
                    <th class="text-end">File</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $document): ?>
                    <?php
                    $expiresOn = (string) ($document['expires_on'] ?? '');
                    $expiringSoon = $expiresOn !== '' && $expiresOn >= $today && $expiresOn <= $soon;
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= field($document, 'title', 'Untitled') ?></div>
                            <div class="small text-muted truncate" style="max-width: 280px;">
                                <?= field($document, 'original_filename') ?>
                                &middot; <?= e($readableSize($document['size_bytes'] ?? 0)) ?>
                            </div>
                        </td>
                        <td><?= e(label($document['category'] ?? null)) ?></td>
                        <td class="tabular"><?= e(date_display($document['issued_on'] ?? null)) ?></td>
                        <td class="tabular">
                            <?= e(date_display($document['expires_on'] ?? null, 'No expiry')) ?>
                            <?php if ($expiringSoon): ?>
                                <div class="small text-warning">
                                    <i class="fa fa-exclamation-triangle"></i>
                                    <?= e(relative_time($document['expires_on'] ?? null)) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="tabular"><?= e(date_display($document['created_at'] ?? null)) ?></td>
                        <td>
                            <?= badge($document['status'] ?? null) ?>
                            <?php if (!empty($document['verified_at'])): ?>
                                <div class="small text-muted">
                                    Checked <?= e(relative_time($document['verified_at'])) ?>
                                </div>
                            <?php endif; ?>
                        </td>
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
        <div class="card-footer text-muted">
            "Pending" means nobody has checked the document yet; "expired" is applied automatically once the
            expiry date passes. Every download is written to the audit trail with who took it and when.
        </div>
    </div>

    <?php View::partial('pagination', ['meta' => $meta]) ?>
<?php endif; ?>
