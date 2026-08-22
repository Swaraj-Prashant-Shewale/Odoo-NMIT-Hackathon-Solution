<?php
/**
 * Primary navigation.
 *
 * Items are hidden when the signed-in user lacks the permission behind them.
 * This is presentation only: the API enforces the same rules independently, so
 * hiding a link is a courtesy rather than the control.
 *
 * @var array $currentUser
 */

use App\Core\Api;
use App\Core\Session;
use Dayflow\Kernel\Security\Permissions;

$unread = 0;
$unreadResponse = Api::data('/notifications/unread-count', [], ['count' => 0]);
if (is_array($unreadResponse)) {
    $unread = (int) ($unreadResponse['count'] ?? 0);
}

$pendingApprovals = 0;
if (Session::canAny(Permissions::LEAVE_APPROVE, Permissions::ATTENDANCE_APPROVE_REGULARISATION)) {
    $queue = Api::data('/leave/pending-approvals', ['per_page' => 1]);
    if (is_array($queue)) {
        $pendingApprovals = (int) ($queue['count'] ?? count($queue));
    }
}

/** @var list<array{label: string, href: string, icon: string, show: bool, badge?: int}> $items */
$items = [
    [
        'label' => 'Dashboard',
        'href' => '/',
        'icon' => 'fa-th-large',
        'show' => true,
    ],
    [
        'label' => 'My Attendance',
        'href' => '/attendance',
        'icon' => 'fa-clock',
        'show' => Session::can(Permissions::ATTENDANCE_VIEW_SELF),
    ],
    [
        'label' => 'Time Off',
        'href' => '/leave',
        'icon' => 'fa-umbrella-beach',
        'show' => Session::can(Permissions::LEAVE_VIEW_SELF),
    ],
    [
        'label' => 'Payroll',
        'href' => '/payroll',
        'icon' => 'fa-file-invoice-dollar',
        'show' => Session::can(Permissions::PAYROLL_VIEW_SELF),
    ],
    [
        'label' => 'Learning',
        'href' => '/learning',
        'icon' => 'fa-graduation-cap',
        'show' => Session::can(Permissions::LEARNING_VIEW_CATALOGUE),
    ],
    [
        'label' => 'Performance',
        'href' => '/performance',
        'icon' => 'fa-bullseye',
        'show' => Session::can(Permissions::TALENT_VIEW_SELF),
    ],
    [
        'label' => 'People',
        'href' => '/people',
        'icon' => 'fa-users',
        'show' => Session::canAny(Permissions::PROFILE_VIEW_ALL, Permissions::PROFILE_VIEW_TEAM),
    ],
    [
        'label' => 'Approvals',
        'href' => '/approvals',
        'icon' => 'fa-check-double',
        'show' => Session::canAny(Permissions::LEAVE_APPROVE, Permissions::ATTENDANCE_APPROVE_REGULARISATION, Permissions::EXPENSE_APPROVE),
        'badge' => $pendingApprovals,
    ],
    [
        'label' => 'Reports',
        'href' => '/reports',
        'icon' => 'fa-chart-pie',
        'show' => Session::canAny(Permissions::REPORT_VIEW_ALL, Permissions::REPORT_VIEW_TEAM),
    ],
    [
        'label' => 'Admin',
        'href' => '/admin',
        'icon' => 'fa-cogs',
        'show' => Session::canAny(Permissions::SYSTEM_SETTINGS, Permissions::USER_MANAGE_ROLES, Permissions::ORG_MANAGE),
    ],
];
?>
<nav class="navbar navbar-expand-lg main-nav sticky-top">
    <div class="container-fluid px-3">

        <a class="navbar-brand d-flex align-items-center gap-2" href="/">
            <span class="brand-mark"><i class="fa fa-bolt"></i></span>
            <span class="brand-text">
                <span class="brand-name"><?= e($appName ?? 'Dayflow') ?></span>
                <span class="brand-tagline d-none d-xl-inline">Human Resources</span>
            </span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#primaryNav" aria-controls="primaryNav"
                aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="primaryNav">
            <ul class="navbar-nav me-auto">
                <?php foreach ($items as $item): ?>
                    <?php if (!$item['show']) {
                        continue;
                    } ?>
                    <li class="nav-item">
                        <a class="nav-link <?= is_active($item['href']) && ($item['href'] !== '/' || is_active('/')) ? 'active-nav' : '' ?>"
                           href="<?= e($item['href']) ?>">
                            <i class="fa <?= e($item['icon']) ?>"></i>
                            <span><?= e($item['label']) ?></span>
                            <?php if (!empty($item['badge'])): ?>
                                <span class="nav-badge"><?= e((string) $item['badge']) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <ul class="navbar-nav align-items-lg-center">

                <li class="nav-item">
                    <a class="nav-link position-relative" href="/notifications" title="Notifications">
                        <i class="fa fa-bell"></i>
                        <span class="d-lg-none">Notifications</span>
                        <?php if ($unread > 0): ?>
                            <span class="nav-badge"><?= e((string) min($unread, 99)) ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#"
                       id="accountMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar avatar-sm"><?= e(initials(Session::displayName())) ?></span>
                        <span class="d-none d-lg-inline"><?= e(Session::firstName()) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="accountMenu">
                        <li class="px-3 py-2 border-bottom">
                            <div class="fw-semibold"><?= e(Session::displayName()) ?></div>
                            <div class="small text-muted"><?= e($currentUser['role_label'] ?? 'Employee') ?></div>
                        </li>
                        <li><a class="dropdown-item" href="/profile"><i class="fa fa-id-card"></i> My profile</a></li>
                        <li><a class="dropdown-item" href="/profile/documents"><i class="fa fa-folder-open"></i> My documents</a></li>
                        <li><a class="dropdown-item" href="/directory"><i class="fa fa-address-book"></i> Company directory</a></li>
                        <li><a class="dropdown-item" href="/profile/security"><i class="fa fa-shield-alt"></i> Security</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="/logout" class="m-0">
                                <?= \App\Core\Csrf::field() ?>
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="fa fa-sign-out-alt"></i> Sign out
                                </button>
                            </form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
