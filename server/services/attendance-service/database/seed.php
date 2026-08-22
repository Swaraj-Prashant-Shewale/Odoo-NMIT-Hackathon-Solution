<?php

declare(strict_types=1);

/**
 * Attendance reference and demonstration data.
 *
 * Runs on every boot, so everything here checks before it inserts. Shift
 * patterns and the holiday calendar are reference data and are always seeded;
 * the punch history behind them is demonstration data and is guarded.
 */

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\DemoCohort;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Str;

$pdo = Connection::pdo();

// ---------------------------------------------------------------------------
// Reference: shift patterns
// ---------------------------------------------------------------------------

$shiftGeneral = 'f1c0a5d2-6b3e-4a17-9c84-2d5f0e7b1a90';
$shiftEarly = '3a7d9e14-58b2-4c6f-8e01-b9d3c2f5a748';
$shiftNight = 'c48b2f60-91d7-4e35-a2b8-5f6c0d3e7194';

$weekdays = json_encode(['mon', 'tue', 'wed', 'thu', 'fri'], JSON_THROW_ON_ERROR);

$insertShift = $pdo->prepare(
    'INSERT INTO shifts
         (id, name, code, starts_at, ends_at, break_minutes, full_day_hours, half_day_hours,
          grace_minutes, is_night_shift, working_days, is_active)
     VALUES
         (:id, :name, :code, CAST(:starts_at AS TIME), CAST(:ends_at AS TIME), :break_minutes,
          :full_day_hours, :half_day_hours, :grace_minutes, :is_night_shift,
          CAST(:working_days AS JSONB), TRUE)
     ON CONFLICT DO NOTHING'
);

$shiftRows = [
    [
        'id' => $shiftGeneral,
        'name' => 'General',
        'code' => 'GEN',
        'starts_at' => '09:30',
        'ends_at' => '18:30',
        'break_minutes' => 60,
        'full_day_hours' => '8.00',
        'half_day_hours' => '4.00',
        'grace_minutes' => 15,
        'is_night_shift' => 'false',
        'working_days' => $weekdays,
    ],
    [
        'id' => $shiftEarly,
        'name' => 'Early',
        'code' => 'EARLY',
        'starts_at' => '07:00',
        'ends_at' => '16:00',
        'break_minutes' => 60,
        'full_day_hours' => '8.00',
        'half_day_hours' => '4.00',
        // An early start is easier to miss, so the shift forgives a little more.
        'grace_minutes' => 20,
        'is_night_shift' => 'false',
        'working_days' => $weekdays,
    ],
    [
        'id' => $shiftNight,
        'name' => 'Night',
        'code' => 'NIGHT',
        'starts_at' => '22:00',
        'ends_at' => '07:00',
        'break_minutes' => 45,
        'full_day_hours' => '8.00',
        'half_day_hours' => '4.00',
        'grace_minutes' => 15,
        'is_night_shift' => 'true',
        'working_days' => $weekdays,
    ],
];

foreach ($shiftRows as $shift) {
    $insertShift->execute($shift);
}

// ---------------------------------------------------------------------------
// Reference: the 2026 holiday calendar
// ---------------------------------------------------------------------------

$insertHoliday = $pdo->prepare(
    'INSERT INTO holidays (id, name, holiday_date, holiday_type, location_id, description, year, is_active)
     VALUES (:id, :name, CAST(:holiday_date AS DATE), :holiday_type, NULL, :description, :year, TRUE)
     ON CONFLICT DO NOTHING'
);

$holidayRows = [
    ['2b91f0c4-3d76-4a58-9e12-7c40d8b5a3f6', 'Republic Day', '2026-01-26', 'public', 'National holiday marking the adoption of the Constitution.'],
    ['6d3e81a7-4f92-4b60-83c5-1a97e2d40b8f', 'Holi', '2026-03-03', 'public', 'Festival of colours.'],
    ['9f52c3d8-7a41-4e29-b6d0-3e81f5c07a24', 'Good Friday', '2026-04-03', 'public', 'Observed across all offices.'],
    ['48a6d20f-9c15-4837-a0e9-62b7d413f5c8', 'Independence Day', '2026-08-15', 'public', 'National holiday.'],
    ['71e0b4f9-8d23-4c96-9b47-05a1c6e28d3b', 'Gandhi Jayanti', '2026-10-02', 'public', 'National holiday.'],
    ['bc39d5a2-1e84-4f07-8a63-9d72b0c41e5f', 'Diwali', '2026-11-08', 'public', 'Festival of lights.'],
    ['05f8a3c1-6b29-4d54-b7e8-4a10c92f6d37', 'Christmas Day', '2026-12-25', 'public', 'Observed across all offices.'],
    ['e7204c9b-5a38-4162-98df-3b60a7e15d4c', 'Janmashtami', '2026-09-04', 'restricted', 'Optional holiday: take it or work the day.'],
    ['3c1f96d5-0e47-42b8-ad39-8f52b04c7e61', 'Guru Nanak Jayanti', '2026-11-24', 'restricted', 'Optional holiday: take it or work the day.'],
];

foreach ($holidayRows as [$id, $name, $date, $type, $description]) {
    $insertHoliday->execute([
        'id' => $id,
        'name' => $name,
        'holiday_date' => $date,
        'holiday_type' => $type,
        'description' => $description,
        'year' => (int) substr($date, 0, 4),
    ]);
}

if (!Env::bool('SEED_DEMO_DATA', true)) {
    return;
}

// ---------------------------------------------------------------------------
// Demonstration data
//
// Every value below is derived from a fixed integer seed hashed together with
// the employee and the date, rather than from a running random sequence. That
// makes a given day reproduce identically no matter which days were generated
// on an earlier boot, which is what lets this seed fill in only the gaps.
// ---------------------------------------------------------------------------

$randomSeed = 20260214;

/** A stable pseudo-random integer in [0, $max] for a named draw. */
$draw = static function (string $key, int $max) use ($randomSeed): int {
    $digest = substr(hash('sha256', $randomSeed . ':' . $key), 0, 8);

    return (int) (hexdec($digest) % ($max + 1));
};

// Employee identifiers are fixed platform-wide; see docs/SEED-IDENTIFIERS.md.
// Somebody who left in February is not clocking in, so the register is
// generated for the people still employed.
$employees = DemoCohort::activeEmployeeIds();

// ---------------------------------------------------------------------------
// Everybody works the General shift
// ---------------------------------------------------------------------------

$assignmentExists = $pdo->prepare(
    'SELECT 1 FROM shift_assignments WHERE employee_id = :employee_id AND shift_id = :shift_id LIMIT 1'
);

$insertAssignment = $pdo->prepare(
    'INSERT INTO shift_assignments (id, employee_id, shift_id, effective_from, effective_to)
     VALUES (:id, :employee_id, :shift_id, CAST(:effective_from AS DATE), NULL)'
);

foreach ($employees as $employeeId) {
    $assignmentExists->execute(['employee_id' => $employeeId, 'shift_id' => $shiftGeneral]);

    if ($assignmentExists->fetchColumn() !== false) {
        continue;
    }

    $insertAssignment->execute([
        'id' => Str::uuid(),
        'employee_id' => $employeeId,
        'shift_id' => $shiftGeneral,
        // Comfortably before any attendance this service will ever hold, so the
        // assignment covers every day the history reaches back to.
        'effective_from' => '2024-01-01',
    ]);
}

// ---------------------------------------------------------------------------
// Sixty days of punch history, working backwards from today
// ---------------------------------------------------------------------------

$today = Clock::today();
$todayStart = Clock::parse($today)->setTime(0, 0);
$historyStart = $todayStart->modify('-60 days');

$closureStatement = $pdo->prepare(
    "SELECT holiday_date FROM holidays
     WHERE is_active AND holiday_type <> 'restricted'
       AND holiday_date BETWEEN CAST(:from_date AS DATE) AND CAST(:to_date AS DATE)"
);
$closureStatement->execute([
    'from_date' => $historyStart->format('Y-m-d'),
    'to_date' => $today,
]);

$closures = [];
foreach ($closureStatement->fetchAll(\PDO::FETCH_COLUMN) as $closure) {
    $closures[(string) $closure] = true;
}

$recorded = [];
foreach ($pdo->query('SELECT employee_id, work_date FROM attendance_records')->fetchAll() as $row) {
    $recorded[$row['employee_id'] . '|' . $row['work_date']] = true;
}

$insertRecord = $pdo->prepare(
    'INSERT INTO attendance_records
         (id, employee_id, work_date, shift_id, check_in_at, check_out_at, check_in_ip, check_out_ip,
          check_in_source, worked_seconds, break_seconds, overtime_seconds,
          late_minutes, early_leave_minutes, status, is_regularised, remarks, created_at, updated_at)
     VALUES
         (:id, :employee_id, CAST(:work_date AS DATE), :shift_id,
          CAST(:check_in_at AS TIMESTAMPTZ), CAST(:check_out_at AS TIMESTAMPTZ),
          :check_in_ip, :check_out_ip, :check_in_source, :worked_seconds, :break_seconds,
          :overtime_seconds, :late_minutes, :early_leave_minutes, :status, FALSE, :remarks,
          CAST(:created_at AS TIMESTAMPTZ), CAST(:created_at2 AS TIMESTAMPTZ))
     ON CONFLICT DO NOTHING'
);

$insertPunch = $pdo->prepare(
    'INSERT INTO attendance_punches
         (id, attendance_record_id, employee_id, punched_at, direction, ip_address, user_agent, source, created_at)
     VALUES
         (:id, :record_id, :employee_id, CAST(:punched_at AS TIMESTAMPTZ), :direction,
          :ip_address, :user_agent, :source, CAST(:punched_at2 AS TIMESTAMPTZ))'
);

$shiftStartMinutes = 9 * 60 + 30;
$graceMinutes = 15;
$shiftEndMinutes = 18 * 60 + 30;
$breakSeconds = 3600;
$fullDaySeconds = 8 * 3600;
$halfDaySeconds = 4 * 3600;
$officeAddresses = ['10.24.8.14', '10.24.8.31', '10.24.9.5', '10.24.9.72', '10.61.2.18'];
$browsers = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) Safari/17.4',
    'Mozilla/5.0 (X11; Linux x86_64) Firefox/125.0',
];

/** Writes one finished day plus its two punches. */
$writeDay = static function (
    string $employeeId,
    string $date,
    ?\DateTimeImmutable $checkIn,
    ?\DateTimeImmutable $checkOut,
    string $status,
    int $workedSeconds,
    int $breakTaken,
    int $overtimeSeconds,
    int $lateMinutes,
    int $earlyMinutes,
    string $address,
    string $browser,
    ?string $remarks = null
) use ($insertRecord, $insertPunch, $shiftGeneral): void {
    $recordId = Str::uuid();
    $createdAt = ($checkIn ?? Clock::parse($date)->setTime(9, 0))->format(\DateTimeInterface::ATOM);

    $inserted = $insertRecord->execute([
        'id' => $recordId,
        'employee_id' => $employeeId,
        'work_date' => $date,
        'shift_id' => $shiftGeneral,
        'check_in_at' => $checkIn?->format(\DateTimeInterface::ATOM),
        'check_out_at' => $checkOut?->format(\DateTimeInterface::ATOM),
        'check_in_ip' => $checkIn === null ? null : $address,
        'check_out_ip' => $checkOut === null ? null : $address,
        'check_in_source' => 'web',
        'worked_seconds' => $workedSeconds,
        'break_seconds' => $breakTaken,
        'overtime_seconds' => $overtimeSeconds,
        'late_minutes' => $lateMinutes,
        'early_leave_minutes' => $earlyMinutes,
        'status' => $status,
        'remarks' => $remarks ?? ($status === 'absent' ? 'No punch recorded for this day.' : null),
        'created_at' => $createdAt,
        'created_at2' => $createdAt,
    ]);

    // A conflict means the day was already recorded, and its punches belong to
    // the row that is already there rather than to the id generated above.
    if (!$inserted || $insertRecord->rowCount() === 0) {
        return;
    }

    foreach ([['in', $checkIn], ['out', $checkOut]] as [$direction, $moment]) {
        if ($moment === null) {
            continue;
        }

        $insertPunch->execute([
            'id' => Str::uuid(),
            'record_id' => $recordId,
            'employee_id' => $employeeId,
            'punched_at' => $moment->format(\DateTimeInterface::ATOM),
            'punched_at2' => $moment->format(\DateTimeInterface::ATOM),
            'direction' => $direction,
            'ip_address' => $address,
            'user_agent' => $browser,
            'source' => 'web',
        ]);
    }
};

// Which days each person was away on approved leave. The leave service owns
// the requests and this service cannot read that schema, so both derive the
// dates from the one plan in the shared roster - otherwise the register would
// show somebody at their desk on a day the leave calendar says they were in
// Kochi.
$thisMonday = Clock::now()->setTime(0, 0)->modify('monday this week');
$leaveByCode = DemoCohort::approvedLeaveDays($thisMonday);

/** Turns a day already in the register into a day of leave. */
$markOnLeave = $pdo->prepare(
    "UPDATE attendance_records
        SET status = 'on_leave',
            check_in_at = NULL,
            check_out_at = NULL,
            check_in_ip = NULL,
            check_out_ip = NULL,
            worked_seconds = 0,
            break_seconds = 0,
            overtime_seconds = 0,
            late_minutes = 0,
            early_leave_minutes = 0,
            remarks = 'Approved leave.',
            updated_at = NOW()
      WHERE employee_id = :employee_id
        AND work_date = CAST(:work_date AS DATE)
        AND status <> 'on_leave'"
);

$clearPunches = $pdo->prepare(
    'DELETE FROM attendance_punches
      WHERE employee_id = :employee_id
        AND punched_at::date = CAST(:work_date AS DATE)'
);

$onLeave = [];
foreach ($leaveByCode as $code => $dates) {
    if (!isset($employees[$code])) {
        continue;
    }

    foreach ($dates as $leaveDate) {
        $onLeave[$employees[$code] . '|' . $leaveDate] = true;
    }
}

/** Holiday names, so a holiday row can say which holiday it was. */
$holidayNames = [];
$holidayStatement = $pdo->prepare(
    "SELECT holiday_date, name FROM holidays
     WHERE is_active AND holiday_type <> 'restricted'
       AND holiday_date BETWEEN CAST(:from_date AS DATE) AND CAST(:to_date AS DATE)"
);
$holidayStatement->execute([
    'from_date' => $historyStart->format('Y-m-d'),
    'to_date' => $today,
]);

foreach ($holidayStatement->fetchAll() as $row) {
    $holidayNames[(string) $row['holiday_date']] = (string) $row['name'];
}

foreach ($employees as $employeeId) {
    $cursor = $historyStart;

    while ($cursor < $todayStart) {
        $date = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');

        $isWeekend = in_array((int) Clock::parse($date)->format('N'), [6, 7], true);

        // Leave is settled first, and it overrules whatever is already there.
        // A generated working day for somebody the leave calendar says was in
        // Kochi is not a fact worth keeping, and skipping it because a row
        // exists would leave the register and the calendar contradicting each
        // other for good.
        if (!$isWeekend && !isset($closures[$date]) && isset($onLeave[$employeeId . '|' . $date])) {
            $markOnLeave->execute(['employee_id' => $employeeId, 'work_date' => $date]);

            if ($markOnLeave->rowCount() === 0) {
                $writeDay(
                    $employeeId,
                    $date,
                    null,
                    null,
                    'on_leave',
                    0,
                    0,
                    0,
                    0,
                    0,
                    $officeAddresses[0],
                    $browsers[0],
                    'Approved leave.'
                );
            } else {
                // The punches belonged to the day this replaced.
                $clearPunches->execute(['employee_id' => $employeeId, 'work_date' => $date]);
            }

            $recorded[$employeeId . '|' . $date] = true;

            continue;
        }

        if (isset($recorded[$employeeId . '|' . $date])) {
            continue;
        }

        // A weekend and a public holiday are both recorded rather than left
        // out. The difference between "did not come in" and "was not expected
        // to" is the whole basis of an attendance rate, and a register with
        // gaps in it cannot express one.
        if ($isWeekend || isset($closures[$date])) {
            $writeDay(
                $employeeId,
                $date,
                null,
                null,
                $isWeekend ? 'weekly_off' : 'holiday',
                0,
                0,
                0,
                0,
                0,
                $officeAddresses[0],
                $browsers[0],
                $isWeekend ? null : ($holidayNames[$date] ?? 'Public holiday')
            );

            $recorded[$employeeId . '|' . $date] = true;

            continue;
        }


        $key = $employeeId . '|' . $date;
        $roll = $draw($key . '|shape', 999);
        $address = $officeAddresses[$draw($key . '|ip', count($officeAddresses) - 1)];
        $browser = $browsers[$draw($key . '|agent', count($browsers) - 1)];

        if ($roll < 30) {
            $writeDay($employeeId, $date, null, null, 'absent', 0, 0, 0, 0, 0, $address, $browser);

            continue;
        }

        $isLate = $draw($key . '|punctuality', 999) < 120;
        $isHalfDay = $roll < 90;

        $inMinutes = $isLate
            ? 9 * 60 + 50 + $draw($key . '|late', 50)
            : 9 * 60 + 5 + $draw($key . '|arrive', 35);

        $outMinutes = $isHalfDay
            // A half day is a morning: in at the usual time, gone by lunch.
            ? $inMinutes + 305 + $draw($key . '|halfday', 30)
            // A full day never finishes before the eight payable hours are in,
            // otherwise the derived status would contradict the visible times.
            : max($inMinutes + 545, $shiftEndMinutes - 15) + $draw($key . '|depart', 40);

        $checkIn = Clock::parse($date)->setTime(intdiv($inMinutes, 60), $inMinutes % 60, $draw($key . '|insec', 59));
        $checkOut = Clock::parse($date)->setTime(intdiv($outMinutes, 60), $outMinutes % 60, $draw($key . '|outsec', 59));

        $grossSeconds = $checkOut->getTimestamp() - $checkIn->getTimestamp();
        $workedSeconds = max(0, $grossSeconds - $breakSeconds);

        $status = match (true) {
            $workedSeconds >= $fullDaySeconds => 'present',
            $workedSeconds >= $halfDaySeconds => 'half_day',
            default => 'absent',
        };

        $lateMinutes = max(0, $inMinutes - ($shiftStartMinutes + $graceMinutes));
        $earlyMinutes = max(0, $shiftEndMinutes - $outMinutes);

        $writeDay(
            $employeeId,
            $date,
            $checkIn,
            $checkOut,
            $status,
            $workedSeconds,
            $breakSeconds,
            max(0, $workedSeconds - $fullDaySeconds),
            $lateMinutes,
            $earlyMinutes,
            $address,
            $browser
        );

        $recorded[$key] = true;
    }
}

// ---------------------------------------------------------------------------
// Two people are on the clock right now, so the live board has something on it
// ---------------------------------------------------------------------------

if (!isset($closures[$today])) {
    $onTheClock = [$employees['DF-0006'], $employees['DF-0007']];

    foreach ($onTheClock as $index => $employeeId) {
        if (isset($recorded[$employeeId . '|' . $today])) {
            continue;
        }

        $key = $employeeId . '|' . $today;
        $inMinutes = 9 * 60 + 20 + $draw($key . '|arrive', 30);
        $checkIn = Clock::parse($today)->setTime(intdiv($inMinutes, 60), $inMinutes % 60, $draw($key . '|insec', 59));

        $writeDay(
            $employeeId,
            $today,
            $checkIn,
            null,
            // Provisional, exactly as a live check-in records it: the day is
            // still running and its hours are settled at check-out.
            'present',
            0,
            0,
            0,
            max(0, $inMinutes - ($shiftStartMinutes + $graceMinutes)),
            0,
            $officeAddresses[$index],
            $browsers[$index]
        );

        $recorded[$key] = true;
    }
}

// ---------------------------------------------------------------------------
// A few planned shift swaps in the coming days
// ---------------------------------------------------------------------------

$insertRoster = $pdo->prepare(
    'INSERT INTO rosters (id, employee_id, shift_id, roster_date, notes, created_by)
     VALUES (:id, :employee_id, :shift_id, CAST(:roster_date AS DATE), :notes, :created_by)
     ON CONFLICT DO NOTHING'
);

/** The next $count weekdays after today. */
$upcomingWeekdays = static function (int $count) use ($todayStart): array {
    $dates = [];
    $cursor = $todayStart;

    while (count($dates) < $count) {
        $cursor = $cursor->modify('+1 day');

        if (!in_array((int) $cursor->format('N'), [6, 7], true)) {
            $dates[] = $cursor->format('Y-m-d');
        }
    }

    return $dates;
};

$plannedDays = $upcomingWeekdays(4);

$rosterPlan = [
    [$employees['DF-0007'], $shiftEarly, $plannedDays[0], 'Covering the early support window.'],
    [$employees['DF-0007'], $shiftEarly, $plannedDays[1], 'Covering the early support window.'],
    [$employees['DF-0008'], $shiftEarly, $plannedDays[0], 'Release verification before the offices open.'],
    [$employees['DF-0012'], $shiftEarly, $plannedDays[2], 'Design review with the London contractors.'],
];

foreach ($rosterPlan as [$employeeId, $shiftId, $date, $notes]) {
    $insertRoster->execute([
        'id' => Str::uuid(),
        'employee_id' => $employeeId,
        'shift_id' => $shiftId,
        'roster_date' => $date,
        'notes' => $notes,
        'created_by' => $employees['DF-0003'],
    ]);
}

// ---------------------------------------------------------------------------
// A regularisation queue with something waiting in it
// ---------------------------------------------------------------------------

/** The $offset-th weekday before today, skipping company closures. */
$recentWeekday = static function (int $offset) use ($todayStart, $closures): string {
    $cursor = $todayStart;
    $found = 0;

    while (true) {
        $cursor = $cursor->modify('-1 day');
        $date = $cursor->format('Y-m-d');

        if (in_array((int) $cursor->format('N'), [6, 7], true) || isset($closures[$date])) {
            continue;
        }

        if (++$found === $offset) {
            return $date;
        }
    }
};

$regularisationExists = $pdo->prepare(
    "SELECT 1 FROM regularisations WHERE employee_id = :employee_id AND work_date = CAST(:work_date AS DATE) LIMIT 1"
);

$insertRegularisation = $pdo->prepare(
    'INSERT INTO regularisations
         (id, employee_id, work_date, requested_check_in, requested_check_out, requested_status,
          reason, status, approver_id, decided_by, decided_at, decision_note, created_at, updated_at)
     VALUES
         (:id, :employee_id, CAST(:work_date AS DATE),
          CAST(:requested_check_in AS TIMESTAMPTZ), CAST(:requested_check_out AS TIMESTAMPTZ),
          :requested_status, :reason, :status, :approver_id, :decided_by,
          CAST(:decided_at AS TIMESTAMPTZ), :decision_note,
          CAST(:created_at AS TIMESTAMPTZ), CAST(:created_at2 AS TIMESTAMPTZ))
     ON CONFLICT DO NOTHING'
);

$markRegularised = $pdo->prepare(
    'UPDATE attendance_records
     SET check_in_at = CAST(:check_in_at AS TIMESTAMPTZ),
         check_out_at = CAST(:check_out_at AS TIMESTAMPTZ),
         worked_seconds = :worked_seconds,
         break_seconds = :break_seconds,
         overtime_seconds = :overtime_seconds,
         late_minutes = 0,
         early_leave_minutes = 0,
         status = :status,
         is_regularised = TRUE,
         remarks = :remarks,
         updated_at = NOW()
     WHERE employee_id = :employee_id AND work_date = CAST(:work_date AS DATE)'
);

$regularisationPlan = [
    [
        'employee' => $employees['DF-0010'],
        'approver' => $employees['DF-0009'],
        'offset' => 3,
        'in' => '09:35',
        'out' => '18:40',
        'reason' => 'Access card failed at the Mumbai turnstile, so neither punch was registered.',
        'status' => 'pending',
    ],
    [
        'employee' => $employees['DF-0011'],
        'approver' => $employees['DF-0002'],
        'offset' => 5,
        'in' => '09:15',
        'out' => '18:20',
        'reason' => 'Worked from the Pune client office all day and could not reach the punch terminal.',
        'status' => 'pending',
    ],
    [
        'employee' => $employees['DF-0008'],
        'approver' => $employees['DF-0005'],
        'offset' => 9,
        'in' => '09:25',
        'out' => '18:45',
        'reason' => 'Forgot to punch out before leaving for the release call.',
        'status' => 'approved',
        'decided_by' => $employees['DF-0005'],
        'note' => 'Confirmed against the release call invite.',
    ],
];

foreach ($regularisationPlan as $plan) {
    $workDate = $recentWeekday($plan['offset']);

    $regularisationExists->execute(['employee_id' => $plan['employee'], 'work_date' => $workDate]);

    if ($regularisationExists->fetchColumn() !== false) {
        continue;
    }

    $requestedIn = Clock::parse($workDate . ' ' . $plan['in']);
    $requestedOut = Clock::parse($workDate . ' ' . $plan['out']);
    $raisedAt = $requestedOut->modify('+2 hours')->format(\DateTimeInterface::ATOM);
    $isApproved = $plan['status'] === 'approved';

    $insertRegularisation->execute([
        'id' => Str::uuid(),
        'employee_id' => $plan['employee'],
        'work_date' => $workDate,
        'requested_check_in' => $requestedIn->format(\DateTimeInterface::ATOM),
        'requested_check_out' => $requestedOut->format(\DateTimeInterface::ATOM),
        'requested_status' => 'present',
        'reason' => $plan['reason'],
        'status' => $plan['status'],
        'approver_id' => $plan['approver'],
        'decided_by' => $isApproved ? $plan['decided_by'] : null,
        'decided_at' => $isApproved ? $requestedOut->modify('+1 day')->format(\DateTimeInterface::ATOM) : null,
        'decision_note' => $isApproved ? $plan['note'] : null,
        'created_at' => $raisedAt,
        'created_at2' => $raisedAt,
    ]);

    if (!$isApproved) {
        continue;
    }

    // An approved request has already been applied to the day it corrected, so
    // the seeded record has to show the same thing the decision endpoint would.
    $gross = $requestedOut->getTimestamp() - $requestedIn->getTimestamp();
    $worked = max(0, $gross - $breakSeconds);

    $markRegularised->execute([
        'check_in_at' => $requestedIn->format(\DateTimeInterface::ATOM),
        'check_out_at' => $requestedOut->format(\DateTimeInterface::ATOM),
        'worked_seconds' => $worked,
        'break_seconds' => $breakSeconds,
        'overtime_seconds' => max(0, $worked - $fullDaySeconds),
        'status' => 'present',
        'remarks' => 'Regularised: ' . $plan['reason'],
        'employee_id' => $plan['employee'],
        'work_date' => $workDate,
    ]);
}

// ---------------------------------------------------------------------------
// Project time logs for the delivery teams
// ---------------------------------------------------------------------------

$timesheetExists = $pdo->prepare(
    'SELECT 1 FROM timesheets
     WHERE employee_id = :employee_id AND work_date = CAST(:work_date AS DATE)
       AND project_code = :project_code
     LIMIT 1'
);

$insertTimesheet = $pdo->prepare(
    'INSERT INTO timesheets
         (id, employee_id, work_date, project_code, task_description, hours, billable,
          approved_by, approved_at, status, created_at)
     VALUES
         (:id, :employee_id, CAST(:work_date AS DATE), :project_code, :task_description,
          :hours, :billable, :approved_by, CAST(:approved_at AS TIMESTAMPTZ), :status,
          CAST(:created_at AS TIMESTAMPTZ))'
);

$projects = ['DF-CORE', 'DF-MOBILE', 'DF-WEB', 'DF-PLATFORM'];
$tasks = [
    'Payroll calculation engine',
    'Attendance punch reliability fixes',
    'Leave approval flow rework',
    'Reporting dashboard queries',
    'Accessibility pass on the employee portal',
    'Release regression testing',
];

$loggers = [
    'DF-0006' => 'DF-0005',
    'DF-0007' => 'DF-0005',
    'DF-0008' => 'DF-0005',
    'DF-0012' => 'DF-0005',
    'DF-0011' => 'DF-0002',
];

foreach ($loggers as $code => $approverCode) {
    $employeeId = $employees[$code];

    for ($offset = 1; $offset <= 10; $offset++) {
        $workDate = $recentWeekday($offset);
        $key = $employeeId . '|' . $workDate;
        $projectCode = $projects[$draw($key . '|project', count($projects) - 1)];

        $timesheetExists->execute([
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'project_code' => $projectCode,
        ]);

        if ($timesheetExists->fetchColumn() !== false) {
            continue;
        }

        // Older entries have been through approval; the last few days are still
        // with the employee, which is what makes the approval queue meaningful.
        $status = match (true) {
            $offset >= 6 => 'approved',
            $offset >= 3 => 'submitted',
            default => 'draft',
        };

        $approvedAt = $status === 'approved'
            ? Clock::parse($workDate)->setTime(19, 30)->modify('+2 days')->format(\DateTimeInterface::ATOM)
            : null;

        $insertTimesheet->execute([
            'id' => Str::uuid(),
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'project_code' => $projectCode,
            'task_description' => $tasks[$draw($key . '|task', count($tasks) - 1)],
            'hours' => number_format(4 + ($draw($key . '|hours', 16) / 4), 2, '.', ''),
            'billable' => $draw($key . '|billable', 9) < 8 ? 'true' : 'false',
            'approved_by' => $status === 'approved' ? $employees[$approverCode] : null,
            'approved_at' => $approvedAt,
            'status' => $status,
            'created_at' => Clock::parse($workDate)->setTime(18, 45)->format(\DateTimeInterface::ATOM),
        ]);
    }
}
