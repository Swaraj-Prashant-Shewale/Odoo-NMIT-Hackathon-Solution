<?php
/**
 * Leave balances, requests still waiting on somebody, and the last payslip.
 *
 * @var array<string, array{available: bool, data: mixed}> $sections
 * @var callable $data
 * @var callable $offline
 * @var callable $rows
 * @var callable $days
 * @var callable $placeholder
 */

use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;

$canApply = Session::can(Permissions::LEAVE_APPLY);
?>

<?php if (isset($sections['leave_balances'])): ?>
    <div class="card mb-4">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="fa fa-umbrella-beach"></i> Your leave balances</span>
            <?php if ($canApply): ?>
                <a class="btn btn-primary btn-sm" href="/leave/apply">
                    <i class="fa fa-plus"></i> Apply for time off
                </a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($offline('leave_balances')): ?>
                <p class="text-muted small text-center mb-0">
                    <i class="fa fa-plug"></i> Your balances are not loading at the moment. Nothing has been deducted.
                </p>
            <?php else: ?>
                <?php
                $balances = $data('leave_balances');
                $types = $rows($balances['types'] ?? null);
                ?>
                <?php if ($types === []): ?>
                    <?php View::partial('empty-state', [
                        'icon' => 'fa-umbrella-beach',
                        'title' => 'No leave has been allocated yet',
                        'message' => 'Once your leave policy is assigned, your entitlement appears here.',
                    ]) ?>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($types as $type): ?>
                            <?php
                            $entitled = (float) ($type['entitled_days'] ?? 0);
                            $used = (float) ($type['used_days'] ?? 0);
                            $available = (float) ($type['available_days'] ?? 0);
                            $pending = (float) ($type['pending_days'] ?? 0);
                            $consumed = $entitled > 0 ? percent(($used / $entitled) * 100) : 0;
                            $bar = $consumed >= 90 ? 'bg-danger' : ($consumed >= 70 ? 'bg-warning' : 'bg-primary');
                            ?>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-baseline gap-2">
                                    <span class="fw-semibold truncate"><?= e((string) ($type['leave_type'] ?? 'Leave')) ?></span>
                                    <span class="small text-muted tabular text-nowrap">
                                        <?= e($days($available)) ?> of <?= e($days($entitled)) ?> days left
                                    </span>
                                </div>
                                <div class="progress progress-lg mt-2">
                                    <div class="progress-bar <?= e($bar) ?>" style="width: <?= e((string) $consumed) ?>%"
                                         role="progressbar"
                                         aria-valuenow="<?= e((string) $consumed) ?>"
                                         aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <div class="small text-muted mt-1">
                                    <?= e($days($used)) ?> used
                                    <?php if ($pending > 0): ?>
                                        · <?= e($days($pending)) ?> awaiting approval
                                    <?php endif; ?>
                                    <?php if (!empty($type['category'])): ?>
                                        · <?= e(label((string) $type['category'])) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (isset($balances['total_available_days'])): ?>
                        <p class="small text-muted mb-0 mt-3">
                            <?= e($days($balances['total_available_days'])) ?> days available in total.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="card-footer">
            <a href="/leave-balances">See how every balance was worked out</a>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($sections['leave_pending']) || isset($sections['latest_payslip'])): ?>
    <div class="row g-4 mb-4">

        <?php if (isset($sections['leave_pending'])): ?>
            <div class="col-lg-6">
                <?php if ($offline('leave_pending')): ?>
                    <?php $placeholder('Requests awaiting approval', 'The leave service did not answer this time.') ?>
                <?php else: ?>
                    <?php
                    $pendingLeave = $data('leave_pending');
                    $requests = $rows($pendingLeave['requests'] ?? null);
                    $pendingCount = (int) ($pendingLeave['count'] ?? count($requests));
                    ?>
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="fa fa-hourglass-half"></i> Awaiting approval</span>
                            <?php if ($pendingCount > 0): ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                    <?= e((string) $pendingCount) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if ($requests === []): ?>
                                <p class="text-muted small mb-0 text-center py-3">
                                    <i class="fa fa-check-circle"></i>
                                    Nothing of yours is waiting on anybody.
                                </p>
                            <?php else: ?>
                                <div>
                                    <?php foreach ($requests as $request): ?>
                                        <div class="stat-row gap-2">
                                            <div class="truncate">
                                                <a href="/leave/<?= e((string) ($request['id'] ?? '')) ?>"
                                                   class="fw-semibold">
                                                    <?= e((string) ($request['leave_type'] ?? 'Leave')) ?>
                                                </a>
                                                <div class="small text-muted">
                                                    <?= e(date_display((string) ($request['starts_on'] ?? ''))) ?>
                                                    to <?= e(date_display((string) ($request['ends_on'] ?? ''))) ?>
                                                </div>
                                            </div>
                                            <span class="small text-muted tabular text-nowrap">
                                                <?= e($days($request['day_count'] ?? 0)) ?> days
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer">
                            <a href="/leave">All of my requests</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($sections['latest_payslip'])): ?>
            <div class="col-lg-6">
                <?php if ($offline('latest_payslip')): ?>
                    <?php $placeholder('Latest payslip', 'Payroll did not answer this time. Nothing about your pay has changed.') ?>
                <?php else: ?>
                    <?php
                    $latest = $data('latest_payslip');
                    $payslip = is_array($latest['payslip'] ?? null) ? $latest['payslip'] : null;
                    ?>
                    <div class="card h-100">
                        <div class="card-header"><i class="fa fa-file-invoice-dollar"></i> Latest payslip</div>
                        <div class="card-body">
                            <?php if ($payslip === null): ?>
                                <p class="text-muted small mb-0 text-center py-3">
                                    No payslip has been published for you yet.
                                </p>
                            <?php else: ?>
                                <div class="section-label">
                                    <?= e((string) ($payslip['period_label'] ?? $payslip['period'] ?? '')) ?>
                                </div>
                                <div class="tile-value tabular"><?= e(money($payslip['net_minor'] ?? 0)) ?></div>
                                <div class="tile-hint">
                                    Net pay
                                    <?php if (!empty($payslip['published_on'])): ?>
                                        · published <?= e(date_display((string) $payslip['published_on'])) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer">
                            <?php if ($payslip !== null && !empty($payslip['id'])): ?>
                                <a href="/payroll/payslips/<?= e((string) $payslip['id']) ?>">
                                    Open the full payslip
                                </a>
                            <?php else: ?>
                                <a href="/payroll">My payroll</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
<?php endif; ?>
