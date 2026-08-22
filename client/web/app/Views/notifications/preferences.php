<?php
/**
 * Which categories reach this person, and by which channel.
 *
 * @var list<array<string, mixed>> $preferences
 * @var array<string, string>      $explanations
 */

use App\Core\Csrf;
use App\Core\View;
?>

<?php View::partial('page-header', [
    'title' => 'Notification preferences',
    'subtitle' => 'Choose what reaches you, and where. These settings are yours alone.',
    'actions' => '<a href="/notifications" class="btn btn-outline-secondary btn-sm">'
        . '<i class="fa fa-arrow-left"></i> Back to notifications</a>',
]) ?>

<?php if ($preferences === []): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-sliders-h',
                'title' => 'Preferences could not be loaded',
                'message' => 'The notification service did not return the category list. Try again in a moment.',
                'actionLabel' => 'Back to notifications',
                'actionHref' => '/notifications',
            ]) ?>
        </div>
    </div>
<?php else: ?>

    <form method="post" action="/notifications/preferences">
        <?= Csrf::field() ?>

        <div class="card">
            <div class="card-header">Categories</div>
            <div class="card-body p-0">
                <div class="table-wrap">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th class="text-center" style="width: 120px;">In app</th>
                                <th class="text-center" style="width: 120px;">Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preferences as $preference): ?>
                                <?php $category = (string) ($preference['category'] ?? ''); ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= field($preference, 'label') ?></div>
                                        <div class="small text-muted">
                                            <?= e($explanations[$category] ?? 'Updates from this part of the platform.') ?>
                                        </div>
                                        <input type="hidden" name="categories[]" value="<?= e($category) ?>">
                                    </td>
                                    <td class="text-center">
                                        <div class="form-check form-switch d-inline-block">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   name="in_app[]" value="<?= e($category) ?>"
                                                   id="in-app-<?= e($category) ?>"
                                                   aria-label="In-app notifications for <?= e($preference['label'] ?? $category) ?>"
                                                <?= ($preference['in_app_enabled'] ?? true) ? 'checked' : '' ?>>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="form-check form-switch d-inline-block">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   name="email[]" value="<?= e($category) ?>"
                                                   id="email-<?= e($category) ?>"
                                                   aria-label="Email for <?= e($preference['label'] ?? $category) ?>"
                                                <?= ($preference['email_enabled'] ?? true) ? 'checked' : '' ?>>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer d-flex flex-wrap align-items-center gap-2">
                <button type="submit" class="btn btn-primary" data-busy-label="Saving...">
                    <i class="fa fa-save"></i> Save preferences
                </button>
                <span class="small text-muted">
                    A category switched off here stops arriving from that moment on; anything already in your feed
                    stays where it is.
                </span>
            </div>
        </div>
    </form>

    <p class="small text-muted mt-3">
        Some messages are never sent by email whatever this page says, because a mail every morning about your own
        arrival is the fastest way to teach a whole company to ignore mail from this platform. Consider leaving the
        account category switched on: sign-in alerts and password changes are how you find out that somebody else
        has been trying to get in.
    </p>

<?php endif; ?>
