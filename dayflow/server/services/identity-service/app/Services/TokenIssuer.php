<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RefreshTokens;
use App\Models\RevokedTokens;
use Dayflow\Kernel\Security\Jwt;
use Dayflow\Kernel\Security\Roles;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Str;

/**
 * Mints and retires the two credentials a signed-in client holds.
 *
 * The access token is a short-lived signed statement of who the caller is. It
 * is never looked up, which is what lets eight other services authorise a
 * request without asking this one. The refresh token is the opposite: a long
 * random value that means nothing on its own and is only worth anything
 * because a row here says so, which is what makes it revocable.
 */
final class TokenIssuer
{
    private RefreshTokens $refreshTokens;
    private RevokedTokens $revokedTokens;

    public function __construct()
    {
        $this->refreshTokens = new RefreshTokens();
        $this->revokedTokens = new RevokedTokens();
    }

    public function accessTokenLifetime(): int
    {
        return Env::int('ACCESS_TOKEN_TTL', 900);
    }

    public function refreshTokenLifetime(): int
    {
        return Env::int('REFRESH_TOKEN_TTL', 604800);
    }

    /**
     * Builds the claim set every service in the platform reads.
     *
     * Permissions are deliberately absent: a service recomputes them from the
     * roles in the token, so revising what a role may do takes effect on the
     * next request rather than when the last old token finally expires.
     *
     * @param array<string, mixed>          $user   The account row.
     * @param list<string>                  $roles  Roles held, from the database.
     * @param array{department_id: ?string, manager_id: ?string} $context
     * @return array<string, mixed>
     */
    public function claimsFor(array $user, array $roles, array $context): array
    {
        return [
            'sub' => (string) $user['id'],
            'employee_id' => $user['employee_id'] === null ? null : (string) $user['employee_id'],
            'email' => (string) $user['email'],
            'name' => trim(((string) $user['first_name']) . ' ' . ((string) $user['last_name'])),
            'roles' => $roles === [] ? [Roles::EMPLOYEE] : array_values($roles),
            'department_id' => $context['department_id'] ?? null,
            'manager_id' => $context['manager_id'] ?? null,
            'type' => 'access',
        ];
    }

    /** @param array<string, mixed> $claims */
    public function issueAccessToken(array $claims): string
    {
        return Jwt::issue($claims, $this->accessTokenLifetime());
    }

    /**
     * Starts a new session and returns its refresh token in the clear, once.
     *
     * @return array{token: string, record: array<string, mixed>}
     */
    public function startSession(string $userId, string $userAgent, string $ipAddress): array
    {
        return $this->mint($userId, Str::uuid(), null, $userAgent, $ipAddress);
    }

    /**
     * Issues the successor to a token that has just been spent.
     *
     * @param array<string, mixed> $parent
     * @return array{token: string, record: array<string, mixed>}
     */
    public function rotate(array $parent, string $userAgent, string $ipAddress): array
    {
        $this->refreshTokens->markUsed((string) $parent['id']);

        return $this->mint(
            (string) $parent['user_id'],
            (string) $parent['family_id'],
            (string) $parent['id'],
            $userAgent,
            $ipAddress
        );
    }

    /**
     * Looks up the row behind a presented refresh token.
     *
     * Retired rows are returned as well: finding one is the entire point of
     * reuse detection, and telling the two cases apart is the caller's job.
     *
     * @return array<string, mixed>|null
     */
    public function findSession(string $refreshToken): ?array
    {
        $record = $this->refreshTokens->findByHash(TokenHasher::hash($refreshToken));

        if ($record === null) {
            return null;
        }

        // The digest lookup already found this row; the constant-time compare
        // is what actually authorises it.
        if (!TokenHasher::matches($refreshToken, (string) $record['token_hash'])) {
            return null;
        }

        return $record;
    }

    public function isUsable(array $record): bool
    {
        return $record['used_at'] === null
            && $record['revoked_at'] === null
            && strtotime((string) $record['expires_at']) > Clock::timestamp();
    }

    /** Retires one session. */
    public function revokeSession(string $familyId, string $reason): int
    {
        return $this->refreshTokens->revokeFamily($familyId, $reason);
    }

    /** Retires every session an account holds, optionally sparing the current one. */
    public function revokeAllSessions(string $userId, string $reason, ?string $exceptFamilyId = null): int
    {
        return $this->refreshTokens->revokeAllForUser($userId, $reason, $exceptFamilyId);
    }

    /**
     * Puts an access token beyond use before its natural expiry.
     *
     * @param array<string, mixed> $claims Verified claims of the token being surrendered.
     */
    public function revokeAccessToken(array $claims): void
    {
        $tokenId = isset($claims['jti']) ? (string) $claims['jti'] : '';
        $expiresAt = isset($claims['exp']) ? (int) $claims['exp'] : 0;

        if ($tokenId === '' || $expiresAt <= Clock::timestamp()) {
            return;
        }

        $this->revokedTokens->revoke($tokenId, (string) ($claims['sub'] ?? ''), $expiresAt);

        // The list only matters until each entry's token would have expired
        // anyway. Sweeping occasionally rather than on a schedule keeps it
        // small without adding a process to operate.
        if (random_int(1, 50) === 1) {
            $this->revokedTokens->prune();
        }
    }

    /**
     * @return array{token: string, record: array<string, mixed>}
     */
    private function mint(
        string $userId,
        string $familyId,
        ?string $parentId,
        string $userAgent,
        string $ipAddress,
    ): array {
        $token = Str::token(32);
        $now = Clock::now();

        $record = $this->refreshTokens->create([
            'id' => Str::uuid(),
            'user_id' => $userId,
            'token_hash' => TokenHasher::hash($token),
            'family_id' => $familyId,
            'parent_id' => $parentId,
            'issued_at' => $now->format(\DateTimeInterface::ATOM),
            'expires_at' => $now->modify('+' . $this->refreshTokenLifetime() . ' seconds')
                ->format(\DateTimeInterface::ATOM),
            'user_agent' => $userAgent === '' ? null : $userAgent,
            'ip_address' => $ipAddress,
        ]);

        return ['token' => $token, 'record' => $record];
    }
}
