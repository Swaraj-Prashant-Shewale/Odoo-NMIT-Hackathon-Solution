<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EmailTemplates;
use App\Models\NotificationPrefs;
use App\Models\Notifications;
use App\Models\ProcessedEvents;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;
use Dayflow\Kernel\Support\Str;

/**
 * Turns one delivered domain event into a notification and an email.
 *
 * The sequence is fixed: find the template that describes the message, work out
 * who it is for, render it, respect what that person asked to receive, and then
 * write the ledger claim, the notification and the queued message together in
 * one transaction. Composing first keeps the call to employee-service outside
 * that transaction; committing the claim with the writes is what makes "exactly
 * once" true even if the process dies half way through.
 */
final class EventIngestor
{
    private Notifications $notifications;
    private NotificationPrefs $preferences;
    private EmailTemplates $templates;
    private ProcessedEvents $processed;
    private Mailer $mailer;

    public function __construct()
    {
        $this->notifications = new Notifications();
        $this->preferences = new NotificationPrefs();
        $this->templates = new EmailTemplates();
        $this->processed = new ProcessedEvents();
        $this->mailer = new Mailer();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed> A summary of what the event produced.
     */
    public function ingest(
        string $event,
        array $payload,
        string $publishedAt,
        string $source,
        ?string $bearerToken,
    ): array {
        $eventId = self::identify($event, $payload, $source);

        // A redelivery is the ordinary case rather than the exception, so it is
        // answered from one cheap read. The claim inside the transaction below
        // is still what actually decides it; this only avoids composing the
        // same message again on every retry.
        if ($this->processed->seen($eventId)) {
            return ['event' => $event, 'event_id' => $eventId, 'status' => 'duplicate'];
        }

        // Composing reads a template and may call employee-service. None of it
        // writes, and none of it belongs inside a transaction: one held open
        // across an HTTP call would hold a connection for as long as the far
        // side takes to answer.
        $message = $this->compose($event, $payload, $publishedAt, $bearerToken);

        $outcome = Connection::transaction(function () use ($eventId, $event, $source, $message): array {
            if (!$this->processed->claim($eventId, $event, $source)) {
                return ['status' => 'duplicate'];
            }

            return $this->persist($message);
        });

        // Delivery is only attempted once the ledger entry, the notification and
        // the queued message have all committed together.
        if (($outcome['status'] ?? '') === 'processed') {
            $outcome['mail'] = $this->mailer->flush();
        }

        return ['event' => $event, 'event_id' => $eventId] + $outcome;
    }

    /**
     * A stable identifier for one occurrence of an event.
     *
     * The published_at stamp is deliberately excluded. A publisher regenerates
     * it every time it retries an undelivered outbox row, so including it would
     * make every retry look like a brand new event and deliver the same message
     * to the same person again.
     *
     * @param array<string, mixed> $payload
     */
    public static function identify(string $event, array $payload, string $source): string
    {
        $canonical = json_encode(
            ['source' => $source, 'event' => $event, 'payload' => self::sortDeep($payload)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return hash('sha256', (string) $canonical);
    }

    /**
     * Decides what this event should produce, writing nothing.
     *
     * Kept separate from persist() so the template lookup, the recipient lookup
     * over HTTP and the render all happen before a transaction is opened.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function compose(string $event, array $payload, string $publishedAt, ?string $bearerToken): array
    {
        $template = $this->templates->activeFor($event);

        if ($template === null) {
            // Not an error. Services are free to publish events this one has no
            // opinion about, and a template can be added later without anything
            // upstream needing to change.
            return ['status' => 'ignored', 'reason' => 'no_template'];
        }

        $definition = EventCatalogue::for($event, $payload);
        $recipient = (new RecipientResolver($bearerToken))->resolve($definition, $payload);

        if (!$recipient['resolved']) {
            return ['status' => 'ignored', 'reason' => 'no_recipient'];
        }

        $values = self::placeholders($event, $payload, $publishedAt, $recipient);

        // A subject is one line by definition, and a payload value rendered into
        // it may not be: a leave note or a rejection reason is free text that
        // people press Enter in. The line breaks are folded away here as well as
        // in the mailer, so the stored subject and the notification title made
        // from it are single lines wherever they are read.
        $subject = self::singleLine(TemplateRenderer::text($template['subject'], $values));

        return [
            'status' => 'composed',
            'event' => $event,
            'definition' => $definition,
            'recipient' => $recipient,
            'channels' => $this->channels($recipient['user_id'], $definition['category']),
            'subject' => $subject === '' ? $event : $subject,
            'body_html' => TemplateRenderer::html($template['body_html'], $values),
            'body_text' => TemplateRenderer::text($template['body_text'], $values),
            'action_url' => self::safeUrl(
                $definition['action_url'] === null
                    ? null
                    : TemplateRenderer::text($definition['action_url'], $values)
            ),
        ];
    }

    /**
     * Writes what compose() decided on.
     *
     * Runs inside the same transaction as the ledger claim, so the claim, the
     * in-app notification and the queued message either all land or none of
     * them do. Nothing here is caught: a failed statement leaves a PostgreSQL
     * transaction that can only be rolled back, and rolling the claim back with
     * it is exactly what lets the publisher's next delivery produce the message
     * once rather than lose it.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function persist(array $message): array
    {
        if (($message['status'] ?? '') !== 'composed') {
            // Nothing to write, but the claim still stands: an event this
            // service has no opinion about should be answered instantly the
            // next time it arrives rather than reconsidered from scratch.
            return $message;
        }

        $event = (string) $message['event'];
        $definition = $message['definition'];
        $recipient = $message['recipient'];
        $channels = $message['channels'];

        $notificationId = null;
        if ($channels['in_app_enabled']) {
            $notification = $this->notifications->create([
                'id' => Str::uuid(),
                'employee_id' => $recipient['employee_id'],
                'user_id' => $recipient['user_id'],
                'category' => $definition['category'],
                'event_name' => $event,
                'title' => $message['subject'],
                'body' => $message['body_text'],
                'action_url' => $message['action_url'],
                'icon' => $definition['icon'],
                'severity' => $definition['severity'],
                'created_at' => Clock::iso(),
            ]);

            $notificationId = $notification['id'] ?? null;
        }

        $emailId = null;
        $address = $recipient['email'];

        if ($definition['email'] && $channels['email_enabled']) {
            if ($address === null) {
                Logger::info('No address available for an event that would have been mailed', [
                    'event' => $event,
                    'employee_id' => $recipient['employee_id'],
                ]);
            } else {
                $queued = $this->mailer->queue([
                    'to_address' => $address,
                    'to_name' => $recipient['name'],
                    'subject' => $message['subject'],
                    'body_html' => $message['body_html'],
                    'body_text' => $message['body_text'],
                    'event_name' => $event,
                    'related_user_id' => $recipient['user_id'],
                ]);

                $emailId = $queued['id'] ?? null;
            }
        }

        return [
            'status' => 'processed',
            'notification_id' => $notificationId,
            'email_id' => $emailId,
        ];
    }

    /**
     * What the recipient has asked to receive for this category.
     *
     * An account with no stored row wants everything, which is what keeps a new
     * joiner reachable without a preference being seeded for them first.
     *
     * @return array{in_app_enabled: bool, email_enabled: bool}
     */
    private function channels(?string $userId, string $category): array
    {
        $defaults = ['in_app_enabled' => true, 'email_enabled' => true];

        if ($userId === null) {
            return $defaults;
        }

        return $this->preferences->forUser($userId)[$category] ?? $defaults;
    }

    /**
     * Everything a template may refer to: the payload itself, plus the few
     * things every message needs and no payload carries.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $recipient
     * @return array<string, mixed>
     */
    private static function placeholders(
        string $event,
        array $payload,
        string $publishedAt,
        array $recipient,
    ): array {
        $name = (string) $recipient['name'];
        $firstName = (string) $recipient['first_name'];

        // Three layers, most authoritative first.
        //
        // The platform's own values are not open to a payload. {{app_url}} is
        // the host of the button in every verification and password-reset
        // email this service sends, so an event able to supply its own would be
        // able to point that button at an address of its choosing.
        //
        // Below that the payload wins over the resolved contact details: an
        // event carrying its own first_name knows the person better than a
        // lookup that may have failed.
        return [
            'app_url' => rtrim((string) Env::get('APP_URL', 'http://localhost:8000'), '/'),
            'company_name' => (string) Env::get('APP_NAME', 'Dayflow'),
            'support_email' => (string) Env::get('MAIL_FROM_ADDRESS', 'no-reply@dayflow.local'),
            'event_name' => $event,
            'published_at' => $publishedAt,
            'today' => Clock::today(),
            'current_year' => Clock::now()->format('Y'),
        ] + $payload + [
            'recipient_name' => $name,
            'full_name' => $name,
            'first_name' => $firstName !== '' ? $firstName : 'there',
        ];
    }

    /**
     * Keeps only links the interface can safely follow.
     *
     * The stored value ends up in the href of a link in every recipient's feed,
     * so a rendered placeholder is never allowed to introduce a scheme of its
     * own, and a protocol-relative path is not treated as internal.
     */
    private static function safeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    /**
     * Collapses every line break and run of whitespace into single spaces.
     *
     * Matched byte by byte rather than in UTF-8 mode: no byte of a multi-byte
     * character falls in the whitespace range, and a byte-wise pattern cannot
     * fail and hand back null on input the encoding does not like.
     */
    private static function singleLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /** Orders keys so the same payload always produces the same digest. */
    private static function sortDeep(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = self::sortDeep($item);
        }

        if (!array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }
}
