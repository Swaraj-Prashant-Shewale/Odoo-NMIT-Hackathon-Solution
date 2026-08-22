<?php
/**
 * New password form, reached from an emailed link.
 *
 * @var string $token
 */

use App\Core\Csrf;
use App\Core\View;
?>
<div class="auth-card">
    <h1 class="h4 fw-bold mb-1">Choose a new password</h1>
    <p class="text-muted small mb-4">
        Setting a new password signs you out everywhere else, on every device.
    </p>

    <form method="post" action="/reset-password" novalidate>
        <?= Csrf::field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="mb-3">
            <label for="password" class="form-label">New password</label>
            <div class="input-group">
                <input type="password" class="form-control" id="password" name="password"
                       autocomplete="new-password" required autofocus>
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
            <?php View::partial('field-errors', ['name' => 'password']) ?>
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Confirm new password</label>
            <input type="password" class="form-control"
                   id="password_confirmation" name="password_confirmation"
                   autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primary w-100" data-busy-label="Saving...">
            <i class="fa fa-key"></i> Set new password
        </button>
    </form>
</div>
