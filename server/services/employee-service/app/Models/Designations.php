<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * Job titles, ranked by level so seniority can be compared numerically.
 */
final class Designations extends Repository
{
    protected string $table = 'designations';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'title', 'code', 'level', 'department_id', 'description', 'is_active',
    ];

    protected array $casts = ['is_active' => 'bool', 'level' => 'int'];

    protected bool $softDeletes = false;

    public function findByCode(string $code): ?array
    {
        $row = $this->rawOne(
            'SELECT * FROM designations WHERE LOWER(code) = LOWER(:code)',
            ['code' => $code]
        );

        return $row === null ? null : $this->present($row);
    }

    /** @return list<array<string, mixed>> */
    public function withDepartment(): array
    {
        $sql = <<<'SQL'
            SELECT g.*, d.name AS department_name
            FROM designations g
            LEFT JOIN departments d ON d.id = g.department_id
            ORDER BY g.level DESC, g.title
        SQL;

        return array_map([$this, 'present'], $this->raw($sql));
    }
}
