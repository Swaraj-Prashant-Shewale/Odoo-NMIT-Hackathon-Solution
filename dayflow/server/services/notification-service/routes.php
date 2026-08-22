<?php

declare(strict_types=1);

use App\Controllers\AnnouncementController;
use App\Controllers\EventController;
use App\Controllers\MailboxController;
use App\Controllers\NotificationController;
use Dayflow\Kernel\Http\Router;
use Dayflow\Kernel\Security\Permissions;

return static function (Router $router): void {
    $events = new EventController();
    $notifications = new NotificationController();
    $announcements = new AnnouncementController();
    $mailbox = new MailboxController();

    // The event sink. It is deliberately absent from the gateway route table,
    // so nothing outside the private network can address it at all, and the
    // kernel still rejects any call that does not carry a valid internal
    // signature. Publishing services deliver from a background flush with no
    // user token attached, so the controller re-checks origin itself rather
    // than demanding a principal that will never be there.
    $router->post('/events', [$events, 'ingest'])->allowPublic();

    // Fixed segments are registered ahead of the {id} routes so a path such as
    // "/notifications/unread-count" can never be read as an identifier.
    $router->get('/notifications', [$notifications, 'index'])->requires(Permissions::NOTIFICATION_VIEW_SELF);
    $router->get('/notifications/unread-count', [$notifications, 'unreadCount'])->requires(Permissions::NOTIFICATION_VIEW_SELF);
    $router->get('/notifications/preferences', [$notifications, 'preferences'])->authenticated();
    $router->put('/notifications/preferences', [$notifications, 'updatePreferences'])->authenticated();
    $router->post('/notifications/read-all', [$notifications, 'readAll'])->authenticated();
    $router->post('/notifications/{id}/read', [$notifications, 'read'])->authenticated();
    $router->delete('/notifications/{id}', [$notifications, 'destroy'])->authenticated();

    $router->get('/announcements', [$announcements, 'index'])->authenticated();
    $router->post('/announcements', [$announcements, 'store'])->requires(Permissions::ANNOUNCEMENT_PUBLISH);
    $router->post('/announcements/{id}/read', [$announcements, 'read'])->authenticated();
    $router->patch('/announcements/{id}', [$announcements, 'update'])->requires(Permissions::ANNOUNCEMENT_PUBLISH);
    $router->delete('/announcements/{id}', [$announcements, 'destroy'])->requires(Permissions::ANNOUNCEMENT_PUBLISH);

    // The development inbox holds every verification and password-reset link in
    // the company. Both routes are only authenticated at the router; the
    // controller refuses outright outside development before it looks at
    // anything else, so the endpoint does not even exist in production.
    $router->get('/mailbox', [$mailbox, 'index'])->authenticated();
    $router->get('/mailbox/{id}', [$mailbox, 'show'])->authenticated();
};
