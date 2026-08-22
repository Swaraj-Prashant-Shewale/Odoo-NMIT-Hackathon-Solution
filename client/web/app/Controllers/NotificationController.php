<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Api;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Roles;

/**
 * The notification feed, its channel preferences, and the company notice board.
 *
 * Nothing here names whose notifications to fetch. Notification-service reads
 * that from the token on every call and answers with the caller's own feed, so
 * an identifier in a request could never widen it. The same is true of the
 * notice board: a notice addressed to one department is filtered in SQL before
 * it is ever loaded, not hidden afterwards by this client.
 */
final class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    private const ANNOUNCEMENTS_PER_PAGE = 10;

    /** Categories offered when publishing, all slug-shaped as the service requires. */
    private const ANNOUNCEMENT_CATEGORIES = [
        'general' => 'General',
        'policy' => 'Policy',
        'benefits' => 'Benefits',
        'event' => 'Event',
        'celebration' => 'Celebration',
        'system' => 'System',
    ];

    private const SEVERITIES = ['info', 'success', 'warning', 'critical'];

    // -----------------------------------------------------------------------
    // The feed
    // -----------------------------------------------------------------------

    public function index(): void
    {
        $unreadOnly = $this->inputBool('unread_only');

        $query = ['page' => $this->page(), 'per_page' => self::PER_PAGE];

        if ($unreadOnly) {
            $query['unread_only'] = 'true';
        }

        $response = Api::get('/notifications', $query);
        $this->guard($response, '/');

        $counter = Api::data('/notifications/unread-count', [], ['unread' => 0]);

        View::render('notifications/index', [
            'pageTitle' => 'Notifications',
            'breadcrumbs' => [['Notifications', '']],
            'notifications' => $this->rows($response),
            'meta' => $response['meta'],
            'unreadOnly' => $unreadOnly,
            'unread' => is_array($counter) ? (int) ($counter['unread'] ?? 0) : 0,
        ]);
    }

    public function markRead(array $parameters = []): void
    {
        $id = (string) ($parameters['id'] ?? '');

        $response = Api::post('/notifications/' . rawurlencode($id) . '/read');

        if (!$response['ok']) {
            $this->backWithErrors($response, [], '/notifications');
        }

        $this->back('/notifications');
    }

    public function markAllRead(): void
    {
        $response = Api::post('/notifications/read-all');

        if (!$response['ok']) {
            $this->backWithErrors($response, [], '/notifications');
        }

        $marked = is_array($response['data']) ? (int) ($response['data']['marked'] ?? 0) : 0;

        Flash::success($marked === 0
            ? 'There was nothing left to mark as read.'
            : sprintf('%d notification%s marked as read.', $marked, $marked === 1 ? '' : 's'));

        $this->back('/notifications');
    }

    // -----------------------------------------------------------------------
    // Channel preferences
    // -----------------------------------------------------------------------

    /**
     * Which categories reach this person, and by which channel.
     *
     * The service returns the full category list with the caller's choices
     * already applied, so a category they have never touched arrives switched
     * on rather than missing.
     */
    public function preferences(): void
    {
        $response = Api::get('/notifications/preferences');
        $this->guard($response, '/notifications');

        View::render('notifications/preferences', [
            'pageTitle' => 'Notification preferences',
            'breadcrumbs' => [['Notifications', '/notifications'], ['Preferences', '']],
            'preferences' => $this->rows($response),
            'explanations' => $this->categoryExplanations(),
        ]);
    }

    /**
     * Saves the grid.
     *
     * An unticked checkbox is not submitted at all, so the categories arrive
     * from a hidden field and each channel is read as the list of categories
     * that were ticked. Without that hidden list, switching everything off
     * would send an empty request that changed nothing.
     */
    public function savePreferences(): void
    {
        $categories = $this->inputArray('categories');
        $inApp = $this->inputArray('in_app');
        $email = $this->inputArray('email');

        if ($categories === []) {
            Flash::error('No preferences were submitted.');
            $this->redirect('/notifications/preferences');
        }

        $preferences = array_map(
            static fn (string $category): array => [
                'category' => $category,
                'in_app_enabled' => in_array($category, $inApp, true),
                'email_enabled' => in_array($category, $email, true),
            ],
            $categories
        );

        $response = Api::put('/notifications/preferences', ['preferences' => $preferences]);

        if (!$response['ok']) {
            $this->backWithErrors($response, [], '/notifications/preferences');
        }

        Flash::success('Your notification preferences have been saved.');
        $this->redirect('/notifications/preferences');
    }

    // -----------------------------------------------------------------------
    // Announcements
    // -----------------------------------------------------------------------

    /**
     * The company notice board.
     *
     * Anybody who may publish can also switch to the whole board, retired and
     * expired notices included, because a board you can only see the live half
     * of cannot be maintained.
     */
    public function announcements(): void
    {
        $mayPublish = Session::can(Permissions::ANNOUNCEMENT_PUBLISH);
        $scope = $mayPublish && $this->input('scope') === 'all' ? 'all' : 'visible';

        $response = Api::get('/announcements', [
            'scope' => $scope,
            'page' => $this->page(),
            'per_page' => self::ANNOUNCEMENTS_PER_PAGE,
        ]);

        $this->guard($response, '/');

        $announcements = $this->rows($response);

        View::render('notifications/announcements', [
            'pageTitle' => 'Announcements',
            'breadcrumbs' => [['Announcements', '']],
            'announcements' => $announcements,
            'publishers' => $this->publishers($announcements),
            'meta' => $response['meta'],
            'scope' => $scope,
            'mayPublish' => $mayPublish,
            'departments' => $mayPublish ? Api::collection('/departments') : [],
            'roles' => array_map(
                static fn (string $role): array => ['value' => $role, 'label' => Roles::label($role)],
                Roles::HIERARCHY
            ),
            'categories' => self::ANNOUNCEMENT_CATEGORIES,
            'severities' => self::SEVERITIES,
            'today' => date('Y-m-d'),
        ]);
    }

    /** Publishes a notice. The author and the time are the service's to set. */
    public function storeAnnouncement(): void
    {
        $payload = array_filter([
            'title' => $this->input('title'),
            'body' => $this->input('body'),
            'category' => $this->input('category', 'general'),
            'severity' => $this->input('severity', 'info'),
            'expires_on' => $this->input('expires_on'),
            'target_department_id' => $this->input('target_department_id'),
            'target_role' => $this->input('target_role'),
        ], static fn (string $value): bool => $value !== '');

        $payload['pinned'] = $this->inputBool('pinned');

        $response = Api::post('/announcements', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/announcements');
        }

        Flash::success('Your announcement has been published.');
        $this->redirect('/announcements');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Names for the accounts that published the notices on this page.
     *
     * An announcement records the account that published it and nothing else,
     * because a name copied at publication time would be wrong the moment
     * somebody married or corrected a spelling. Only the handful of authors
     * actually on the page are looked up, and only for a caller entitled to
     * read account records at all.
     *
     * @param list<array<string, mixed>> $announcements
     * @return array<string, string>
     */
    private function publishers(array $announcements): array
    {
        if (!Session::can(Permissions::PROFILE_VIEW_ALL)) {
            return [];
        }

        $ids = [];

        foreach ($announcements as $announcement) {
            $id = (string) ($announcement['published_by'] ?? '');

            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        $names = [];

        foreach (array_keys($ids) as $id) {
            $response = Api::get('/users/' . rawurlencode($id));

            if ($response['ok'] && is_array($response['data'])) {
                $names[$id] = (string) ($response['data']['full_name'] ?? $response['data']['email'] ?? '');
            }
        }

        return $names;
    }

    /**
     * What each notification category actually covers.
     *
     * The service supplies the key and its label; this adds the sentence that
     * tells somebody what they are switching off before they switch it off.
     *
     * @return array<string, string>
     */
    private function categoryExplanations(): array
    {
        return [
            'account' => 'Sign-in alerts, password changes and anything affecting your account itself.',
            'people' => 'Changes to your profile, and documents approaching their renewal date.',
            'attendance' => 'Your own punches, flagged absences and attendance correction decisions.',
            'leave' => 'Requests you raise or approve, decisions on them, and balance adjustments.',
            'payroll' => 'Payslips published, salary revisions and expense claim decisions.',
            'learning' => 'Courses assigned to you and the certificates you earn.',
            'talent' => 'Review cycles that open for you, submitted reviews and goal progress.',
            'general' => 'Anything the platform sends that does not belong to one of the areas above.',
        ];
    }

    /**
     * The data element of a response as a list of records.
     *
     * @param array{data?: mixed} $response
     * @return list<array<string, mixed>>
     */
    private function rows(array $response): array
    {
        $data = $response['data'] ?? null;

        if (!is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, 'is_array'));
    }
}
