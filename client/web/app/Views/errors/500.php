<?php
/**
 * Unexpected failure.
 *
 * @var string|null $detail Present only while APP_DEBUG is on.
 */
?>
<div class="text-center py-5">
    <div class="empty-state-icon mx-auto"><i class="fa fa-exclamation-triangle"></i></div>
    <h1 class="h4 mt-3 mb-2">Something went wrong</h1>
    <p class="text-muted mb-4">
        The problem has been recorded. Please try again, and let an administrator
        know if it keeps happening.
    </p>

    <?php if (!empty($detail)): ?>
        <div class="alert alert-warning text-start small mx-auto" style="max-width: 760px;">
            <strong>Development detail</strong><br>
            <code><?= e($detail) ?></code>
        </div>
    <?php endif; ?>

    <a href="/" class="btn btn-primary btn-sm"><i class="fa fa-home"></i> Back to dashboard</a>
</div>
