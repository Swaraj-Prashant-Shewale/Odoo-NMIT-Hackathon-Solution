<?php
/**
 * The development mail inbox.
 *
 * @var bool                       $available Whether the endpoint exists in this environment.
 * @var list<array<string, mixed>> $messages
 * @var array<string, mixed>       $meta
 * @var string                     $driver
 * @var string                     $status
 */

use App\Core\View;
?>

<?php View::partial('page-header', [
    'title' => 'Development inbox',
    'subtitle' => 'Everything the platform has queued to send, held locally instead of being delivered.',
]) ?>

<div class="alert alert-info d-flex gap-2">
    <i class="fa fa-info-circle mt-1"></i>
    <div class="small">
        <p class="mb-1">
            <strong>This page exists because MAIL_DRIVER is set to log.</strong>
            Nothing leaves the machine: every message is written to the outbox and stopped there. It is how a
            verification link or a password reset link is read while working locally.
        </p>
        <p class="mb-0">
            The endpoint behind it is refused outright in production &mdash; and refused as "not found" rather than
            "forbidden", so its existence is not even confirmed. The outbox holds the rendered body of every message
            the platform has sent, which includes every verification and reset link in the company; reachable in
            production, that single page would be a complete account takeover.
        </p>
    </div>
</div>

<?php if (!$available): ?>

    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-envelope-open-text',
                'title' => 'The development inbox is switched off',
                'message' => 'The notification service did not publish this endpoint, which is what happens as soon'
                    . ' as the environment is production. There is nothing wrong: mail is being delivered normally'
                    . ' rather than being held here.',
                'actionLabel' => 'Back to administration',
                'actionHref' => '/admin',
            ]) ?>
        </div>
    </div>

<?php else: ?>

    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-end gap-2">
            <form method="get" action="/mailbox" class="d-flex align-items-end gap-2">
                <div>
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status" data-submit-on-change>
                        <option value="">Everything</option>
                        <?php foreach (['queued', 'sent', 'failed'] as $option): ?>
                            <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>>
                                <?= e(label($option)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-outline-secondary"><i class="fa fa-filter"></i></button>
            </form>

            <?php if ($driver !== ''): ?>
                <div class="small text-muted">
                    Mail driver: <code><?= e($driver) ?></code>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?php if ($messages === []): ?>
                <div class="p-3">
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-inbox',
                        'title' => 'Nothing in the outbox',
                        'message' => 'No message has been queued yet. Register an account or ask for a password reset and it will appear here.',
                    ]) ?>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>To</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Queued</th>
                                <th>Sent</th>
                                <th class="text-end">Body</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($messages as $message): ?>
                                <?php
                                $panelId = 'mail-' . preg_replace('/[^A-Za-z0-9]/', '', (string) ($message['id'] ?? ''));
                                $body = (string) ($message['body_text'] ?? '');
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= field($message, 'to_name', 'No name') ?></div>
                                        <div class="small text-muted truncate" style="max-width: 220px;"
                                             title="<?= e($message['to_address'] ?? '') ?>">
                                            <?= field($message, 'to_address') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="truncate" style="max-width: 280px;"
                                             title="<?= e($message['subject'] ?? '') ?>">
                                            <?= field($message, 'subject') ?>
                                        </div>
                                        <?php if (!empty($message['event_name'])): ?>
                                            <div class="small text-muted"><code><?= field($message, 'event_name') ?></code></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= badge((string) ($message['status'] ?? '')) ?>
                                        <?php if ((int) ($message['attempts'] ?? 0) > 1): ?>
                                            <div class="small text-muted"><?= e((string) (int) $message['attempts']) ?> attempts</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= e(datetime_display($message['queued_at'] ?? null)) ?></td>
                                    <td class="small"><?= e(datetime_display($message['sent_at'] ?? null, 'Not sent')) ?></td>
                                    <td class="text-end">
                                        <button class="btn btn-outline-secondary btn-sm" type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#<?= e($panelId) ?>"
                                                aria-expanded="false"
                                                aria-controls="<?= e($panelId) ?>">
                                            <i class="fa fa-envelope-open"></i> Read
                                        </button>
                                    </td>
                                </tr>
                                <tr class="collapse" id="<?= e($panelId) ?>">
                                    <td colspan="6" class="bg-light">
                                        <?php if (!empty($message['last_error'])): ?>
                                            <div class="alert alert-danger small">
                                                <i class="fa fa-exclamation-circle"></i>
                                                <?= field($message, 'last_error') ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="section-label">Message</div>
                                        <?php if ($body === ''): ?>
                                            <p class="small text-muted mb-0">This message has no plain text body.</p>
                                        <?php else: ?>
                                            <pre class="small mb-2" style="white-space: pre-wrap; word-break: break-word;"><?= e($body) ?></pre>
                                        <?php endif; ?>

                                        <p class="small text-muted mb-0">
                                            Shown as plain text on purpose. The HTML version of a message is a
                                            rendered template carrying names and links that came from elsewhere, and
                                            writing it into this page unescaped would run whatever it contained.
                                            Copy the link out of the text above instead.
                                        </p>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($messages !== []): ?>
            <div class="card-footer">
                <?php View::partial('pagination', ['meta' => $meta]) ?>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>
