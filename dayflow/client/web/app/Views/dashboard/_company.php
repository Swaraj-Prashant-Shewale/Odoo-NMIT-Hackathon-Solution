<?php
/**
 * The organisation, for the people whose job it is.
 *
 * @var array<string, array{available: bool, data: mixed}> $sections
 * @var array<string, array<string, mixed>> $charts
 * @var float|null $attendanceRate
 * @var string     $attendanceRatePeriod
 * @var list<string> $companyKeys
 * @var callable $data
 * @var callable $offline
 * @var callable $rows
 * @var callable $anyOf
 * @var callable $days
 * @var callable $placeholder
 */

use App\Core\Session;
use Dayflow\Kernel\Security\Permissions;

if (!$anyOf($companyKeys)) {
    return;
}

$headcountReady = isset($sections['headcount']) && !$offline('headcount');
$headcount = $data('headcount');
?>

<div class="section-label mt-4">The organisation</div>

<div class="row g-3 mb-4">
    <?php if (isset($sections['headcount'])): ?>
        <?php if (!$headcountReady): ?>
            <div class="col-12 col-lg-9">
                <?php $placeholder('Headcount', 'The employee service did not answer this time.') ?>
            </div>
        <?php else: ?>
            <div class="col-6 col-lg-3">
                <div class="tile">
                    <div class="tile-icon"><i class="fa fa-users"></i></div>
                    <div class="tile-label">Headcount</div>
                    <div class="tile-value tabular"><?= e((string) (int) ($headcount['headcount'] ?? 0)) ?></div>
                    <div class="tile-hint">
                        <?= e($days($headcount['average_tenure_years'] ?? 0)) ?> years average tenure
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="tile tile-success">
                    <div class="tile-icon"><i class="fa fa-user-plus"></i></div>
                    <div class="tile-label">Joiners</div>
                    <div class="tile-value tabular"><?= e((string) (int) ($headcount['joiners_this_month'] ?? 0)) ?></div>
                    <div class="tile-hint">this month</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="tile tile-warning">
                    <div class="tile-icon"><i class="fa fa-user-minus"></i></div>
                    <div class="tile-label">Leavers</div>
                    <div class="tile-value tabular"><?= e((string) (int) ($headcount['leavers_this_month'] ?? 0)) ?></div>
                    <div class="tile-hint">
                        <?php $net = (int) ($headcount['net_change'] ?? 0); ?>
                        net <?= e(($net > 0 ? '+' : '') . $net) ?> this month
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (isset($sections['attendance_rate_trend'])): ?>
        <div class="col-6 col-lg-3">
            <div class="tile tile-info">
                <div class="tile-icon"><i class="fa fa-clipboard-check"></i></div>
                <div class="tile-label">Attendance</div>
                <div class="tile-value tabular">
                    <?= $attendanceRate === null ? '—' : e($days($attendanceRate) . '%') ?>
                </div>
                <div class="tile-hint">
                    <?= $attendanceRatePeriod === '' ? 'not available right now' : e($attendanceRatePeriod) ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="row g-4 mb-4">
    <?php if (isset($sections['workforce_composition'])): ?>
        <div class="col-lg-7">
            <?php if ($offline('workforce_composition') || !isset($charts['headcount_by_department'])): ?>
                <?php $placeholder('Headcount by department', 'The department split is not available at the moment.') ?>
            <?php else: ?>
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-sitemap"></i> Headcount by department</div>
                    <div class="card-body">
                        <div data-chart='<?= ejs($charts['headcount_by_department']) ?>'></div>
                        <?php $byType = $rows($data('workforce_composition')['by_employment_type'] ?? null); ?>
                        <?php if ($byType !== []): ?>
                            <div class="section-label mt-3">By employment type</div>
                            <?php foreach ($byType as $type): ?>
                                <div class="stat-row">
                                    <span class="stat-key"><?= e((string) ($type['label'] ?? '')) ?></span>
                                    <span class="stat-val tabular"><?= e((string) (int) ($type['value'] ?? 0)) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($sections['leave_utilisation'])): ?>
        <div class="col-lg-5">
            <?php if ($offline('leave_utilisation') || !isset($charts['leave_utilisation'])): ?>
                <?php $placeholder('Leave utilisation', 'The leave service did not answer this time.') ?>
            <?php else: ?>
                <?php $utilisation = $data('leave_utilisation'); ?>
                <?php $utilisationPeriod = is_array($utilisation['period'] ?? null) ? $utilisation['period'] : []; ?>
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-chart-pie"></i> Leave taken by type</div>
                    <div class="card-body">
                        <div data-chart='<?= ejs($charts['leave_utilisation']) ?>'></div>
                    </div>
                    <?php if (!empty($utilisationPeriod['from'])): ?>
                        <div class="card-footer text-muted">
                            Since <?= e(date_display((string) $utilisationPeriod['from'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="row g-4 mb-4">
    <?php if (isset($sections['attendance_rate_trend'])): ?>
        <div class="col-lg-7">
            <?php if ($offline('attendance_rate_trend') || !isset($charts['attendance_trend'])): ?>
                <?php $placeholder('Attendance trend', 'The attendance service did not answer this time.') ?>
            <?php else: ?>
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-chart-line"></i> Attendance rate, last six months</div>
                    <div class="card-body">
                        <div data-chart='<?= ejs($charts['attendance_trend']) ?>'></div>
                        <?php $missing = (int) ($charts['attendance_trend_gaps']['missing'] ?? 0); ?>
                        <?php if ($missing > 0): ?>
                            <p class="small text-muted mb-0 mt-2">
                                <?= e((string) $missing) ?>
                                <?= $missing === 1 ? 'month is' : 'months are' ?>
                                left off the line because no figure could be read for
                                <?= $missing === 1 ? 'it' : 'them' ?>.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($sections['absence_by_department'])): ?>
        <div class="col-lg-5">
            <?php if ($offline('absence_by_department')): ?>
                <?php $placeholder('Absence by department', 'The attendance service did not answer this time.') ?>
            <?php else: ?>
                <?php $absence = $rows($data('absence_by_department')['departments'] ?? null); ?>
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-user-slash"></i> Absence this month</div>
                    <div class="card-body">
                        <?php if ($absence === []): ?>
                            <p class="text-muted small mb-0 text-center py-3">
                                No absence has been recorded this month.
                            </p>
                        <?php else: ?>
                            <?php foreach ($absence as $department): ?>
                                <div class="stat-row">
                                    <span class="stat-key truncate"><?= e((string) ($department['department'] ?? 'Unassigned')) ?></span>
                                    <span class="stat-val tabular text-nowrap">
                                        <?= e($days($department['absent_days'] ?? 0)) ?> days
                                        <span class="text-muted">(<?= e($days($department['absence_rate'] ?? 0)) ?>%)</span>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (Session::can(Permissions::ATTENDANCE_VIEW_ALL)): ?>
                        <div class="card-footer">
                            <a href="/attendance/register">The attendance register</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="row g-4 mb-4">
    <?php if (isset($sections['onboarding'])): ?>
        <div class="col-md-6 col-lg-4">
            <?php if ($offline('onboarding')): ?>
                <?php $placeholder('Onboarding', 'The employee service did not answer this time.') ?>
            <?php else: ?>
                <?php $onboarding = $data('onboarding'); ?>
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-user-clock"></i> Onboarding</div>
                    <div class="card-body">
                        <div class="tile-value tabular"><?= e((string) (int) ($onboarding['in_progress'] ?? 0)) ?></div>
                        <div class="tile-hint mb-2">in progress</div>
                        <div class="stat-row">
                            <span class="stat-key">Not started</span>
                            <span class="stat-val tabular"><?= e((string) (int) ($onboarding['not_started'] ?? 0)) ?></span>
                        </div>
                    </div>
                    <?php if (Session::can(Permissions::ONBOARDING_MANAGE)): ?>
                        <div class="card-footer"><a href="/onboarding">Open onboarding</a></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($sections['documents_expiring'])): ?>
        <div class="col-md-6 col-lg-4">
            <?php if ($offline('documents_expiring')): ?>
                <?php $placeholder('Expiring documents', 'The document store did not answer this time.') ?>
            <?php else: ?>
                <?php
                $expiring = $data('documents_expiring');
                $documents = $rows($expiring['documents'] ?? null);
                $window = (int) ($expiring['window_days'] ?? 30);
                ?>
                <div class="card h-100">
                    <div class="card-header">
                        <i class="fa fa-file-alt"></i> Expiring in <?= e((string) $window) ?> days
                    </div>
                    <div class="card-body">
                        <?php if ($documents === []): ?>
                            <p class="text-muted small mb-0 text-center py-3">
                                <i class="fa fa-check-circle"></i>
                                Nothing expires in the next <?= e((string) $window) ?> days.
                            </p>
                        <?php else: ?>
                            <div class="tile-value tabular"><?= e((string) (int) ($expiring['count'] ?? count($documents))) ?></div>
                            <div class="tile-hint mb-2">documents need renewing</div>
                            <?php foreach ($documents as $document): ?>
                                <div class="stat-row">
                                    <span class="stat-key truncate">
                                        <?= e((string) ($document['document_type'] ?? 'Document')) ?>
                                        <?php if (!empty($document['employee_name'])): ?>
                                            · <?= e((string) $document['employee_name']) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="stat-val text-nowrap">
                                        <?= e(date_display((string) ($document['expires_on'] ?? ''))) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($sections['training_compliance'])): ?>
        <div class="col-md-6 col-lg-4">
            <?php if ($offline('training_compliance')): ?>
                <?php $placeholder('Training compliance', 'The learning service did not answer this time.') ?>
            <?php else: ?>
                <?php
                $compliance = $data('training_compliance');
                $rate = percent($compliance['compliance_rate'] ?? 0);
                ?>
                <div class="card h-100">
                    <div class="card-header"><i class="fa fa-certificate"></i> Training compliance</div>
                    <div class="card-body">
                        <div class="tile-value tabular"><?= e((string) $rate) ?>%</div>
                        <div class="tile-hint mb-2">
                            <?= e((string) (int) ($compliance['compliant'] ?? 0)) ?> of
                            <?= e((string) (int) ($compliance['total'] ?? 0)) ?> mandatory enrolments complete
                        </div>
                        <div class="progress progress-lg">
                            <div class="progress-bar <?= $rate < 80 ? 'bg-warning' : 'bg-success' ?>"
                                 style="width: <?= e((string) $rate) ?>%"
                                 role="progressbar"
                                 aria-valuenow="<?= e((string) $rate) ?>"
                                 aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                    <?php if (Session::can(Permissions::LEARNING_ASSIGN_ANY)): ?>
                        <div class="card-footer"><a href="/learning/compliance">Who is outstanding</a></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (isset($sections['headcount_trend'])): ?>
    <div class="card mb-4">
        <div class="card-header"><i class="fa fa-chart-area"></i> Headcount over the last twelve months</div>
        <div class="card-body">
            <?php if ($offline('headcount_trend') || !isset($charts['headcount_trend'])): ?>
                <p class="text-muted small text-center mb-0">
                    <i class="fa fa-plug"></i> The headcount trend is not available at the moment.
                </p>
            <?php else: ?>
                <div data-chart='<?= ejs($charts['headcount_trend']) ?>'></div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
