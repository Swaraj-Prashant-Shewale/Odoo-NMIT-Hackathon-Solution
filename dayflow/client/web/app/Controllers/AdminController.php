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
 * Platform administration.
 *
 * Every screen here is a thin viewer over one of the services: accounts and
 * the access model from identity, the organisation from employee-service,
 * leave policy from leave-service, shifts and holidays from attendance. The
 * client decides nothing. It reads what a service reports, renders it, and
 * posts back exactly the fields that service accepts.
 *
 * Two habits run through the whole class. Permissions are checked before a
 * control is drawn, so nobody is shown a button that will fail — the API
 * enforces the same rules again, which is where the real decision is made.
 * And every write redirects afterwards, so a refresh on an administration
 * screen can never repeat a role change or a second holiday.
 */
final class AdminController extends Controller
{
    /** Rows per page on the paginated administration listings. */
    private const PER_PAGE = 20;

    /** The audit trail is denser, so it carries more per page. */
    private const AUDIT_PER_PAGE = 25;

    /**
     * Pages of the people directory a picker will load.
     *
     * A department head is chosen from a select, and a select cannot page.
     * Five pages of a hundred covers any organisation this product is built
     * for, and the form says so when it did not reach the end rather than
     * presenting a truncated list as if it were everybody.
     */
    private const DIRECTORY_PAGES = 5;

    // -----------------------------------------------------------------------
    // Administration home
    // -----------------------------------------------------------------------

    /**
     * The administration index.
     *
     * The route carries no permission of its own because each area behind it
     * has a different one, so the page is assembled from whichever of them the
     * caller actually holds. Somebody with none of them sees an empty state
     * rather than a wall of links that all end in "access denied".
     */
    public function index(): void
    {
        $summary = ['total' => null, 'active' => null];

        if (Session::can(Permissions::PROFILE_VIEW_ALL)) {
            $all = Api::get('/users', ['per_page' => 1]);
            $active = Api::get('/users', ['status' => 'active', 'per_page' => 1]);

            $summary['total'] = $all['ok'] ? (int) ($all['meta']['total'] ?? 0) : null;
            $summary['active'] = $active['ok'] ? (int) ($active['meta']['total'] ?? 0) : null;
        }

        $health = null;

        if (Session::can(Permissions::SYSTEM_SETTINGS)) {
            $response = Api::get('/health');
            $health = $response['ok'] && is_array($response['data']) ? $response['data'] : null;
        }

        View::render('admin/index', [
            'pageTitle' => 'Administration',
            'breadcrumbs' => [['Administration', '']],
            'areas' => $this->areas(),
            'summary' => $summary,
            'health' => $health,
        ]);
    }

    // -----------------------------------------------------------------------
    // Accounts
    // -----------------------------------------------------------------------

    /** A filtered page of login accounts. */
    public function users(): void
    {
        $filters = $this->filled([
            'search' => $this->input('search'),
            'role' => $this->input('role'),
            'status' => $this->input('status'),
        ]);

        $response = Api::get('/users', $filters + [
            'page' => $this->page(),
            'per_page' => self::PER_PAGE,
        ]);

        $this->guard($response, '/admin');

        View::render('admin/users', [
            'pageTitle' => 'User accounts',
            'breadcrumbs' => [['Administration', '/admin'], ['Users', '']],
            'users' => $this->rows($response),
            'meta' => $response['meta'],
            'filters' => $filters,
            'roleOptions' => $this->roleOptions(),
            'grantable' => $this->grantableRoles(),
            'callerRole' => $this->callerRole(),
            'callerUserId' => (string) Session::userId(),
            'mayManageRoles' => Session::can(Permissions::USER_MANAGE_ROLES),
        ]);
    }

    /** The form that creates an account on somebody's behalf. */
    public function createUser(): void
    {
        View::render('admin/user-new', [
            'pageTitle' => 'New account',
            'breadcrumbs' => [['Administration', '/admin'], ['Users', '/admin/users'], ['New account', '']],
            'grantable' => $this->grantableRoles(),
            'callerRole' => $this->callerRole(),
        ]);
    }

    /**
     * Creates the account.
     *
     * Leaving the password blank is the normal path: identity-service then
     * generates one, returns it exactly once, and forces a change on first
     * sign-in. That value is flashed straight back so it can be handed over,
     * and it is never stored anywhere in this client.
     */
    public function storeUser(): void
    {
        $payload = $this->filled([
            'first_name' => $this->input('first_name'),
            'last_name' => $this->input('last_name'),
            'email' => strtolower($this->input('email')),
            'employee_code' => strtoupper($this->input('employee_code')),
            'employee_id' => $this->input('employee_id'),
            'role' => $this->input('role', Roles::EMPLOYEE),
        ]);

        $password = $this->input('password');

        if ($password !== '') {
            $payload['password'] = $password;
            $payload['must_change_password'] = $this->inputBool('must_change_password');
        }

        $response = Api::post('/users', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/admin/users/new');
        }

        $created = is_array($response['data']) ? $response['data'] : [];
        $issued = (string) ($created['generated_password'] ?? '');

        Flash::success(sprintf('The account for %s has been created.', (string) ($created['email'] ?? $payload['email'] ?? 'that person')));

        if ($issued !== '') {
            Flash::warning(sprintf(
                'Temporary password: %s — hand it over now, it is shown only once and must be changed at first sign-in.',
                $issued
            ));
        }

        $this->redirect('/admin/users');
    }

    /** Replaces the roles an account holds. */
    public function updateRoles(array $parameters = []): void
    {
        $id = (string) ($parameters['id'] ?? '');
        $roles = $this->inputArray('roles');

        $response = Api::post('/users/' . rawurlencode($id) . '/roles', ['roles' => $roles]);

        if (!$response['ok']) {
            $this->backWithErrors($response, ['roles' => $roles], '/admin/users');
        }

        // Changing a grant ends every session the account holds, so the person
        // signs in again and their next token carries the new roles.
        Flash::success('Those roles have been updated and the account has been signed out of its current sessions.');
        $this->back('/admin/users');
    }

    /** Puts a suspended or locked account back into service. */
    public function activateUser(array $parameters = []): void
    {
        $id = (string) ($parameters['id'] ?? '');
        $response = Api::post('/users/' . rawurlencode($id) . '/activate');

        if (!$response['ok']) {
            $this->backWithErrors($response, [], '/admin/users');
        }

        Flash::success('That account is active again.');
        $this->back('/admin/users');
    }

    /** Takes an account out of service. */
    public function deactivateUser(array $parameters = []): void
    {
        $id = (string) ($parameters['id'] ?? '');
        $response = Api::post('/users/' . rawurlencode($id) . '/deactivate');

        if (!$response['ok']) {
            $this->backWithErrors($response, [], '/admin/users');
        }

        Flash::success('That account has been deactivated and its sessions ended.');
        $this->back('/admin/users');
    }

    // -----------------------------------------------------------------------
    // The access model
    // -----------------------------------------------------------------------

    /**
     * The role-to-permission matrix.
     *
     * Both halves come from identity-service, which reads them out of the code
     * that enforces them. Nothing on this screen is assembled locally, so it
     * cannot drift away from what the platform actually does.
     */
    public function roles(): void
    {
        $roles = Api::get('/roles');
        $this->guard($roles, '/admin');

        $permissions = Api::get('/permissions');
        $this->guard($permissions, '/admin');

        View::render('admin/roles', [
            'pageTitle' => 'Roles and permissions',
            'breadcrumbs' => [['Administration', '/admin'], ['Roles and permissions', '']],
            'roles' => $this->rows($roles),
            'groups' => $this->rows($permissions),
            'selfService' => is_array($roles['meta']['self_service_roles'] ?? null)
                ? $roles['meta']['self_service_roles']
                : [],
        ]);
    }

    // -----------------------------------------------------------------------
    // Organisation structure
    // -----------------------------------------------------------------------

    /** Departments, designations and locations, in three tabbed sections. */
    public function organisation(): void
    {
        $departments = Api::get('/departments');
        $this->guard($departments, '/admin');

        $tab = $this->input('tab', 'departments');
        $directory = $this->directory();

        View::render('admin/organisation', [
            'pageTitle' => 'Organisation',
            'breadcrumbs' => [['Administration', '/admin'], ['Organisation', '']],
            'departments' => $this->rows($departments),
            'designations' => Api::collection('/designations'),
            'locations' => Api::collection('/locations'),
            'people' => $directory['people'],
            'peopleComplete' => $directory['complete'],
            'timezones' => \DateTimeZone::listIdentifiers(),
            'tab' => in_array($tab, ['departments', 'designations', 'locations'], true) ? $tab : 'departments',
        ]);
    }

    public function storeDepartment(): void
    {
        $payload = $this->filled([
            'name' => $this->input('name'),
            'code' => strtoupper($this->input('code')),
            'description' => $this->input('description'),
            'parent_id' => $this->input('parent_id'),
            'head_employee_id' => $this->input('head_employee_id'),
            'cost_centre' => $this->input('cost_centre'),
        ]);

        $response = Api::post('/departments', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/admin/organisation?tab=departments');
        }

        Flash::success('That department has been added.');
        $this->redirect('/admin/organisation?tab=departments');
    }

    public function storeDesignation(): void
    {
        $payload = $this->filled([
            'title' => $this->input('title'),
            'code' => strtoupper($this->input('code')),
            'department_id' => $this->input('department_id'),
            'description' => $this->input('description'),
        ]);

        if ($this->input('level') !== '') {
            $payload['level'] = $this->inputInt('level', 1);
        }

        $response = Api::post('/designations', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/admin/organisation?tab=designations');
        }

        Flash::success('That designation has been added.');
        $this->redirect('/admin/organisation?tab=designations');
    }

    public function storeLocation(): void
    {
        $payload = $this->filled([
            'name' => $this->input('name'),
            'address_line1' => $this->input('address_line1'),
            'address_line2' => $this->input('address_line2'),
            'city' => $this->input('city'),
            'state' => $this->input('state'),
            'country' => $this->input('country'),
            'postal_code' => $this->input('postal_code'),
            'timezone' => $this->input('timezone'),
        ]);

        // A location's timezone decides what "late" means for everybody based
        // there, so the flag is always sent rather than left to a default.
        $payload['is_remote'] = $this->inputBool('is_remote');

        $response = Api::post('/locations', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/admin/organisation?tab=locations');
        }

        Flash::success('That location has been added.');
        $this->redirect('/admin/organisation?tab=locations');
    }

    // -----------------------------------------------------------------------
    // Leave policy
    // -----------------------------------------------------------------------

    /** Every leave type, retired ones included, with its full policy. */
    public function leaveTypes(): void
    {
        // Retired types are shown deliberately: historical requests still
        // reference them, and an administrator needs to see why.
        $response = Api::get('/leave-types', ['include_inactive' => 'true']);
        $this->guard($response, '/admin');

        View::render('admin/leave-types', [
            'pageTitle' => 'Leave policy',
            'breadcrumbs' => [['Administration', '/admin'], ['Leave policy', '']],
            'types' => $this->rows($response),
            'categories' => ['paid', 'sick', 'unpaid', 'casual', 'maternity', 'paternity', 'comp_off', 'bereavement'],
            'frequencies' => ['none', 'monthly', 'quarterly', 'yearly'],
            'genders' => ['any', 'female', 'male'],
        ]);
    }

    public function storeLeaveType(): void
    {
        $payload = $this->filled([
            'name' => $this->input('name'),
            'code' => strtoupper($this->input('code')),
            'category' => $this->input('category'),
            'colour' => strtoupper($this->input('colour')),
            'accrual_frequency' => $this->input('accrual_frequency', 'none'),
            'applies_to_gender' => $this->input('applies_to_gender', 'any'),
        ]);

        foreach (['annual_quota_days', 'accrual_days', 'max_carry_forward_days'] as $decimal) {
            if ($this->input($decimal) !== '') {
                $payload[$decimal] = (float) $this->input($decimal);
            }
        }

        foreach (['requires_document_after_days', 'min_notice_days', 'max_consecutive_days'] as $whole) {
            if ($this->input($whole) !== '') {
                $payload[$whole] = $this->inputInt($whole);
            }
        }

        $payload['allows_half_day'] = $this->inputBool('allows_half_day');
        $payload['is_paid'] = $this->inputBool('is_paid');

        $response = Api::post('/leave-types', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/admin/leave-types');
        }

        Flash::success('That leave type has been added to the policy.');
        $this->redirect('/admin/leave-types');
    }

    // -----------------------------------------------------------------------
    // Shift patterns
    // -----------------------------------------------------------------------

    public function shifts(): void
    {
        $response = Api::get('/shifts', ['page' => $this->page(), 'per_page' => self::PER_PAGE]);
        $this->guard($response, '/admin');

        View::render('admin/shifts', [
            'pageTitle' => 'Shift patterns',
            'breadcrumbs' => [['Administration', '/admin'], ['Shifts', '']],
            'shifts' => $this->rows($response),
            'meta' => $response['meta'],
            'weekdays' => $this->weekdays(),
        ]);
    }

    public function storeShift(): void
    {
        $payload = $this->filled([
            'name' => $this->input('name'),
            'code' => strtoupper($this->input('code')),
            'starts_at' => $this->input('starts_at'),
            'ends_at' => $this->input('ends_at'),
        ]);

        foreach (['break_minutes', 'grace_minutes'] as $minutes) {
            if ($this->input($minutes) !== '') {
                $payload[$minutes] = $this->inputInt($minutes);
            }
        }

        foreach (['full_day_hours', 'half_day_hours'] as $length) {
            if ($this->input($length) !== '') {
                $payload[$length] = (float) $this->input($length);
            }
        }

        $days = $this->inputArray('working_days');

        if ($days !== []) {
            $payload['working_days'] = $days;
        }

        // Only sent when it was ticked. Left out, attendance-service works it
        // out from the two times, which is right far more often than a default.
        if ($this->inputBool('is_night_shift')) {
            $payload['is_night_shift'] = true;
        }

        $response = Api::post('/shifts', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/admin/shifts');
        }

        Flash::success('That shift pattern has been added.');
        $this->redirect('/admin/shifts');
    }

    // -----------------------------------------------------------------------
    // Holiday calendar
    // -----------------------------------------------------------------------

    /** One year of the holiday calendar, grouped by month in the template. */
    public function holidays(): void
    {
        $year = $this->inputInt('year', (int) date('Y'));
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) date('Y');

        $response = Api::get('/holidays', ['year' => $year, 'include_inactive' => 'true']);
        $this->guard($response, '/admin');

        View::render('admin/holidays', [
            'pageTitle' => 'Holiday calendar',
            'breadcrumbs' => [['Administration', '/admin'], ['Holidays', '']],
            'holidays' => $this->rows($response),
            'year' => $year,
            'years' => range((int) date('Y') - 2, (int) date('Y') + 2),
            'locations' => Api::collection('/locations'),
            'today' => date('Y-m-d'),
        ]);
    }

    public function storeHoliday(): void
    {
        $payload = $this->filled([
            'name' => $this->input('name'),
            'holiday_date' => $this->input('holiday_date'),
            'holiday_type' => $this->input('holiday_type', 'public'),
            'location_id' => $this->input('location_id'),
            'description' => $this->input('description'),
        ]);

        $response = Api::post('/holidays', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/admin/holidays');
        }

        $year = substr((string) ($payload['holiday_date'] ?? ''), 0, 4);

        Flash::success('That holiday has been added to the calendar.');
        $this->redirect('/admin/holidays' . ($year === '' ? '' : '?year=' . urlencode($year)));
    }

    // -----------------------------------------------------------------------
    // Company assets
    // -----------------------------------------------------------------------

    public function assets(): void
    {
        $filters = $this->filled([
            'category' => $this->input('category'),
            'status' => $this->input('status'),
            'search' => $this->input('search'),
        ]);

        $response = Api::get('/assets', $filters + [
            'page' => $this->page(),
            'per_page' => self::PER_PAGE,
        ]);

        $this->guard($response, '/admin');

        View::render('admin/assets', [
            'pageTitle' => 'Company assets',
            'breadcrumbs' => [['Administration', '/admin'], ['Assets', '']],
            'assets' => $this->rows($response),
            'meta' => $response['meta'],
            'filters' => $filters,
            'categories' => [
                'laptop', 'desktop', 'monitor', 'phone', 'tablet', 'peripheral',
                'furniture', 'access_card', 'software_licence', 'vehicle', 'other',
            ],
            'statuses' => ['available', 'assigned', 'in_repair', 'retired', 'lost'],
            'conditions' => ['new', 'good', 'fair', 'poor', 'damaged'],
        ]);
    }

    public function storeAsset(): void
    {
        $payload = $this->filled([
            'asset_tag' => strtoupper($this->input('asset_tag')),
            'category' => $this->input('category'),
            'name' => $this->input('name'),
            'serial_number' => $this->input('serial_number'),
            'purchased_on' => $this->input('purchased_on'),
            'condition' => $this->input('condition', 'good'),
            'status' => $this->input('status', 'available'),
            'notes' => $this->input('notes'),
            // Submitted as a decimal amount; the service turns it into minor
            // units so no rounding error can creep into an inventory total.
            'value' => $this->input('value'),
        ]);

        $response = Api::post('/assets', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/admin/assets');
        }

        Flash::success('That asset has been added to the register.');
        $this->redirect('/admin/assets');
    }

    // -----------------------------------------------------------------------
    // Audit trail
    // -----------------------------------------------------------------------

    /**
     * The platform-wide audit trail.
     *
     * This is the screen an auditor is shown, so it holds back nothing the
     * service returns: the actor and the role they held, the address they
     * called from, the request that carried it, and the before and after state
     * of whatever changed.
     */
    public function audit(): void
    {
        $filters = $this->auditFilters();

        $response = Api::get('/audit', $filters + [
            'page' => $this->page(),
            'per_page' => self::AUDIT_PER_PAGE,
        ]);

        $this->guard($response, '/admin');

        $entries = $this->rows($response);
        $actions = is_array($response['meta']['actions'] ?? null) ? $response['meta']['actions'] : [];

        View::render('admin/audit', [
            'pageTitle' => 'Audit trail',
            'breadcrumbs' => [['Administration', '/admin'], ['Audit trail', '']],
            'entries' => $entries,
            'meta' => $response['meta'],
            'filters' => $filters,
            'actions' => $actions,
            'subjects' => $this->distinct($entries, 'subject_type', (string) ($filters['subject'] ?? '')),
            'services' => $this->distinct($entries, 'service', (string) ($filters['service'] ?? '')),
            'accounts' => Session::can(Permissions::PROFILE_VIEW_ALL) ? $this->accounts() : [],
            'mayExport' => Session::can(Permissions::AUDIT_EXPORT),
        ]);
    }

    /**
     * Downloads the filtered trail as a spreadsheet.
     *
     * Identity-service records the export in the trail itself before it hands
     * the file over, because taking the security history of every person in
     * the company off the platform is an event worth keeping.
     */
    public function exportAudit(): void
    {
        if (Api::stream('/audit/export', $this->auditFilters())) {
            return;
        }

        Flash::error('That export could not be produced. Please try again in a moment.');
        $this->back('/admin/audit');
    }

    // -----------------------------------------------------------------------
    // Company settings
    // -----------------------------------------------------------------------

    /** The company defaults every other screen in the product is drawn from. */
    public function settings(): void
    {
        $response = Api::get('/settings');
        $this->guard($response, '/admin');

        $data = is_array($response['data']) ? $response['data'] : [];
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $catalogue = is_array($data['catalogue'] ?? null) ? $data['catalogue'] : [];

        $labels = [];
        $defaults = [];

        foreach ($catalogue as $entry) {
            if (!is_array($entry) || !isset($entry['key'])) {
                continue;
            }

            $labels[(string) $entry['key']] = (string) ($entry['label'] ?? $entry['key']);
            $defaults[(string) $entry['key']] = $entry['is_default'] ?? false;
        }

        View::render('admin/settings', [
            'pageTitle' => 'Company settings',
            'breadcrumbs' => [['Administration', '/admin'], ['Settings', '']],
            'settings' => $settings,
            'labels' => $labels,
            'isDefault' => $defaults,
            'weekdays' => $this->weekdays(),
        ]);
    }

    /**
     * Saves the company defaults.
     *
     * Every key is sent on each save so a value cleared in the form is
     * genuinely cleared rather than silently keeping its previous setting.
     * Identity-service refuses the whole request if any one value is malformed,
     * which is why nothing is written here field by field.
     */
    public function saveSettings(): void
    {
        $settings = [
            'company.name' => $this->input('company_name'),
            'company.working_days' => $this->inputArray('working_days'),
            'company.work_hours' => [
                'start' => $this->input('work_hours_start'),
                'end' => $this->input('work_hours_end'),
            ],
            'company.half_day_hours' => (float) $this->input('half_day_hours', '4'),
            'company.full_day_hours' => (float) $this->input('full_day_hours', '8'),
            'company.late_grace_minutes' => $this->inputInt('late_grace_minutes', 15),
            'company.currency' => [
                'code' => strtoupper($this->input('currency_code')),
                'symbol' => $this->input('currency_symbol'),
            ],
            'company.financial_year_start' => $this->input('financial_year_start'),
        ];

        $response = Api::put('/settings', ['settings' => $settings]);

        if (!$response['ok']) {
            $this->backWithErrors($response, [], '/admin/settings');
        }

        Flash::success('The company settings have been saved.');
        $this->redirect('/admin/settings');
    }

    // -----------------------------------------------------------------------
    // System health and the development inbox
    // -----------------------------------------------------------------------

    /**
     * What the gateway can currently reach.
     *
     * The gateway pings each service in turn and reports the database
     * separately, so an unreachable entry here means that one process, not the
     * platform. It is rendered even when the call itself fails, because "the
     * gateway did not answer" is the single most useful thing this page can
     * ever tell somebody.
     */
    public function health(): void
    {
        $response = Api::get('/health');
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        View::render('admin/health', [
            'pageTitle' => 'System health',
            'breadcrumbs' => [['Administration', '/admin'], ['System health', '']],
            'reachable' => $response['ok'],
            'health' => $data,
            'services' => is_array($data['services'] ?? null) ? $data['services'] : [],
            'failure' => $response['ok'] ? '' : (string) ($response['error']['message'] ?? 'The gateway did not answer.'),
        ]);
    }

    /**
     * The development mail inbox.
     *
     * Notification-service refuses this endpoint outright when the environment
     * is production, and answers 404 rather than 403 so its existence is not
     * confirmed. That answer is not an error here: it is the expected state,
     * and the page says so plainly instead of showing a failure.
     */
    public function mailbox(): void
    {
        $status = $this->input('status');

        $response = Api::get('/mailbox', $this->filled(['status' => $status]) + [
            'page' => $this->page(),
            'per_page' => self::PER_PAGE,
        ]);

        if (!$response['ok'] && $response['status'] !== 404) {
            $this->guard($response, '/admin');
        }

        View::render('admin/mailbox', [
            'pageTitle' => 'Development inbox',
            'breadcrumbs' => [['Administration', '/admin'], ['Development inbox', '']],
            'available' => $response['ok'],
            'messages' => $this->rows($response),
            'meta' => $response['meta'],
            'driver' => (string) ($response['meta']['driver'] ?? ''),
            'status' => in_array($status, ['queued', 'sent', 'failed'], true) ? $status : '',
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The administration areas this caller may open.
     *
     * @return list<array{label: string, description: string, href: string, icon: string, variant: string}>
     */
    private function areas(): array
    {
        $catalogue = [
            [
                'label' => 'Users',
                'description' => 'Accounts, verification state and who may sign in.',
                'href' => '/admin/users',
                'icon' => 'fa-users-cog',
                'variant' => '',
                'permission' => Permissions::USER_MANAGE_ALL,
            ],
            [
                'label' => 'Roles and permissions',
                'description' => 'The full access model, and what each role carries.',
                'href' => '/admin/roles',
                'icon' => 'fa-user-shield',
                'variant' => 'tile-info',
                'permission' => Permissions::USER_MANAGE_ROLES,
            ],
            [
                'label' => 'Organisation',
                'description' => 'Departments, designations and office locations.',
                'href' => '/admin/organisation',
                'icon' => 'fa-sitemap',
                'variant' => '',
                'permission' => Permissions::ORG_MANAGE,
            ],
            [
                'label' => 'Leave policy',
                'description' => 'Leave types, quotas, accrual and carry forward.',
                'href' => '/admin/leave-types',
                'icon' => 'fa-umbrella-beach',
                'variant' => 'tile-success',
                'permission' => Permissions::LEAVE_MANAGE_POLICY,
            ],
            [
                'label' => 'Shifts',
                'description' => 'Working patterns, grace periods and day lengths.',
                'href' => '/admin/shifts',
                'icon' => 'fa-business-time',
                'variant' => '',
                'permission' => Permissions::ATTENDANCE_MANAGE_SHIFTS,
            ],
            [
                'label' => 'Holidays',
                'description' => 'The company holiday calendar, year by year.',
                'href' => '/admin/holidays',
                'icon' => 'fa-calendar-day',
                'variant' => 'tile-warning',
                'permission' => Permissions::ATTENDANCE_MANAGE_HOLIDAYS,
            ],
            [
                'label' => 'Assets',
                'description' => 'Equipment issued to staff, and who holds what.',
                'href' => '/admin/assets',
                'icon' => 'fa-laptop',
                'variant' => '',
                'permission' => Permissions::ORG_MANAGE,
            ],
            [
                'label' => 'Settings',
                'description' => 'Working week, day lengths, currency and financial year.',
                'href' => '/admin/settings',
                'icon' => 'fa-sliders-h',
                'variant' => 'tile-info',
                'permission' => Permissions::SYSTEM_SETTINGS,
            ],
            [
                'label' => 'Audit trail',
                'description' => 'Every recorded action, with before and after state.',
                'href' => '/admin/audit',
                'icon' => 'fa-clipboard-list',
                'variant' => 'tile-danger',
                'permission' => Permissions::AUDIT_VIEW,
            ],
            [
                'label' => 'System health',
                'description' => 'What the gateway can reach right now.',
                'href' => '/admin/health',
                'icon' => 'fa-heartbeat',
                'variant' => 'tile-success',
                'permission' => Permissions::SYSTEM_SETTINGS,
            ],
            [
                'label' => 'Development inbox',
                'description' => 'Mail the platform queued while running locally.',
                'href' => '/mailbox',
                'icon' => 'fa-inbox',
                'variant' => 'tile-warning',
                'permission' => Permissions::SYSTEM_SETTINGS,
            ],
        ];

        $areas = [];

        foreach ($catalogue as $area) {
            if (!Session::can((string) $area['permission'])) {
                continue;
            }

            unset($area['permission']);
            $areas[] = $area;
        }

        return $areas;
    }

    /**
     * The filters the audit screen and its export share.
     *
     * @return array<string, string>
     */
    private function auditFilters(): array
    {
        return $this->filled([
            'actor' => $this->input('actor'),
            'action' => $this->input('action'),
            'subject' => $this->input('subject'),
            'service' => $this->input('service'),
            'from' => $this->input('from'),
            'to' => $this->input('to'),
        ]);
    }

    /**
     * The people directory, for the pickers on the organisation screen.
     *
     * A select cannot page, so this loads a bounded number of pages and
     * reports whether it reached the end. The form says so when it did not,
     * rather than presenting a truncated list as if it were everybody.
     *
     * @return array{people: list<array<string, mixed>>, complete: bool}
     */
    private function directory(): array
    {
        $people = [];
        $complete = false;

        for ($page = 1; $page <= self::DIRECTORY_PAGES; $page++) {
            $response = Api::get('/employees', ['page' => $page, 'per_page' => 100]);

            if (!$response['ok']) {
                break;
            }

            $batch = $this->rows($response);
            $people = array_merge($people, $batch);

            if ($batch === [] || $page >= (int) ($response['meta']['total_pages'] ?? 1)) {
                $complete = true;

                break;
            }
        }

        return ['people' => $people, 'complete' => $complete];
    }

    /**
     * Accounts, for the audit trail's actor filter.
     *
     * @return list<array<string, mixed>>
     */
    private function accounts(): array
    {
        $response = Api::get('/users', ['per_page' => 100]);

        return $response['ok'] ? $this->rows($response) : [];
    }

    /**
     * The distinct values of one column on the current page, plus whatever is
     * already filtered on, so a chosen value never disappears from its own list.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function distinct(array $rows, string $key, string $selected): array
    {
        $values = [];

        foreach ($rows as $row) {
            $value = is_array($row) ? (string) ($row[$key] ?? '') : '';

            if ($value !== '') {
                $values[$value] = true;
            }
        }

        if ($selected !== '') {
            $values[$selected] = true;
        }

        $list = array_keys($values);
        sort($list);

        return $list;
    }

    /**
     * Every role, for the filter dropdown.
     *
     * @return list<array{value: string, label: string}>
     */
    private function roleOptions(): array
    {
        return array_map(
            static fn (string $role): array => ['value' => $role, 'label' => Roles::label($role)],
            Roles::HIERARCHY
        );
    }

    /**
     * The roles this caller is allowed to hand out.
     *
     * RolePolicy in identity-service refuses anything above the caller's own
     * role and refuses a caller editing themselves at all. Both rules are
     * mirrored here so the interface never offers a checkbox that the service
     * is going to reject.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    private function grantableRoles(): array
    {
        $ceiling = $this->callerRole();

        $grantable = [];

        foreach (Roles::HIERARCHY as $role) {
            if (!Roles::outranks($ceiling, $role)) {
                continue;
            }

            $grantable[] = [
                'value' => $role,
                'label' => Roles::label($role),
                'description' => Roles::description($role),
            ];
        }

        return $grantable;
    }

    /** The caller's most senior role, which is the ceiling on what they may grant. */
    private function callerRole(): string
    {
        $roles = Session::user()['roles'] ?? [];

        return Roles::primary(is_array($roles) ? $roles : []);
    }

    /** @return list<array{value: string, label: string}> */
    private function weekdays(): array
    {
        return [
            ['value' => 'mon', 'label' => 'Monday'],
            ['value' => 'tue', 'label' => 'Tuesday'],
            ['value' => 'wed', 'label' => 'Wednesday'],
            ['value' => 'thu', 'label' => 'Thursday'],
            ['value' => 'fri', 'label' => 'Friday'],
            ['value' => 'sat', 'label' => 'Saturday'],
            ['value' => 'sun', 'label' => 'Sunday'],
        ];
    }

    /**
     * Drops the fields a form left empty.
     *
     * An untouched optional field arrives as an empty string, and an empty
     * string is not a valid date, uuid or amount. Omitting it entirely lets the
     * service apply its own default instead of refusing the whole request.
     *
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function filled(array $values): array
    {
        return array_filter($values, static fn (string $value): bool => $value !== '');
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
