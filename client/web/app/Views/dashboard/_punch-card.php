<?php
/**
 * Today's attendance, and the button that records it.
 *
 * The most important element on the page: whatever else is happening, the
 * person standing at their desk at nine in the morning needs one button.
 *
 * @var array<string, array{available: bool, data: mixed}> $sections
 * @var string   $today      Calendar date the dashboard was assembled for.
 * @var string   $serverTime Rendered clock, replaced by the live one once JS runs.
 * @var callable $data
 * @var callable $offline
 */

use App\Core\Csrf;
use App\Core\Session;
use Dayflow\Kernel\Security\Permissions;

// Absent rather than unavailable: this person has no attendance of their own,
// so there is nothing to stand in for.
if (!isset($sections['attendance_today'])) {
    return;
}

$attendance = $data('attendance_today');

$checkedInAt = (string) ($attendance['checked_in_at'] ?? '');
$checkedOutAt = (string) ($attendance['checked_out_at'] ?? '');
$isWorking = $checkedInAt !== '' && $checkedOutAt === '';
$isDone = $checkedInAt !== '' && $checkedOutAt !== '';

// The counter counts from an epoch second the server worked out, so a browser
// clock that is minutes out only skews the display.
$since = $checkedInAt === '' ? 0 : (int) (strtotime($checkedInAt) ?: 0);

$shift = is_array($attendance['shift'] ?? null) ? $attendance['shift'] : [];
$shiftName = (string) ($shift['name'] ?? '');
$shiftStart = substr((string) ($shift['starts_at'] ?? ''), 0, 5);
$shiftEnd = substr((string) ($shift['ends_at'] ?? ''), 0, 5);

$shiftHint = $shiftName;

if ($shiftStart !== '' && $shiftEnd !== '') {
    $shiftHint .= ($shiftName === '' ? '' : ' · ') . $shiftStart . ' to ' . $shiftEnd;
}

$canPunch = Session::can(Permissions::ATTENDANCE_PUNCH);
?>
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-4 align-items-center">

            <div class="col-lg-4">
                <div class="section-label">Right now</div>
                <div class="punch-clock tabular" data-live-clock><?= e($serverTime) ?></div>
                <div class="punch-date">
                    <?= e(date_display($today)) ?>
                    <?php if ($shiftHint !== ''): ?>
                        <br><i class="fa fa-business-time"></i> <?= e($shiftHint) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-4">
                <?php if ($offline('attendance_today')): ?>
                    <div class="text-muted small">
                        <i class="fa fa-plug"></i>
                        Today's attendance record is not loading at the moment. Your punches are still being kept.
                    </div>
                <?php elseif ($isDone): ?>
                    <div class="section-label">Your day</div>
                    <div class="stat-row">
                        <span class="stat-key">Checked in</span>
                        <span class="stat-val tabular"><?= e(time_display($checkedInAt)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Checked out</span>
                        <span class="stat-val tabular"><?= e(time_display($checkedOutAt)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Hours worked</span>
                        <span class="stat-val tabular"><?= e(hours($attendance['worked_seconds'] ?? 0)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Status</span>
                        <span class="stat-val"><?= badge((string) ($attendance['status'] ?? '')) ?></span>
                    </div>
                <?php elseif ($isWorking): ?>
                    <div class="section-label">On the clock</div>
                    <div class="stat-row">
                        <span class="stat-key">Checked in at</span>
                        <span class="stat-val tabular"><?= e(time_display($checkedInAt)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-key">Working for</span>
                        <span class="stat-val tabular" data-elapsed-since="<?= e((string) $since) ?>">
                            <?= e(hours($attendance['worked_seconds'] ?? 0)) ?>
                        </span>
                    </div>
                    <?php if (!empty($attendance['is_late'])): ?>
                        <div class="stat-row">
                            <span class="stat-key">Arrival</span>
                            <span class="stat-val"><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Late</span></span>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="section-label">Not started</div>
                    <p class="mb-0 text-muted">
                        You have not checked in today. Your hours start counting the moment you do.
                    </p>
                <?php endif; ?>
            </div>

            <div class="col-lg-4 text-lg-end">
                <?php if (!$canPunch): ?>
                    <a class="btn btn-outline-secondary" href="/attendance">
                        <i class="fa fa-calendar-alt"></i> My attendance
                    </a>
                <?php elseif ($isDone): ?>
                    <div class="text-success fw-semibold mb-2">
                        <i class="fa fa-check-circle"></i> Day recorded
                    </div>
                    <a class="btn btn-outline-secondary btn-sm" href="/attendance">
                        <i class="fa fa-calendar-alt"></i> My attendance
                    </a>
                <?php elseif ($isWorking): ?>
                    <form method="post" action="/attendance/check-out" class="mb-2">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-danger btn-lg w-100"
                                data-busy-label="Checking out…"
                                data-confirm="Check out and close your day?">
                            <i class="fa fa-sign-out-alt"></i> Check out
                        </button>
                    </form>
                    <a class="small" href="/attendance/regularise">Forgot to punch? Request a correction</a>
                <?php else: ?>
                    <form method="post" action="/attendance/check-in" class="mb-2">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-primary btn-lg w-100"
                                data-busy-label="Checking in…">
                            <i class="fa fa-sign-in-alt"></i> Check in
                        </button>
                    </form>
                    <a class="small" href="/attendance/regularise">Missed a day? Request a correction</a>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
