<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EventIngestor;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Jwt;
use Dayflow\Kernel\Security\TokenException;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Validation\Validator;

/**
 * The event sink every other service delivers to.
 *
 * The contract with a publisher is simple: anything answered with a 2xx will
 * never be sent again, so this endpoint answers 200 for everything it has
 * understood, including events it deliberately does nothing about. It only
 * fails when the failure is genuinely worth a retry.
 */
final class EventController
{
    /** Dotted, lower case, at least two segments: "leave.request.decided". */
    private const EVENT_NAME = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/';

    private EventIngestor $ingestor;

    public function __construct()
    {
        $this->ingestor = new EventIngestor();
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
            // Acknowledged rather than refused. A publisher retries anything it
            // is not given a 2xx for, and an event name this service will never
            // recognise would sit in its outbox being redelivered for ever.
            return Response::ok([
                'event' => $event,
                'status' => 'ignored',
                'reason' => 'unrecognised_event_name',
            ]);
        }

        $payload = $data['payload'] ?? [];

        $result = $this->ingestor->ingest(
            $event,
            is_array($payload) ? $payload : [],
            (string) ($data['published_at'] ?? Clock::iso()),
            (string) ($data['source'] ?? 'unknown'),
            $request->bearerToken()
        );

        return Response::ok($result);
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
