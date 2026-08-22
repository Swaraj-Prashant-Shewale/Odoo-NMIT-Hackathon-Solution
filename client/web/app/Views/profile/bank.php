<?php
/**
 * Salary bank details.
 *
 * The account number is written once and encrypted. Payroll-service never
 * returns it to anybody — not to HR, not to finance, not to the person who
 * entered it — so this page shows the last four digits and says plainly that
 * the rest cannot be retrieved. Replacing the details means typing the whole
 * number again, which is a property of the design rather than a limitation to
 * apologise for.
 *
 * @var array<string, mixed> $account
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

$onFile = !empty($account['has_bank_account']);

View::partial('page-header', [
    'title' => 'Salary bank details',
    'subtitle' => 'Where your salary is paid.',
]);
?>

<div class="row g-3">

    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-university"></i> On file</div>
            <div class="card-body">
                <?php if (!$onFile): ?>
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-university',
                        'title' => 'No account recorded',
                        'message' => 'Add your details so payroll has somewhere to send your salary.',
                    ]) ?>
                <?php else: ?>
                    <div class="stat-row">
                        <span class="stat-key">Account holder</span>
                        <span class="stat-val"><?= field($account, 'account_holder_name') ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Bank</span>
                        <span class="stat-val"><?= field($account, 'bank_name') ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Account number</span>
                        <span class="stat-val tabular"><?= field($account, 'account_number_masked') ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">IFSC</span>
                        <span class="stat-val tabular"><?= field($account, 'ifsc_code') ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Tax identifier</span>
                        <span class="stat-val tabular">
                            <?php if (!empty($account['tax_identifier_last4'])): ?>
                                ******<?= e($account['tax_identifier_last4']) ?>
                            <?php else: ?>
                                Not recorded
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Confirmed by finance</span>
                        <span class="stat-val">
                            <?php if (!empty($account['verified_at'])): ?>
                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                    <?= e(date_display($account['verified_at'])) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                    Awaiting confirmation
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Last changed</span>
                        <span class="stat-val"><?= e(datetime_display($account['updated_at'] ?? null)) ?></span>
                    </div>

                    <p class="small text-muted mt-3 mb-0">
                        Only the last four digits are ever shown. The full number is encrypted at rest and is
                        not readable through this application by anyone, including HR and finance.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header">
                <i class="fa fa-pen"></i> <?= $onFile ? 'Replace these details' : 'Add your details' ?>
            </div>
            <div class="card-body">
                <form method="post" action="/profile/bank" novalidate autocomplete="off">
                    <?= Csrf::field() ?>

                    <div class="mb-3">
                        <label for="account_holder_name" class="form-label">Account holder name</label>
                        <input type="text"
                               class="form-control<?= Flash::hasError('account_holder_name') ? ' is-invalid' : '' ?>"
                               id="account_holder_name" name="account_holder_name" maxlength="120" required
                               value="<?= e(Flash::old('account_holder_name', (string) ($account['account_holder_name'] ?? ''))) ?>">
                        <div class="form-text">Exactly as the bank holds it, or the transfer will be returned.</div>
                        <?php View::partial('field-errors', ['name' => 'account_holder_name']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="bank_name" class="form-label">Bank name</label>
                        <input type="text"
                               class="form-control<?= Flash::hasError('bank_name') ? ' is-invalid' : '' ?>"
                               id="bank_name" name="bank_name" maxlength="120" required
                               value="<?= e(Flash::old('bank_name', (string) ($account['bank_name'] ?? ''))) ?>">
                        <?php View::partial('field-errors', ['name' => 'bank_name']) ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="account_number" class="form-label">Account number</label>
                            <div class="input-group">
                                <input type="password"
                                       class="form-control tabular<?= Flash::hasError('account_number') ? ' is-invalid' : '' ?>"
                                       id="account_number" name="account_number" required
                                       inputmode="numeric" autocomplete="off"
                                       placeholder="<?= $onFile ? 'Type the full number again' : '6 to 20 digits' ?>">
                                <button class="btn btn-outline-secondary" type="button"
                                        data-toggle-password="account_number" aria-label="Show account number">
                                    <i class="fa fa-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">6 to 20 digits, no spaces.</div>
                            <?php View::partial('field-errors', ['name' => 'account_number']) ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="ifsc_code" class="form-label">IFSC code</label>
                            <input type="text"
                                   class="form-control tabular<?= Flash::hasError('ifsc_code') ? ' is-invalid' : '' ?>"
                                   id="ifsc_code" name="ifsc_code" maxlength="11" required
                                   value="<?= e(Flash::old('ifsc_code', (string) ($account['ifsc_code'] ?? ''))) ?>"
                                   placeholder="HDFC0001234">
                            <div class="form-text">Four letters, a zero, then six characters.</div>
                            <?php View::partial('field-errors', ['name' => 'ifsc_code']) ?>
                        </div>
                    </div>

                    <div class="mt-3 mb-3">
                        <label for="tax_identifier" class="form-label">Tax identifier <span class="text-muted">(optional)</span></label>
                        <input type="text"
                               class="form-control tabular<?= Flash::hasError('tax_identifier') ? ' is-invalid' : '' ?>"
                               id="tax_identifier" name="tax_identifier" maxlength="20"
                               value="<?= e(Flash::old('tax_identifier')) ?>"
                               placeholder="10 to 20 letters and numbers">
                        <div class="form-text">
                            Stored encrypted in the same way. Only the last four characters are shown afterwards.
                        </div>
                        <?php View::partial('field-errors', ['name' => 'tax_identifier']) ?>
                    </div>

                    <div class="alert alert-info d-flex gap-2 align-items-start">
                        <i class="fa fa-lock mt-1"></i>
                        <div>
                            These details are encrypted before they are stored and are used for one purpose:
                            paying your salary. Saving them again resets the finance confirmation, so a new
                            account is checked before the next pay run uses it.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" data-busy-label="Saving...">
                        <i class="fa fa-check"></i> <?= $onFile ? 'Replace details' : 'Save details' ?>
                    </button>
                    <a href="/profile" class="btn btn-outline-secondary">Back to my profile</a>
                </form>
            </div>
        </div>
    </div>

</div>
