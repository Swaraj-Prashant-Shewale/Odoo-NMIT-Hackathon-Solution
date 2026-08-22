<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * What an employee is contracted to be paid, and from when.
 *
 * Rows are append-only in spirit: a revision closes the previous row instead
 * of overwriting it, so every historical payslip can still be tied back to the
 * figures that were in force on the day it was produced.
 */
final class SalaryStructures extends Repository
{
    /** Namespace for this table's advisory locks, so they cannot collide. */
    private const ADVISORY_SCOPE = 20614;

    protected string $table = 'salary_structures';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'employee_id', 'effective_from', 'effective_to',
        'ctc_annual_minor', 'gross_monthly_minor', 'basic_monthly_minor',
        'currency', 'revision_reason', 'approved_by', 'approved_at', 'created_by',
    ];

    protected array $casts = [
        'ctc_annual_minor' => 'int',
        'gross_monthly_minor' => 'int',
        'basic_monthly_minor' => 'int',
    ];

    protected bool $softDeletes = false;

    /** The structure in force on a given calendar date. */
    public function effectiveOn(string $employeeId, string $date): ?array
    {
        $row = $this->rawOne(
            'SELECT * FROM salary_structures
              WHERE employee_id = :employee_id
                AND effective_from <= :date
                AND (effective_to IS NULL OR effective_to >= :date)
              ORDER BY effective_from DESC
              LIMIT 1',
            ['employee_id' => $employeeId, 'date' => $date]
        );

        return $row === null ? null : $this->present($row);
    }

    /**
     * The structure that overlaps a payroll period.
     *
     * A revision mid-month still produces one payslip, so the most recent
     * structure touching the month is the one the run is calculated from.
     */
    public function effectiveDuring(string $employeeId, string $from, string $to): ?array
    {
        $row = $this->rawOne(
            'SELECT * FROM salary_structures
              WHERE employee_id = :employee_id
                AND effective_from <= :to
                AND (effective_to IS NULL OR effective_to >= :from)
              ORDER BY effective_from DESC
              LIMIT 1',
            ['employee_id' => $employeeId, 'from' => $from, 'to' => $to]
        );

        return $row === null ? null : $this->present($row);
    }

    /** @return list<array<string, mixed>> Newest revision first. */
    public function historyFor(string $employeeId): array
    {
        $rows = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->orderBy('effective_from', 'desc')
            ->get();

        return array_map([$this, 'present'], $rows);
    }

    /** The open-ended structure, if the employee has one. */
    public function openFor(string $employeeId): ?array
    {
        $row = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->whereNull('effective_to')
            ->first();

        return $row === null ? null : $this->present($row);
    }

    /** The latest revision regardless of whether it has started yet. */
    public function latestFor(string $employeeId): ?array
    {
        $row = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->orderBy('effective_from', 'desc')
            ->first();

        return $row === null ? null : $this->present($row);
    }

    /** Closes a structure the day before its successor takes effect. */
    public function closeOn(string $id, string $effectiveTo): void
    {
        $this->execute(
            'UPDATE salary_structures SET effective_to = :effective_to, updated_at = NOW() WHERE id = :id',
            ['effective_to' => $effectiveTo, 'id' => $id]
        );
    }

    /**
     * Serialises revisions for one employee.
     *
     * Recording a revision reads the latest structure, decides the new one may
     * follow it, closes the open one and inserts the successor. Two revisions
     * filed at the same moment would otherwise both read the same "latest" and
     * both be accepted, leaving two rows open at once. A row lock cannot help
     * before the first structure exists, so the employee is locked instead;
     * the lock is held until the surrounding transaction ends.
     */
    public function lockEmployee(string $employeeId): void
    {
        $this->execute('SELECT pg_advisory_xact_lock(:scope, :key)', [
            'scope' => self::ADVISORY_SCOPE,
            'key' => self::signedKey($employeeId),
        ]);
    }

    /** A CRC folded into the signed 32-bit range the lock function accepts. */
    private static function signedKey(string $value): int
    {
        $hash = crc32($value);

        return $hash >= 2147483648 ? $hash - 4294967296 : $hash;
    }
}
