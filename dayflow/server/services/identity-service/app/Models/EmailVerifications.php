<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

/**
 * Single-use links that prove somebody controls the address they signed up with.
 *
 * Only the digest of a link is stored, so the table is worthless to anyone who
 * reads it: it cannot be turned back into a working verification link.
 */
final class EmailVerifications extends Repository
{
    protected string $table = 'email_verifications';

    protected string $primaryKey = 'id';

    protected array $fillable = ['id', 'user_id', 'token_hash', 'expires_at', 'consumed_at', 'created_at'];

    protected array $hidden = ['token_hash'];

    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    public function findOpenByHash(string $tokenHash): ?array
    {
        return $this->rawOne(
            'SELECT * FROM email_verifications
              WHERE token_hash = :token_hash AND consumed_at IS NULL AND expires_at > NOW()
              LIMIT 1',
            ['token_hash' => $tokenHash]
        );
    }

    /** Returns true only if this call was the one that consumed the link. */
    public function consume(string $id): bool
    {
        return $this->execute(
            'UPDATE email_verifications SET consumed_at = :now
              WHERE id = :id AND consumed_at IS NULL',
            ['id' => $id, 'now' => Clock::iso()]
        ) === 1;
    }
}
