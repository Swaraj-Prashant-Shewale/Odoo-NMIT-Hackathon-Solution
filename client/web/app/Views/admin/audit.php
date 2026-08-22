<?php
/**
 * The platform-wide audit trail.
 *
 * @var list<array<string, mixed>> $entries
 * @var array<string, mixed>       $meta
 * @var array<string, string>      $filters
 * @var list<string>               $actions
 * @var list<string>               $subjects
 * @var list<string>               $services
 * @var list<array<string, mixed>> $accounts
 * @var bool                       $mayExport
 */

use App\Core\View;
use Dayflow\Kernel\Security\Roles;

/** Renders a recorded state block as readable, escaped text. */
$state = static function (mixed $value): string {
    if (!is_array($value) || $value === []) {
        return '';
    }

    return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

$exportHref = '/admin/audit/export' . ($filters === [] ? '' : '?' . http_build_query($filters));

$exportButton = $mayExport
    ? '<a href="' . e($exportHref) . '" class="btn btn-outline-secondary btn-sm">'
        . '<i class="fa fa-file-csv"></i> Export CSV</a>'
    : '';
?>

<?php View::partial('page-header', [
    'title' => 'Audit trail',
    'subtitle' => 'Every recorded action across all nine services, in one append-only trail.',
    'actions' => $exportButton,
]) ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" action="/admin/audit" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="actor" class="form-label">Who</label>
                <select class="form-select" id="actor" name="actor">
                    <option value="">Anybody</option>
                    <?php $known = false; ?>
                    <?php foreach ($accounts as $account): ?>
                        <?php $selected = ($filters['actor'] ?? '') === (string) ($account['id'] ?? ''); ?>
                        <?php $known = $known || $selected; ?>
                        <option value="<?= e($account['id'] ?? '') ?>" <?= $selected ? 'selected' : '' ?>>
                            <?= e($account['full_name'] ?? $account['email'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if (!$known && ($filters['actor'] ?? '') !== ''): ?>
                        <option value="<?= e($filters['actor']) ?>" selected><?= e($filters['actor']) ?></option>
                    <?php endif; ?>
                </select>
                <?php if ($accounts !== []): ?>
                    <div class="form-text">The <?= e((string) count($accounts)) ?> most recently created accounts.</div>
                <?php endif; ?>
            </div>

            <div class="col-md-3">
                <label for="action" class="form-label">Action</label>
                <input type="text" class="form-control" id="action" name="action"
                       list="audit-actions" value="<?= e($filters['action'] ?? '') ?>"
                       placeholder="leave. matches the whole family">
                <datalist id="audit-actions">
                    <?php foreach ($actions as $action): ?>
                        <option value="<?= e($action) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="col-md-2">
                <label for="subject" class="form-label">Record type</label>
                <input type="text" class="form-control" id="subject" name="subject"
                       list="audit-subjects" value="<?= e($filters['subject'] ?? '') ?>">
                <datalist id="audit-subjects">
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?= e($subject) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="col-md-2">
                <label for="service" class="form-label">Service</label>
                <input type="text" class="form-control" id="service" name="service"
                       list="audit-services" value="<?= e($filters['service'] ?? '') ?>">
                <datalist id="audit-services">
                    <?php foreach ($services as $service): ?>
                        <option value="<?= e($service) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="col-md-1">
                <label for="from" class="form-label">From</label>
                <input type="date" class="form-control" id="from" name="from"
                       value="<?= e($filters['from'] ?? '') ?>" data-range-start="to">
            </div>

            <div class="col-md-1">
                <label for="to" class="form-label">To</label>
                <input type="date" class="form-control" id="to" name="to" value="<?= e($filters['to'] ?? '') ?>">
            </div>

            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa fa-filter"></i> Apply
                </button>
                <a href="/admin/audit" class="btn btn-outline-secondary btn-sm">Clear</a>
                <?php if ($filters !== []): ?>
                    <span class="align-self-center small text-muted">
                        <?= e((string) (int) ($meta['total'] ?? 0)) ?> entries match these filters.
                    </span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if ($entries === []): ?>
            <div class="p-3">
                <?php View::partial('empty-state', [
                    'icon' => 'fa-clipboard-list',
                    'title' => 'Nothing recorded for that',
                    'message' => 'No entry in the trail matches those filters. Widen the dates or clear them entirely.',
                ]) ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th style="min-width: 150px;">When</th>
                            <th>Who</th>
                            <th>What</th>
                            <th>Record</th>
                            <th>From</th>
                            <th class="text-end">State</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <?php
                            $before = $state($entry['before_state'] ?? null);
                            $after = $state($entry['after_state'] ?? null);
                            $context = $state($entry['context'] ?? null);
                            $hasState = $before !== '' || $after !== '' || $context !== '';
                            $panelId = 'audit-' . preg_replace('/[^A-Za-z0-9]/', '', (string) ($entry['id'] ?? ''));
                            $action = (string) ($entry['action'] ?? '');
                            $denied = str_contains($action, 'denied') || str_contains($action, 'failed');
                            ?>
                            <tr>
                                <td>
                                    <div class="small"><?= e(datetime_display($entry['occurred_at'] ?? null)) ?></div>
                                    <div class="small text-muted"><?= e(relative_time((string) ($entry['occurred_at'] ?? ''))) ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($entry['actor_email'])): ?>
                                        <div class="truncate" style="max-width: 200px;"
                                             title="<?= e($entry['actor_email']) ?>">
                                            <?= field($entry, 'actor_email') ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?= e(Roles::label((string) ($entry['actor_role'] ?? 'employee'))) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">The system</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code class="<?= $denied ? 'text-danger' : '' ?>"><?= e($action) ?></code>
                                    <div class="small text-muted"><?= field($entry, 'service', '—') ?></div>
                                </td>
                                <td>
                                    <div><?= e(label((string) ($entry['subject_type'] ?? ''), '—')) ?></div>
                                    <?php if (!empty($entry['subject_id'])): ?>
                                        <div class="small text-muted truncate" style="max-width: 200px;"
                                             title="<?= e($entry['subject_id']) ?>">
                                            <?= field($entry, 'subject_id') ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small tabular"><?= field($entry, 'ip_address', 'Not recorded') ?></div>
                                    <?php if (!empty($entry['request_id'])): ?>
                                        <div class="small text-muted truncate" style="max-width: 160px;"
                                             title="Request <?= e($entry['request_id']) ?>">
                                            <?= field($entry, 'request_id') ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($hasState): ?>
                                        <button class="btn btn-outline-secondary btn-sm" type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#<?= e($panelId) ?>"
                                                aria-expanded="false"
                                                aria-controls="<?= e($panelId) ?>">
                                            <i class="fa fa-code-branch"></i> Detail
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <?php if ($hasState): ?>
                                <tr class="collapse" id="<?= e($panelId) ?>">
                                    <td colspan="6" class="bg-light">
                                        <div class="row g-3">
                                            <?php if ($before !== ''): ?>
                                                <div class="col-lg-6">
                                                    <div class="section-label">Before</div>
                                                    <pre class="small mb-0" style="white-space: pre-wrap; word-break: break-word;"><?= e($before) ?></pre>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($after !== ''): ?>
                                                <div class="col-lg-6">
                                                    <div class="section-label">After</div>
                                                    <pre class="small mb-0" style="white-space: pre-wrap; word-break: break-word;"><?= e($after) ?></pre>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($context !== ''): ?>
                                                <div class="col-12">
                                                    <div class="section-label">Context</div>
                                                    <pre class="small mb-0" style="white-space: pre-wrap; word-break: break-word;"><?= e($context) ?></pre>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($entry['user_agent'])): ?>
                                                <div class="col-12">
                                                    <div class="section-label">User agent</div>
                                                    <div class="small text-muted"><?= field($entry, 'user_agent') ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card-footer">
        <?php View::partial('pagination', ['meta' => $meta]) ?>
        <p class="small text-muted mb-0 mt-2">
            The trail is append-only: entries can be read and written, never edited or removed. A refused attempt is
            recorded as carefully as a successful one, because somebody reaching for something they may not have is
            exactly what this trail is for.
            <?php if ($mayExport): ?>
                Exporting is itself recorded, with your name and the filters you used.
            <?php endif; ?>
        </p>
    </div>
</div>
