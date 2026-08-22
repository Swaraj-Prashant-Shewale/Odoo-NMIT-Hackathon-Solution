<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

/**
 * Rotating refresh tokens.
 *
 * Each sign-in starts a family. Every refresh retires the token presented and
 * issues a successor in the same family, so a family is a session and the one
 * unused row in it is that session's current key.
 */
final class RefreshTokens extends Repository
{
    protected string $table = 'refresh_tokens';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'user_id', 'token_hash', 'family_id', 'parent_id', 'issued_at',
        'expires_at', 'used_at', 'revoked_at', 'revoked_reason', 'user_agent', 'ip_address',
    ];

    protected array $hidden = ['token_hash'];

    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    /**
     * Finds the row a presented token belongs to.
     *
     * The lookup is by digest so the comparison is an index seek rather than a
     * scan; already-used and already-revoked rows are returned too, because
     * finding one of those is exactly how a stolen token is detected.
     */
    public function findByHash(string $tokenHash): ?array
    {
        return $this->rawOne(
            'SELECT * FROM refresh_tokens WHERE token_hash = :token_hash LIMIT 1',
            ['token_hash' => $tokenHash]
        );
    }

    public function markUsed(string $id): void
    {
        $this->execute(
            'UPDATE refresh_tokens SET used_at = :now WHERE id = :id AND used_at IS NULL',
            ['id' => $id, 'now' => Clock::iso()]
        );
    }

    /** Retires every live token in one session. Returns how many were revoked. */
    public function revokeFamily(string $familyId, string $reason): int
    {
        return $this->execute(
            'UPDATE refresh_tokens
                SET revoked_at = :now, revoked_reason = :reason
              WHERE family_id = :family_id AND revoked_at IS NULL',
            ['family_id' => $familyId, 'now' => Clock::iso(), 'reason' => $reason]
        );
    }

    /** Signs the account out everywhere, used on password change and deactivation. */
    public function revokeAllForUser(string $userId, string $reason, ?string $exceptFamilyId = null): int
    {
        return $this->execute(
            'UPDATE refresh_tokens
                SET revoked_at = :now, revoked_reason = :reason
              WHERE user_id = :user_id
                AND revoked_at IS NULL
                AND family_id IS DISTINCT FROM :except::uuid',
            [
                'user_id' => $userId,
                'now' => Clock::iso(),
                'reason' => $reason,
                'except' => $exceptFamilyId,
            ]
        );
    }

    /**
     * The caller's live sessions, one row per family.
     *
     * A family only ever has one token that is neither used nor revoked, so
     * this filter naturally collapses the rotation history into sessions.
     *
     * @return list<array<string, mixed>>
     */
    public function liveSessionsFor(string $userId): array
    {
        return $this->raw(
            'SELECT id, family_id, issued_at, expires_at, user_agent, ip_address, started_at
               FROM (
                    SELECT id,
                           family_id,
                           issued_at,
                           expires_at,
                           user_agent,
                           ip_address,
                           used_at,
                           revoked_at,
                           MIN(issued_at) OVER (PARTITION BY family_id) AS started_at
                      FROM refresh_tokens
                     WHERE user_id = :user_id
               ) AS sessions
              WHERE used_at IS NULL
                AND revoked_at IS NULL
                AND expires_at > NOW()
              ORDER BY issued_at DESC',
            ['user_id' => $userId]
        );
    }

    public function findOwnedSession(string $id, string $userId): ?array
    {
        return $this->rawOne(
            'SELECT * FROM refresh_tokens WHERE id = :id AND user_id = :user_id LIMIT 1',
            ['id' => $id, 'user_id' => $userId]
        );
    }
}
