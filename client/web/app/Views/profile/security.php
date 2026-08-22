<?php
/**
 * Account security: password, live sessions, and what the account can do.
 *
 * The password rules shown here are read from identity-service rather than
 * written out again, so the page cannot drift away from what the server
 * actually enforces.
 *
 * @var array<string, mixed>       $account
 * @var list<array<string, mixed>> $sessions
 * @var string|null                $currentSessionId
 * @var array<string, mixed>       $policy
 */

use App\Core\Csrf;
use App\Core\View;

$requirements = is_array($policy['requirements'] ?? null) ? $policy['requirements'] : [];
$roles = is_array($account['roles'] ?? null) ? $account['roles'] : [];

/**
 * A recognisable name for a browser, from the string it sent.
 *
 * Only ever used as a label; the full string is shown underneath so nothing is
 * hidden by the simplification.
 */
$deviceName = static function (?string $userAgent): string {
    $agent = (string) $userAgent;

    if ($agent === '') {
        return 'Unknown device';
    }

    $browser = match (true) {
        str_contains($agent, 'Edg/') => 'Edge',
        str_contains($agent, 'OPR/') => 'Opera',
        str_contains($agent, 'Firefox/') => 'Firefox',
        str_contains($agent, 'Chrome/') => 'Chrome',
        str_contains($agent, 'Safari/') => 'Safari',
        default => 'Browser',
    };

    $platform = match (true) {
        str_contains($agent, 'Windows') => 'Windows',
        str_contains($agent, 'Android') => 'Android',
        str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
        str_contains($agent, 'Mac OS X') => 'macOS',
        str_contains($agent, 'Linux') => 'Linux',
        default => 'unknown platform',
    };

    return $browser . ' on ' . $platform;
};

View::partial('page-header', [
    'title' => 'Security',
    'subtitle' => 'Your password, the devices you are signed in on, and what this account can do.',
]);
?>

<div class="row g-3">

    <div class="col-12 col-lg-7">

        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-key"></i> Change your password</div>
            <div class="card-body">
                <form method="post" action="/profile/security/password" novalidate>
                    <?= Csrf::field() ?>

                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-lock"></i></span>
                            <input type="password" class="form-control" id="current_password"
                                   name="current_password" autocomplete="current-password" required>
                            <button class="btn btn-outline-secondary" type="button"
                                    data-toggle-password="current_password" aria-label="Show password">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>
                        <?php View::partial('field-errors', ['name' => 'current_password']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">New password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-key"></i></span>
                            <input type="password" class="form-control" id="password"
                                   name="password" autocomplete="new-password" required>
                            <button class="btn btn-outline-secondary" type="button"
                                    data-toggle-password="password" aria-label="Show password">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>

                        <div class="mt-2">
                            <div class="meter" data-strength-for="password">
                                <span></span><span></span><span></span><span></span><span></span>
                            </div>
                            <div class="form-text" data-strength-label></div>
                        </div>

                        <?php View::partial('field-errors', ['name' => 'password']) ?>
                    </div>

                    <div class="mb-3">
                        <label for="password_confirmation" class="form-label">Repeat the new password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-key"></i></span>
                            <input type="password" class="form-control" id="password_confirmation"
                                   name="password_confirmation" autocomplete="new-password" required>
                        </div>
                        <?php View::partial('field-errors', ['name' => 'password_confirmation']) ?>
                    </div>

                    <button type="submit" class="btn btn-primary" data-busy-label="Changing...">
                        <i class="fa fa-check"></i> Change password
                    </button>
                </form>
            </div>
            <div class="card-footer">
                <div class="section-label">What the server requires</div>
                <?php if ($requirements === []): ?>
                    <p class="small text-muted mb-0">
                        Use at least
                        <?= e((string) ($policy['min_length'] ?? 12)) ?> characters, mixing upper and lower
                        case, a number and a symbol.
                    </p>
                <?php else: ?>
                    <ul class="small text-muted mb-0 ps-3">
                        <?php foreach ($requirements as $requirement): ?>
                            <li><?= e($requirement) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p class="small text-muted mt-2 mb-0">
                    Changing your password ends every other session. This browser stays signed in.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-desktop"></i> Where you are signed in</span>
                <span class="small text-muted"><?= e((string) count($sessions)) ?> active</span>
            </div>

            <?php if ($sessions === []): ?>
                <div class="card-body">
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-desktop',
                        'title' => 'No other sessions',
                        'message' => 'Nothing else is holding a signed-in session for this account.',
                    ]) ?>
                </div>
            <?php else: ?>
                <div class="card-body pb-0">
                    <?php foreach ($sessions as $session): ?>
                        <?php $isCurrent = $currentSessionId !== null
                            && $currentSessionId === (string) ($session['id'] ?? ''); ?>
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 pb-3 mb-3 border-bottom">
                            <div class="d-flex gap-3">
                                <span class="avatar"><i class="fa fa-desktop"></i></span>
                                <div>
                                    <div class="fw-semibold">
                                        <?= e($deviceName($session['user_agent'] ?? null)) ?>
                                        <?php if ($isCurrent): ?>
                                            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                                This device
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="fa fa-network-wired"></i>
                                        <?= field($session, 'ip_address', 'Address not recorded') ?>
                                    </div>
                                    <div class="small text-muted">
                                        Signed in <?= e(datetime_display($session['started_at'] ?? null)) ?>
                                        &middot; last used <?= e(relative_time($session['last_used_at'] ?? null)) ?>
                                    </div>
                                    <div class="small text-muted truncate" style="max-width: 420px;">
                                        <?= field($session, 'user_agent', 'No browser signature') ?>
                                    </div>
                                </div>
                            </div>

                            <?php if (!$isCurrent): ?>
                                <form method="post"
                                      action="/profile/security/sessions/<?= e($session['id'] ?? '') ?>/revoke"
                                      class="m-0">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            data-confirm="End this session? That device will have to sign in again."
                                            data-busy-label="Ending...">
                                        <i class="fa fa-times"></i> Revoke
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="card-footer text-muted">
                    "This device" is matched on the browser signature this page was requested with. If you do
                    not recognise a session, end it and change your password.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-id-card"></i> This account</div>
            <div class="card-body">
                <div class="stat-row">
                    <span class="stat-key">Sign-in email</span>
                    <span class="stat-val"><?= field($account, 'email') ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Email verified</span>
                    <span class="stat-val">
                        <?php if (!empty($account['is_verified'])): ?>
                            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                Verified <?= e(date_display($account['email_verified_at'] ?? null, '')) ?>
                            </span>
                        <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                Not verified
                            </span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Account state</span>
                    <span class="stat-val">
                        <?php if (!empty($account['is_locked'])): ?>
                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                                Temporarily locked
                            </span>
                        <?php elseif (!empty($account['is_active'])): ?>
                            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                Active
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                Disabled
                            </span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Last sign-in</span>
                    <span class="stat-val"><?= e(datetime_display($account['last_login_at'] ?? null, 'This is your first')) ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">From</span>
                    <span class="stat-val"><?= field($account, 'last_login_ip', 'Not recorded') ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-key">Employee record</span>
                    <span class="stat-val tabular"><?= field($account, 'employee_code', 'Not linked') ?></span>
                </div>

                <div class="section-label mt-4">Roles held</div>
                <?php if ($roles === []): ?>
                    <p class="small text-muted mb-0">No roles are assigned to this account.</p>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($roles as $role): ?>
                            <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                                <?= e(label(is_string($role) ? $role : '')) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer text-muted">
                Roles decide what you can see across the platform. Only an administrator can change them.
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fa fa-shield-alt"></i> How your session is held</div>
            <div class="card-body">
                <ul class="small text-muted mb-0 ps-3">
                    <li>Your access token never reaches this page or your browser. It is kept on the server
                        and attached to each request on your behalf.</li>
                    <li>An idle session ends by itself, and every session has a maximum length regardless of
                        how much it is used.</li>
                    <li>Signing in from a new device starts a separate session, which is why more than one
                        can be listed here.</li>
                </ul>
            </div>
        </div>
    </div>

</div>
