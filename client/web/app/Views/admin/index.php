<?php
/**
 * Administration home.
 *
 * @var list<array{label: string, description: string, href: string, icon: string, variant: string}> $areas
 * @var array{total: ?int, active: ?int}                                                             $summary
 * @var array<string, mixed>|null                                                                    $health
 */

use App\Core\View;

$status = is_array($health) ? (string) ($health['status'] ?? '') : '';
$unreachable = 0;

if (is_array($health) && is_array($health['services'] ?? null)) {
    foreach ($health['services'] as $state) {
        if ((string) $state !== 'healthy') {
            $unreachable++;
        }
    }
}
?>

<?php View::partial('page-header', [
    'title' => 'Administration',
    'subtitle' => 'Accounts, the access model, organisation structure and the platform itself.',
]) ?>

<?php if ($summary['total'] !== null || $health !== null): ?>
    <div class="row g-3 mb-4">
        <?php if ($summary['total'] !== null): ?>
            <div class="col-sm-6 col-lg-4">
                <div class="tile">
                    <div class="tile-icon"><i class="fa fa-users"></i></div>
                    <div class="tile-label">Accounts</div>
                    <div class="tile-value tabular"><?= e((string) $summary['total']) ?></div>
                    <div class="tile-hint">Every login account on the platform</div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($summary['active'] !== null): ?>
            <div class="col-sm-6 col-lg-4">
                <div class="tile tile-success">
                    <div class="tile-icon"><i class="fa fa-user-check"></i></div>
                    <div class="tile-label">Active</div>
                    <div class="tile-value tabular"><?= e((string) $summary['active']) ?></div>
                    <div class="tile-hint">
                        <?php if ($summary['total'] !== null && $summary['total'] > 0): ?>
                            <?= e((string) percent(($summary['active'] / $summary['total']) * 100)) ?>% of all accounts
                        <?php else: ?>
                            Accounts able to sign in today
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($health !== null): ?>
            <div class="col-sm-6 col-lg-4">
                <a class="tile <?= $status === 'healthy' ? 'tile-success' : 'tile-danger' ?> text-decoration-none text-reset"
                   href="/admin/health">
                    <div class="tile-icon"><i class="fa fa-heartbeat"></i></div>
                    <div class="tile-label">Platform</div>
                    <div class="tile-value"><?= e(label($status, 'Unknown')) ?></div>
                    <div class="tile-hint">
                        <?php if ($unreachable === 0): ?>
                            Every service answering, database reachable
                        <?php else: ?>
                            <?= e((string) $unreachable) ?> service<?= $unreachable === 1 ? '' : 's' ?> not responding
                        <?php endif; ?>
                    </div>
                </a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($areas === []): ?>
    <div class="card">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-lock',
                'title' => 'Nothing to administer',
                'message' => 'Your role does not carry any of the administration permissions. Ask a platform administrator if you need one of them.',
            ]) ?>
        </div>
    </div>
<?php else: ?>
    <div class="section-label">Areas</div>
    <div class="row g-3">
        <?php foreach ($areas as $area): ?>
            <div class="col-sm-6 col-lg-4">
                <a class="tile <?= e($area['variant']) ?> text-decoration-none text-reset h-100"
                   href="<?= e($area['href']) ?>">
                    <div class="tile-icon"><i class="fa <?= e($area['icon']) ?>"></i></div>
                    <div class="fw-semibold mt-2"><?= e($area['label']) ?></div>
                    <div class="tile-hint"><?= e($area['description']) ?></div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
