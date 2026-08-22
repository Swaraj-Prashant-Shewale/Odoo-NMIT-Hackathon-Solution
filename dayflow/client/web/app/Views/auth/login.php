<?php
/** Sign-in form. */

use App\Core\Csrf;
use App\Core\Flash;
?>
<div class="auth-card">
    <h1 class="h4 fw-bold mb-1">Sign in</h1>
    <p class="text-muted small mb-4">Welcome back. Enter your details to continue.</p>

    <form method="post" action="/login" novalidate>
        <?= Csrf::field() ?>

        <div class="mb-3">
            <label for="email" class="form-label">Work email</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fa fa-envelope"></i></span>
                <input type="email"
                       class="form-control"
                       id="email"
                       name="email"
                       value="<?= e(Flash::old('email')) ?>"
                       placeholder="you@company.com"
                       autocomplete="username"
                       required
                       autofocus>
            </div>
        </div>

        <div class="mb-2">
            <div class="d-flex justify-content-between align-items-baseline">
                <label for="password" class="form-label">Password</label>
                <a href="/forgot-password" class="small">Forgot password?</a>
            </div>
            <div class="input-group">
                <span class="input-group-text"><i class="fa fa-lock"></i></span>
                <input type="password"
                       class="form-control"
                       id="password"
                       name="password"
                       placeholder="Your password"
                       autocomplete="current-password"
                       required>
                <button class="btn btn-outline-secondary"
                        type="button"
                        data-toggle-password="password"
                        aria-label="Show password">
                    <i class="fa fa-eye"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 mt-3" data-busy-label="Signing in...">
            <i class="fa fa-sign-in-alt"></i> Sign in
        </button>
    </form>

    <hr class="my-4">

    <p class="text-center small mb-0 text-muted">
        No account yet? <a href="/register">Create one</a>
    </p>
</div>
