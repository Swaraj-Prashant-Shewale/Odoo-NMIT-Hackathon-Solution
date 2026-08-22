<?php
/**
 * Verification reminder screen.
 *
 * @var string $email
 */

use App\Core\Csrf;
use Dayflow\Kernel\Support\Env;
?>
<div class="auth-card text-center">
    <div class="tile-icon mx-auto"><i class="fa fa-envelope-open-text"></i></div>

    <h1 class="h4 fw-bold mb-1">Check your email</h1>
    <p class="text-muted small mb-4">
        We have sent a verification link to your address. Open it to activate your account,
        then sign in.
    </p>

    <?php if (Env::get('MAIL_DRIVER', 'log') === 'log'): ?>
        <div class="alert alert-info text-start small">
            <strong><i class="fa fa-flask"></i> Development mode.</strong>
            Email is not actually delivered while <code>MAIL_DRIVER</code> is <code>log</code>.
            An administrator can read every message, including this verification link,
            in the development inbox at <code>/mailbox</code>.
        </div>
    <?php endif; ?>

    <form method="post" action="/resend-verification" class="text-start">
        <?= Csrf::field() ?>

        <div class="mb-3">
            <label for="email" class="form-label">Email address</label>
            <input type="email" class="form-control" id="email" name="email"
                   value="<?= e($email) ?>" placeholder="you@company.com" required>
        </div>

        <button type="submit" class="btn btn-outline-primary w-100" data-busy-label="Sending...">
            <i class="fa fa-redo"></i> Send the link again
        </button>
    </form>

    <hr class="my-4">

    <p class="small mb-0 text-muted"><a href="/login">Back to sign in</a></p>
</div>
