<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

/**
 * In-app notifications.
 *
 * A row is addressed to an employee, a login account, or both. Account-only
 * rows exist because the identity events fire before anybody has an employee
 * record: a welcome or password-reset message has to reach a person who is not
 * yet on the payroll.
 */
final class Notifications extends Repository
{
    protected string $table = 'notifications';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'employee_id', 'user_id', 'category', 'event_name', 'title',
        'body', 'action_url', 'icon', 'severity', 'read_at', 'created_at',
    ];

    // The table carries no updated_at: a notification is written once and only
    // ever gains a read timestamp afterwards.
    protected bool $timestamps = false;

    public function present(array $row): array
    {
        $row = parent::present($row);

        if ($row !== []) {
            $row['is_read'] = ($row['read_at'] ?? null) !== null;
        }

        return $row;
    }

    /**
     * One page of the notifications addressed to a single person.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function pageFor(
        ?string $employeeId,
        ?string $userId,
        bool $unreadOnly,
        int $page,
        int $perPage,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));

        $where = '(employee_id = :employee_id OR user_id = :user_id)';
        if ($unreadOnly) {
            $where .= ' AND read_at IS NULL';
        }

        $total = (int) ($this->rawOne(
            'SELECT COUNT(*) AS aggregate FROM notifications WHERE ' . $where,
            ['employee_id' => $employeeId, 'user_id' => $userId]
        )['aggregate'] ?? 0);

        $statement = Connection::pdo()->prepare(
            'SELECT * FROM notifications WHERE ' . $where
            . ' ORDER BY created_at DESC, id DESC LIMIT :take OFFSET :skip'
        );

        $statement->bindValue('employee_id', $employeeId);
        $statement->bindValue('user_id', $userId);
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

    public function unreadCountFor(?string $employeeId, ?string $userId): int
    {
        $row = $this->rawOne(
            'SELECT COUNT(*) AS aggregate FROM notifications
             WHERE read_at IS NULL AND (employee_id = :employee_id OR user_id = :user_id)',
            ['employee_id' => $employeeId, 'user_id' => $userId]
        );

        return (int) ($row['aggregate'] ?? 0);
    }

    /** Reads one row without any ownership filter; the caller must apply it. */
    public function findRaw(string $id): ?array
    {
        return $this->rawOne('SELECT * FROM notifications WHERE id = :id', ['id' => $id]);
    }

    /** Stamps a single notification as read and returns the fresh row. */
    public function markRead(string $id): ?array
    {
        $row = $this->rawOne(
            'UPDATE notifications SET read_at = :read_at
             WHERE id = :id AND read_at IS NULL
             RETURNING *',
            ['read_at' => Clock::iso(), 'id' => $id]
        );

        // A row that was already read updates nothing, so fall back to reading
        // it rather than reporting a miss the caller would treat as a 404.
        return $row === null ? $this->find($id) : $this->present($row);
    }

    /** Stamps every unread notification belonging to one person. */
    public function markAllRead(?string $employeeId, ?string $userId): int
    {
        return $this->execute(
            'UPDATE notifications SET read_at = :read_at
             WHERE read_at IS NULL AND (employee_id = :employee_id OR user_id = :user_id)',
            ['read_at' => Clock::iso(), 'employee_id' => $employeeId, 'user_id' => $userId]
        );
    }
}
