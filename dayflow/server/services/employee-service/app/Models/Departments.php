<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * Organisational units, arranged as a tree through parent_id.
 */
final class Departments extends Repository
{
    protected string $table = 'departments';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'name', 'code', 'description', 'parent_id',
        'head_employee_id', 'cost_centre', 'is_active',
    ];

    protected array $casts = ['is_active' => 'bool'];

    protected bool $softDeletes = false;

    /** Case-insensitive lookup, so two departments cannot differ only in casing. */
    public function findByCode(string $code): ?array
    {
        $row = $this->rawOne(
            'SELECT * FROM departments WHERE LOWER(code) = LOWER(:code)',
            ['code' => $code]
        );

        return $row === null ? null : $this->present($row);
    }

    public function findByName(string $name): ?array
    {
        $row = $this->rawOne(
            'SELECT * FROM departments WHERE LOWER(name) = LOWER(:name)',
            ['name' => $name]
        );

        return $row === null ? null : $this->present($row);
    }

    public function hasChildren(string $id): bool
    {
        return $this->query()->where('parent_id', '=', $id)->exists();
    }

    /**
     * Every department, each carrying its own headcount.
     *
     * Counting in the same statement avoids one query per row when the
     * organisation screen lists twenty departments.
     *
     * @return list<array<string, mixed>>
     */
    public function withHeadcount(): array
    {
        $sql = <<<'SQL'
            SELECT d.*,
                   p.name AS parent_name,
                   CASE WHEN h.id IS NULL THEN NULL
                        ELSE TRIM(h.first_name || ' ' || h.last_name) END AS head_employee_name,
                   COUNT(e.id) AS employee_count
            FROM departments d
            LEFT JOIN departments p ON p.id = d.parent_id
            LEFT JOIN employees   h ON h.id = d.head_employee_id AND h.deleted_at IS NULL
            LEFT JOIN employees   e ON e.department_id = d.id AND e.deleted_at IS NULL AND e.is_active
            GROUP BY d.id, p.name, h.id, h.first_name, h.last_name
            ORDER BY d.name
        SQL;

        return array_map(function (array $row): array {
            $row = $this->present($row);
            $row['employee_count'] = (int) $row['employee_count'];

            return $row;
        }, $this->raw($sql));
    }

    /** Would this parent choice make a department its own ancestor? */
    public function wouldCycle(string $departmentId, string $parentId): bool
    {
        $sql = <<<'SQL'
            WITH RECURSIVE chain AS (
                SELECT d.id, d.parent_id, 1 AS depth
                FROM departments d
                WHERE d.id = :parent
                UNION ALL
                SELECT p.id, p.parent_id, c.depth + 1
                FROM departments p
                JOIN chain c ON p.id = c.parent_id
                WHERE c.depth < 32
            )
            SELECT 1 AS found FROM chain WHERE id = :department LIMIT 1
        SQL;

        return $this->rawOne($sql, ['parent' => $parentId, 'department' => $departmentId]) !== null;
    }
}
