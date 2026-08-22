<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Audit;

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Logger;
use Dayflow\Kernel\Support\Str;

/**
 * Append-only record of every action that changes data.
 *
 * The trail lives in the shared "platform" schema so one query answers "what
 * happened to this person?" across all services. Entries record who acted, on
 * what, from where, and what changed. Writing the trail must never break the
 * operation being audited, so failures here are logged rather than thrown.
 */
final class AuditLog
{
    // Values that are never copied into the trail, even when a caller passes
    // a whole record as the "before" or "after" state.
    private const NEVER_RECORD = [
        'password', 'password_hash', 'password_confirmation', 'token',
        'refresh_token', 'access_token', 'secret', 'bank_account_number',
        'tax_identifier', 'verification_token', 'reset_token',
    ];

    /**
     * @param string               $action  Dotted verb, e.g. "leave.request.approved".
     * @param string               $subject Type of record touched, e.g. "leave_request".
     * @param array<string, mixed> $before  State before the change.
     * @param array<string, mixed> $after   State after the change.
     */
    public static function record(
        Request $request,
        string $action,
        string $subject,
        ?string $subjectId = null,
        array $before = [],
        array $after = [],
        array $context = [],
    ): void {
        try {
            $actorId = null;
            $actorEmail = null;
            $actorRole = null;

            if ($request->hasPrincipal()) {
                $principal = $request->principal();
                $actorId = $principal->userId;
                $actorEmail = $principal->email;
                $actorRole = $principal->primaryRole();
            }

            $sql = <<<'SQL'
                INSERT INTO platform.audit_log
                    (id, occurred_at, service, action, subject_type, subject_id,
                     actor_id, actor_email, actor_role, ip_address, user_agent,
                     before_state, after_state, context, request_id)
                VALUES
                    (:id, :occurred_at, :service, :action, :subject_type, :subject_id,
                     :actor_id, :actor_email, :actor_role, :ip_address, :user_agent,
                     :before_state, :after_state, :context, :request_id)
            SQL;

            $statement = Connection::pdo()->prepare($sql);
            $statement->execute([
                'id' => Str::uuid(),
                'occurred_at' => Clock::iso(),
                'service' => \Dayflow\Kernel\Support\Env::get('SERVICE_NAME', 'dayflow'),
                'action' => $action,
                'subject_type' => $subject,
                'subject_id' => $subjectId,
                'actor_id' => $actorId,
                'actor_email' => $actorEmail,
                'actor_role' => $actorRole,
                'ip_address' => $request->clientIp,
                'user_agent' => $request->userAgent(),
                'before_state' => self::encode(self::scrub($before)),
                'after_state' => self::encode(self::scrub($after)),
                'context' => self::encode(self::scrub($context)),
                'request_id' => $request->requestId,
            ]);
        } catch (\Throwable $exception) {
            // An audit failure must not roll back the business action, but it
            // is important enough to surface loudly in the service log.
            Logger::error('Audit trail write failed', [
                'action' => $action,
                'subject' => $subject,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Records an authentication event where there is no principal yet.
     *
     * Failed sign-ins are the single most useful thing in a security log, so
     * they are captured even though nobody is authenticated at that moment.
     */
    public static function recordAuthEvent(
        Request $request,
        string $action,
        ?string $userId,
        ?string $email,
        bool $successful,
        array $context = [],
    ): void {
        try {
            $sql = <<<'SQL'
                INSERT INTO platform.audit_log
                    (id, occurred_at, service, action, subject_type, subject_id,
                     actor_id, actor_email, actor_role, ip_address, user_agent,
                     before_state, after_state, context, request_id)
                VALUES
                    (:id, :occurred_at, :service, :action, 'authentication', :subject_id,
                     :actor_id, :actor_email, NULL, :ip_address, :user_agent,
                     NULL, NULL, :context, :request_id)
            SQL;

            $statement = Connection::pdo()->prepare($sql);
            $statement->execute([
                'id' => Str::uuid(),
                'occurred_at' => Clock::iso(),
                'service' => \Dayflow\Kernel\Support\Env::get('SERVICE_NAME', 'dayflow'),
                'action' => $action,
                'subject_id' => $userId,
                'actor_id' => $userId,
                // Email is masked: the trail should show which account was hit
                // without becoming a harvestable list of addresses itself.
                'actor_email' => $email === null ? null : Str::maskEmail($email),
                'ip_address' => $request->clientIp,
                'user_agent' => $request->userAgent(),
                'context' => self::encode($context + ['successful' => $successful]),
                'request_id' => $request->requestId,
            ]);
        } catch (\Throwable $exception) {
            Logger::error('Auth audit write failed', ['action' => $action, 'error' => $exception->getMessage()]);
        }
    }

    /** Computes the changed fields between two states, for a compact trail. */
    public static function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $key => $value) {
            if (in_array(strtolower((string) $key), self::NEVER_RECORD, true)) {
                continue;
            }

            $previous = $before[$key] ?? null;
            if ($previous !== $value) {
                $changes[$key] = ['from' => $previous, 'to' => $value];
            }
        }

        return $changes;
    }

    private static function scrub(array $state): array
    {
        foreach ($state as $key => $value) {
            if (is_array($value)) {
                $state[$key] = self::scrub($value);
                continue;
            }

            if (in_array(strtolower((string) $key), self::NEVER_RECORD, true)) {
                $state[$key] = '[redacted]';
            }
        }

        return $state;
    }

    private static function encode(array $state): ?string
    {
        if ($state === []) {
            return null;
        }

        return json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
