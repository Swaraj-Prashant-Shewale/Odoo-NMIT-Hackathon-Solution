<?php
/**
 * The newest announcement, and how much is waiting in the bell.
 *
 * @var array<string, array{available: bool, data: mixed}> $sections
 * @var callable $data
 * @var callable $offline
 * @var callable $placeholder
 */
?>

<?php if (isset($sections['inbox'])): ?>
    <div class="row g-4 mb-4">
        <?php if ($offline('inbox')): ?>
            <div class="col-12">
                <?php $placeholder('Announcements', 'The notification service did not answer this time.') ?>
            </div>
        <?php else: ?>
            <?php
            $inbox = $data('inbox');
            $announcement = is_array($inbox['latest_announcement'] ?? null) ? $inbox['latest_announcement'] : null;
            $unread = (int) ($inbox['unread_count'] ?? 0);
            $unreadKnown = ($inbox['unread_available'] ?? false) === true;
            ?>
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-bullhorn"></i> Latest announcement</div>
                    <div class="card-body">
                        <?php if ($announcement === null): ?>
                            <p class="text-muted small mb-0 text-center py-3">
                                Nothing has been announced recently.
                            </p>
                        <?php else: ?>
                            <h6 class="mb-1"><?= e((string) ($announcement['title'] ?? 'Announcement')) ?></h6>
                            <?php if (!empty($announcement['published_at'])): ?>
                                <div class="small text-muted mb-2">
                                    <?= e(relative_time((string) $announcement['published_at'])) ?>
                                </div>
                            <?php endif; ?>
                            <p class="mb-0"><?= e((string) ($announcement['excerpt'] ?? '')) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer">
                        <a href="/announcements">All announcements</a>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <a class="tile <?= $unread > 0 ? 'tile-warning' : '' ?>" href="/notifications">
                    <div class="tile-icon"><i class="fa fa-bell"></i></div>
                    <div class="tile-label">Notifications</div>
                    <div class="tile-value tabular">
                        <?= $unreadKnown ? e((string) $unread) : '—' ?>
                    </div>
                    <div class="tile-hint">
                        <?php if (!$unreadKnown): ?>
                            The count is not available right now
                        <?php elseif ($unread === 0): ?>
                            You are all caught up
                        <?php else: ?>
                            unread, waiting for you
                        <?php endif; ?>
                    </div>
                </a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
