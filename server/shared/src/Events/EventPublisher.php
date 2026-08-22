<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Events;

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Http\ServiceClient;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;
use Dayflow\Kernel\Support\Str;

/**
 * Publishes domain events to interested services.
 *
 * Events are written to an outbox table inside the publishing service's own
 * schema, in the same transaction as the change that produced them. That is
 * what makes the pattern reliable: an event can never be announced for a
 * change that was rolled back, and a change can never be committed without its
 * event being recorded.
 *
 * Delivery happens after the HTTP response has been flushed, so a slow or
 * unavailable subscriber never delays the user. Anything that fails to deliver
 * stays in the outbox and is retried on the next flush.
 */
final class EventPublisher
{
    /** @var list<array{name: string, payload: array}> */
    private static array $queued = [];

    /**
     * Queues an event for publication.
     *
     * @param string               $name    Dotted event name, e.g. "leave.request.approved".
     * @param array<string, mixed> $payload Data subscribers need. Keep it small.
     */
    public static function publish(string $name, array $payload): void
    {
        self::$queued[] = ['name' => $name, 'payload' => $payload];

        try {
            $statement = Connection::pdo()->prepare(sprintf(
                'INSERT INTO %s.event_outbox (id, event_name, payload, created_at)
                 VALUES (:id, :event_name, :payload, :created_at)',
                Connection::quoteIdentifier(Connection::schema())
            ));

            $statement->execute([
                'id' => Str::uuid(),
                'event_name' => $name,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => Clock::iso(),
            ]);
        } catch (\Throwable $exception) {
            Logger::error('Could not queue event', ['event' => $name, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Delivers everything sitting in the outbox.
     *
     * Called by the kernel once the response has been sent.
     */
    public static function flush(): void
    {
        self::$queued = [];

        if (!Env::bool('EVENTS_ENABLED', true)) {
            return;
        }

        try {
            $schema = Connection::quoteIdentifier(Connection::schema());

            // Claim a batch so two concurrent flushes cannot deliver the same
            // event twice.
            //
            // The claim has to be a single statement. A bare SELECT ... FOR
            // UPDATE outside an explicit transaction releases its locks the
            // moment the statement finishes, so SKIP LOCKED would protect
            // nothing. Wrapping the select inside an UPDATE ... RETURNING makes
            // the lock, the claim and the read one atomic operation: whoever
            // wins the row takes it, and the other flush skips straight past.
            $claim = Connection::pdo()->prepare(sprintf(
                'UPDATE %1$s.event_outbox
                 SET attempts = attempts + 1, last_attempt_at = NOW()
                 WHERE id IN (
                     SELECT id
                     FROM %1$s.event_outbox
                     WHERE delivered_at IS NULL AND attempts < :max_attempts
                     ORDER BY created_at
                     LIMIT :batch
                     FOR UPDATE SKIP LOCKED
                 )
                 RETURNING id, event_name, payload, attempts',
                $schema
            ));

            $claim->bindValue('max_attempts', Env::int('EVENT_MAX_ATTEMPTS', 5), \PDO::PARAM_INT);
            $claim->bindValue('batch', Env::int('EVENT_BATCH_SIZE', 25), \PDO::PARAM_INT);
            $claim->execute();

            $events = $claim->fetchAll();

            foreach ($events as $event) {
                self::deliver(
                    (string) $event['id'],
                    (string) $event['event_name'],
                    json_decode((string) $event['payload'], true) ?: [],
                    (int) $event['attempts']
                );
            }
        } catch (\Throwable $exception) {
            Logger::warning('Event flush failed', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Which services listen for which events.
     *
     * Kept as a static map because the topology is small and explicit routing
     * is far easier to reason about than a discovery mechanism.
     *
     * @return array<string, list<string>> Event name pattern => service names.
     */
    private static function subscribers(): array
    {
        return [
            'employee.*' => ['notification'],
            'leave.*' => ['notification'],
            // Approved leave has to reach the attendance register, or somebody
            // on a fortnight off is simply absent from it for ten days and
            // their attendance rate falls as though they never turned up.
            'leave.request.decided' => ['attendance'],
            'leave.request.cancelled' => ['attendance'],
            'attendance.*' => ['notification'],
            'payroll.*' => ['notification'],
            'learning.*' => ['notification'],
            'talent.*' => ['notification'],
            'identity.*' => ['notification'],
        ];
    }

    private static function deliver(string $id, string $name, array $payload, int $attempts): void
    {
        $targets = [];

        foreach (self::subscribers() as $pattern => $services) {
            if (fnmatch($pattern, $name)) {
                $targets = array_merge($targets, $services);
            }
        }

        $targets = array_values(array_unique($targets));

        if ($targets === []) {
            self::markDelivered($id);

            return;
        }

        $delivered = true;

        foreach ($targets as $service) {
            try {
                ServiceClient::for($service)->post('/events', [
                    'event' => $name,
                    'payload' => $payload,
                    'published_at' => Clock::iso(),
                    'source' => Env::get('SERVICE_NAME', 'dayflow'),
                ]);
            } catch (\Throwable $exception) {
                $delivered = false;
                Logger::warning('Event delivery failed', [
                    'event' => $name,
                    'target' => $service,
                    'attempt' => $attempts + 1,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        // A failed delivery needs no write: the claim already recorded the
        // attempt, so the event simply stays unclaimed for the next flush.
        if ($delivered) {
            self::markDelivered($id);
        }
    }

    private static function markDelivered(string $id): void
    {
        $statement = Connection::pdo()->prepare(sprintf(
            'UPDATE %s.event_outbox SET delivered_at = :delivered_at WHERE id = :id',
            Connection::quoteIdentifier(Connection::schema())
        ));

        $statement->execute(['delivered_at' => Clock::iso(), 'id' => $id]);
    }
}
