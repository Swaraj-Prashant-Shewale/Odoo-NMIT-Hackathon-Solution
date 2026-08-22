<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

/**
 * Login accounts.
 *
 * The password hash is hidden rather than merely omitted by callers, so no
 * future endpoint can leak it by returning a whole record. Anything that
 * genuinely needs the hash - only the sign-in and password-change paths - asks
 * for it explicitly through findForAuthentication().
 */
final class Users extends Repository
{
    protected string $table = 'users';

    protected string $primaryKey = 'id';

    /**
     * Every column this service writes, which is wider than anything a caller
     * may influence. Request data never reaches here unfiltered: a controller
     * hands over only the array the Validator returned, and the endpoints that
     * edit an account declare no rule for the credential columns at all, so
     * they cannot appear in it.
     */
    protected array $fillable = [
        'id', 'employee_id', 'employee_code', 'email', 'password_hash',
        'first_name', 'last_name', 'is_active', 'email_verified_at',
        'must_change_password', 'failed_login_count', 'locked_until',
        'last_login_at', 'last_login_ip', 'deleted_at',
    ];

    protected array $hidden = ['password_hash'];

    protected array $casts = [
        'is_active' => 'bool',
        'must_change_password' => 'bool',
        'failed_login_count' => 'int',
    ];

    protected bool $softDeletes = true;

    /**
     * Reads a PostgreSQL boolean from a row however the driver hands it over.
     *
     * Rows fetched through raw() skip the repository's casts, and filter_var on
     * its own is not a safe substitute: it maps both 't' and 'f' to false,
     * which would quietly treat every active account as a disabled one.
     */
    public static function isTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }

    /** Adds the derived fields the interface needs so it never assembles them itself. */
    public function present(array $row): array
    {
        $row = parent::present($row);

        if ($row === []) {
            return $row;
        }

        $row['full_name'] = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
        $row['is_locked'] = isset($row['locked_until']) && strtotime((string) $row['locked_until']) > Clock::timestamp();
        $row['is_verified'] = ($row['email_verified_at'] ?? null) !== null;

        return $row;
    }

    /**
     * One filtered page of the account list.
     *
     * Written out rather than assembled with the query builder for two
     * reasons. The role filter is an EXISTS test instead of a join, so a
     * future second grant of the same role could never make one person appear
     * twice and throw the page total out. And the free-text search needs one
     * value compared against four columns, which means four placeholders
     * carrying the same value: a builder that binds a shared placeholder once
     * cannot express it.
     *
     * @param array<string, mixed> $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $clauses = ['deleted_at IS NULL'];
        $bindings = [];

        if (($filters['search'] ?? null) !== null) {
            // PostgreSQL treats a backslash as LIKE's escape character by
            // default, so neutralising the wildcards is enough to make a "%"
            // typed into the search box match a literal per cent sign.
            $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['search']) . '%';

            $clauses[] = '(email ILIKE :match_email
                           OR first_name ILIKE :match_first_name
                           OR last_name ILIKE :match_last_name
                           OR employee_code ILIKE :match_employee_code)';

            $bindings['match_email'] = $pattern;
            $bindings['match_first_name'] = $pattern;
            $bindings['match_last_name'] = $pattern;
            $bindings['match_employee_code'] = $pattern;
        }

        if (($filters['role'] ?? null) !== null) {
            $clauses[] = 'EXISTS (SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role = :role)';
            $bindings['role'] = (string) $filters['role'];
        }

        $status = $filters['status'] ?? null;

        if ($status !== null) {
            $clauses[] = match ($status) {
                'active' => 'is_active = TRUE',
                'inactive' => 'is_active = FALSE',
                'locked' => 'locked_until > NOW()',
                'unverified' => 'email_verified_at IS NULL',
                'verified' => 'email_verified_at IS NOT NULL',
            };
        }

        $where = implode(' AND ', $clauses);

        $count = $this->rawOne('SELECT COUNT(*) AS aggregate FROM users WHERE ' . $where, $bindings);
        $total = (int) ($count['aggregate'] ?? 0);

        $rows = $this->raw(
            'SELECT * FROM users
              WHERE ' . $where . '
              ORDER BY created_at DESC, id
              LIMIT :page_size OFFSET :page_offset',
            $bindings + [
                'page_size' => $perPage,
                'page_offset' => (max(1, $page) - 1) * $perPage,
            ]
        );

        return [
            'data' => array_map([$this, 'present'], $rows),
            'meta' => [
                'page' => max(1, $page),
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }

    /**
     * Reads the row the sign-in path needs, including the password hash.
     *
     * Deleted and disabled accounts are returned as well, because refusing
     * them is a decision the controller has to make explicitly rather than one
     * that should silently look like "no such address".
     */
    public function findForAuthentication(string $email): ?array
    {
        return $this->rawOne(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => strtolower(trim($email))]
        );
    }

    /** The stored hash for one account, used when confirming a current password. */
    public function passwordHashFor(string $userId): ?string
    {
        $row = $this->rawOne(
            'SELECT password_hash FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $userId]
        );

        return $row === null ? null : (string) $row['password_hash'];
    }

    /**
     * True when the address is already taken, deleted accounts included.
     *
     * Registration has to know this without the caller learning it, and a
     * soft-deleted row still occupies the unique index.
     */
    public function emailTaken(string $email): bool
    {
        return $this->rawOne(
            'SELECT 1 AS taken FROM users WHERE email = :email LIMIT 1',
            ['email' => strtolower(trim($email))]
        ) !== null;
    }

    /**
     * IS DISTINCT FROM lets one placeholder serve both cases: a null exclusion
     * matches every row, a supplied one skips exactly that account.
     */
    public function employeeCodeTaken(string $employeeCode, ?string $exceptUserId = null): bool
    {
        return $this->rawOne(
            'SELECT 1 AS taken FROM users
             WHERE upper(employee_code) = upper(:code) AND id IS DISTINCT FROM :except::uuid
             LIMIT 1',
            ['code' => $employeeCode, 'except' => $exceptUserId]
        ) !== null;
    }

    public function employeeLinkTaken(string $employeeId, ?string $exceptUserId = null): bool
    {
        return $this->rawOne(
            'SELECT 1 AS taken FROM users
             WHERE employee_id = :employee_id::uuid AND id IS DISTINCT FROM :except::uuid
             LIMIT 1',
            ['employee_id' => $employeeId, 'except' => $exceptUserId]
        ) !== null;
    }

    /** Records a failed sign-in and reports the resulting counter. */
    public function registerFailedAttempt(string $userId): int
    {
        $row = $this->rawOne(
            'UPDATE users
                SET failed_login_count = failed_login_count + 1, updated_at = :now
              WHERE id = :id
          RETURNING failed_login_count',
            ['id' => $userId, 'now' => Clock::iso()]
        );

        return (int) ($row['failed_login_count'] ?? 0);
    }

    public function lock(string $userId, string $until): void
    {
        // The counter is cleared alongside the lock so the next window starts
        // fresh once the lock expires, rather than locking again immediately.
        $this->execute(
            'UPDATE users SET locked_until = :until, failed_login_count = 0, updated_at = :now WHERE id = :id',
            ['id' => $userId, 'until' => $until, 'now' => Clock::iso()]
        );
    }

    public function registerSuccessfulLogin(string $userId, string $ipAddress): void
    {
        $now = Clock::iso();

        $this->execute(
            'UPDATE users
                SET failed_login_count = 0,
                    locked_until       = NULL,
                    last_login_at      = :signed_in_at,
                    last_login_ip      = :ip,
                    updated_at         = :updated_at
              WHERE id = :id',
            ['id' => $userId, 'ip' => $ipAddress, 'signed_in_at' => $now, 'updated_at' => $now]
        );
    }

    /**
     * Replaces the stored credential.
     *
     * Clearing the lock at the same time is deliberate: somebody who has just
     * proved control of the account should not still be locked out of it.
     */
    public function replacePassword(string $userId, string $passwordHash, bool $mustChange = false): void
    {
        $this->execute(
            'UPDATE users
                SET password_hash        = :hash,
                    must_change_password = :must_change,
                    failed_login_count   = 0,
                    locked_until         = NULL,
                    updated_at           = :now
              WHERE id = :id',
            [
                'id' => $userId,
                'hash' => $passwordHash,
                'must_change' => $mustChange ? 'true' : 'false',
                'now' => Clock::iso(),
            ]
        );
    }

    public function markEmailVerified(string $userId): void
    {
        $now = Clock::iso();

        // Only the first confirmation is recorded, so a replayed link cannot
        // rewrite the date the address was actually proven.
        $this->execute(
            'UPDATE users SET email_verified_at = :verified_at, updated_at = :updated_at
              WHERE id = :id AND email_verified_at IS NULL',
            ['id' => $userId, 'verified_at' => $now, 'updated_at' => $now]
        );
    }
}
