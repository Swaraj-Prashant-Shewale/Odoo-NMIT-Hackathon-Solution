<?php
/**
 * A page whose data could not be loaded.
 *
 * Shown in place of the page that failed rather than as a redirect, so the
 * address bar still says which page it was and a reload retries that page
 * instead of quietly landing somewhere else.
 *
 * @var int    $status
 * @var string $message
 */

$throttled = ($status ?? 0) === 429;
?>
<div class="text-center py-5">
    <div class="empty-state-icon mx-auto">
        <i class="fa <?= $throttled ? 'fa-hourglass-half' : 'fa-plug-circle-exclamation' ?>"></i>
    </div>

    <h1 class="h4 mt-3 mb-2">
        <?= $throttled ? 'Just a moment' : 'This page could not be loaded' ?>
    </h1>

    <p class="text-muted mb-4 mx-auto" style="max-width: 34rem;">
        <?= e($message ?? 'That could not be loaded.') ?>
    </p>

    <div class="d-flex gap-2 justify-content-center flex-wrap">
        <button type="button" class="btn btn-primary btn-sm" onclick="window.location.reload()">
            <i class="fa fa-rotate-right"></i> Try again
        </button>
        <a href="/" class="btn btn-outline-secondary btn-sm">
            <i class="fa fa-home"></i> Back to dashboard
        </a>
    </div>
</div>
