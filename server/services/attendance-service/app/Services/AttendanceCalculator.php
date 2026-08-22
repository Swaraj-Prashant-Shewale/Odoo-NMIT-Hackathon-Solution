<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Support\Clock;

/**
 * Turns a punch trail into the numbers a payslip and a dashboard need.
 *
 * All of it is pure arithmetic over a shift definition and a list of punches,
 * with no database access, so the same rules apply whether they are being run
 * for a live check-out, an HR correction or an approved regularisation.
 */
final class AttendanceCalculator
{
    private function __construct()
    {
    }

    /** The moment a shift begins on a particular working day. */
    public static function shiftStart(array $shift, string $workDate): \DateTimeImmutable
    {
        return Clock::parse($workDate . ' ' . TimeFormat::hhmm((string) $shift['starts_at']));
    }

    /**
     * The moment a shift ends.
     *
     * A shift whose end time is at or before its start time runs past midnight,
     * so it finishes on the following calendar day.
     */
    public static function shiftEnd(array $shift, string $workDate): \DateTimeImmutable
    {
        $start = self::shiftStart($shift, $workDate);
        $end = Clock::parse($workDate . ' ' . TimeFormat::hhmm((string) $shift['ends_at']));

        return $end <= $start ? $end->modify('+1 day') : $end;
    }

    /**
     * Seconds actually on the clock, summed across in/out pairs.
     *
     * An unmatched trailing "in" is deliberately not counted: the day is still
     * running, and worked_seconds must only ever describe completed time.
     *
     * @param list<array<string, mixed>> $punches Ordered by punched_at.
     */
    public static function workedSeconds(array $punches): int
    {
        $total = 0;
        $openedAt = null;

        foreach (self::sorted($punches) as $punch) {
            $moment = Clock::parse((string) $punch['punched_at']);

            if ($punch['direction'] === 'in') {
                // A second "in" without an "out" keeps the earliest one, which
                // is the reading that matches when the person actually arrived.
                $openedAt ??= $moment;

                continue;
            }

            if ($openedAt !== null) {
                $total += max(0, $moment->getTimestamp() - $openedAt->getTimestamp());
                $openedAt = null;
            }
        }

        return $total;
    }

    /**
     * Seconds on the clock including the segment still open, measured to $now.
     *
     * This is what a running timer on the dashboard shows; it never reaches the
     * stored record.
     *
     * @param list<array<string, mixed>> $punches
     */
    public static function elapsedSeconds(array $punches, \DateTimeImmutable $now): int
    {
        $sorted = self::sorted($punches);
        $total = self::workedSeconds($sorted);
        $openedAt = self::openSegmentStart($sorted);

        if ($openedAt !== null) {
            $total += max(0, $now->getTimestamp() - $openedAt->getTimestamp());
        }

        return $total;
    }

    /**
     * Splits a day into payable time and unpaid break.
     *
     * The unpaid break is only charged to the extent it was not already taken:
     * someone who punched out for lunch has their break in the gaps between
     * pairs, and deducting the shift's break on top would bill them twice.
     *
     * @param list<array<string, mixed>> $punches
     * @return array{worked_seconds: int, break_seconds: int, gross_seconds: int}
     */
    public static function settle(array $punches, int $breakMinutes): array
    {
        $sorted = self::sorted($punches);
        $gross = self::workedSeconds($sorted);

        if ($sorted === []) {
            return ['worked_seconds' => 0, 'break_seconds' => 0, 'gross_seconds' => 0];
        }

        $first = Clock::parse((string) $sorted[0]['punched_at'])->getTimestamp();
        $last = Clock::parse((string) $sorted[count($sorted) - 1]['punched_at'])->getTimestamp();

        $breakAlreadyTaken = max(0, ($last - $first) - $gross);
        $breakStillOwed = max(0, ($breakMinutes * 60) - $breakAlreadyTaken);

        return [
            'worked_seconds' => max(0, $gross - $breakStillOwed),
            'break_seconds' => $breakAlreadyTaken + $breakStillOwed,
            'gross_seconds' => $gross,
        ];
    }

    /**
     * Payable time for a day expressed only as a single in/out pair.
     *
     * Used by regularisations and HR corrections, which state what the pair
     * should have been rather than adding to the immutable punch trail.
     *
     * @return array{worked_seconds: int, break_seconds: int, gross_seconds: int}
     */
    public static function settlePair(?string $checkIn, ?string $checkOut, int $breakMinutes): array
    {
        if ($checkIn === null || $checkOut === null) {
            return ['worked_seconds' => 0, 'break_seconds' => 0, 'gross_seconds' => 0];
        }

        $gross = max(0, Clock::parse($checkOut)->getTimestamp() - Clock::parse($checkIn)->getTimestamp());
        $break = min($breakMinutes * 60, $gross);

        return [
            'worked_seconds' => $gross - $break,
            'break_seconds' => $break,
            'gross_seconds' => $gross,
        ];
    }

    /**
     * The attendance status a quantity of payable time earns.
     *
     * A day short of the half-day threshold counts as an absence: the person
     * was at the office, but not for long enough for the day to be paid.
     */
    public static function statusFor(array $shift, int $workedSeconds): string
    {
        if ($workedSeconds >= (int) round(((float) $shift['full_day_hours']) * 3600)) {
            return 'present';
        }

        if ($workedSeconds >= (int) round(((float) $shift['half_day_hours']) * 3600)) {
            return 'half_day';
        }

        return 'absent';
    }

    public static function overtimeSeconds(array $shift, int $workedSeconds): int
    {
        return max(0, $workedSeconds - (int) round(((float) $shift['full_day_hours']) * 3600));
    }

    /** Minutes past the point at which arrival stops being forgiven. */
    public static function lateMinutes(array $shift, string $workDate, string $checkInAt): int
    {
        $forgivenUntil = self::shiftStart($shift, $workDate)
            ->modify(sprintf('+%d minutes', (int) $shift['grace_minutes']));

        $arrived = Clock::parse($checkInAt);

        if ($arrived <= $forgivenUntil) {
            return 0;
        }

        return (int) ceil(($arrived->getTimestamp() - $forgivenUntil->getTimestamp()) / 60);
    }

    /** Minutes between leaving and the end of the shift. */
    public static function earlyLeaveMinutes(array $shift, string $workDate, string $checkOutAt): int
    {
        $due = self::shiftEnd($shift, $workDate);
        $left = Clock::parse($checkOutAt);

        if ($left >= $due) {
            return 0;
        }

        return (int) floor(($due->getTimestamp() - $left->getTimestamp()) / 60);
    }

    /** The start of an in/out pair that has not been closed yet, if any. */
    public static function openSegmentStart(array $punches): ?\DateTimeImmutable
    {
        $openedAt = null;

        foreach (self::sorted($punches) as $punch) {
            if ($punch['direction'] === 'in') {
                $openedAt ??= Clock::parse((string) $punch['punched_at']);

                continue;
            }

            $openedAt = null;
        }

        return $openedAt;
    }

    /**
     * @param list<array<string, mixed>> $punches
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $punches): array
    {
        usort(
            $punches,
            static fn (array $a, array $b): int => Clock::parse((string) $a['punched_at'])->getTimestamp()
                <=> Clock::parse((string) $b['punched_at'])->getTimestamp()
        );

        return array_values($punches);
    }
}
