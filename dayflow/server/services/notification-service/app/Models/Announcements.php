<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

/**
 * Company announcements.
 *
 * An announcement is either addressed to everybody or narrowed to one
 * department or one role. Narrowing is applied in SQL rather than filtered out
 * after the fact, so a targeted notice is never loaded into a response the
 * reader is not entitled to see.
 */
final class Announcements extends Repository
{
    protected string $table = 'announcements';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'title', 'body', 'category', 'severity', 'published_by',
        'published_at', 'expires_on', 'pinned', 'target_department_id',
        'target_role', 'is_active',
    ];

    protected array $casts = [
        'pinned' => 'bool',
        'is_active' => 'bool',
        'is_read' => 'bool',
    ];

    /**
     * The announcements one person is entitled to read, newest first with
     * pinned notices held at the top, each carrying that person's read state.
     *
     * @param list<string> $roles
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function feedFor(
        ?string $employeeId,
        ?string $departmentId,
        array $roles,
        int $page,
        int $perPage,
    ): array {
        [$roleClause, $roleBindings] = $this->roleClause($roles);

        $where = 'a.is_active = TRUE
                  AND (a.expires_on IS NULL OR a.expires_on >= :today)
                  AND (a.target_department_id IS NULL OR a.target_department_id = :department_id)
                  AND ' . $roleClause;

        $bindings = $roleBindings + [
            'today' => Clock::today(),
            'department_id' => $departmentId,
        ];

        return $this->paginateJoined($where, $bindings, $employeeId, $page, $perPage);
    }

    /**
     * Every announcement, including retired and expired ones.
     *
     * Reserved for callers holding the publishing permission: an editor cannot
     * maintain a notice board they are only allowed to see the live half of.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function allFor(?string $employeeId, int $page, int $perPage): array
    {
        return $this->paginateJoined('TRUE', [], $employeeId, $page, $perPage);
    }

    /**
     * @param array<string, mixed> $bindings
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    private function paginateJoined(
        string $where,
        array $bindings,
        ?string $employeeId,
        int $page,
        int $perPage,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));

        $total = (int) ($this->rawOne(
            'SELECT COUNT(*) AS aggregate FROM announcements a WHERE ' . $where,
            $bindings
        )['aggregate'] ?? 0);

        $sql = 'SELECT a.*, (r.id IS NOT NULL) AS is_read, r.read_at
                FROM announcements a
                LEFT JOIN announcement_reads r
                       ON r.announcement_id = a.id AND r.employee_id = :reader_id
                WHERE ' . $where . '
                ORDER BY a.pinned DESC, a.published_at DESC, a.id DESC
                LIMIT :take OFFSET :skip';

        $statement = Connection::pdo()->prepare($sql);

        foreach ($bindings as $name => $value) {
            $statement->bindValue($name, $value);
        }

        $statement->bindValue('reader_id', $employeeId);
        $statement->bindValue('take', $perPage, \PDO::PARAM_INT);
        $statement->bindValue('skip', ($page - 1) * $perPage, \PDO::PARAM_INT);
        $statement->execute();

        return [
            'data' => array_map([$this, 'present'], $statement->fetchAll()),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Builds the role filter.
     *
     * Placeholder names are generated here rather than taken from the role
     * strings, so nothing a caller controls reaches the statement text.
     *
     * @param list<string> $roles
     * @return array{0: string, 1: array<string, string>}
     */
    private function roleClause(array $roles): array
    {
        $roles = array_values(array_unique(array_map('strval', $roles)));

        if ($roles === []) {
            return ['a.target_role IS NULL', []];
        }

        $placeholders = [];
        $bindings = [];

        foreach ($roles as $index => $role) {
            $name = 'role' . $index;
            $placeholders[] = ':' . $name;
            $bindings[$name] = $role;
        }

        return [
            '(a.target_role IS NULL OR a.target_role IN (' . implode(', ', $placeholders) . '))',
            $bindings,
        ];
    }
}
