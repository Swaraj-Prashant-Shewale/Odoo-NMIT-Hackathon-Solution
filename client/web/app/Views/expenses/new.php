<?php
/**
 * Submit an expense claim.
 *
 * @var list<string>               $categories
 * @var string                     $approverName Empty when nobody is set as the manager.
 * @var list<array<string, mixed>> $documents    The caller's own uploaded documents.
 * @var string                     $currencySymbol
 * @var string                     $today
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
?>

<?php View::partial('page-header', [
    'title' => 'New expense claim',
    'subtitle' => 'Claim back money you spent on the company\'s behalf.',
    'actions' => '<a href="/expenses" class="btn btn-outline-secondary">'
        . '<i class="fa fa-arrow-left"></i> All claims</a>',
]) ?>

<div class="row g-4">

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="fa fa-receipt"></i> Claim details</div>
            <form method="post" action="/expenses/new" class="card-body">
                <?= Csrf::field() ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="category" class="form-label">Category</label>
                        <select class="form-select <?= Flash::hasError('category') ? 'is-invalid' : '' ?>"
                                id="category" name="category" required>
                            <option value="">Choose a category</option>
                            <?php foreach ($categories as $option): ?>
                                <option value="<?= e($option) ?>"
                                    <?= Flash::old('category') === $option ? 'selected' : '' ?>>
                                    <?= e(label($option)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php View::partial('field-errors', ['name' => 'category']) ?>
                    </div>

                    <div class="col-md-6">
                        <label for="incurred_on" class="form-label">Date incurred</label>
                        <input type="date"
                               class="form-control <?= Flash::hasError('incurred_on') ? 'is-invalid' : '' ?>"
                               id="incurred_on"
                               name="incurred_on"
                               max="<?= e($today) ?>"
                               value="<?= e(Flash::old('incurred_on')) ?>"
                               required>
                        <?php View::partial('field-errors', ['name' => 'incurred_on']) ?>
                        <div class="form-text">A claim cannot be dated in the future.</div>
                    </div>

                    <div class="col-md-8">
                        <label for="title" class="form-label">Title</label>
                        <input type="text"
                               class="form-control <?= Flash::hasError('title') ? 'is-invalid' : '' ?>"
                               id="title"
                               name="title"
                               maxlength="160"
                               value="<?= e(Flash::old('title')) ?>"
                               placeholder="Client visit to Pune, return train fare"
                               required>
                        <?php View::partial('field-errors', ['name' => 'title']) ?>
                    </div>

                    <div class="col-md-4">
                        <label for="amount" class="form-label">Amount</label>
                        <div class="input-group">
                            <span class="input-group-text"><?= e($currencySymbol) ?></span>
                            <input type="number"
                                   class="form-control <?= Flash::hasError('amount') ? 'is-invalid' : '' ?>"
                                   id="amount"
                                   name="amount"
                                   step="0.01"
                                   min="0.01"
                                   value="<?= e(Flash::old('amount')) ?>"
                                   required>
                        </div>
                        <?php View::partial('field-errors', ['name' => 'amount']) ?>
                    </div>

                    <div class="col-12">
                        <label for="description" class="form-label">
                            Description <span class="text-muted">(optional)</span>
                        </label>
                        <textarea class="form-control <?= Flash::hasError('description') ? 'is-invalid' : '' ?>"
                                  id="description"
                                  name="description"
                                  rows="4"
                                  maxlength="2000"
                                  placeholder="What the money was spent on, and why."><?= e(Flash::old('description')) ?></textarea>
                        <div class="form-text" data-counter-for="description"></div>
                        <?php View::partial('field-errors', ['name' => 'description']) ?>
                    </div>

                    <div class="col-12">
                        <label for="receipt_document_id" class="form-label">
                            Receipt <span class="text-muted">(optional)</span>
                        </label>
                        <?php if ($documents === []): ?>
                            <div class="form-text mb-0">
                                You have no documents on file to attach.
                                <a href="/profile/documents">Upload the receipt</a> first and it will be
                                selectable here.
                            </div>
                        <?php else: ?>
                            <select class="form-select <?= Flash::hasError('receipt_document_id') ? 'is-invalid' : '' ?>"
                                    id="receipt_document_id" name="receipt_document_id">
                                <option value="">No receipt attached</option>
                                <?php foreach ($documents as $document): ?>
                                    <?php $documentId = (string) ($document['id'] ?? ''); ?>
                                    <option value="<?= e($documentId) ?>"
                                        <?= Flash::old('receipt_document_id') === $documentId ? 'selected' : '' ?>>
                                        <?= field($document, 'title') ?>
                                        &middot; <?= e(label((string) ($document['category'] ?? ''))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Choose one of your uploaded documents. Anything else can be
                                <a href="/profile/documents">uploaded here</a> first.
                            </div>
                        <?php endif; ?>
                        <?php View::partial('field-errors', ['name' => 'receipt_document_id']) ?>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary" data-busy-label="Submitting...">
                        <i class="fa fa-paper-plane"></i> Submit claim
                    </button>
                    <a href="/expenses" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="fa fa-user-check"></i> Who decides this</div>
            <div class="card-body">
                <?php if ($approverName !== ''): ?>
                    <div class="d-flex align-items-center gap-3">
                        <span class="avatar avatar-lg"><?= e(initials($approverName)) ?></span>
                        <div class="truncate">
                            <div class="fw-semibold truncate"><?= e($approverName) ?></div>
                            <div class="small text-muted">Your manager</div>
                        </div>
                    </div>
                    <p class="small text-muted mt-3 mb-0">
                        The claim goes to them as soon as you submit it. They see the title, the amount and
                        anything you write in the description.
                    </p>
                <?php else: ?>
                    <div class="d-flex align-items-center gap-3">
                        <span class="avatar avatar-lg"><i class="fa fa-users"></i></span>
                        <div>
                            <div class="fw-semibold">Human resources</div>
                            <div class="small text-muted">No manager is set for you</div>
                        </div>
                    </div>
                    <p class="small text-muted mt-3 mb-0">
                        With no reporting line on your record, the claim is routed to HR so that it does not
                        sit in nobody's queue.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header"><i class="fa fa-circle-info"></i> Before you submit</div>
            <div class="card-body small text-muted">
                <ul class="mb-0 ps-3">
                    <li>Claim the amount you actually paid, in <?= e($currencySymbol) ?>.</li>
                    <li>The date incurred is the day of the spend, not the day you are claiming it.</li>
                    <li>Approved claims are paid back by finance against a payment reference.</li>
                    <li>Nobody can approve their own claim, whatever permissions they hold.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
