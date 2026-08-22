<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\NotificationPrefs;
use App\Models\Notifications;
use App\Policies\NotificationPolicy;
use App\Services\EventCatalogue;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\Validator;

/**
 * A person's own notification feed and channel preferences.
 *
 * Every method here is scoped to the caller. The route permission only says
 * that somebody may look at notifications at all; which notifications is
 * decided from the token, never from anything in the request.
 */
final class NotificationController
{
    /**
     * There are eight categories, so a request naming more than a handful of
     * them per category is either a mistake or an attempt to make the service
     * do pointless work.
     */
    private const MAX_PREFERENCE_ENTRIES = 50;

    private Notifications $notifications;

    private NotificationPrefs $preferences;

    public function __construct()
    {
        $this->notifications = new Notifications();
        $this->preferences = new NotificationPrefs();
    }

    public function index(Request $request): Response
    {
        $principal = $request->principal();

        return Response::page($this->notifications->pageFor(
            $principal->employeeId,
            $principal->userId,
            $request->bool('unread_only'),
            $request->page(),
            $request->perPage()
        ));
    }

    public function unreadCount(Request $request): Response
    {
        $principal = $request->principal();

        return Response::ok([
            'unread' => $this->notifications->unreadCountFor($principal->employeeId, $principal->userId),
        ]);
    }

    public function read(Request $request): Response
    {
        $principal = $request->principal();

        $notification = NotificationPolicy::assertBelongsTo(
            $principal,
            $this->notifications->findRaw(self::identifier($request))
        );

        return Response::ok($this->notifications->markRead((string) $notification['id']));
    }

    public function readAll(Request $request): Response
    {
        $principal = $request->principal();

        $marked = $this->notifications->markAllRead($principal->employeeId, $principal->userId);

        return Response::ok(['marked' => $marked, 'unread' => 0]);
    }

    public function destroy(Request $request): Response
    {
        $principal = $request->principal();

        $notification = NotificationPolicy::assertBelongsTo(
            $principal,
            $this->notifications->findRaw(self::identifier($request))
        );

        $id = (string) $notification['id'];
        $this->notifications->delete($id);

        AuditLog::record($request, 'notification.deleted', 'notification', $id, $notification, []);

        return Response::noContent();
    }

    public function preferences(Request $request): Response
    {
        return Response::ok($this->effectivePreferences($request->principal()->userId));
    }

    public function updatePreferences(Request $request): Response
    {
        $principal = $request->principal();

        $input = Validator::make($request->all(), [
            'preferences' => 'required|array',
        ], [
            'preferences' => 'The list of preferences',
        ])->validated();

        $entries = $input['preferences'];

        if (count($entries) > self::MAX_PREFERENCE_ENTRIES) {
            throw HttpException::unprocessable('Too many preferences were sent in one request.');
        }

        // Every entry is checked before any of them is stored. Writing them as
        // they are read would leave the first few saved and the rest refused
        // when an entry further down the list turns out to be invalid, and the
        // caller would have no way of telling which half had taken effect.
        // Keying by category also settles a request that names one twice.
        $changes = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw HttpException::unprocessable('Each preference must name a category and its two channels.');
            }

            $preference = Validator::make($entry, [
                'category' => 'required|string|max:40',
                'in_app_enabled' => 'required|bool',
                'email_enabled' => 'required|bool',
            ])->validated();

            $category = (string) $preference['category'];

            // Only categories the platform actually publishes may be stored, so
            // the table cannot be filled with rows nothing will ever read.
            if (!EventCatalogue::isCategory($category)) {
                throw HttpException::unprocessable(sprintf('"%s" is not a notification category.', $category));
            }

            $changes[$category] = [
                'in_app_enabled' => (bool) $preference['in_app_enabled'],
                'email_enabled' => (bool) $preference['email_enabled'],
            ];
        }

        $before = $this->effectivePreferences($principal->userId);

        Connection::transaction(function () use ($principal, $changes): void {
            foreach ($changes as $category => $channels) {
                $this->preferences->set(
                    $principal->userId,
                    (string) $category,
                    $channels['in_app_enabled'],
                    $channels['email_enabled']
                );
            }
        });

        $after = $this->effectivePreferences($principal->userId);

        AuditLog::record(
            $request,
            'notification.preferences.updated',
            'notification_prefs',
            $principal->userId,
            ['preferences' => $before],
            ['preferences' => $after]
        );

        return Response::ok($after);
    }

    /**
     * The full category list with the caller's choices applied.
     *
     * Categories without a stored row are reported as on, which is what they
     * behave as: the absence of a row is the default, not a missing setting.
     *
     * @return list<array<string, mixed>>
     */
    private function effectivePreferences(string $userId): array
    {
        $stored = $this->preferences->forUser($userId);
        $effective = [];

        foreach (EventCatalogue::categories() as $category => $label) {
            $preference = $stored[$category] ?? ['in_app_enabled' => true, 'email_enabled' => true];

            $effective[] = [
                'category' => $category,
                'label' => $label,
                'in_app_enabled' => $preference['in_app_enabled'],
                'email_enabled' => $preference['email_enabled'],
            ];
        }

        return $effective;
    }

    /** Route parameters are input like any other and are validated as such. */
    private static function identifier(Request $request): string
    {
        $data = Validator::make(['id' => $request->route('id')], ['id' => 'required|uuid'])->validated();

        return (string) $data['id'];
    }
}
