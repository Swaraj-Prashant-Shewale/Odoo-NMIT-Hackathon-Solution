<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

/**
 * Access tokens that were surrendered before they expired.
 *
 * The gateway consults this list on every authenticated call, which is what
 * makes signing out immediate rather than eventual. Entries are only useful
 * until the token would have expired anyway, so expired rows are swept away.
 */
final class RevokedTokens extends Repository
{
    protected string $table = 'revoked_tokens';

    protected string $primaryKey = 'token_id';

    protected array $fillable = ['token_id', 'user_id', 'revoked_at', 'expires_at'];

    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    /** @param int $expiresAt Unix timestamp taken from the token's own exp claim. */
    public function revoke(string $tokenId, string $userId, int $expiresAt): void
    {
        $this->execute(
            'INSERT INTO revoked_tokens (token_id, user_id, revoked_at, expires_at)
             VALUES (:token_id, :user_id, :revoked_at, :expires_at)
             ON CONFLICT (token_id) DO NOTHING',
            [
                'token_id' => $tokenId,
                'user_id' => $userId,
                'revoked_at' => Clock::iso(),
                'expires_at' => (new \DateTimeImmutable('@' . $expiresAt))
                    ->setTimezone(Clock::timezone())
                    ->format(\DateTimeInterface::ATOM),
            ]
        );
    }

    public function prune(): int
    {
        return $this->execute('DELETE FROM revoked_tokens WHERE expires_at < NOW()');
    }
}
