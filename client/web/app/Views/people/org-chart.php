<?php
/**
 * The reporting tree.
 *
 * Employee-service walks the whole hierarchy in one recursive statement and
 * hands back nested JSON, so the depth of the organisation costs nothing extra
 * to draw. All that is left here is to walk that structure, which the closure
 * below does by calling itself.
 *
 * Somebody whose manager has been removed comes back as an extra root rather
 * than vanishing, which is why more than one top-level node is normal.
 *
 * @var list<array<string, mixed>> $roots
 * @var array<string, mixed>       $summary
 */

use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;

$myEmployeeId = (string) Session::employeeId();
$seesEveryone = Session::can(Permissions::PROFILE_VIEW_ALL);
$seesTeam = Session::can(Permissions::PROFILE_VIEW_TEAM);

/**
 * Where a node links to, or an empty string when the viewer may not open it.
 *
 * A link that lands on "access denied" is worse than no link, so a name is
 * only made clickable when the record behind it is genuinely readable.
 */
$linkFor = static function (array $node) use ($myEmployeeId, $seesEveryone, $seesTeam): string {
    $id = (string) ($node['id'] ?? '');

    if ($id === '') {
        return '';
    }

    if ($myEmployeeId !== '' && $id === $myEmployeeId) {
        return '/profile';
    }

    if ($seesEveryone) {
        return '/people/' . rawurlencode($id);
    }

    if ($seesTeam && $myEmployeeId !== '' && (string) ($node['manager_id'] ?? '') === $myEmployeeId) {
        return '/people/' . rawurlencode($id);
    }

    return '';
};

/**
 * How far down the tree this page will draw.
 *
 * Employee-service walks the hierarchy with a cycle guard, so a loop should
 * never reach the client. This bound is here so that a malformed response
 * could still only produce a truncated chart rather than recursion that runs
 * until the request dies.
 */
$maxDepth = 32;

$renderBranch = static function (array $nodes, int $depth = 0) use (&$renderBranch, $linkFor, $myEmployeeId, $maxDepth): void {
    if ($depth >= $maxDepth) {
        ?>
        <ul class="org-tree">
            <li><div class="org-node text-muted small">The rest of this branch is deeper than the chart draws.</div></li>
        </ul>
        <?php

        return;
    }
    ?>
    <ul class="org-tree">
        <?php foreach ($nodes as $node): ?>
            <?php
            if (!is_array($node)) {
                continue;
            }

            $href = $linkFor($node);
            $reports = is_array($node['reports'] ?? null) ? $node['reports'] : [];
            $teamSize = (int) ($node['team_size'] ?? 0);
            $isMe = $myEmployeeId !== '' && (string) ($node['id'] ?? '') === $myEmployeeId;
            ?>
            <li>
                <div class="org-node <?= $isMe ? 'border-primary' : '' ?>">
                    <span class="avatar avatar-sm"><?= e(initials($node['full_name'] ?? '')) ?></span>
                    <span>
                        <span class="d-block fw-semibold">
                            <?php if ($href !== ''): ?>
                                <a href="<?= e($href) ?>"><?= field($node, 'full_name', 'Unnamed') ?></a>
                            <?php else: ?>
                                <?= field($node, 'full_name', 'Unnamed') ?>
                            <?php endif; ?>
                            <?php if ($isMe): ?>
                                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">You</span>
                            <?php endif; ?>
                            <?php if (isset($node['is_active']) && $node['is_active'] === false): ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                    Left
                                </span>
                            <?php endif; ?>
                        </span>
                        <span class="d-block small text-muted">
                            <?= field($node, 'designation_name', 'Designation not set') ?>
                            <?php if (!empty($node['department_name'])): ?>
                                &middot; <?= e($node['department_name']) ?>
                            <?php endif; ?>
                            <?php if ($teamSize > 0): ?>
                                &middot; <?= e((string) $teamSize) ?> in their line
                            <?php endif; ?>
                        </span>
                    </span>
                </div>

                <?php if ($reports !== []): ?>
                    <?php $renderBranch($reports, $depth + 1) ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
};

ob_start(); ?>
<a href="/people" class="btn btn-outline-secondary"><i class="fa fa-users"></i> People</a>
<a href="/directory" class="btn btn-outline-secondary"><i class="fa fa-address-book"></i> Directory</a>
<?php $actions = (string) ob_get_clean();

View::partial('page-header', [
    'title' => 'Organisation chart',
    'subtitle' => 'Who reports to whom, drawn from the reporting line on each record.',
    'actions' => $actions,
]);
?>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="tile tile-info">
            <div class="tile-icon"><i class="fa fa-users"></i></div>
            <div class="tile-label">People on the chart</div>
            <div class="tile-value tabular"><?= e((string) ($summary['total'] ?? 0)) ?></div>
            <div class="tile-hint">Everyone with a live record</div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="tile">
            <div class="tile-icon"><i class="fa fa-layer-group"></i></div>
            <div class="tile-label">Levels deep</div>
            <div class="tile-value tabular"><?= e((string) (((int) ($summary['max_depth'] ?? 0)) + 1)) ?></div>
            <div class="tile-hint">From the top of the tree to the deepest report</div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="tile">
            <div class="tile-icon"><i class="fa fa-sitemap"></i></div>
            <div class="tile-label">Top-level people</div>
            <div class="tile-value tabular"><?= e((string) count($roots)) ?></div>
            <div class="tile-hint">Nobody above them on the chart</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="fa fa-sitemap"></i> Reporting tree</div>
    <div class="card-body">
        <?php if ($roots === []): ?>
            <?php View::partial('empty-state', [
                'icon' => 'fa-sitemap',
                'title' => 'Nothing to draw yet',
                'message' => 'The chart appears once there are person records with a reporting line between them.',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <?php $renderBranch($roots) ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-footer text-muted">
        A name is a link when you are able to open that record. Everyone else appears as plain text, which
        is why some names on the chart are not clickable.
    </div>
</div>
