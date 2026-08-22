<?php

declare(strict_types=1);

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\DemoCohort;
use Dayflow\Kernel\Support\Env;

/**
 * Reference and demonstration data for the leave service.
 *
 * Runs on every boot, so every statement is written to be a no-op the second
 * time. Reference data (the leave catalogue and the entitlement policy) is
 * always present; the sample people, balances and requests only appear when
 * SEED_DEMO_DATA is on.
 *
 * Identifiers for anything another service also seeds are copied from
 * docs/SEED-IDENTIFIERS.md verbatim. Rows this service owns outright get a
 * deterministic identifier derived from their natural key, which is what makes
 * re-running the seed harmless.
 */

$pdo = Connection::pdo();

/** Stable identifier for a row this service owns, derived from its natural key. */
$id = static function (string $key): string {
    $hash = md5('dayflow.leave.' . $key);

    return sprintf(
        '%s-%s-5%s-%s%s-%s',
        substr($hash, 0, 8),
        substr($hash, 8, 4),
        substr($hash, 13, 3),
        ['8', '9', 'a', 'b'][hexdec($hash[16]) % 4],
        substr($hash, 17, 3),
        substr($hash, 20, 12)
    );
};

// ---------------------------------------------------------------------------
// Reference data: the leave catalogue
// ---------------------------------------------------------------------------

$leaveTypes = [
    [
        'code' => 'EARNED', 'name' => 'Earned Leave', 'category' => 'paid', 'colour' => '#2563EB',
        'annual_quota_days' => 18, 'accrual_frequency' => 'monthly', 'accrual_days' => 1.5,
        'max_carry_forward_days' => 10, 'allows_half_day' => true,
        'requires_document_after_days' => null, 'min_notice_days' => 3,
        'max_consecutive_days' => 15, 'is_paid' => true, 'applies_to_gender' => 'any',
    ],
    [
        'code' => 'SICK', 'name' => 'Sick Leave', 'category' => 'sick', 'colour' => '#DC2626',
        'annual_quota_days' => 12, 'accrual_frequency' => 'none', 'accrual_days' => 0,
        'max_carry_forward_days' => 0, 'allows_half_day' => true,
        // Nobody can give notice of falling ill, so sick leave may be applied
        // for after the fact; a medical note is asked for once it runs long.
        'requires_document_after_days' => 3, 'min_notice_days' => 0,
        'max_consecutive_days' => 7, 'is_paid' => true, 'applies_to_gender' => 'any',
    ],
    [
        'code' => 'CASUAL', 'name' => 'Casual Leave', 'category' => 'casual', 'colour' => '#F59E0B',
        'annual_quota_days' => 6, 'accrual_frequency' => 'none', 'accrual_days' => 0,
        'max_carry_forward_days' => 0, 'allows_half_day' => true,
        'requires_document_after_days' => null, 'min_notice_days' => 1,
        'max_consecutive_days' => 3, 'is_paid' => true, 'applies_to_gender' => 'any',
    ],
    [
        'code' => 'UNPAID', 'name' => 'Unpaid Leave', 'category' => 'unpaid', 'colour' => '#6B7280',
        'annual_quota_days' => 0, 'accrual_frequency' => 'none', 'accrual_days' => 0,
        'max_carry_forward_days' => 0, 'allows_half_day' => true,
        'requires_document_after_days' => null, 'min_notice_days' => 5,
        'max_consecutive_days' => 30, 'is_paid' => false, 'applies_to_gender' => 'any',
    ],
    [
        'code' => 'MATERNITY', 'name' => 'Maternity Leave', 'category' => 'maternity', 'colour' => '#DB2777',
        'annual_quota_days' => 182, 'accrual_frequency' => 'none', 'accrual_days' => 0,
        'max_carry_forward_days' => 0, 'allows_half_day' => false,
        'requires_document_after_days' => 30, 'min_notice_days' => 30,
        'max_consecutive_days' => 182, 'is_paid' => true, 'applies_to_gender' => 'female',
    ],
    [
        'code' => 'PATERNITY', 'name' => 'Paternity Leave', 'category' => 'paternity', 'colour' => '#7C3AED',
        'annual_quota_days' => 15, 'accrual_frequency' => 'none', 'accrual_days' => 0,
        'max_carry_forward_days' => 0, 'allows_half_day' => false,
        'requires_document_after_days' => 15, 'min_notice_days' => 7,
        'max_consecutive_days' => 15, 'is_paid' => true, 'applies_to_gender' => 'male',
    ],
    [
        'code' => 'COMPOFF', 'name' => 'Compensatory Off', 'category' => 'comp_off', 'colour' => '#059669',
        // Comp off is never granted in advance: the days arrive as a balance
        // adjustment once the extra work has actually been done.
        'annual_quota_days' => 0, 'accrual_frequency' => 'none', 'accrual_days' => 0,
        'max_carry_forward_days' => 0, 'allows_half_day' => true,
        'requires_document_after_days' => null, 'min_notice_days' => 1,
        'max_consecutive_days' => 2, 'is_paid' => true, 'applies_to_gender' => 'any',
    ],
    [
        'code' => 'BEREAVE', 'name' => 'Bereavement Leave', 'category' => 'bereavement', 'colour' => '#334155',
        'annual_quota_days' => 5, 'accrual_frequency' => 'none', 'accrual_days' => 0,
        'max_carry_forward_days' => 0, 'allows_half_day' => false,
        'requires_document_after_days' => null, 'min_notice_days' => 0,
        'max_consecutive_days' => 5, 'is_paid' => true, 'applies_to_gender' => 'any',
    ],
];

$insertType = $pdo->prepare(
    'INSERT INTO leave_types (
        id, name, code, category, colour, annual_quota_days, accrual_frequency, accrual_days,
        max_carry_forward_days, allows_half_day, requires_document_after_days, min_notice_days,
        max_consecutive_days, is_paid, applies_to_gender, is_active
     ) VALUES (
        :id, :name, :code, :category, :colour, :annual_quota_days, :accrual_frequency, :accrual_days,
        :max_carry_forward_days, :allows_half_day, :requires_document_after_days, :min_notice_days,
        :max_consecutive_days, :is_paid, :applies_to_gender, TRUE
     )
     ON CONFLICT (code) DO NOTHING'
);

foreach ($leaveTypes as $type) {
    $insertType->execute([
        'id' => $id('type.' . $type['code']),
        'name' => $type['name'],
        'code' => $type['code'],
        'category' => $type['category'],
        'colour' => $type['colour'],
        'annual_quota_days' => $type['annual_quota_days'],
        'accrual_frequency' => $type['accrual_frequency'],
        'accrual_days' => $type['accrual_days'],
        'max_carry_forward_days' => $type['max_carry_forward_days'],
        'allows_half_day' => $type['allows_half_day'] ? 'true' : 'false',
        'requires_document_after_days' => $type['requires_document_after_days'],
        'min_notice_days' => $type['min_notice_days'],
        'max_consecutive_days' => $type['max_consecutive_days'],
        'is_paid' => $type['is_paid'] ? 'true' : 'false',
        'applies_to_gender' => $type['applies_to_gender'],
    ]);
}

// Read the ids back rather than assuming ours won the insert: a type may
// already exist from an earlier boot or have been created through the API.
$typeIds = [];
foreach ($pdo->query('SELECT id, code FROM leave_types')->fetchAll() as $row) {
    $typeIds[(string) $row['code']] = (string) $row['id'];
}

// ---------------------------------------------------------------------------
// Reference data: how each type applies to each kind of employment
// ---------------------------------------------------------------------------

$policyStart = '2019-04-01';

$policies = [
    ['EARNED', 'full_time', 0, null],
    ['EARNED', 'part_time', 3, 9],
    ['EARNED', 'contract', 3, 12],
    ['EARNED', 'intern', 0, 6],
    ['SICK', 'full_time', 0, null],
    ['SICK', 'intern', 0, 6],
    ['SICK', 'contract', 0, 6],
    ['CASUAL', 'full_time', 0, null],
    ['CASUAL', 'intern', 0, 3],
    ['UNPAID', 'full_time', 0, null],
    ['COMPOFF', 'full_time', 0, null],
    ['BEREAVE', 'full_time', 0, null],
    // Statutory parental leave carries a qualifying period of service.
    ['MATERNITY', 'full_time', 6, null],
    ['PATERNITY', 'full_time', 6, null],
];

$insertPolicy = $pdo->prepare(
    'INSERT INTO leave_policies (
        id, leave_type_id, employment_type, applies_after_months, quota_override_days, effective_from
     ) VALUES (
        :id, :leave_type_id, :employment_type, :applies_after_months, :quota_override_days, :effective_from
     )
     ON CONFLICT (leave_type_id, employment_type, effective_from) DO NOTHING'
);

foreach ($policies as [$code, $employmentType, $afterMonths, $quotaOverride]) {
    if (!isset($typeIds[$code])) {
        continue;
    }

    $insertPolicy->execute([
        'id' => $id(sprintf('policy.%s.%s.%s', $code, $employmentType, $policyStart)),
        'leave_type_id' => $typeIds[$code],
        'employment_type' => $employmentType,
        'applies_after_months' => $afterMonths,
        'quota_override_days' => $quotaOverride,
        'effective_from' => $policyStart,
    ]);
}

if (!Env::bool('SEED_DEMO_DATA', true)) {
    return;
}

// ---------------------------------------------------------------------------
// Demonstration data
// ---------------------------------------------------------------------------

/** Employee identifiers, owned by employee-service. */
// Balances and requests belong to the people still employed.
$employees = DemoCohort::activeEmployeeIds();

/** The reporting line, which is also the approval routing. */
$managers = DemoCohort::approvers();

$now = Clock::now()->setTime(0, 0);
$thisMonday = $now->modify('monday this week');

/** The working date $dayIndex days into the week $weekOffset weeks from now. */
$workDay = static function (int $weekOffset, int $dayIndex) use ($thisMonday): string {
    return $thisMonday
        ->modify(sprintf('%+d weeks', $weekOffset))
        ->modify(sprintf('+%d days', $dayIndex))
        ->format('Y-m-d');
};

/** Days charged for a range, excluding Saturdays and Sundays. */
$chargeableDays = static function (string $from, string $to): float {
    $days = 0;

    foreach (Clock::dateRange($from, $to) as $date) {
        if (!in_array((int) Clock::parse($date)->format('N'), [6, 7], true)) {
            $days++;
        }
    }

    return (float) $days;
};

// The plan lives in the shared roster because the attendance service needs the
// same answer: it marks these same days "on leave" in its register, and it
// cannot read this schema to work out which they are.
$requestPlan = DemoCohort::leavePlan();

$insertRequest = $pdo->prepare(
    'INSERT INTO leave_requests (
        id, employee_id, leave_type_id, starts_on, ends_on, day_count, is_half_day, half_day_period,
        reason, contact_during_leave, status, approver_id, decided_by, decided_at, decision_note,
        cancelled_at, cancelled_by, holiday_calendar_applied, applied_at, created_at, updated_at
     ) VALUES (
        :id, :employee_id, :leave_type_id, :starts_on, :ends_on, :day_count, :is_half_day, :half_day_period,
        :reason, :contact_during_leave, :status, :approver_id, :decided_by, :decided_at, :decision_note,
        :cancelled_at, :cancelled_by, TRUE, :applied_at, :created_at, :updated_at
     )
     ON CONFLICT (id) DO UPDATE
        SET starts_on    = EXCLUDED.starts_on,
            ends_on      = EXCLUDED.ends_on,
            day_count    = EXCLUDED.day_count,
            applied_at   = EXCLUDED.applied_at,
            decided_at   = EXCLUDED.decided_at,
            cancelled_at = EXCLUDED.cancelled_at,
            created_at   = EXCLUDED.created_at,
            updated_at   = EXCLUDED.updated_at
      WHERE leave_requests.status = EXCLUDED.status'
);

$insertApproval = $pdo->prepare(
    'INSERT INTO leave_approvals (id, leave_request_id, level, approver_id, status, note, decided_at, created_at)
     VALUES (:id, :leave_request_id, 1, :approver_id, :status, :note, :decided_at, :created_at)
     ON CONFLICT (leave_request_id, level) DO UPDATE
        SET decided_at = EXCLUDED.decided_at,
            created_at = EXCLUDED.created_at
      WHERE leave_approvals.status = EXCLUDED.status'
);

/** Running totals so the balances agree with the requests that produced them. */
$consumed = [];

foreach ($requestPlan as $index => [$code, $typeCode, $weekOffset, $firstDay, $lastDay, $status, $halfPeriod, $reason, $note]) {
    if (!isset($employees[$code], $typeIds[$typeCode])) {
        continue;
    }

    $startsOn = $workDay($weekOffset, $firstDay);
    $endsOn = $workDay($weekOffset, $lastDay);
    $dayCount = $halfPeriod === null ? $chargeableDays($startsOn, $endsOn) : 0.5;

    if ($dayCount <= 0) {
        continue;
    }

    // Applications are made a few days ahead where that is possible, and
    // otherwise recently, so nothing appears to have been filed in the future.
    $appliedAt = min(
        Clock::parse($startsOn)->modify('-5 days'),
        $now->modify(sprintf('-%d days', $index % 7))
    );

    // Keyed on the slot in the plan, never on the date it resolves to. The
    // dates move with the current week so the demo is never stale, and an
    // identifier that moved with them would make every boot in a new week
    // insert a second copy of all thirty requests instead of shifting the
    // ones already there.
    $requestId = $id(sprintf('request.%s.%s.%d.%d', $code, $typeCode, $weekOffset, $firstDay));
    $approverCode = $managers[$code] ?? null;
    $approverId = $approverCode === null ? null : $employees[$approverCode];

    $decidedAt = in_array($status, ['approved', 'rejected'], true)
        ? $appliedAt->modify('+1 day')->setTime(11, 20)->format(DATE_ATOM)
        : null;

    $cancelledAt = $status === 'cancelled'
        ? $appliedAt->modify('+2 days')->setTime(9, 45)->format(DATE_ATOM)
        : null;

    $insertRequest->execute([
        'id' => $requestId,
        'employee_id' => $employees[$code],
        'leave_type_id' => $typeIds[$typeCode],
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
        'day_count' => $dayCount,
        'is_half_day' => $halfPeriod === null ? 'false' : 'true',
        'half_day_period' => $halfPeriod,
        'reason' => $reason,
        'contact_during_leave' => $typeCode === 'EARNED' ? 'Reachable on the registered mobile number' : null,
        'status' => $status,
        'approver_id' => $approverId,
        'decided_by' => $decidedAt === null ? null : $approverId,
        'decided_at' => $decidedAt,
        'decision_note' => $decidedAt === null ? null : $note,
        'cancelled_at' => $cancelledAt,
        'cancelled_by' => $cancelledAt === null ? null : $employees[$code],
        'applied_at' => $appliedAt->format(DATE_ATOM),
        'created_at' => $appliedAt->format(DATE_ATOM),
        'updated_at' => $decidedAt ?? $cancelledAt ?? $appliedAt->format(DATE_ATOM),
    ]);

    if ($approverId !== null) {
        $insertApproval->execute([
            'id' => $id('approval.' . $requestId),
            'leave_request_id' => $requestId,
            'approver_id' => $approverId,
            'status' => match ($status) {
                'approved' => 'approved',
                'rejected' => 'rejected',
                'cancelled' => 'skipped',
                default => 'pending',
            },
            'note' => $decidedAt === null ? null : $note,
            'decided_at' => $decidedAt ?? $cancelledAt,
            'created_at' => $appliedAt->format(DATE_ATOM),
        ]);
    }

    $year = (int) substr($startsOn, 0, 4);
    $bucket = $code . '|' . $typeCode . '|' . $year;

    $consumed[$bucket] ??= ['used' => 0.0, 'pending' => 0.0];

    if ($status === 'approved') {
        $consumed[$bucket]['used'] += $dayCount;
    } elseif ($status === 'pending') {
        $consumed[$bucket]['pending'] += $dayCount;
    }
}

// ---------------------------------------------------------------------------
// Manual corrections, and the adjusted totals they imply
// ---------------------------------------------------------------------------

$currentYear = (int) $now->format('Y');

$adjustmentPlan = [
    ['DF-0007', 'COMPOFF', 2.0, 'DF-0003', 'Compensatory credit for release support over a weekend.'],
    ['DF-0006', 'COMPOFF', 1.0, 'DF-0003', 'Compensatory credit for on-call cover on a public holiday.'],
    ['DF-0008', 'EARNED', 2.0, 'DF-0002', 'Opening balance correction after moving from contract to permanent.'],
    ['DF-0011', 'EARNED', -1.0, 'DF-0002', 'Correction: a day taken in December was recorded late.'],
];

$adjustedTotals = [];

foreach ($adjustmentPlan as [$code, $typeCode, $delta]) {
    $adjustedTotals[$code . '|' . $typeCode . '|' . $currentYear] =
        ($adjustedTotals[$code . '|' . $typeCode . '|' . $currentYear] ?? 0.0) + $delta;
}

// ---------------------------------------------------------------------------
// Balances
// ---------------------------------------------------------------------------

/** Days brought forward from last year, per person, for earned leave only. */
$carriedForward = [
    'DF-0001' => 10.0, 'DF-0002' => 8.0, 'DF-0003' => 5.0, 'DF-0004' => 7.0,
    'DF-0005' => 9.0, 'DF-0006' => 6.0, 'DF-0007' => 4.0, 'DF-0008' => 3.0,
    'DF-0009' => 8.0, 'DF-0010' => 5.0, 'DF-0011' => 2.0, 'DF-0012' => 4.0,
];

// Earned leave has been credited monthly up to the current month, capped at
// the annual entitlement, which is what the accrual job would have produced.
$earnedAccrued = min(1.5 * (int) $now->format('n'), 18.0);
$accrualPeriod = $now->format('Y-m');

$openingByType = [
    'EARNED' => 0.0,
    'SICK' => 12.0,
    'CASUAL' => 6.0,
    'UNPAID' => 0.0,
    'COMPOFF' => 0.0,
    'BEREAVE' => 5.0,
];

$insertBalance = $pdo->prepare(
    'INSERT INTO leave_balances (
        id, employee_id, leave_type_id, year, opening_days, accrued_days, used_days,
        pending_days, carried_forward_days, adjusted_days, last_accrual_period
     ) VALUES (
        :id, :employee_id, :leave_type_id, :year, :opening_days, :accrued_days, :used_days,
        :pending_days, :carried_forward_days, :adjusted_days, :last_accrual_period
     )
     ON CONFLICT (employee_id, leave_type_id, year) DO NOTHING'
);

// Every year touched by the demo requests, so a request that landed either
// side of a new year still has the balance it was charged against.
$years = [$currentYear];

foreach (array_keys($consumed) as $bucket) {
    $years[] = (int) substr($bucket, strrpos($bucket, '|') + 1);
}

$years = array_values(array_unique($years));

foreach ($employees as $code => $employeeId) {
    foreach ($years as $year) {
        foreach ($openingByType as $typeCode => $opening) {
            if (!isset($typeIds[$typeCode])) {
                continue;
            }

            $bucket = $code . '|' . $typeCode . '|' . $year;
            $movement = $consumed[$bucket] ?? ['used' => 0.0, 'pending' => 0.0];

            // A year already behind us is fully accrued; one still ahead has
            // accrued nothing yet.
            $accrued = 0.0;
            if ($typeCode === 'EARNED') {
                $accrued = match (true) {
                    $year < $currentYear => 18.0,
                    $year === $currentYear => $earnedAccrued,
                    default => 0.0,
                };
            }

            $insertBalance->execute([
                'id' => $id(sprintf('balance.%s.%s.%d', $code, $typeCode, $year)),
                'employee_id' => $employeeId,
                'leave_type_id' => $typeIds[$typeCode],
                'year' => $year,
                'opening_days' => $opening,
                'accrued_days' => $accrued,
                'used_days' => $movement['used'],
                'pending_days' => $movement['pending'],
                'carried_forward_days' => $typeCode === 'EARNED' ? ($carriedForward[$code] ?? 0.0) : 0.0,
                'adjusted_days' => $adjustedTotals[$bucket] ?? 0.0,
                'last_accrual_period' => $typeCode === 'EARNED' && $year === $currentYear ? $accrualPeriod : null,
            ]);
        }
    }
}

$insertAdjustment = $pdo->prepare(
    'INSERT INTO leave_adjustments (id, employee_id, leave_type_id, year, delta_days, reason, adjusted_by, created_at)
     VALUES (:id, :employee_id, :leave_type_id, :year, :delta_days, :reason, :adjusted_by, :created_at)
     ON CONFLICT (id) DO NOTHING'
);

foreach ($adjustmentPlan as $offset => [$code, $typeCode, $delta, $byCode, $reason]) {
    if (!isset($employees[$code], $employees[$byCode], $typeIds[$typeCode])) {
        continue;
    }

    $insertAdjustment->execute([
        'id' => $id(sprintf('adjustment.%s.%s.%d', $code, $typeCode, $currentYear)),
        'employee_id' => $employees[$code],
        'leave_type_id' => $typeIds[$typeCode],
        'year' => $currentYear,
        'delta_days' => $delta,
        'reason' => $reason,
        'adjusted_by' => $employees[$byCode],
        'created_at' => $now->modify(sprintf('-%d days', 30 + ($offset * 9)))->setTime(15, 5)->format(DATE_ATOM),
    ]);
}

// ---------------------------------------------------------------------------
// Approval delegations
// ---------------------------------------------------------------------------

$delegationPlan = [
    ['DF-0005', 'DF-0009', -2, 7, true, 'Engineering offsite - Karthik is covering approvals.'],
    ['DF-0002', 'DF-0003', 14, 24, true, 'Annual leave - Rahul is covering approvals.'],
    ['DF-0009', 'DF-0005', -80, -70, false, 'Sales kick-off travel. Withdrawn on return.'],
];

$insertDelegation = $pdo->prepare(
    'INSERT INTO approval_delegations (id, delegator_id, delegate_id, starts_on, ends_on, reason, is_active, created_at)
     VALUES (:id, :delegator_id, :delegate_id, :starts_on, :ends_on, :reason, :is_active, :created_at)
     ON CONFLICT (id) DO UPDATE
        SET starts_on  = EXCLUDED.starts_on,
            ends_on    = EXCLUDED.ends_on,
            created_at = EXCLUDED.created_at
      WHERE approval_delegations.is_active = EXCLUDED.is_active'
);

foreach ($delegationPlan as [$delegatorCode, $delegateCode, $startOffset, $endOffset, $isActive, $reason]) {
    if (!isset($employees[$delegatorCode], $employees[$delegateCode])) {
        continue;
    }

    $startsOn = $now->modify(sprintf('%+d days', $startOffset));
    $endsOn = $now->modify(sprintf('%+d days', $endOffset));

    $insertDelegation->execute([
        'id' => $id(sprintf('delegation.%s.%s.%d', $delegatorCode, $delegateCode, $startOffset)),
        'delegator_id' => $employees[$delegatorCode],
        'delegate_id' => $employees[$delegateCode],
        'starts_on' => $startsOn->format('Y-m-d'),
        'ends_on' => $endsOn->format('Y-m-d'),
        'reason' => $reason,
        'is_active' => $isActive ? 'true' : 'false',
        'created_at' => $startsOn->modify('-3 days')->setTime(10, 0)->format(DATE_ATOM),
    ]);
}
