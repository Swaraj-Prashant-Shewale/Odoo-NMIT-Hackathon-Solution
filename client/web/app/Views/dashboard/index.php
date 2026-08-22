<?php
/**
 * The home screen.
 *
 * The page is a list of sections that the analytics service either sent, sent
 * as unavailable, or did not offer at all. The three cases are drawn as the
 * card, a calm placeholder, and nothing respectively — never as a zero, which
 * would read as a fact rather than as a gap.
 *
 * @var string                                            $greeting
 * @var string                                            $serverTime
 * @var string                                            $today
 * @var array<string, mixed>                              $viewer
 * @var array<string, mixed>|null                         $profile
 * @var array<string, array{available: bool, data: mixed}> $sections
 * @var list<string>                                      $audience
 * @var bool                                              $hasSelfSection
 * @var bool                                              $companyFirst
 * @var array<string, array<string, mixed>>               $charts
 * @var float|null                                        $attendanceRate
 * @var string                                            $attendanceRatePeriod
 * @var list<array{label: string, href: string, icon: string}> $quickActions
 * @var list<string>                                      $unavailableServices
 * @var bool                                              $cached
 */

use App\Core\Session;
use App\Core\View;

/** True when a section arrived with usable content. */
$ready = static function (string $key) use ($sections): bool {
    return isset($sections[$key]) && $sections[$key]['available'] && $sections[$key]['data'] !== null;
};

/** True when a section belongs to this person but the service behind it did not answer. */
$offline = static function (string $key) use ($sections): bool {
    return isset($sections[$key]) && !$sections[$key]['available'];
};

/** True when at least one of these sections is on this person's page at all. */
$anyOf = static function (array $keys) use ($sections): bool {
    foreach ($keys as $key) {
        if (isset($sections[$key])) {
            return true;
        }
    }

    return false;
};

/**
 * One section's payload as an array, empty when it is absent or unavailable.
 *
 * @return array<array-key, mixed>
 */
$data = static function (string $key) use ($sections): array {
    return isset($sections[$key]) && $sections[$key]['available'] && is_array($sections[$key]['data'])
        ? $sections[$key]['data']
        : [];
};

/**
 * The rows of a nested list, with anything that is not a row discarded.
 *
 * @return list<array<string, mixed>>
 */
$rows = static function (mixed $value): array {
    return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
};

/** A day count without a pointless trailing zero: 12.5 stays, 12.0 becomes 12. */
$days = static function (mixed $value): string {
    return rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
};

/**
 * Stands in for a card whose service did not answer.
 *
 * Deliberately quiet. Nothing has gone wrong for the person reading it, they
 * simply cannot see this one figure for a minute, and an alarming red panel
 * would suggest otherwise.
 */
$placeholder = static function (string $title, string $note = 'This figure will be back shortly.'): void {
    ?>
    <div class="card h-100">
        <div class="card-body text-center text-muted py-4">
            <div class="mb-2"><i class="fa fa-plug fa-lg"></i></div>
            <div class="fw-semibold text-body"><?= e($title) ?></div>
            <p class="small mb-0"><?= e($note) ?></p>
        </div>
    </div>
    <?php
};

$designation = is_array($profile) ? (string) ($profile['designation_name'] ?? '') : '';
$department = is_array($profile) ? (string) ($profile['department_name'] ?? '') : '';

$subtitle = date_display($today);

if ($designation !== '') {
    $subtitle .= ' · ' . $designation;
}

if ($department !== '') {
    $subtitle .= ($designation === '' ? ' · ' : ', ') . $department;
}

if ($designation === '' && $department === '' && !empty($viewer['role_label'])) {
    $subtitle .= ' · ' . (string) $viewer['role_label'];
}

$teamKeys = ['team_today', 'pending_approvals', 'team_leave_calendar', 'team_goals'];
$companyKeys = [
    'headcount', 'workforce_composition', 'headcount_trend', 'attendance_rate_trend',
    'absence_by_department', 'leave_utilisation', 'onboarding', 'documents_expiring',
    'training_compliance',
];
$financeKeys = ['payroll_cost_trend', 'payroll_cost_by_department', 'expense_claims'];
?>

<?php View::partial('page-header', [
    'title' => $greeting . ', ' . Session::firstName(),
    'subtitle' => $subtitle,
    'actions' => '<a class="btn btn-outline-secondary btn-sm" href="/?refresh=1">'
        . '<i class="fa fa-sync-alt"></i> Refresh</a>',
]) ?>

<?php if ($sections === []): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-compass',
                'title' => 'Nothing to show here yet',
                'message' => 'Your dashboard fills in as soon as your employee record is linked to this account. '
                    . 'Your HR team can finish that off.',
                'actionLabel' => 'View my profile',
                'actionHref' => '/profile',
            ]) ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($companyFirst): ?>
    <?php require __DIR__ . '/_company.php'; ?>
    <?php require __DIR__ . '/_finance.php'; ?>
<?php endif; ?>

<?php require __DIR__ . '/_team.php'; ?>

<?php if ($hasSelfSection): ?>
    <div class="section-label mt-4">Your day</div>
<?php endif; ?>

<?php require __DIR__ . '/_punch-card.php'; ?>
<?php require __DIR__ . '/_my-month.php'; ?>
<?php require __DIR__ . '/_time-off.php'; ?>
<?php require __DIR__ . '/_growth.php'; ?>
<?php require __DIR__ . '/_inbox.php'; ?>

<?php if (!$companyFirst): ?>
    <?php require __DIR__ . '/_company.php'; ?>
    <?php require __DIR__ . '/_finance.php'; ?>
<?php endif; ?>

<?php require __DIR__ . '/_quick-actions.php'; ?>

<?php if ($unavailableServices !== []): ?>
    <p class="small text-muted mt-4 mb-0">
        <i class="fa fa-info-circle"></i>
        Some cards are waiting on a service that did not answer:
        <?= e(implode(', ', array_map('label', $unavailableServices))) ?>.
        Everything else on this page is current.
    </p>
<?php endif; ?>
