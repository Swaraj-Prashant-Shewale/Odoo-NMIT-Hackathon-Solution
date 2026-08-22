<?php
/**
 * The signed-in application shell.
 *
 * @var string $content     The rendered page body.
 * @var string $pageTitle
 * @var array  $breadcrumbs List of [label, path] pairs.
 * @var string $appName
 */

use App\Core\View;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title><?= e($pageTitle) ?> &middot; <?= e($appName) ?></title>

    <link rel="stylesheet" href="/assets/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/all.min.css">
    <link rel="stylesheet" href="/assets/dayflow.css">
    <link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
</head>
<body>

<?php View::partial('nav') ?>

<main class="container-fluid py-4">
    <div class="mx-auto" style="max-width: 1240px;">

        <?php if (!empty($breadcrumbs)): ?>
            <?php View::partial('breadcrumbs', ['breadcrumbs' => $breadcrumbs]) ?>
        <?php endif; ?>

        <?php View::partial('flash') ?>

        <?= $content ?>

    </div>
</main>

<footer class="border-top mt-5 py-4">
    <div class="container-fluid">
        <div class="mx-auto d-flex flex-wrap justify-content-between align-items-center gap-2 small text-muted"
             style="max-width: 1240px;">
            <span>
                <i class="fa fa-bolt text-primary"></i>
                <strong><?= e($appName) ?></strong> &middot; Every workday, perfectly aligned.
            </span>
            <span><?= date('Y') ?> &middot; Human Resource Management System</span>
        </div>
    </div>
</footer>

<script src="/assets/bootstrap.min.js"></script>
<script src="/assets/dayflow.js"></script>
<script src="/assets/charts.js"></script>
</body>
</html>
