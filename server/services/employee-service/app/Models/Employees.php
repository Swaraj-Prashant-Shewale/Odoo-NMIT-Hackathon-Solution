<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\QueryBuilder;
use Dayflow\Kernel\Database\Repository;

/**
 * The people register: the system of record for a person.
 *
 * Every other service stores an employee_id and nothing else, so the shape
 * returned from here is a published contract rather than an internal detail.
 */
final class Employees extends Repository
{
    /**
     * Advisory lock key guarding employee-code allocation.
     *
     * A fixed constant rather than a hash of a string, so the value is stable
     * across PHP versions and recognisable in pg_locks while debugging.
     */
    private const CODE_LOCK_KEY = 731004101;

    public const CODE_PREFIX = 'DF-';

    protected string $table = 'employees';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'employee_code', 'user_id', 'first_name', 'last_name',
        'work_email', 'personal_email', 'phone', 'alternate_phone',
        'date_of_birth', 'gender', 'blood_group', 'marital_status',
        'address_line1', 'address_line2', 'city', 'state', 'country', 'postal_code',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
        'department_id', 'designation_id', 'location_id', 'manager_id',
        'employment_type', 'employment_status', 'joined_on', 'probation_end_on',
        'confirmed_on', 'notice_start_on', 'exit_date', 'exit_reason',
        'photo_document_id', 'is_active',
    ];

    /** Maintained by the database purely to drive search; of no use to a client. */
    protected array $hidden = ['search_text'];

    protected array $casts = ['is_active' => 'bool'];

    protected bool $softDeletes = true;

    public function present(array $row): array
    {
        $row = parent::present($row);

        if (isset($row['first_name'], $row['last_name'])) {
            $row['full_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
        }

        if (array_key_exists('photo_document_id', $row) && isset($row['id'])) {
            // An authorised endpoint rather than a filesystem path, so the
            // stored filename never reaches a client.
            $row['photo_url'] = $row['photo_document_id'] === null
                ? null
                : '/documents/photo/' . $row['id'];
        }

        return $row;
    }

    /**
     * The lightweight shape used by the company directory.
     *
     * Personal contact details, date of birth and home address are absent by
     * construction: the directory is readable by every employee.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function toDirectoryEntry(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            'employee_code' => $row['employee_code'] ?? null,
            'first_name' => $row['first_name'] ?? null,
            'last_name' => $row['last_name'] ?? null,
            'full_name' => $row['full_name'] ?? null,
            'work_email' => $row['work_email'] ?? null,
            'designation_name' => $row['designation_name'] ?? null,
            'department_name' => $row['department_name'] ?? null,
            'location_name' => $row['location_name'] ?? null,
            'photo_url' => $row['photo_url'] ?? null,
            'is_active' => $row['is_active'] ?? null,
        ];
    }

    /** Base query for list endpoints, with the organisation names joined in. */
    public function listQuery(): QueryBuilder
    {
        return $this->query()
            ->select('employees.*')
            ->selectRaw('"departments"."name" AS department_name')
            ->selectRaw('"designations"."title" AS designation_name')
            ->selectRaw('"locations"."name" AS location_name')
            ->join('departments', 'employees.department_id', '=', 'departments.id', 'LEFT')
            ->join('designations', 'employees.designation_id', '=', 'designations.id', 'LEFT')
            ->join('locations', 'employees.location_id', '=', 'locations.id', 'LEFT');
    }

    /** Directory query: only the columns the directory is allowed to show. */
    public function directoryQuery(): QueryBuilder
    {
        return $this->query()
            ->select(
                'employees.id',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employees.work_email',
                'employees.photo_document_id',
                'employees.is_active'
            )
            ->selectRaw('"departments"."name" AS department_name')
            ->selectRaw('"designations"."title" AS designation_name')
            ->selectRaw('"locations"."name" AS location_name')
            ->join('departments', 'employees.department_id', '=', 'departments.id', 'LEFT')
            ->join('designations', 'employees.designation_id', '=', 'designations.id', 'LEFT')
            ->join('locations', 'employees.location_id', '=', 'locations.id', 'LEFT');
    }

    /**
     * The canonical record, with department, designation and manager resolved.
     *
     * @return array<string, mixed>|null
     */
    public function findDetailed(string $id): ?array
    {
        $sql = <<<'SQL'
            SELECT e.*,
                   d.name  AS department_name,
                   g.title AS designation_name,
                   l.name  AS location_name,
                   CASE WHEN m.id IS NULL THEN NULL
                        ELSE TRIM(m.first_name || ' ' || m.last_name) END AS manager_name
            FROM employees e
            LEFT JOIN departments  d ON d.id = e.department_id
            LEFT JOIN designations g ON g.id = e.designation_id
            LEFT JOIN locations    l ON l.id = e.location_id
            LEFT JOIN employees    m ON m.id = e.manager_id
            WHERE e.id = :id AND e.deleted_at IS NULL
            SQL;

        $row = $this->rawOne($sql, ['id' => $id]);

        return $row === null ? null : $this->present($row);
    }

    public function findByWorkEmail(string $email): ?array
    {
        $row = $this->rawOne(
            'SELECT * FROM employees WHERE LOWER(work_email) = LOWER(:email) AND deleted_at IS NULL',
            ['email' => $email]
        );

        return $row === null ? null : $this->present($row);
    }

    public function findByUserId(string $userId): ?array
    {
        $row = $this->query()->where('user_id', '=', $userId)->first();

        return $row === null ? null : $this->present($row);
    }

    /**
     * Fills in manager_name across a page of rows with a single extra query.
     *
     * The self-join this would otherwise need cannot be expressed by the query
     * builder, and one bulk lookup costs less than a join on every page.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function attachManagerNames(array $rows): array
    {
        $managerIds = array_values(array_unique(array_filter(
            array_column($rows, 'manager_id'),
            static fn (mixed $id): bool => is_string($id) && $id !== ''
        )));

        $names = [];

        if ($managerIds !== []) {
            $managers = $this->query()
                ->select('id', 'first_name', 'last_name')
                ->whereIn('id', $managerIds)
                ->get();

            foreach ($managers as $manager) {
                $names[(string) $manager['id']] = trim($manager['first_name'] . ' ' . $manager['last_name']);
            }
        }

        return array_map(static function (array $row) use ($names): array {
            $row['manager_name'] = $names[(string) ($row['manager_id'] ?? '')] ?? null;

            return $row;
        }, $rows);
    }

    /** @return list<array<string, mixed>> */
    public function directReports(string $managerId): array
    {
        $rows = $this->listQuery()
            ->where('employees.manager_id', '=', $managerId)
            ->orderBy('employees.first_name')
            ->orderBy('employees.last_name')
            ->get();

        return $this->attachManagerNames(array_map([$this, 'present'], $rows));
    }

    /** @return list<string> */
    public function directReportIds(string $managerId): array
    {
        $rows = $this->query()->select('id')->where('manager_id', '=', $managerId)->get();

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    public function countInDepartment(string $departmentId): int
    {
        return $this->query()->where('department_id', '=', $departmentId)->count();
    }

    public function countWithDesignation(string $designationId): int
    {
        return $this->query()->where('designation_id', '=', $designationId)->count();
    }

    public function countAtLocation(string $locationId): int
    {
        return $this->query()->where('location_id', '=', $locationId)->count();
    }

    /**
     * Headcount including archived records.
     *
     * The department and designation keys are ON DELETE RESTRICT, and a soft
     * deleted person still holds the reference, so a delete guard has to count
     * what the database counts rather than what the screen shows. Counting only
     * live rows lets the delete through and turns the constraint into a 500.
     */
    public function countReferencingDepartment(string $departmentId): int
    {
        $row = $this->rawOne(
            'SELECT COUNT(*) AS total FROM employees WHERE department_id = :department_id',
            ['department_id' => $departmentId]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function countReferencingDesignation(string $designationId): int
    {
        $row = $this->rawOne(
            'SELECT COUNT(*) AS total FROM employees WHERE designation_id = :designation_id',
            ['designation_id' => $designationId]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Claims the next free number in the DF-nnnn series.
     *
     * The advisory lock is transaction scoped, so it is released by the COMMIT
     * that inserts the new row. Two concurrent creates serialise here instead
     * of both reading the same highest number and colliding on the unique key.
     *
     * Soft-deleted rows are counted deliberately: a code that has ever been
     * issued is never handed to a second person.
     */
    public function reserveNextCodeNumber(): int
    {
        $this->raw('SELECT pg_advisory_xact_lock(:key)', ['key' => self::CODE_LOCK_KEY]);

        $row = $this->rawOne(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(employee_code FROM 4) AS INTEGER)), 0) AS highest
             FROM employees
             WHERE employee_code ~ '^DF-[0-9]+$'"
        );

        return ((int) ($row['highest'] ?? 0)) + 1;
    }

    /**
     * The whole reporting tree, flat, from one recursive walk.
     *
     * A single statement rather than a query per manager: otherwise the depth
     * of the organisation would decide how many round trips the chart costs.
     *
     * The walk is seeded with everyone who has no *live* manager, not just
     * those with a NULL manager_id. Someone left behind by a deleted manager
     * becomes an extra root, which keeps their own reports on the chart
     * instead of silently removing a whole branch.
     *
     * @return list<array<string, mixed>>
     */
    public function reportingTree(): array
    {
        $sql = <<<'SQL'
            WITH RECURSIVE reporting_line AS (
                SELECT e.id, e.manager_id, 0 AS depth, ARRAY[e.id] AS ancestry
                FROM employees e
                WHERE e.deleted_at IS NULL
                  AND (
                      e.manager_id IS NULL
                      OR NOT EXISTS (
                          SELECT 1 FROM employees m
                          WHERE m.id = e.manager_id AND m.deleted_at IS NULL
                      )
                  )
                UNION ALL
                SELECT e.id, e.manager_id, r.depth + 1, r.ancestry || e.id
                FROM employees e
                JOIN reporting_line r ON e.manager_id = r.id
                WHERE e.deleted_at IS NULL AND NOT (e.id = ANY (r.ancestry))
            )
            SELECT r.depth,
                   e.id, e.employee_code, e.first_name, e.last_name, e.work_email,
                   e.manager_id, e.photo_document_id, e.employment_status,
                   e.employment_type, e.is_active,
                   d.name  AS department_name,
                   g.title AS designation_name,
                   l.name  AS location_name
            FROM reporting_line r
            JOIN employees e ON e.id = r.id
            LEFT JOIN departments  d ON d.id = e.department_id
            LEFT JOIN designations g ON g.id = e.designation_id
            LEFT JOIN locations    l ON l.id = e.location_id
            ORDER BY r.depth, e.first_name, e.last_name
            SQL;

        return $this->raw($sql);
    }

    /**
     * True when giving $employeeId the manager $candidateManagerId would make
     * the employee their own ancestor.
     *
     * The walk is depth bounded so a loop already present in the data cannot
     * make this query run forever.
     */
    public function wouldCreateReportingCycle(string $employeeId, string $candidateManagerId): bool
    {
        if ($employeeId === $candidateManagerId) {
            return true;
        }

        $sql = <<<'SQL'
            WITH RECURSIVE chain AS (
                SELECT e.id, e.manager_id, 1 AS depth
                FROM employees e
                WHERE e.id = :candidate AND e.deleted_at IS NULL
                UNION ALL
                SELECT m.id, m.manager_id, c.depth + 1
                FROM employees m
                JOIN chain c ON m.id = c.manager_id
                WHERE m.deleted_at IS NULL AND c.depth < 64
            )
            SELECT 1 AS found FROM chain WHERE id = :employee LIMIT 1
            SQL;

        return $this->rawOne($sql, [
            'candidate' => $candidateManagerId,
            'employee' => $employeeId,
        ]) !== null;
    }
}
