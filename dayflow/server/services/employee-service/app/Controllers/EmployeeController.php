<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ChecklistTemplates;
use App\Models\CompanyAssets;
use App\Models\Departments;
use App\Models\Designations;
use App\Models\EmployeeDocuments;
use App\Models\Employees;
use App\Models\Locations;
use App\Models\OffboardingTasks;
use App\Models\OnboardingTasks;
use App\Policies\EmployeeAccessPolicy;
use App\Policies\ProfileFieldPolicy;
use App\Services\ChecklistBuilder;
use App\Services\DocumentStorage;
use App\Services\EmployeeCodeSequence;
use App\Services\RecordId;
use App\Services\ReferenceGuard;
use App\Services\RequiredColumns;
use App\Services\SearchTerm;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\ValidationException;
use Dayflow\Kernel\Validation\Validator;

/**
 * People records.
 */
final class EmployeeController
{
    /** Employment statuses a person may be moved into when they leave. */
    private const EXIT_STATUSES = ['resigned', 'terminated'];

    private const DEFAULT_EXIT_STATUS = 'resigned';

    private Employees $employees;
    private EmployeeDocuments $documents;
    private OnboardingTasks $onboarding;
    private OffboardingTasks $offboarding;
    private CompanyAssets $assets;
    private EmployeeAccessPolicy $access;
    private ProfileFieldPolicy $fields;
    private ReferenceGuard $references;
    private EmployeeCodeSequence $codes;
    private ChecklistBuilder $checklists;
    private DocumentStorage $storage;

    public function __construct()
    {
        $this->employees = new Employees();
        $this->documents = new EmployeeDocuments();
        $this->onboarding = new OnboardingTasks();
        $this->offboarding = new OffboardingTasks();
        $this->assets = new CompanyAssets();
        $this->access = new EmployeeAccessPolicy($this->employees);
        $this->fields = new ProfileFieldPolicy();
        $this->references = new ReferenceGuard(
            new Departments(),
            new Designations(),
            new Locations(),
            $this->employees
        );
        $this->codes = new EmployeeCodeSequence($this->employees);
        $this->checklists = new ChecklistBuilder(new ChecklistTemplates());
        $this->storage = new DocumentStorage();
    }

    public function index(Request $request): Response
    {
        $principal = $request->principal();

        $filters = Validator::make($request->query, [
            'search' => 'nullable|safe_text|max:120',
            'department_id' => 'nullable|uuid',
            'manager_id' => 'nullable|uuid',
            'status' => 'nullable|in:probation,confirmed,notice_period,resigned,terminated',
        ])->validated();

        $query = $this->employees->listQuery();

        // Scope first, so no filter combination can widen what this caller
        // is entitled to read.
        if ($this->access->scopeFor($principal) !== EmployeeAccessPolicy::SCOPE_ALL) {
            $query->whereIn('employees.id', $this->access->visibleEmployeeIds($principal));
        }

        if (!empty($filters['search'])) {
            $query->where('employees.search_text', 'ILIKE', SearchTerm::pattern((string) $filters['search']));
        }

        if (!empty($filters['department_id'])) {
            $query->where('employees.department_id', '=', $filters['department_id']);
        }

        if (!empty($filters['manager_id'])) {
            $query->where('employees.manager_id', '=', $filters['manager_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('employees.employment_status', '=', $filters['status']);
        }

        $query->orderBy('employees.first_name')->orderBy('employees.last_name');

        $page = $this->employees->paginate($query, $request->page(), $request->perPage());
        $page['data'] = $this->employees->attachManagerNames($page['data']);

        return Response::page($page);
    }

    public function show(Request $request): Response
    {
        $id = RecordId::orNull($request->route('id'));
        $employee = $id === null ? null : $this->employees->findDetailed($id);

        if ($employee === null) {
            throw HttpException::notFound('That person record does not exist.');
        }

        $this->access->assertCanView($request->principal(), $employee);

        return Response::ok($employee);
    }

    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'first_name' => 'required|safe_text|max:80',
            'last_name' => 'required|safe_text|max:80',
            'work_email' => 'required|email|max:150',
            'personal_email' => 'nullable|email|max:150',
            'user_id' => 'nullable|uuid',
            'phone' => 'nullable|phone|max:20',
            'alternate_phone' => 'nullable|phone|max:20',
            'date_of_birth' => 'nullable|date|before_or_equal:today',
            'gender' => 'nullable|in:male,female,other,undisclosed',
            'blood_group' => 'nullable|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'marital_status' => 'nullable|in:single,married,divorced,widowed,undisclosed',
            'address_line1' => 'nullable|safe_text|max:150',
            'address_line2' => 'nullable|safe_text|max:150',
            'city' => 'nullable|safe_text|max:80',
            'state' => 'nullable|safe_text|max:80',
            'country' => 'nullable|safe_text|max:80',
            'postal_code' => 'nullable|safe_text|max:20',
            'emergency_contact_name' => 'nullable|safe_text|max:120',
            'emergency_contact_phone' => 'nullable|phone|max:20',
            'emergency_contact_relation' => 'nullable|safe_text|max:60',
            'department_id' => 'required|uuid',
            'designation_id' => 'required|uuid',
            'location_id' => 'nullable|uuid',
            'manager_id' => 'nullable|uuid',
            'employment_type' => 'required|in:full_time,part_time,contract,intern,consultant',
            'employment_status' => 'nullable|in:probation,confirmed,notice_period,resigned,terminated',
            'joined_on' => 'required|date',
            'probation_end_on' => 'nullable|date|after_or_equal:joined_on',
            'confirmed_on' => 'nullable|date|after_or_equal:joined_on',
        ])->validated();

        if ($this->employees->findByWorkEmail((string) $data['work_email']) !== null) {
            throw HttpException::conflict('Somebody already holds that work email address.', [
                'work_email' => $data['work_email'],
            ]);
        }

        if (!empty($data['user_id']) && $this->employees->findByUserId((string) $data['user_id']) !== null) {
            throw HttpException::conflict('That login account is already linked to a person record.');
        }

        $this->references->assertOrganisationReferences($data);
        $this->references->assertManagerAssignment(
            isset($data['manager_id']) ? (string) $data['manager_id'] : null,
            null
        );

        $data = $this->applyEmploymentDefaults($data);

        $employee = Connection::transaction(function () use ($data): array {
            // The code is allocated under an advisory lock held until this
            // transaction commits, so two simultaneous creates cannot both
            // read the same highest number.
            $created = $this->employees->create($data + [
                'employee_code' => $this->codes->next(),
                'is_active' => true,
            ]);

            $this->checklists->apply(
                $this->onboarding,
                ChecklistTemplates::ONBOARDING,
                $created,
                (string) $created['joined_on']
            );

            EventPublisher::publish('employee.onboarded', [
                'employee_id' => $created['id'],
                'employee_code' => $created['employee_code'],
                'full_name' => $created['full_name'],
                'manager_id' => $created['manager_id'],
            ]);

            return $created;
        });

        AuditLog::record($request, 'employee.created', 'employee', $employee['id'], [], $employee);

        return Response::created($this->employees->findDetailed((string) $employee['id']));
    }

    public function update(Request $request): Response
    {
        $id = $request->route('id');
        $before = $this->requireEmployee($id);
        $principal = $request->principal();

        // Query string is included in the field check as well as the body, so
        // a restricted field cannot be smuggled past the policy in the URL.
        $submitted = array_keys($request->all());
        $rules = $this->fields->rulesFor($principal, (string) $before['id'], $submitted);

        $data = RequiredColumns::stripNulls(
            Validator::make($request->all(), $rules)->validated(),
            ['first_name', 'last_name', 'work_email', 'employment_type', 'employment_status', 'joined_on', 'is_active']
        );

        if ($data === []) {
            throw HttpException::badRequest('No changes were supplied.');
        }

        $this->fields->assertStatusChangePermitted($principal, $data);

        $this->references->assertOrganisationReferences($data);

        if (array_key_exists('manager_id', $data)) {
            $this->references->assertManagerAssignment(
                $data['manager_id'] === null ? null : (string) $data['manager_id'],
                (string) $before['id']
            );
        }

        if (array_key_exists('work_email', $data) && $data['work_email'] !== null) {
            $existing = $this->employees->findByWorkEmail((string) $data['work_email']);

            if ($existing !== null && (string) $existing['id'] !== (string) $before['id']) {
                throw HttpException::conflict('Somebody already holds that work email address.');
            }
        }

        // The partial unique index would otherwise report this as a database
        // failure, and moving an account to a second person record quietly
        // hands them somebody else's sign-in.
        if (array_key_exists('user_id', $data) && $data['user_id'] !== null) {
            $linked = $this->employees->findByUserId((string) $data['user_id']);

            if ($linked !== null && (string) $linked['id'] !== (string) $before['id']) {
                throw HttpException::conflict('That login account is already linked to a person record.');
            }
        }

        $this->assertDateOrdering(array_merge($before, $data));

        $after = $this->employees->update((string) $before['id'], $data);

        if ($after === null) {
            throw HttpException::notFound('That person record does not exist.');
        }

        // updated_at moves on every write, and full_name and photo_url are
        // derived; none of them is a change a subscriber wants to hear about.
        $changed = array_values(array_diff(
            array_keys(AuditLog::diff($before, $after)),
            ['updated_at', 'full_name', 'photo_url']
        ));

        if ($changed !== []) {
            EventPublisher::publish('employee.profile.updated', [
                'employee_id' => $after['id'],
                'changed_fields' => $changed,
            ]);

            AuditLog::record($request, 'employee.profile.updated', 'employee', $after['id'], $before, $after);
        }

        return Response::ok($this->employees->findDetailed((string) $after['id']));
    }

    public function photo(Request $request): Response
    {
        $employee = $this->requireEmployee($request->route('id'));
        $principal = $request->principal();

        if (!$principal->can(Permissions::PROFILE_EDIT_ALL) && !$principal->owns((string) $employee['id'])) {
            throw HttpException::forbidden('You may only change your own photograph.');
        }

        $file = $request->files['photo'] ?? $request->files['file'] ?? null;
        $stored = $this->storage->store(is_array($file) ? $file : null, DocumentStorage::IMAGE_EXTENSIONS);

        $previousId = $employee['photo_document_id'] === null ? null : (string) $employee['photo_document_id'];
        $previous = $previousId === null ? null : $this->documents->findInternal($previousId);

        $document = Connection::transaction(function () use ($stored, $employee, $principal, $previousId): array {
            $created = $this->documents->create($stored + [
                'employee_id' => $employee['id'],
                'category' => 'photo',
                'title' => 'Profile photograph',
                'status' => 'pending',
                'uploaded_by' => RecordId::orNull($principal->employeeId),
            ]);

            $this->employees->update((string) $employee['id'], ['photo_document_id' => $created['id']]);

            // Removed only after the pointer has moved, so a failure here can
            // never leave the record aimed at a row that no longer exists.
            if ($previousId !== null) {
                $this->documents->delete($previousId);
            }

            return $created;
        });

        if ($previous !== null) {
            $this->storage->remove((string) $previous['stored_filename']);
        }

        AuditLog::record($request, 'employee.photo.updated', 'employee', (string) $employee['id'], [], [
            'document_id' => $document['id'],
        ]);

        return Response::ok($this->employees->findDetailed((string) $employee['id']));
    }

    public function offboard(Request $request): Response
    {
        $before = $this->requireEmployee($request->route('id'));

        if ($before['exit_date'] !== null) {
            throw HttpException::conflict('That person has already been offboarded.', [
                'exit_date' => $before['exit_date'],
            ]);
        }

        $data = Validator::make($request->all(), [
            'exit_date' => 'required|date',
            'exit_reason' => 'required|safe_text|max:500',
            'employment_status' => 'nullable|in:' . implode(',', self::EXIT_STATUSES),
            'notice_start_on' => 'nullable|date',
        ])->validated();

        $exitDate = (string) $data['exit_date'];

        if (strtotime($exitDate) < strtotime((string) $before['joined_on'])) {
            throw new ValidationException('Some of the information supplied is not valid.', [
                'exit_date' => ['The leaving date cannot be before the joining date.'],
            ]);
        }

        $today = Clock::today();
        $stillServingNotice = strtotime($exitDate) > strtotime($today);

        $changes = [
            'exit_date' => $exitDate,
            'exit_reason' => $data['exit_reason'],
            // Somebody working out their notice is still employed: they keep
            // appearing in the directory, keep accruing leave and stay on the
            // payroll until the leaving date actually arrives.
            'employment_status' => $stillServingNotice
                ? 'notice_period'
                : (string) ($data['employment_status'] ?? self::DEFAULT_EXIT_STATUS),
            'is_active' => $stillServingNotice,
            'notice_start_on' => $data['notice_start_on'] ?? $before['notice_start_on'] ?? $today,
        ];

        $after = Connection::transaction(function () use ($before, $changes, $exitDate): array {
            $updated = $this->employees->update((string) $before['id'], $changes);

            if ($updated === null) {
                throw HttpException::notFound('That person record does not exist.');
            }

            $this->checklists->apply(
                $this->offboarding,
                ChecklistTemplates::OFFBOARDING,
                $updated,
                $exitDate
            );

            EventPublisher::publish('employee.offboarded', [
                'employee_id' => $updated['id'],
                'exit_date' => $updated['exit_date'],
            ]);

            return $updated;
        });

        // Whatever is still issued to them is returned alongside the
        // checklist: the "return company assets" step is unactionable without
        // knowing what is actually outstanding.
        $outstandingAssets = $this->assets->assignedTo((string) $after['id']);

        AuditLog::record($request, 'employee.offboarded', 'employee', $after['id'], $before, $after, [
            'assets_outstanding' => count($outstandingAssets),
        ]);

        return Response::ok([
            'employee' => $this->employees->findDetailed((string) $after['id']),
            'checklist' => $this->offboarding->forEmployee((string) $after['id']),
            'assets_outstanding' => $outstandingAssets,
        ]);
    }

    public function team(Request $request): Response
    {
        $employee = $this->requireEmployee($request->route('id'));

        $this->access->assertCanViewTeamOf($request->principal(), $employee);

        $reports = $this->employees->directReports((string) $employee['id']);

        return Response::ok($reports, [
            'manager_id' => $employee['id'],
            'manager_name' => $employee['full_name'],
            'total' => count($reports),
        ]);
    }

    /** @return array<string, mixed> */
    private function requireEmployee(string $id): array
    {
        $id = RecordId::orNull($id);
        $employee = $id === null ? null : $this->employees->find($id);

        if ($employee === null) {
            throw HttpException::notFound('That person record does not exist.');
        }

        return $employee;
    }

    /**
     * Fills in the dates that follow from the employment status.
     *
     * Payroll and leave both read probation_end_on and confirmed_on, so
     * leaving them empty on creation would leave those services guessing.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyEmploymentDefaults(array $data): array
    {
        $status = (string) ($data['employment_status'] ?? 'probation');
        $data['employment_status'] = $status;
        $joinedOn = (string) $data['joined_on'];

        if ($status === 'probation' && empty($data['probation_end_on'])) {
            $data['probation_end_on'] = Clock::parse($joinedOn)->modify('+6 months')->format('Y-m-d');
        }

        if ($status === 'confirmed' && empty($data['confirmed_on'])) {
            // Hired straight into a confirmed position: there is no probation
            // period to end, so confirmation dates from the joining day.
            $data['confirmed_on'] = $joinedOn;
        }

        return $data;
    }

    /**
     * Rejects a combination of dates that contradicts itself.
     *
     * The table enforces the two that matter for integrity; these are the
     * remaining orderings, checked here so the caller gets a field-level
     * message instead of a constraint violation.
     *
     * @param array<string, mixed> $record
     */
    private function assertDateOrdering(array $record): void
    {
        $errors = [];
        $joinedOn = isset($record['joined_on']) ? strtotime((string) $record['joined_on']) : null;

        foreach (['probation_end_on', 'confirmed_on', 'notice_start_on', 'exit_date'] as $field) {
            if ($joinedOn === null || empty($record[$field])) {
                continue;
            }

            if (strtotime((string) $record[$field]) < $joinedOn) {
                $errors[$field] = ['That date cannot fall before the joining date.'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Some of the information supplied is not valid.', $errors);
        }
    }
}
