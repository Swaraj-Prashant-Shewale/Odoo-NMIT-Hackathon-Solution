<?php
/**
 * Shown in place of a table or list when there is nothing to display.
 *
 * @var string      $icon
 * @var string      $title
 * @var string|null $message
 * @var string|null $actionLabel
 * @var string|null $actionHref
 */
?>
<div class="empty-state text-center py-5">
    <div class="empty-state-icon"><i class="fa <?= e($icon ?? 'fa-inbox') ?>"></i></div>
    <h5 class="mt-3 mb-1"><?= e($title) ?></h5>
    <?php if (!empty($message)): ?>
        <p class="text-muted mb-3"><?= e($message) ?></p>
    <?php endif; ?>
    <?php if (!empty($actionLabel) && !empty($actionHref)): ?>
        <a href="<?= e($actionHref) ?>" class="btn btn-primary btn-sm">
            <i class="fa fa-plus"></i> <?= e($actionLabel) ?>
        </a>
    <?php endif; ?>
</div>
