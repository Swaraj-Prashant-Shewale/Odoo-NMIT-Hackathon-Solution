<?php
/**
 * Shown when a signed-in account has no person record behind it.
 *
 * The two identifiers are deliberately separate: an account can exist before
 * onboarding has created the employee record. That is a normal state on a
 * first day rather than a fault, so it is explained rather than reported as an
 * error.
 */

use App\Core\View;

View::partial('page-header', [
    'title' => 'My profile',
]);
?>

<div class="card">
    <div class="card-body">
        <?php View::partial('empty-state', [
            'icon' => 'fa-id-card',
            'title' => 'Your employee record is not ready yet',
            'message' => 'Your sign-in account exists, but HR has not finished creating the person record '
                . 'behind it. Your profile, documents and salary details will appear here as soon as they do.',
            'actionLabel' => 'Open the company directory',
            'actionHref' => '/directory',
        ]) ?>

        <div class="mx-auto" style="max-width: 520px;">
            <div class="section-label">In the meantime</div>
            <p class="small text-muted mb-0">
                You can still change your password and review your active sessions on the
                <a href="/profile/security">security page</a>. If this has not resolved itself by the end of
                your first day, ask HR to check that your account is linked to your employee record.
            </p>
        </div>
    </div>
</div>
