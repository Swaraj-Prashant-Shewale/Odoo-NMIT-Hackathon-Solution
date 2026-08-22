<?php
/** Password recovery request form. */

use App\Core\Csrf;
use App\Core\Flash;
?>
<div class="auth-card">
    <h1 class="h4 fw-bold mb-1">Reset your password</h1>
    <p class="text-muted small mb-4">
        Enter the email address on your account and we will send you a link to set a new password.
    </p>

    <form method="post" action="/forgot-password" novalidate>
        <?= Csrf::field() ?>

        <div class="mb-3">
            <label for="email" class="form-label">Work email</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fa fa-envelope"></i></span>
                <input type="email" class="form-control" id="email" name="email"
                       value="<?= e(Flash::old('email')) ?>"
                       placeholder="you@company.com" autocomplete="username" required autofocus>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100" data-busy-label="Sending...">
            <i class="fa fa-paper-plane"></i> Send reset link
        </button>
    </form>

    <hr class="my-4">

    <p class="text-center small mb-0 text-muted">
        Remembered it? <a href="/login">Back to sign in</a>
    </p>
</div>
