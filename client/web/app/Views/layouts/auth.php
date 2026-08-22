<?php
/**
 * The signed-out shell used by sign-in, sign-up, verification and reset.
 *
 * @var string $content
 * @var string $pageTitle
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
<body class="auth-body">

<div class="auth-shell">

    <aside class="auth-aside d-none d-lg-flex">
        <div class="auth-aside-inner">
            <div class="d-flex align-items-center gap-2 mb-5">
                <span class="brand-mark brand-mark-lg"><i class="fa fa-bolt"></i></span>
                <div>
                    <div class="h4 mb-0 text-white fw-bold"><?= e($appName) ?></div>
                    <div class="small text-white-50">Every workday, perfectly aligned.</div>
                </div>
            </div>

            <h2 class="auth-headline">Run your people operations in one place.</h2>
            <p class="auth-lede">
                Attendance, time off, payroll, learning and performance &mdash; joined up,
                auditable, and available to every employee from day one.
            </p>

            <ul class="auth-points list-unstyled mt-4">
                <li><i class="fa fa-clock"></i><span>Check in and out with a full daily and weekly record</span></li>
                <li><i class="fa fa-umbrella-beach"></i><span>Apply for time off and watch balances update live</span></li>
                <li><i class="fa fa-file-invoice-dollar"></i><span>Payslips and salary structure, always to hand</span></li>
                <li><i class="fa fa-graduation-cap"></i><span>Assigned training with progress tracked to completion</span></li>
                <li><i class="fa fa-shield-alt"></i><span>Role-based access with a complete audit trail</span></li>
            </ul>
        </div>
    </aside>

    <section class="auth-main">
        <div class="auth-card-wrap">

            <div class="d-lg-none text-center mb-4">
                <span class="brand-mark brand-mark-lg"><i class="fa fa-bolt"></i></span>
                <div class="h4 mt-2 mb-0 fw-bold"><?= e($appName) ?></div>
                <div class="small text-muted">Human Resource Management</div>
            </div>

            <?php View::partial('flash') ?>

            <?= $content ?>

            <p class="text-center text-muted small mt-4 mb-0">
                <?= date('Y') ?> <?= e($appName) ?> &middot; Human Resource Management System
            </p>
        </div>
    </section>

</div>

<script src="/assets/bootstrap.min.js"></script>
<script src="/assets/dayflow.js"></script>
</body>
</html>
