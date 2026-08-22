<?php
/**
 * The handful of things people come here to do.
 *
 * Every tile is already filtered by permission in the controller, so nothing
 * here leads to a page that would refuse the person who clicked it.
 *
 * @var list<array{label: string, href: string, icon: string}> $quickActions
 */

if ($quickActions === []) {
    return;
}
?>

<div class="section-label mt-4">Quick actions</div>

<div class="row g-3">
    <?php foreach ($quickActions as $action): ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a class="tile text-decoration-none" href="<?= e($action['href']) ?>">
                <div class="tile-icon"><i class="fa <?= e($action['icon']) ?>"></i></div>
                <div class="fw-semibold"><?= e($action['label']) ?></div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
