<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;

/**
 * Every message the platform has ever tried to send.
 *
 * Messages are written here before any transport is touched, so a send that
 * fails leaves a row to retry and an error to read rather than a message that
 * disappeared between two services.
 */
final class EmailOutbox extends Repository
{
    protected string $table = 'email_outbox';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'to_address', 'to_name', 'subject', 'body_html', 'body_text',
        'status', 'attempts', 'last_error', 'queued_at', 'sent_at',
        'event_name', 'related_user_id', 'created_at',
    ];

    protected array $casts = ['attempts' => 'int'];

    // Rows record queued_at and sent_at instead of a generic updated_at.
    protected bool $timestamps = false;

    /**
     * @param array{to_address: string, to_name?: string, subject: string,
     *              body_html: string, body_text: string, event_name?: ?string,
     *              related_user_id?: ?string} $message
     */
    public function queue(array $message): array
    {
        $now = Clock::iso();

        return $this->create([
            'id' => Str::uuid(),
            'to_address' => $message['to_address'],
            'to_name' => $message['to_name'] ?? '',
            'subject' => $message['subject'],
            'body_html' => $message['body_html'],
            'body_text' => $message['body_text'],
            'status' => 'queued',
            'attempts' => 0,
            'queued_at' => $now,
            'event_name' => $message['event_name'] ?? null,
            'related_user_id' => $message['related_user_id'] ?? null,
            'created_at' => $now,
        ]);
    }

    /**
     * Takes ownership of a batch of pending messages.
     *
     * The claim and the attempt counter move in a single statement so two
     * concurrent flushes can never pick up the same message, and a process that
     * dies mid-send still leaves the attempt recorded.
     *
     * @return list<array<string, mixed>>
     */
    public function claimPending(int $limit, int $maxAttempts): array
    {
        $statement = Connection::pdo()->prepare(
            'UPDATE email_outbox SET attempts = attempts + 1
             WHERE id IN (
                 SELECT id FROM email_outbox
                 WHERE status = :status AND attempts < :max_attempts
                 ORDER BY queued_at
                 LIMIT :take
                 FOR UPDATE SKIP LOCKED
             )
             RETURNING *'
        );

        $statement->bindValue('status', 'queued');
        $statement->bindValue('max_attempts', $maxAttempts, \PDO::PARAM_INT);
        $statement->bindValue('take', max(1, $limit), \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function markSent(string $id): void
    {
        $this->execute(
            'UPDATE email_outbox SET status = :status, sent_at = :sent_at, last_error = NULL WHERE id = :id',
            ['status' => 'sent', 'sent_at' => Clock::iso(), 'id' => $id]
        );
    }

    /**
     * Records a send failure.
     *
     * The message stays queued while attempts remain, because the usual cause
     * is a mail server that is briefly unreachable.
     */
    public function markAttemptFailed(string $id, string $error, int $maxAttempts): void
    {
        $this->execute(
            "UPDATE email_outbox
             SET last_error = :error,
                 status = CASE WHEN attempts >= :max_attempts THEN 'failed' ELSE 'queued' END
             WHERE id = :id",
            ['error' => mb_substr($error, 0, 500), 'max_attempts' => $maxAttempts, 'id' => $id]
        );
    }

    /** Records a failure that retrying cannot fix, such as a malformed address. */
    public function markUndeliverable(string $id, string $error): void
    {
        $this->execute(
            'UPDATE email_outbox SET status = :status, last_error = :error WHERE id = :id',
            ['status' => 'failed', 'error' => mb_substr($error, 0, 500), 'id' => $id]
        );
    }
}
