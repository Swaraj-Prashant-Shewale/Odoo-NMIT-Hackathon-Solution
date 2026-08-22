<?php
/**
 * System health.
 *
 * @var bool                  $reachable Whether the gateway answered at all.
 * @var array<string, mixed>  $health
 * @var array<string, mixed>  $services  Service name => reported state.
 * @var string                $failure   Why the call failed, when it did.
 */

use App\Core\View;

/**
 * A reported state, which is a plain word today but may become a structure
 * carrying a response time. Both shapes are read here so this page keeps
 * working either way.
 *
 * @return array{healthy: bool, label: string, ms: ?int}
 */
$read = static function (mixed $value): array {
    if (is_array($value)) {
        $state = (string) ($value['status'] ?? 'unknown');
        $ms = isset($value['duration_ms']) && is_numeric($value['duration_ms']) ? (int) $value['duration_ms'] : null;

        return ['healthy' => $state === 'healthy', 'label' => $state, 'ms' => $ms];
    }

    $state = (string) $value;

    return ['healthy' => $state === 'healthy', 'label' => $state === '' ? 'unknown' : $state, 'ms' => null];
};

$overall = (string) ($health['status'] ?? '');
$database = $read($health['database'] ?? '');
$gateway = $read($health['gateway'] ?? ($reachable ? 'healthy' : 'unreachable'));

$down = [];

foreach ($services as $name => $value) {
    if (!$read($value)['healthy']) {
        $down[] = (string) $name;
    }
}
?>

<?php View::partial('page-header', [
    'title' => 'System health',
    'subtitle' => 'What the API gateway can reach right now, checked the moment this page loaded.',
    'actions' => '<a href="/admin/health" class="btn btn-outline-secondary btn-sm">'
        . '<i class="fa fa-sync"></i> Check again</a>',
]) ?>

<?php if (!$reachable): ?>
    <div class="alert alert-danger d-flex gap-2">
        <i class="fa fa-exclamation-circle mt-1"></i>
        <div>
            <div class="fw-semibold">The gateway did not answer</div>
            <p class="small mb-0"><?= e($failure) ?></p>
            <p class="small mb-0">
                Nothing else in this application will load either, because every screen goes through the gateway.
                Check that the gateway container is running and that it can reach the network the services sit on.
            </p>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-4">
        <div class="tile <?= $overall === 'healthy' ? 'tile-success' : 'tile-danger' ?>">
            <div class="tile-icon"><i class="fa fa-heartbeat"></i></div>
            <div class="tile-label">Overall</div>
            <div class="tile-value"><?= e(label($overall, 'Unknown')) ?></div>
            <div class="tile-hint">
                <?php if ($reachable && $down === [] && $database['healthy']): ?>
                    Everything answering
                <?php elseif ($reachable): ?>
                    Some part of the platform is not responding
                <?php else: ?>
                    The gateway itself could not be reached
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-lg-4">
        <div class="tile <?= $gateway['healthy'] ? 'tile-success' : 'tile-danger' ?>">
            <div class="tile-icon"><i class="fa fa-network-wired"></i></div>
            <div class="tile-label">Gateway</div>
            <div class="tile-value"><?= e(label($gateway['label'])) ?></div>
            <div class="tile-hint">The single entrance to the API</div>
        </div>
    </div>

    <div class="col-sm-6 col-lg-4">
        <div class="tile <?= $database['healthy'] ? 'tile-success' : 'tile-danger' ?>">
            <div class="tile-icon"><i class="fa fa-database"></i></div>
            <div class="tile-label">Database</div>
            <div class="tile-value"><?= e(label($database['label'])) ?></div>
            <div class="tile-hint">
                <?php if ($database['ms'] !== null): ?>
                    Answered in <?= e((string) $database['ms']) ?>ms
                <?php else: ?>
                    One cluster, one schema per service
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Services</span>
                <?php if (!empty($health['time'])): ?>
                    <span class="small text-muted">Checked <?= e(datetime_display((string) $health['time'])) ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if ($services === []): ?>
                    <div class="p-3">
                        <?php View::partial('empty-state', [
                            'icon' => 'fa-server',
                            'title' => 'No service states reported',
                            'message' => 'The gateway answered without a list of services, which means it could not run its own checks.',
                        ]) ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th>State</th>
                                    <th class="text-end">Response</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($services as $name => $value): ?>
                                    <?php $state = $read($value); ?>
                                    <tr>
                                        <td class="fw-semibold"><?= e(label((string) $name)) ?></td>
                                        <td>
                                            <?php if ($state['healthy']): ?>
                                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                                    <i class="fa fa-circle"></i> Healthy
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                                                    <i class="fa fa-circle"></i> <?= e(label($state['label'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end tabular">
                                            <?php if ($state['ms'] !== null): ?>
                                                <?= e((string) $state['ms']) ?>ms
                                            <?php else: ?>
                                                <span class="text-muted">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">When something is unreachable</div>
            <div class="card-body">
                <?php if ($down !== []): ?>
                    <div class="alert alert-warning small">
                        <i class="fa fa-exclamation-triangle"></i>
                        Not answering:
                        <strong><?= e(implode(', ', array_map(static fn (string $name): string => label($name), $down))) ?></strong>.
                        Anything in the product that reads from those services will fail while this lasts; the rest
                        of the platform is unaffected, because each service is independent.
                    </div>
                <?php endif; ?>

                <div class="timeline">
                    <div class="timeline-item">
                        <div class="fw-semibold">Check the container is running</div>
                        <div class="small text-muted">
                            An unreachable service usually means the process has stopped or is still starting up.
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="fw-semibold">Check it can reach the database</div>
                        <div class="small text-muted">
                            A service answers its own health endpoint only once its connection is good, so a service
                            that is up but reported unreachable is often a database credential or network problem.
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="fw-semibold">Read its log</div>
                        <div class="small text-muted">
                            Every failure is logged with the request that caused it, so the log says what the health
                            check cannot.
                        </div>
                    </div>
                    <div class="timeline-item is-muted">
                        <div class="fw-semibold">Check again from here</div>
                        <div class="small text-muted">
                            This page re-runs every check as it loads; nothing is cached.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
