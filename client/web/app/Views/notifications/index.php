<?php
/**
 * The signed-in person's notification feed.
 *
 * @var list<array<string, mixed>> $notifications
 * @var array<string, mixed>       $meta
 * @var bool                       $unreadOnly
 * @var int                        $unread
 */

use App\Core\Csrf;
use App\Core\View;

$icons = [
    'critical' => 'fa-exclamation-circle',
    'warning' => 'fa-exclamation-triangle',
    'success' => 'fa-check-circle',
    'info' => 'fa-info-circle',
];

$tones = [
    'critical' => 'danger',
    'warning' => 'warning',
    'success' => 'success',
    'info' => 'info',
];

/**
 * Only a path within this application is ever turned into a link.
 *
 * A notification is written from an event payload, so its action address is
 * not something to follow on trust: an off-site one rendered as a link here
 * would be a phishing page arriving with the platform's own styling.
 */
$destination = static function (mixed $url): string {
    $value = is_string($url) ? trim($url) : '';

    return str_starts_with($value, '/') && !str_starts_with($value, '//') ? $value : '';
};
?>

<?php
$headerActions = '';

if ($unread > 0) {
    $headerActions = '<form method="post" action="/notifications/read-all" class="m-0">'
        . Csrf::field()
        . '<button type="submit" class="btn btn-outline-secondary btn-sm" data-busy-label="Marking...">'
        . '<i class="fa fa-check-double"></i> Mark all read</button></form>';
}
?>

<?php View::partial('page-header', [
    'title' => 'Notifications',
    'subtitle' => $unread === 0
        ? 'Everything here has been read.'
        : $unread . ' unread ' . ($unread === 1 ? 'notification' : 'notifications') . '.',
    'actions' => $headerActions,
]) ?>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" action="/notifications" class="d-flex align-items-center gap-3">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="unread_only" name="unread_only" value="1"
                       data-submit-on-change <?= $unreadOnly ? 'checked' : '' ?>>
                <label class="form-check-label" for="unread_only">Unread only</label>
            </div>
            <noscript>
                <button type="submit" class="btn btn-outline-secondary btn-sm">Apply</button>
            </noscript>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if ($notifications === []): ?>
            <div class="p-3">
                <?php View::partial('empty-state', [
                    'icon' => 'fa-bell-slash',
                    'title' => $unreadOnly ? 'Nothing unread' : 'No notifications yet',
                    'message' => $unreadOnly
                        ? 'You have read everything. Switch the filter off to see the rest.'
                        : 'Anything that needs your attention will appear here, newest first.',
                ]) ?>
            </div>
        <?php else: ?>
            <div class="divider-y">
                <?php foreach ($notifications as $notification): ?>
                    <?php
                    $severity = (string) ($notification['severity'] ?? 'info');
                    $icon = $icons[$severity] ?? 'fa-bell';
                    $tone = $tones[$severity] ?? 'secondary';
                    $isRead = ($notification['is_read'] ?? false) === true;
                    $href = $destination($notification['action_url'] ?? null);
                    ?>
                    <div class="d-flex gap-3 p-3 <?= $isRead ? '' : 'notification-unread' ?>">
                        <div class="tile-icon flex-shrink-0 text-<?= e($tone) ?>">
                            <i class="fa <?= e($icon) ?>"></i>
                        </div>

                        <div class="flex-grow-1">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <div class="fw-semibold">
                                    <?php if (!$isRead): ?>
                                        <span class="badge bg-primary">New</span>
                                    <?php endif; ?>
                                    <?= field($notification, 'title') ?>
                                </div>
                                <div class="small text-muted" title="<?= e(datetime_display($notification['created_at'] ?? null)) ?>">
                                    <?= e(relative_time((string) ($notification['created_at'] ?? ''))) ?>
                                </div>
                            </div>

                            <?php if (!empty($notification['body'])): ?>
                                <p class="small mb-2"><?= nl2br(e($notification['body'])) ?></p>
                            <?php endif; ?>

                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                    <?= e(label((string) ($notification['category'] ?? 'general'))) ?>
                                </span>

                                <?php if ($href !== ''): ?>
                                    <a href="<?= e($href) ?>" class="btn btn-link btn-sm p-0">
                                        Open <i class="fa fa-arrow-right"></i>
                                    </a>
                                <?php endif; ?>

                                <?php if (!$isRead): ?>
                                    <form method="post"
                                          action="/notifications/<?= e($notification['id'] ?? '') ?>/read"
                                          class="m-0">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="btn btn-link btn-sm p-0 text-muted">
                                            <i class="fa fa-check"></i> Mark read
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="small text-muted">
                                        Read <?= e(relative_time((string) ($notification['read_at'] ?? ''))) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
        <?php View::partial('pagination', ['meta' => $meta]) ?>
        <a href="/notifications/preferences" class="btn btn-outline-secondary btn-sm">
            <i class="fa fa-sliders-h"></i> Preferences
        </a>
    </div>
</div>
