<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\QueryBuilder;
use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;

/**
 * Which roles an account holds.
 *
 * Only the grant is stored. What a role is allowed to do lives in the Roles
 * catalogue in code, so widening a permission is a reviewed change rather than
 * an UPDATE somebody can run against the database.
 */
final class UserRoles extends Repository
{
    protected string $table = 'user_roles';

    protected string $primaryKey = 'id';

    protected array $fillable = ['id', 'user_id', 'role', 'granted_by', 'granted_at'];

    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    /** @return list<string> */
    public function rolesFor(string $userId): array
    {
        $rows = $this->raw(
            'SELECT role FROM user_roles WHERE user_id = :user_id ORDER BY role',
            ['user_id' => $userId]
        );

        return array_map(static fn (array $row): string => (string) $row['role'], $rows);
    }

    /**
     * Roles for several accounts at once, keyed by user id.
     *
     * The user list would otherwise issue one query per row, which is the
     * difference between one round trip and a hundred on a busy page.
     *
     * @param list<string> $userIds
     * @return array<string, list<string>>
     */
    public function rolesForMany(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));

        if ($userIds === []) {
            return [];
        }

        $rows = QueryBuilder::table($this->table)
            ->select('user_id', 'role')
            ->whereIn('user_id', $userIds)
            ->orderBy('role')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['user_id']][] = (string) $row['role'];
        }

        return $grouped;
    }

    /** Adds a grant, leaving an existing one untouched. */
    public function grant(string $userId, string $role, ?string $grantedBy): void
    {
        $this->execute(
            'INSERT INTO user_roles (id, user_id, role, granted_by, granted_at)
             VALUES (:id, :user_id, :role, :granted_by, :granted_at)
             ON CONFLICT (user_id, role) DO NOTHING',
            [
                'id' => Str::uuid(),
                'user_id' => $userId,
                'role' => $role,
                'granted_by' => $grantedBy,
                'granted_at' => Clock::iso(),
            ]
        );
    }

    public function revoke(string $userId, string $role): void
    {
        $this->execute(
            'DELETE FROM user_roles WHERE user_id = :user_id AND role = :role',
            ['user_id' => $userId, 'role' => $role]
        );
    }
}
