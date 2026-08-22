<?php
/**
 * Registration form.
 *
 * @var list<array{value: string, label: string, description: string}> $roles
 */

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
?>
<div class="auth-card">
    <h1 class="h4 fw-bold mb-1">Create your account</h1>
    <p class="text-muted small mb-4">It takes a minute. You will verify your email before signing in.</p>

    <form method="post" action="/register" novalidate>
        <?= Csrf::field() ?>

        <div class="row g-2 mb-3">
            <div class="col-6">
                <label for="first_name" class="form-label">First name</label>
                <input type="text"
                       class="form-control <?= Flash::hasError('first_name') ? 'is-invalid' : '' ?>"
                       id="first_name" name="first_name"
                       value="<?= e(Flash::old('first_name')) ?>"
                       maxlength="60" required autofocus>
                <?php View::partial('field-errors', ['name' => 'first_name']) ?>
            </div>
            <div class="col-6">
                <label for="last_name" class="form-label">Last name</label>
                <input type="text"
                       class="form-control <?= Flash::hasError('last_name') ? 'is-invalid' : '' ?>"
                       id="last_name" name="last_name"
                       value="<?= e(Flash::old('last_name')) ?>"
                       maxlength="60" required>
                <?php View::partial('field-errors', ['name' => 'last_name']) ?>
            </div>
        </div>

        <div class="mb-3">
            <label for="email" class="form-label">Work email</label>
            <input type="email"
                   class="form-control <?= Flash::hasError('email') ? 'is-invalid' : '' ?>"
                   id="email" name="email"
                   value="<?= e(Flash::old('email')) ?>"
                   placeholder="you@company.com"
                   autocomplete="username" required>
            <?php View::partial('field-errors', ['name' => 'email']) ?>
        </div>

        <div class="mb-3">
            <label for="employee_code" class="form-label">
                Employee ID <span class="text-muted fw-normal">(optional)</span>
            </label>
            <input type="text"
                   class="form-control <?= Flash::hasError('employee_code') ? 'is-invalid' : '' ?>"
                   id="employee_code" name="employee_code"
                   value="<?= e(Flash::old('employee_code')) ?>"
                   placeholder="DF-0042" maxlength="20">
            <div class="form-text">If HR has already given you one, enter it to link your records.</div>
            <?php View::partial('field-errors', ['name' => 'employee_code']) ?>
        </div>

        <div class="mb-3">
            <label for="role" class="form-label">Account type</label>
            <select class="form-select" id="role" name="role">
                <?php foreach ($roles as $role): ?>
                    <option value="<?= e($role['value']) ?>" <?= Flash::old('role') === $role['value'] ? 'selected' : '' ?>>
                        <?= e($role['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">
                Administrator access is granted by an existing administrator, never chosen here.
            </div>
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <div class="input-group">
                <input type="password"
                       class="form-control <?= Flash::hasError('password') ? 'is-invalid' : '' ?>"
                       id="password" name="password"
                       autocomplete="new-password" required>
                <button class="btn btn-outline-secondary" type="button"
                        data-toggle-password="password" aria-label="Show password">
                    <i class="fa fa-eye"></i>
                </button>
            </div>
            <div class="d-flex align-items-center gap-2 mt-2">
                <div class="meter flex-grow-1" data-strength-for="password">
                    <span></span><span></span><span></span><span></span>
                </div>
                <small class="text-muted" data-strength-label></small>
            </div>
            <div class="form-text">
                At least 10 characters, with upper and lower case letters and a number.
            </div>
            <?php View::partial('field-errors', ['name' => 'password']) ?>
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Confirm password</label>
            <input type="password" class="form-control"
                   id="password_confirmation" name="password_confirmation"
                   autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primary w-100" data-busy-label="Creating account...">
            <i class="fa fa-user-plus"></i> Create account
        </button>
    </form>

    <hr class="my-4">

    <p class="text-center small mb-0 text-muted">
        Already have an account? <a href="/login">Sign in</a>
    </p>
</div>
