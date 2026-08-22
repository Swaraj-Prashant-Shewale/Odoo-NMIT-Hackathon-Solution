<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

/**
 * Single-use, short-lived links for setting a forgotten password.
 *
 * As with verification, only the digest is stored. The address that asked for
 * the link is recorded so a burst of requests against many accounts from one
 * place is visible afterwards.
 */
final class PasswordResets extends Repository
{
    protected string $table = 'password_resets';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'user_id', 'token_hash', 'expires_at', 'consumed_at', 'created_at', 'requested_ip',
    ];

    protected array $hidden = ['token_hash'];

    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    public function findOpenByHash(string $tokenHash): ?array
    {
        return $this->rawOne(
            'SELECT * FROM password_resets
              WHERE token_hash = :token_hash AND consumed_at IS NULL AND expires_at > NOW()
              LIMIT 1',
            ['token_hash' => $tokenHash]
        );
    }

    /** Returns true only if this call was the one that consumed the link. */
    public function consume(string $id): bool
    {
        return $this->execute(
            'UPDATE password_resets SET consumed_at = :now
              WHERE id = :id AND consumed_at IS NULL',
            ['id' => $id, 'now' => Clock::iso()]
        ) === 1;
    }

    /**
     * Retires any link still outstanding for an account.
     *
     * Issuing a new one has to invalidate the old, otherwise a link that
     * reached the wrong inbox stays usable for its full lifetime.
     */
    public function invalidateOpenFor(string $userId): int
    {
        return $this->execute(
            'UPDATE password_resets SET consumed_at = :now
              WHERE user_id = :user_id AND consumed_at IS NULL',
            ['user_id' => $userId, 'now' => Clock::iso()]
        );
    }
}
