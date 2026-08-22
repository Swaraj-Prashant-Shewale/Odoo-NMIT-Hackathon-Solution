<?php
/**
 * Time off: balances across the top, then the caller's requests.
 *
 * @var list<array<string, mixed>>   $balances   One row per active leave type.
 * @var list<array<string, mixed>>   $records    Leave requests for the open tab.
 * @var array<string, mixed>         $meta       Pagination block from the API.
 * @var array<string, string>        $names      employee_id => display name.
 * @var string                       $tab        "mine" or "others".
 * @var string                       $status     The active status filter, or "".
 * @var list<string>                 $statuses   The statuses offered as filters.
 * @var bool                         $seesOthers Whether the second tab is offered.
 * @var string|null                  $employeeId The caller's own employee record.
 * @var string                       $today
 */

use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;

/** Leave types carry their own colour; anything not a plain hex is refused. */
$hex = static fn (mixed $value): string => is_string($value)
    && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1
        ? strtoupper($value)
        : '#64748B';

/** A day count reads better without its trailing zeroes: 1.00 is just "1". */
$days = static fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';

$canApply = Session::can(Permissions::LEAVE_APPLY);
$canCancel = Session::can(Permissions::LEAVE_CANCEL_SELF);

$filterLink = static function (string $forTab, string $forStatus): string {
    $query = array_filter(['tab' => $forTab, 'status' => $forStatus], static fn (string $v): bool => $v !== '');

    return '/leave' . ($query === [] ? '' : '?' . http_build_query($query));
};

ob_start(); ?>
    <a href="/leave-calendar" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-calendar-alt"></i> Leave calendar
    </a>
    <a href="/leave-balances" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-list-ol"></i> Balance statement
    </a>
    <?php if ($canApply): ?>
        <a href="/leave/apply" class="btn btn-primary btn-sm">
            <i class="fa fa-plus"></i> Apply for time off
        </a>
    <?php endif; ?>
<?php
$actions = (string) ob_get_clean();

View::partial('page-header', [
    'title' => 'Time off',
    'subtitle' => 'Your entitlement, everything you have booked, and where each request has got to.',
    'actions' => $actions,
]);
?>

<?php if ($balances === []): ?>
    <div class="card mb-4">
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-umbrella-beach',
                'title' => 'No leave types have been set up yet',
                'message' => 'Once HR publishes the leave policy your entitlement will appear here.',
            ]) ?>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <?php foreach ($balances as $balance): ?>
            <?php
            $colour = $hex($balance['colour'] ?? null);

            $entitled = (float) ($balance['opening_days'] ?? 0)
                + (float) ($balance['accrued_days'] ?? 0)
                + (float) ($balance['carried_forward_days'] ?? 0)
                + (float) ($balance['adjusted_days'] ?? 0);

            if ($entitled <= 0.0) {
                $entitled = (float) ($balance['annual_quota_days'] ?? 0);
            }

            $used = (float) ($balance['used_days'] ?? 0);
            $pending = (float) ($balance['pending_days'] ?? 0);
            $consumed = $used + $pending;
            $share = $entitled > 0.0 ? percent($consumed / $entitled * 100) : 0;
            ?>
            <div class="col-6 col-lg-3">
                <div class="tile h-100">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <div style="min-width: 0;">
                            <span class="tile-label truncate d-block"><?= e($balance['leave_type_name'] ?? 'Leave') ?></span>
                            <div class="tile-value tabular">
                                <?= e($days($balance['available_days'] ?? 0)) ?>
                                <span class="fs-6 fw-normal text-muted">/ <?= e($days($entitled)) ?></span>
                            </div>
                        </div>
                        <span class="tile-icon"
                              style="background: <?= e($colour) ?>1A; color: <?= e($colour) ?>;">
                            <i class="fa fa-umbrella-beach"></i>
                        </span>
                    </div>

                    <div class="progress mt-2" role="progressbar"
                         aria-label="<?= e($balance['leave_type_name'] ?? 'Leave') ?> consumed"
                         aria-valuenow="<?= e((string) $share) ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar"
                             style="width: <?= e((string) $share) ?>%; background: <?= e($colour) ?>;"></div>
                    </div>

                    <div class="tile-hint mt-2 d-flex justify-content-between align-items-baseline gap-2">
                        <span class="truncate">
                            <?= e($days($used)) ?> taken
                            <?php if ($pending > 0): ?>
                                &middot; <?= e($days($pending)) ?> awaiting approval
                            <?php endif; ?>
                        </span>
                        <?php if ($canApply): ?>
                            <a class="text-decoration-none flex-shrink-0"
                               href="/leave/apply?leave_type_id=<?= e(urlencode((string) ($balance['leave_type_id'] ?? ''))) ?>">
                                Apply <i class="fa fa-arrow-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <ul class="nav nav-tabs card-header-tabs mb-0">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'mine' ? 'active' : '' ?>" href="<?= e($filterLink('mine', $status)) ?>">
                    <i class="fa fa-user"></i> My requests
                </a>
            </li>
            <?php if ($seesOthers): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $tab === 'others' ? 'active' : '' ?>" href="<?= e($filterLink('others', $status)) ?>">
                        <i class="fa fa-users"></i>
                        <?= Session::can(Permissions::LEAVE_VIEW_ALL) ? 'Everyone' : 'My team' ?>
                    </a>
                </li>
            <?php endif; ?>
        </ul>

        <div class="d-flex flex-wrap gap-2 align-items-center">
            <form method="get" action="/leave" class="d-flex gap-2 align-items-center m-0">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">

                <label class="visually-hidden" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status"
                        style="width: auto;" data-submit-on-change>
                    <option value="">All statuses</option>
                    <?php foreach ($statuses as $option): ?>
                        <option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>>
                            <?= e(label($option)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button></noscript>
            </form>

            <!-- Filters the rows already on the page; it never asks for more. -->
            <label class="visually-hidden" for="requestSearch">Filter the requests below</label>
            <input type="search" class="form-control form-control-sm" id="requestSearch"
                   style="width: auto;" placeholder="Filter these rows"
                   data-filter-table="leaveRequests">
        </div>
    </div>

    <?php if ($records === []): ?>
        <div class="card-body">
            <?php View::partial('empty-state', [
                'icon' => 'fa-inbox',
                'title' => $tab === 'mine' ? 'No leave requests here yet' : 'Nothing booked by anyone here',
                'message' => $status === ''
                    ? 'When a request is filed it will appear in this list with its progress.'
                    : 'No request currently has the status you filtered on.',
                'actionLabel' => $tab === 'mine' && $canApply ? 'Apply for time off' : null,
                'actionHref' => $tab === 'mine' && $canApply ? '/leave/apply' : null,
            ]) ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table align-middle" id="leaveRequests">
                <thead>
                    <tr>
                        <?php if ($tab === 'others'): ?>
                            <th scope="col">Employee</th>
                        <?php endif; ?>
                        <th scope="col">Type</th>
                        <th scope="col">Dates</th>
                        <th scope="col" class="text-end">Days</th>
                        <th scope="col">Applied</th>
                        <th scope="col">Status</th>
                        <th scope="col">Approver</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <?php
                        $requestId = (string) ($record['id'] ?? '');
                        $type = is_array($record['leave_type'] ?? null) ? $record['leave_type'] : [];
                        $colour = $hex($type['colour'] ?? null);
                        $owner = (string) ($record['employee_id'] ?? '');
                        $approver = (string) ($record['approver_id'] ?? '');
                        $state = (string) ($record['status'] ?? '');
                        $startsOn = (string) ($record['starts_on'] ?? '');

                        $isMine = $employeeId !== null && $employeeId === $owner;
                        $mayCancel = $isMine && $canCancel
                            && ($state === 'pending' || ($state === 'approved' && $startsOn > $today));
                        ?>
                        <tr>
                            <?php if ($tab === 'others'): ?>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar avatar-sm"><?= e(initials($names[$owner] ?? '?')) ?></span>
                                        <span class="truncate"><?= e($names[$owner] ?? 'Unnamed employee') ?></span>
                                    </div>
                                </td>
                            <?php endif; ?>

                            <td>
                                <span class="d-inline-block rounded-circle align-middle me-1"
                                      style="width: 9px; height: 9px; background: <?= e($colour) ?>;"></span>
                                <?= e($type['name'] ?? 'Leave') ?>
                                <?php if (($type['is_paid'] ?? true) === false): ?>
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle ms-1">Unpaid</span>
                                <?php endif; ?>
                            </td>

                            <td class="tabular">
                                <?= e(date_display($startsOn)) ?>
                                <?php if ((string) ($record['ends_on'] ?? '') !== $startsOn): ?>
                                    &ndash; <?= e(date_display($record['ends_on'] ?? null)) ?>
                                <?php endif; ?>
                                <?php if (($record['is_half_day'] ?? false) === true): ?>
                                    <div class="small text-muted"><?= e(label($record['half_day_period'] ?? 'half day')) ?></div>
                                <?php endif; ?>
                            </td>

                            <td class="text-end tabular"><?= e($days($record['day_count'] ?? 0)) ?></td>

                            <td class="small text-muted"><?= e(date_display($record['applied_at'] ?? null)) ?></td>

                            <td><?= badge($state) ?></td>

                            <td class="small truncate">
                                <?= $approver === '' ? '<span class="text-muted">Not routed yet</span>' : e($names[$approver] ?? 'Unnamed approver') ?>
                            </td>

                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <a class="btn btn-sm btn-outline-secondary" href="/leave/<?= e(urlencode($requestId)) ?>">
                                        <i class="fa fa-eye"></i> View
                                    </a>
                                    <?php if ($mayCancel): ?>
                                        <form method="post" action="/leave/<?= e(urlencode($requestId)) ?>/cancel" class="m-0">
                                            <?= Csrf::field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    data-busy-label="Cancelling..."
                                                    data-confirm="Cancel this leave request? The days go back on your balance.">
                                                <i class="fa fa-times"></i> Cancel
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-body py-2 text-center text-muted small" data-filter-empty style="display: none;">
            No row here matches what you typed.
        </div>

        <?php if ((int) ($meta['total_pages'] ?? 1) > 1): ?>
            <div class="card-footer">
                <?php View::partial('pagination', ['meta' => $meta]) ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
