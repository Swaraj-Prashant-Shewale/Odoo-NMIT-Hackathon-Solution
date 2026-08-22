<?php
/**
 * Page breadcrumbs.
 *
 * @var list<array{0: string, 1: string}> $breadcrumbs Label and path pairs.
 */
?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb dayflow-crumbs">
        <li class="breadcrumb-item"><a href="/"><i class="fa fa-home"></i></a></li>
        <?php foreach ($breadcrumbs as $index => $crumb): ?>
            <?php $isLast = $index === array_key_last($breadcrumbs); ?>
            <li class="breadcrumb-item <?= $isLast ? 'active' : '' ?>" <?= $isLast ? 'aria-current="page"' : '' ?>>
                <?php if ($isLast || empty($crumb[1])): ?>
                    <?= e($crumb[0]) ?>
                <?php else: ?>
                    <a href="<?= e($crumb[1]) ?>"><?= e($crumb[0]) ?></a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
