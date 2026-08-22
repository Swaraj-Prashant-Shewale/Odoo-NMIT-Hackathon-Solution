<?php
/**
 * Standard page heading with an optional action area.
 *
 * @var string      $title
 * @var string|null $subtitle
 * @var string|null $actions Pre-rendered HTML for the right-hand side.
 */
?>
<div class="page-header d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="page-title"><?= e($title) ?></h1>
        <?php if (!empty($subtitle)): ?>
            <p class="page-subtitle mb-0"><?= e($subtitle) ?></p>
        <?php endif; ?>
    </div>
    <?php if (!empty($actions)): ?>
        <div class="d-flex flex-wrap gap-2"><?= $actions ?></div>
    <?php endif; ?>
</div>
