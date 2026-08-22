<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class ShiftAssignments extends Repository
{
    protected string $table = 'shift_assignments';

    protected string $primaryKey = 'id';

    protected array $fillable = ['employee_id', 'shift_id', 'effective_from', 'effective_to'];

    // The table records when an assignment was made, not when it was last
    // touched, so the base class must not try to write an updated_at column.
    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    /** The assignment covering a given calendar date, most recent first. */
    public function effectiveOn(string $employeeId, string $date): ?array
    {
        return $this->rawOne(
            'SELECT * FROM shift_assignments
             WHERE employee_id = :employee_id
               AND effective_from <= CAST(:on_date AS DATE)
               AND (effective_to IS NULL OR effective_to >= CAST(:on_date2 AS DATE))
             ORDER BY effective_from DESC
             LIMIT 1',
            ['employee_id' => $employeeId, 'on_date' => $date, 'on_date2' => $date]
        );
    }

    /** @return list<array<string, mixed>> */
    public function history(string $employeeId): array
    {
        return $this->raw(
            'SELECT a.*, s.name AS shift_name, s.code AS shift_code,
                    s.starts_at AS shift_starts_at, s.ends_at AS shift_ends_at
             FROM shift_assignments a
             JOIN shifts s ON s.id = a.shift_id
             WHERE a.employee_id = :employee_id
             ORDER BY a.effective_from DESC',
            ['employee_id' => $employeeId]
        );
    }

    /** Guards against two assignments claiming the same day for one person. */
    public function overlaps(string $employeeId, string $from, ?string $to, ?string $ignoreId = null): bool
    {
        $row = $this->rawOne(
            'SELECT 1 FROM shift_assignments
             WHERE employee_id = :employee_id
               AND (CAST(:ignore_id AS UUID) IS NULL OR id <> CAST(:ignore_id2 AS UUID))
               AND effective_from <= COALESCE(CAST(:to_date AS DATE), DATE \'9999-12-31\')
               AND COALESCE(effective_to, DATE \'9999-12-31\') >= CAST(:from_date AS DATE)
             LIMIT 1',
            [
                'employee_id' => $employeeId,
                'ignore_id' => $ignoreId,
                'ignore_id2' => $ignoreId,
                'to_date' => $to,
                'from_date' => $from,
            ]
        );

        return $row !== null;
    }
}
