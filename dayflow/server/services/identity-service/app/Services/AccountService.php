<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EmailVerifications;
use App\Models\PasswordResets;
use App\Models\UserRoles;
use App\Models\Users;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Str;

/**
 * Creating accounts and the single-use links that prove control of an address.
 *
 * A link's plaintext exists exactly twice: in the return value here, which
 * goes straight into the event payload the notification service consumes, and
 * in the message that reaches the person. Only its digest is written down, so
 * neither this database nor its backups can produce a working link.
 */
final class AccountService
{
    private Users $users;
    private UserRoles $userRoles;
    private EmailVerifications $verifications;
    private PasswordResets $resets;

    public function __construct()
    {
        $this->users = new Users();
        $this->userRoles = new UserRoles();
        $this->verifications = new EmailVerifications();
        $this->resets = new PasswordResets();
    }

    public function verificationLifetime(): int
    {
        return max(600, Env::int('EMAIL_VERIFICATION_TTL', 86400));
    }

    public function resetLifetime(): int
    {
        // Short by design: a reset link is the strongest credential the system
        // ever puts in an inbox, so it should stop working long before anyone
        // gets around to reading an old message.
        return max(300, Env::int('PASSWORD_RESET_TTL', 3600));
    }

    /**
     * Creates an account together with its roles, in one transaction.
     *
     * @param array<string, mixed> $attributes Already validated and normalised.
     * @param list<string>         $roles
     * @return array<string, mixed> The created account row.
     */
    public function create(array $attributes, array $roles, ?string $grantedBy): array
    {
        return Connection::transaction(function () use ($attributes, $roles, $grantedBy): array {
            $user = $this->users->create($attributes + ['id' => Str::uuid()]);

            foreach ($roles as $role) {
                $this->userRoles->grant((string) $user['id'], $role, $grantedBy);
            }

            return $user;
        });
    }

    /**
     * Issues a fresh verification link.
     *
     * @return string The plaintext token, which is never stored.
     */
    public function issueVerificationToken(string $userId): string
    {
        $token = Str::token(32);

        $this->verifications->create([
            'id' => Str::uuid(),
            'user_id' => $userId,
            'token_hash' => TokenHasher::hash($token),
            'expires_at' => Clock::now()
                ->modify('+' . $this->verificationLifetime() . ' seconds')
                ->format(\DateTimeInterface::ATOM),
            'created_at' => Clock::iso(),
        ]);

        return $token;
    }

    /**
     * Issues a fresh password-reset link, retiring any still outstanding.
     *
     * @return string The plaintext token, which is never stored.
     */
    public function issueResetToken(string $userId, string $requestedIp): string
    {
        $token = Str::token(32);

        // Superseding the previous link matters: one that reached the wrong
        // inbox must stop working the moment the real owner asks for another.
        $this->resets->invalidateOpenFor($userId);

        $this->resets->create([
            'id' => Str::uuid(),
            'user_id' => $userId,
            'token_hash' => TokenHasher::hash($token),
            'expires_at' => Clock::now()
                ->modify('+' . $this->resetLifetime() . ' seconds')
                ->format(\DateTimeInterface::ATOM),
            'created_at' => Clock::iso(),
            'requested_ip' => $requestedIp,
        ]);

        return $token;
    }

    /**
     * Consumes a verification link and returns the account it belonged to.
     *
     * @return array<string, mixed>|null Null when the link is unknown, spent or expired.
     */
    public function consumeVerification(string $token): ?array
    {
        $record = $this->verifications->findOpenByHash(TokenHasher::hash($token));

        if ($record === null || !TokenHasher::matches($token, (string) $record['token_hash'])) {
            return null;
        }

        // consume() only succeeds for the first caller, so two clicks arriving
        // together cannot both be treated as the one that verified.
        if (!$this->verifications->consume((string) $record['id'])) {
            return null;
        }

        return $this->users->find((string) $record['user_id']);
    }

    /**
     * Resolves a reset link without spending it.
     *
     * Lookup and consumption are separate here, unlike verification, because
     * the new password still has to be judged against the account's own name
     * and address. Spending the link first would mean a rejected password also
     * destroyed the only way to try again.
     *
     * @return array{reset: array<string, mixed>, user: array<string, mixed>}|null
     */
    public function findOpenReset(string $token): ?array
    {
        $record = $this->resets->findOpenByHash(TokenHasher::hash($token));

        if ($record === null || !TokenHasher::matches($token, (string) $record['token_hash'])) {
            return null;
        }

        $user = $this->users->find((string) $record['user_id']);

        return $user === null ? null : ['reset' => $record, 'user' => $user];
    }

    /** Spends a reset link. False when something else got there first. */
    public function consumeReset(string $resetId): bool
    {
        return $this->resets->consume($resetId);
    }
}
