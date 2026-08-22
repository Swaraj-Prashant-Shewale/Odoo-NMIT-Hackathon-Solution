<?php
/**
 * Create a login account on somebody's behalf.
 *
 * @var list<array{value: string, label: string, description: string}> $grantable
 * @var string                                                        $callerRole
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use Dayflow\Kernel\Security\Roles;
?>

<?php View::partial('page-header', [
    'title' => 'New account',
    'subtitle' => 'Creates a sign-in account. The email address starts out confirmed, because you have already established who this person is.',
    'actions' => '<a href="/admin/users" class="btn btn-outline-secondary btn-sm">'
        . '<i class="fa fa-arrow-left"></i> Back to accounts</a>',
]) ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form method="post" action="/admin/users/new" novalidate>
            <?= Csrf::field() ?>

            <div class="card">
                <div class="card-header">Who the account is for</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="first_name" class="form-label">First name</label>
                            <input type="text"
                                   class="form-control <?= Flash::hasError('first_name') ? 'is-invalid' : '' ?>"
                                   id="first_name"
                                   name="first_name"
                                   value="<?= e(Flash::old('first_name')) ?>"
                                   maxlength="80"
                                   required>
                            <?php View::partial('field-errors', ['name' => 'first_name']) ?>
                        </div>

                        <div class="col-md-6">
                            <label for="last_name" class="form-label">Last name</label>
                            <input type="text"
                                   class="form-control <?= Flash::hasError('last_name') ? 'is-invalid' : '' ?>"
                                   id="last_name"
                                   name="last_name"
                                   value="<?= e(Flash::old('last_name')) ?>"
                                   maxlength="80"
                                   required>
                            <?php View::partial('field-errors', ['name' => 'last_name']) ?>
                        </div>

                        <div class="col-md-7">
                            <label for="email" class="form-label">Work email</label>
                            <input type="email"
                                   class="form-control <?= Flash::hasError('email') ? 'is-invalid' : '' ?>"
                                   id="email"
                                   name="email"
                                   value="<?= e(Flash::old('email')) ?>"
                                   maxlength="190"
                                   autocomplete="off"
                                   required>
                            <div class="form-text">This is what they sign in with, and it cannot be changed here afterwards.</div>
                            <?php View::partial('field-errors', ['name' => 'email']) ?>
                        </div>

                        <div class="col-md-5">
                            <label for="employee_code" class="form-label">Employee code <span class="text-muted">(optional)</span></label>
                            <input type="text"
                                   class="form-control <?= Flash::hasError('employee_code') ? 'is-invalid' : '' ?>"
                                   id="employee_code"
                                   name="employee_code"
                                   value="<?= e(Flash::old('employee_code')) ?>"
                                   placeholder="DF-0007"
                                   maxlength="20">
                            <?php View::partial('field-errors', ['name' => 'employee_code']) ?>
                        </div>

                        <div class="col-md-12">
                            <label for="employee_id" class="form-label">Linked employee record <span class="text-muted">(optional)</span></label>
                            <input type="text"
                                   class="form-control <?= Flash::hasError('employee_id') ? 'is-invalid' : '' ?>"
                                   id="employee_id"
                                   name="employee_id"
                                   value="<?= e(Flash::old('employee_id')) ?>"
                                   placeholder="The employee record identifier, if one already exists">
                            <div class="form-text">
                                Leave this blank when the person record has not been created yet. An account and an
                                employee record are deliberately separate, and either can exist without the other.
                            </div>
                            <?php View::partial('field-errors', ['name' => 'employee_id']) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">Role</div>
                <div class="card-body">
                    <p class="small text-muted">
                        Your own role is <?= e(Roles::label($callerRole)) ?>, so you may only create an account at
                        that level or below. Further roles can be added afterwards from the account list.
                    </p>

                    <?php foreach ($grantable as $role): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input"
                                   type="radio"
                                   name="role"
                                   value="<?= e($role['value']) ?>"
                                   id="role-<?= e($role['value']) ?>"
                                <?= (Flash::old('role') === $role['value']
                                    || (Flash::old('role') === '' && $role['value'] === Roles::EMPLOYEE)) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="role-<?= e($role['value']) ?>">
                                <span class="fw-semibold"><?= e($role['label']) ?></span>
                                <span class="d-block small text-muted"><?= e($role['description']) ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>

                    <?php View::partial('field-errors', ['name' => 'roles']) ?>
                    <?php View::partial('field-errors', ['name' => 'role']) ?>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">First password</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label for="password" class="form-label">Password <span class="text-muted">(optional)</span></label>
                            <div class="input-group">
                                <input type="password"
                                       class="form-control <?= Flash::hasError('password') ? 'is-invalid' : '' ?>"
                                       id="password"
                                       name="password"
                                       autocomplete="new-password">
                                <button class="btn btn-outline-secondary"
                                        type="button"
                                        data-toggle-password="password"
                                        aria-label="Show password">
                                    <i class="fa fa-eye"></i>
                                </button>
                            </div>
                            <?php View::partial('field-errors', ['name' => 'password']) ?>
                        </div>

                        <div class="col-md-5 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input"
                                       type="checkbox"
                                       id="must_change_password"
                                       name="must_change_password"
                                       value="1"
                                       checked>
                                <label class="form-check-label" for="must_change_password">
                                    Require a change at first sign-in
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 mb-0 d-flex gap-2">
                        <i class="fa fa-key mt-1"></i>
                        <div class="small">
                            Leave the password blank and one is generated for you. It is shown once, on the next
                            screen, so you can hand it over — it is never stored in the clear and never appears in
                            the audit trail. A generated password always has to be changed at first sign-in,
                            because it is a handover credential that you also know.
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-primary" data-busy-label="Creating...">
                        <i class="fa fa-user-plus"></i> Create account
                    </button>
                    <a href="/admin/users" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">What happens next</div>
            <div class="card-body">
                <div class="timeline">
                    <div class="timeline-item">
                        <div class="fw-semibold">The account is created verified</div>
                        <div class="small text-muted">
                            No verification email is sent, because an administrator has already confirmed who this
                            person is.
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="fw-semibold">You hand over the password</div>
                        <div class="small text-muted">
                            Either the one you typed, or the generated one shown on the next screen.
                        </div>
                    </div>
                    <div class="timeline-item is-muted">
                        <div class="fw-semibold">They choose their own</div>
                        <div class="small text-muted">
                            At first sign-in, so the credential you know stops working.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
