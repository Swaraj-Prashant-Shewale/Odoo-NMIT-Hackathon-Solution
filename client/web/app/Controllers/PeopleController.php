<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Api;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;

/**
 * People records: the register HR works in, and the organisation around it.
 *
 * Everything here is a view onto employee-service. The client decides nothing
 * about who may see what; it asks and renders the answer. The service scopes
 * `/employees` by the caller — everyone for HR, the team for a manager, the
 * person themselves for anybody else — so the same listing page is correct for
 * all three without the client knowing which case it is in.
 *
 * Permission checks in this class exist only to keep the interface honest.
 * Two of them are load bearing for a different reason: `/assets` and
 * `/documents` silently narrow to the *caller's* own rows when the caller
 * lacks the "all" permission. Requesting them for somebody else without that
 * permission would therefore render one person's belongings under another
 * person's name, so those sections are asked for only when they can be
 * answered about the person actually on screen.
 */
final class PeopleController extends Controller
{
    private const PER_PAGE = 20;

    /** Enough for any real organisation; a guard rather than a limit. */
    private const MAX_TREE_DEPTH = 32;

    /** Reference lists are pickers, not pages: they are read in one go. */
    private const REFERENCE_PER_PAGE = 100;

    /** @var list<string> */
    private const EMPLOYMENT_STATUSES = ['probation', 'confirmed', 'notice_period', 'resigned', 'terminated'];

    /** @var list<string> */
    private const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract', 'intern', 'consultant'];

    /** @var list<string> */
    private const GENDERS = ['male', 'female', 'other', 'undisclosed'];

    /** @var list<string> */
    private const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /** @var list<string> */
    private const MARITAL_STATUSES = ['single', 'married', 'divorced', 'widowed', 'undisclosed'];

    /** Statuses offered when somebody leaves; notice is applied by the service. */
    private const EXIT_STATUSES = ['resigned', 'terminated'];

    public function index(): void
    {
        $filters = [
            'search' => $this->input('search'),
            'department_id' => $this->input('department_id'),
            'status' => $this->input('status'),
            'manager_id' => $this->input('manager_id'),
        ];

        $query = array_filter($filters, static fn (string $value): bool => $value !== '');
        $query['page'] = $this->page();
        $query['per_page'] = self::PER_PAGE;

        $response = Api::get('/employees', $query);
        $this->guard($response, '/');

        // A manager filter only means something to somebody who can see past
        // their own team, so the (whole-organisation) lookup behind it is only
        // paid for when it will be used.
        $managers = Session::can(Permissions::PROFILE_VIEW_ALL)
            ? array_values(array_filter(
                $this->people(),
                static fn (array $person): bool => (int) ($person['report_count'] ?? 0) > 0
            ))
            : [];

        View::render('people/index', [
            'pageTitle' => 'People',
            'breadcrumbs' => [['People', '']],
            'employees' => is_array($response['data']) ? $response['data'] : [],
            'meta' => $response['meta'],
            'filters' => $filters,
            'departments' => Api::collection('/departments', ['per_page' => self::REFERENCE_PER_PAGE]),
            'managers' => $managers,
            'statuses' => self::EMPLOYMENT_STATUSES,
        ]);
    }

    public function create(): void
    {
        View::render('people/create', [
            'pageTitle' => 'Add an employee',
            'breadcrumbs' => [['People', '/people'], ['Add an employee', '']],
        ] + $this->referenceData());
    }

    public function store(): void
    {
        $payload = $this->collect(
            'first_name',
            'last_name',
            'work_email',
            'personal_email',
            'phone',
            'alternate_phone',
            'date_of_birth',
            'gender',
            'blood_group',
            'marital_status',
            'address_line1',
            'address_line2',
            'city',
            'state',
            'country',
            'postal_code',
            'emergency_contact_name',
            'emergency_contact_phone',
            'emergency_contact_relation',
            'department_id',
            'designation_id',
            'location_id',
            'manager_id',
            'employment_type',
            'employment_status',
            'joined_on',
            'probation_end_on'
        );

        $response = Api::post('/employees', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/people/new');
        }

        $created = is_array($response['data']) ? $response['data'] : [];
        $id = (string) ($created['id'] ?? '');

        Flash::success(sprintf(
            '%s has been added as %s. An onboarding checklist is waiting on the joiner list.',
            (string) ($created['full_name'] ?? 'The new employee'),
            (string) ($created['employee_code'] ?? 'a new employee')
        ));

        $this->redirect($id === '' ? '/people' : '/people/' . rawurlencode($id));
    }

    public function show(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');

        $response = Api::get('/employees/' . rawurlencode($id));
        $this->guard($response, '/people');

        $employee = is_array($response['data']) ? $response['data'] : [];
        $isSelf = Session::employeeId() !== null && Session::employeeId() === (string) ($employee['id'] ?? '');

        $team = Api::collection('/employees/' . rawurlencode($id) . '/team');

        // Only asked for when the answer would genuinely be about this person
        // rather than about whoever is looking at the page.
        $assets = Session::can(Permissions::ORG_MANAGE)
            ? Api::collection('/assets', ['assigned_to' => $id, 'per_page' => 100])
            : [];

        $documents = Session::canAny(Permissions::DOCUMENT_VIEW_ALL, Permissions::DOCUMENT_MANAGE)
            ? Api::collection('/documents', ['employee_id' => $id, 'per_page' => 100])
            : [];

        $checklist = Api::get('/onboarding/' . rawurlencode($id));
        $salary = Session::can(Permissions::PAYROLL_VIEW_ALL) || $isSelf
            ? Api::data('/salary-structures/' . rawurlencode($id), [], null)
            : null;

        View::render('people/show', [
            'pageTitle' => (string) ($employee['full_name'] ?? 'Employee'),
            'breadcrumbs' => [['People', '/people'], [(string) ($employee['full_name'] ?? 'Employee'), '']],
            'employee' => $employee,
            'team' => $team,
            'assets' => $assets,
            'documents' => $documents,
            'onboarding' => $checklist['ok'] && is_array($checklist['data']) ? $checklist['data'] : [],
            'onboardingProgress' => is_array($checklist['meta']['progress'] ?? null) ? $checklist['meta']['progress'] : [],
            'salary' => is_array($salary) ? $salary : null,
            'exitStatuses' => self::EXIT_STATUSES,
        ]);
    }

    public function edit(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');

        $response = Api::get('/employees/' . rawurlencode($id));
        $this->guard($response, '/people');

        $employee = is_array($response['data']) ? $response['data'] : [];

        View::render('people/edit', [
            'pageTitle' => 'Edit ' . (string) ($employee['full_name'] ?? 'employee'),
            'breadcrumbs' => [
                ['People', '/people'],
                [(string) ($employee['full_name'] ?? 'Employee'), '/people/' . rawurlencode($id)],
                ['Edit', ''],
            ],
            'employee' => $employee,
        ] + $this->referenceData($id));
    }

    public function update(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');

        $payload = $this->collect(
            'first_name',
            'last_name',
            'work_email',
            'personal_email',
            'phone',
            'alternate_phone',
            'date_of_birth',
            'gender',
            'blood_group',
            'marital_status',
            'address_line1',
            'address_line2',
            'city',
            'state',
            'country',
            'postal_code',
            'emergency_contact_name',
            'emergency_contact_phone',
            'emergency_contact_relation',
            'department_id',
            'designation_id',
            'location_id',
            'manager_id',
            'employment_type',
            'employment_status',
            'joined_on',
            'probation_end_on',
            'confirmed_on',
            'is_active'
        );

        $response = Api::patch('/employees/' . rawurlencode($id), $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/people/' . rawurlencode($id) . '/edit');
        }

        Flash::success('The record has been updated.');
        $this->redirect('/people/' . rawurlencode($id));
    }

    public function offboard(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');

        $payload = [
            'exit_date' => $this->input('exit_date'),
            'exit_reason' => $this->input('exit_reason'),
            'employment_status' => $this->input('employment_status', 'resigned'),
        ];

        $response = Api::post('/employees/' . rawurlencode($id) . '/offboard', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/people/' . rawurlencode($id));
        }

        $result = is_array($response['data']) ? $response['data'] : [];
        $outstanding = (int) ($result['assets_outstanding'] ?? 0);

        Flash::success('The leaving details have been recorded and an exit checklist has been created.');

        if ($outstanding > 0) {
            Flash::warning(sprintf(
                '%d company %s still issued to this person and %s to be collected.',
                $outstanding,
                $outstanding === 1 ? 'asset is' : 'assets are',
                $outstanding === 1 ? 'needs' : 'need'
            ));
        }

        $this->redirect('/people/' . rawurlencode($id));
    }

    public function documents(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');

        $response = Api::get('/employees/' . rawurlencode($id));
        $this->guard($response, '/people');

        $employee = is_array($response['data']) ? $response['data'] : [];

        $documents = Api::get('/documents', [
            'employee_id' => $id,
            'category' => $this->input('category'),
            'status' => $this->input('status'),
            'page' => $this->page(),
            'per_page' => self::PER_PAGE,
        ]);
        $this->guard($documents, '/people/' . rawurlencode($id));

        View::render('people/documents', [
            'pageTitle' => 'Documents for ' . (string) ($employee['full_name'] ?? 'employee'),
            'breadcrumbs' => [
                ['People', '/people'],
                [(string) ($employee['full_name'] ?? 'Employee'), '/people/' . rawurlencode($id)],
                ['Documents', ''],
            ],
            'employee' => $employee,
            'documents' => is_array($documents['data']) ? $documents['data'] : [],
            'meta' => $documents['meta'],
            'filters' => [
                'category' => $this->input('category'),
                'status' => $this->input('status'),
            ],
        ]);
    }

    public function onboarding(): void
    {
        $response = Api::get('/onboarding');
        $this->guard($response, '/people');

        $joiners = array_values(array_filter(
            is_array($response['data']) ? $response['data'] : [],
            'is_array'
        ));

        // The summary endpoint returns counts, not the tasks themselves, so the
        // outstanding items are fetched per joiner. The list only ever contains
        // people with at least one open task, which keeps it short by
        // construction.
        foreach ($joiners as $index => $joiner) {
            $employeeId = (string) ($joiner['employee_id'] ?? '');

            $joiners[$index]['tasks'] = $employeeId === ''
                ? []
                : array_values(array_filter(
                    Api::collection('/onboarding/' . rawurlencode($employeeId)),
                    static fn (mixed $task): bool => is_array($task)
                        && in_array((string) ($task['status'] ?? ''), ['pending', 'in_progress'], true)
                ));
        }

        View::render('people/onboarding', [
            'pageTitle' => 'Onboarding',
            'breadcrumbs' => [['People', '/people'], ['Onboarding', '']],
            'joiners' => $joiners,
            'summary' => $response['meta'],
        ]);
    }

    public function completeOnboardingTask(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');

        $response = Api::post('/onboarding/' . rawurlencode($id) . '/complete');

        if (!$response['ok']) {
            Flash::error((string) ($response['error']['message'] ?? 'That task could not be marked as done.'));
            $this->back('/onboarding');
        }

        Flash::success('That task is marked as done.');
        $this->back('/onboarding');
    }

    public function orgChart(): void
    {
        $response = Api::get('/org-chart');
        $this->guard($response, '/');

        View::render('people/org-chart', [
            'pageTitle' => 'Organisation chart',
            'breadcrumbs' => [['People', '/people'], ['Organisation chart', '']],
            'roots' => is_array($response['data']) ? $response['data'] : [],
            'summary' => $response['meta'],
        ]);
    }

    /**
     * The lists every create and edit form picks from.
     *
     * @param string|null $excludeId A person cannot be their own manager, so
     *                               they are dropped from the manager choices.
     * @return array<string, mixed>
     */
    private function referenceData(?string $excludeId = null): array
    {
        $candidates = $this->people();

        if ($excludeId !== null && $excludeId !== '') {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (array $person): bool => (string) ($person['id'] ?? '') !== $excludeId
            ));
        }

        // Asked for in one page each: these are pickers rather than listings,
        // and the default page size would quietly drop the tail of a long
        // department or designation catalogue from the form.
        return [
            'departments' => Api::collection('/departments', ['per_page' => self::REFERENCE_PER_PAGE]),
            'designations' => Api::collection('/designations', ['per_page' => self::REFERENCE_PER_PAGE]),
            'locations' => Api::collection('/locations', ['per_page' => self::REFERENCE_PER_PAGE]),
            'managerCandidates' => $candidates,
            'employmentTypes' => self::EMPLOYMENT_TYPES,
            'employmentStatuses' => self::EMPLOYMENT_STATUSES,
            'genders' => self::GENDERS,
            'bloodGroups' => self::BLOOD_GROUPS,
            'maritalStatuses' => self::MARITAL_STATUSES,
        ];
    }

    /**
     * Everybody in the organisation, flat and in name order.
     *
     * The reporting tree is the only endpoint that returns the whole register
     * in one response; `/employees` is paginated and capped, which would leave
     * a manager picker quietly missing anybody past the first page.
     *
     * @return list<array<string, mixed>>
     */
    private function people(): array
    {
        $flat = [];
        $this->flatten(Api::collection('/org-chart'), $flat);

        usort($flat, static fn (array $a, array $b): int => strcasecmp(
            (string) ($a['full_name'] ?? ''),
            (string) ($b['full_name'] ?? '')
        ));

        return $flat;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $collected
     */
    private function flatten(array $nodes, array &$collected, int $depth = 0): void
    {
        // Employee-service walks the tree with a cycle guard, so this bound
        // should never be reached. It is here so that a malformed response
        // could only ever truncate the picker rather than recurse until the
        // request runs out of memory.
        if ($depth >= self::MAX_TREE_DEPTH) {
            return;
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $reports = is_array($node['reports'] ?? null) ? $node['reports'] : [];
            unset($node['reports']);

            $collected[] = $node;

            $this->flatten($reports, $collected, $depth + 1);
        }
    }
}
