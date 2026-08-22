<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;

/**
 * The ledger of events this service has already dealt with.
 *
 * Publishing services keep an event in their outbox until the sink answers,
 * which means an event arrives again after every failed or slow delivery. The
 * unique event id is the only thing standing between that retry and a second
 * copy of the same email landing in somebody's inbox.
 *
 * A claim is only ever taken in the same transaction as the notification and
 * the queued message it accounts for, so there is nothing to give back: a
 * failure rolls the claim away with the writes it was standing for.
 */
final class ProcessedEvents extends Repository
{
    protected string $table = 'processed_events';

    protected string $primaryKey = 'id';

    protected array $fillable = ['id', 'event_id', 'event_name', 'source', 'processed_at'];

    protected bool $timestamps = false;

    /**
     * Whether an event id has already been dealt with.
     *
     * Only a hint: it answers a redelivery without composing the message again,
     * but it cannot decide the race between two deliveries arriving at once.
     * claim() is what settles that.
     */
    public function seen(string $eventId): bool
    {
        return $this->rawOne(
            'SELECT 1 AS present FROM processed_events WHERE event_id = :event_id',
            ['event_id' => $eventId]
        ) !== null;
    }

    /**
     * Reserves an event id for this delivery.
     *
     * Returns false when the id is already present, which is the signal to
     * acknowledge the retry and do nothing else. The insert is the claim, so
     * two deliveries arriving at once cannot both win.
     */
    public function claim(string $eventId, string $eventName, string $source): bool
    {
        $affected = $this->execute(
            'INSERT INTO processed_events (id, event_id, event_name, source, processed_at)
             VALUES (:id, :event_id, :event_name, :source, :processed_at)
             ON CONFLICT (event_id) DO NOTHING',
            [
                'id' => Str::uuid(),
                'event_id' => $eventId,
                'event_name' => $eventName,
                'source' => $source,
                'processed_at' => Clock::iso(),
            ]
        );

        return $affected > 0;
    }
}
