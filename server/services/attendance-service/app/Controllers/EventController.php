<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\LeaveMirror;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Jwt;
use Dayflow\Kernel\Security\TokenException;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;
use Dayflow\Kernel\Support\Str;
use Dayflow\Kernel\Validation\Validator;

/**
 * Events this service acts on.
 *
 * Only one thing matters here so far: when leave is approved, those days have
 * to appear in the attendance register as taken. The two facts live in
 * different services and neither can read the other's tables, so the leave
 * service says what it decided and this service works out what that means for
 * the register.
 *
 * The contract with a publisher is the same one notification-service keeps:
 * anything answered with a 2xx is never sent again, so an event this service
 * has no interest in is acknowledged rather than refused. Only a genuine
 * failure - something a retry might fix - is allowed to fail.
 */
final class EventController
{
    /** Dotted, lower case, at least two segments: "leave.request.decided". */
    private const EVENT_NAME = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/';

    private LeaveMirror $mirror;

    public function __construct()
    {
        $this->mirror = new LeaveMirror();
    }

    public function ingest(Request $request): Response
    {
        self::assertTrustedOrigin($request);

        $data = Validator::make($request->all(), [
            'event' => 'required|string|max:120',
            'payload' => 'nullable|array',
            'published_at' => 'nullable|datetime',
            'source' => 'nullable|string|max:60',
        ])->validated();

        $event = (string) $data['event'];

        if (preg_match(self::EVENT_NAME, $event) !== 1) {
            return Response::ok(['event' => $event, 'status' => 'ignored', 'reason' => 'unrecognised_event_name']);
        }

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $source = (string) ($data['source'] ?? 'unknown');

        if (!in_array($event, ['leave.request.decided', 'leave.request.cancelled'], true)) {
            return Response::ok(['event' => $event, 'status' => 'ignored', 'reason' => 'not_subscribed']);
        }

        // The published_at stamp is deliberately not part of the identity: a
        // publisher stamps it afresh on every retry of the same outbox row, so
        // including it would make each retry look like a new event and write
        // the same days again.
        $eventId = hash('sha256', (string) json_encode(
            ['source' => $source, 'event' => $event, 'payload' => self::sortDeep($payload)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        $outcome = Connection::transaction(function () use ($eventId, $event, $source, $payload): array {
            // The claim is what actually decides a duplicate, not a prior read:
            // two deliveries arriving at once would both pass a check-then-act.
            if (!$this->claim($eventId, $event, $source)) {
                return ['status' => 'duplicate'];
            }

            return $this->apply($event, $payload);
        });

        return Response::ok(['event' => $event, 'event_id' => $eventId] + $outcome);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function apply(string $event, array $payload): array
    {
        $employeeId = self::text($payload, 'employee_id');
        $from = self::text($payload, 'starts_on');
        $to = self::text($payload, 'ends_on');
        $status = self::text($payload, 'status');

        if ($employeeId === '' || $from === '' || $to === '') {
            // Nothing to act on, but nothing a retry would improve either.
            return ['status' => 'processed', 'days' => 0, 'reason' => 'incomplete_payload'];
        }

        // A half day is still a day at work, so the register keeps the punch
        // rather than being overwritten with a whole day away.
        if (self::flag($payload, 'is_half_day')) {
            return ['status' => 'processed', 'days' => 0, 'reason' => 'half_day'];
        }

        if ($event === 'leave.request.cancelled' || $status === 'rejected') {
            $cleared = $this->mirror->clearAway($employeeId, $from, $to);

            Logger::info('Leave cleared from the attendance register', [
                'employee_id' => $employeeId,
                'from' => $from,
                'to' => $to,
                'days' => $cleared,
            ]);

            return ['status' => 'processed', 'days' => $cleared, 'action' => 'cleared'];
        }

        if ($status !== 'approved') {
            return ['status' => 'processed', 'days' => 0, 'reason' => 'not_approved'];
        }

        $written = $this->mirror->markAway($employeeId, $from, $to, self::text($payload, 'reason') ?: null);

        Logger::info('Approved leave recorded in the attendance register', [
            'employee_id' => $employeeId,
            'from' => $from,
            'to' => $to,
            'days' => $written,
        ]);

        return ['status' => 'processed', 'days' => $written, 'action' => 'marked'];
    }

    /** Records this event as handled, or reports that somebody else already did. */
    private function claim(string $eventId, string $event, string $source): bool
    {
        $statement = Connection::pdo()->prepare(
            'INSERT INTO processed_events (id, event_id, event_name, source, processed_at)
             VALUES (:id, :event_id, :event_name, :source, :processed_at)
             ON CONFLICT (event_id) DO NOTHING'
        );

        $statement->execute([
            'id' => Str::uuid(),
            'event_id' => $eventId,
            'event_name' => $event,
            'source' => $source,
            'processed_at' => Clock::iso(),
        ]);

        return $statement->rowCount() > 0;
    }

    /** @param array<string, mixed> $payload */
    private static function text(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $payload */
    private static function flag(array $payload, string $key): bool
    {
        return filter_var($payload[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Sorts keys at every level so two identical payloads digest identically
     * however they happened to be ordered on the wire.
     */
    private static function sortDeep(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $sorted = array_map([self::class, 'sortDeep'], $value);

        if (!array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }

    /**
     * Confirms the caller is another Dayflow service.
     *
     * Two things already stand in front of this endpoint: it is absent from the
     * gateway's route table, so nothing outside the private network can address
     * it, and the kernel verifies an HMAC over the method, path and body of
     * every inbound call. Events are delivered from a background flush that
     * carries no user token, so that signature is the proof of origin.
     *
     * When signature checking has been switched off the endpoint has no proof
     * of anything, and falls back to requiring a verified access token.
     */
    private static function assertTrustedOrigin(Request $request): void
    {
        if (Env::bool('REQUIRE_GATEWAY_SIGNATURE', true)) {
            return;
        }

        $token = $request->bearerToken();

        if ($token === null) {
            throw HttpException::unauthorized();
        }

        try {
            $claims = Jwt::verify($token);
        } catch (TokenException $exception) {
            throw HttpException::unauthorized($exception->getMessage());
        }

        if (($claims['type'] ?? 'access') !== 'access') {
            throw HttpException::unauthorized('This token cannot be used to call the API.');
        }
    }
}
