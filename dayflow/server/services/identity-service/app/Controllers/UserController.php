<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserRoles;
use App\Models\Users;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Services\AccountService;
use App\Services\EmployeeDirectory;
use App\Services\TokenIssuer;
use App\Services\UserProfile;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Password;
use Dayflow\Kernel\Security\Roles;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/**
 * Account administration.
 *
 * Every route here carries a permission, but a permission only ever answers
 * "may this role administer accounts". It cannot answer "may this particular
 * person be granted this particular role by this particular caller", which is
 * where privilege escalation actually happens, so that question is asked
 * separately by RolePolicy on every path that changes a grant.
 */
final class UserController
{
    private Users $users;
    private UserRoles $userRoles;
    private AccountService $accounts;
    private UserProfile $profiles;
    private TokenIssuer $tokens;

    public function __construct()
    {
        $this->users = new Users();
        $this->userRoles = new UserRoles();
        $this->accounts = new AccountService();
        $this->profiles = new UserProfile();
        $this->tokens = new TokenIssuer();
    }

    /** A filtered page of accounts. */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'search' => 'nullable|safe_text|max:120',
            'role' => 'nullable|role',
            'status' => 'nullable|in:active,inactive,locked,unverified,verified',
        ])->validated();

        $page = $this->users->search($filters, $request->page(), $request->perPage());
        $page['data'] = $this->profiles->collection($page['data']);

        return Response::page($page);
    }

    /**
     * Creates an account on somebody's behalf.
     *
     * An administrator has already established who this person is, so the
     * address starts out confirmed rather than sending them through a
     * verification link they never asked for. What they do have to do is
     * choose their own password: the one issued here is a handover credential,
     * known to whoever created the account, and must not survive first use.
     */
    public function store(Request $request): Response
    {
        $principal = $request->principal();

        $data = Validator::make($request->all(), [
            'email' => 'required|email|max:190',
            'first_name' => 'required|safe_text|max:80',
            'last_name' => 'required|safe_text|max:80',
            'employee_code' => 'nullable|employee_code',
            'employee_id' => 'nullable|uuid',
            'password' => 'nullable|password',
            'role' => 'nullable|role',
            'roles' => 'nullable|array',
            'must_change_password' => 'nullable|boolean',
        ])->validated();

        $roles = $this->requestedRoles($data['roles'] ?? null, $data['role'] ?? null);

        // Creating an account is a role grant like any other, so it faces the
        // same ceiling. Without this, the guard on /users/{id}/roles could be
        // stepped around by simply making a new account instead.
        $refusal = RolePolicy::refusalReason($principal, '', [], $roles);

        if ($refusal !== null) {
            AuditLog::record($request, 'identity.user.creation.denied', 'user', null, [], [], [
                'requested_roles' => $roles,
                'reason' => $refusal,
            ]);

            throw HttpException::forbidden($refusal);
        }

        $email = strtolower((string) $data['email']);

        if ($this->users->emailTaken($email)) {
            throw HttpException::conflict('An account already exists for that email address.');
        }

        $employeeId = isset($data['employee_id']) ? (string) $data['employee_id'] : null;
        $employeeCode = isset($data['employee_code']) ? (string) $data['employee_code'] : null;

        if ($employeeId !== null && $this->users->employeeLinkTaken($employeeId)) {
            throw HttpException::conflict('Another account is already linked to that employee record.');
        }

        // Decoration only: if employee-service cannot be reached the account is
        // still created, just without the code copied across.
        if ($employeeId !== null && $employeeCode === null) {
            $record = EmployeeDirectory::fetch($employeeId, $request->bearerToken());

            if (is_array($record) && isset($record['employee_code']) && is_string($record['employee_code'])) {
                $employeeCode = strtoupper($record['employee_code']);
            }
        }

        if ($employeeCode !== null && $this->users->employeeCodeTaken($employeeCode)) {
            throw HttpException::conflict('Another account already uses that employee code.');
        }

        $supplied = isset($data['password']) ? (string) $data['password'] : null;
        $issuedPassword = $supplied ?? Password::generate();

        $user = $this->accounts->create([
            'email' => $email,
            'password_hash' => Password::hash($issuedPassword),
            'first_name' => (string) $data['first_name'],
            'last_name' => (string) $data['last_name'],
            'employee_code' => $employeeCode,
            'employee_id' => $employeeId,
            'is_active' => true,
            'email_verified_at' => Clock::iso(),
            'must_change_password' => $supplied === null
                ? true
                : (bool) ($data['must_change_password'] ?? true),
            'failed_login_count' => 0,
        ], $roles, $principal->userId);

        AuditLog::record($request, 'identity.user.created', 'user', (string) $user['id'], [], $user, [
            'roles' => $roles,
            'password_generated' => $supplied === null,
        ]);

        $profile = $this->profiles->withRoles($user, $roles);

        // Returned exactly once, so the administrator can hand it over. It is
        // never stored in the clear and never appears in the audit trail.
        if ($supplied === null) {
            $profile['generated_password'] = $issuedPassword;
        }

        return Response::created($profile);
    }

    /** One account, for an administrator or for its owner. */
    public function show(Request $request): Response
    {
        $principal = $request->principal();
        $id = $this->routeId($request);

        if (!UserPolicy::canView($principal, $id)) {
            throw HttpException::forbidden();
        }

        $user = $this->users->find($id);

        if ($user === null) {
            throw HttpException::notFound('That account does not exist.');
        }

        return Response::ok($this->profiles->withRoles($user));
    }

    /**
     * Edits the descriptive fields of an account.
     *
     * Credentials are deliberately out of reach here. An address change would
     * hand the account to whoever made it, and a password change belongs to
     * the person who owns the account or to the reset flow, both of which
     * require proof this endpoint does not ask for.
     */
    public function update(Request $request): Response
    {
        $id = $this->routeId($request);
        $before = $this->users->find($id);

        if ($before === null) {
            throw HttpException::notFound('That account does not exist.');
        }

        $data = Validator::make($request->all(), [
            'first_name' => 'nullable|safe_text|max:80',
            'last_name' => 'nullable|safe_text|max:80',
            'employee_code' => 'nullable|employee_code',
            'employee_id' => 'nullable|uuid',
            'must_change_password' => 'nullable|boolean',
        ])->validated();

        if ($data === []) {
            throw HttpException::unprocessable('There is nothing to change in this request.');
        }

        // A blank first or last name would leave an account nobody can identify
        // in a list, so clearing them is refused rather than silently accepted.
        foreach (['first_name', 'last_name'] as $required) {
            if (array_key_exists($required, $data) && $data[$required] === null) {
                throw HttpException::unprocessable('The information supplied is not valid.', [
                    $required => ['This field cannot be emptied.'],
                ]);
            }
        }

        if (($data['employee_code'] ?? null) !== null
            && $this->users->employeeCodeTaken((string) $data['employee_code'], $id)
        ) {
            throw HttpException::conflict('Another account already uses that employee code.');
        }

        if (($data['employee_id'] ?? null) !== null
            && $this->users->employeeLinkTaken((string) $data['employee_id'], $id)
        ) {
            throw HttpException::conflict('Another account is already linked to that employee record.');
        }

        $after = $this->users->update($id, $data);

        if ($after === null) {
            throw HttpException::notFound('That account does not exist.');
        }

        AuditLog::record($request, 'identity.user.updated', 'user', $id, $before, $after, [
            'changed' => AuditLog::diff($before, $after),
        ]);

        return Response::ok($this->profiles->withRoles($after));
    }

    /** Replaces the set of roles an account holds. */
    public function assignRoles(Request $request): Response
    {
        $principal = $request->principal();
        $id = $this->routeId($request);

        $target = $this->users->find($id);

        if ($target === null) {
            throw HttpException::notFound('That account does not exist.');
        }

        $data = Validator::make($request->all(), [
            'roles' => 'required|array',
        ])->validated();

        $desired = $this->requestedRoles($data['roles'], null);
        $current = $this->userRoles->rolesFor($id);

        $refusal = RolePolicy::refusalReason($principal, $id, $current, $desired);

        if ($refusal !== null) {
            // The attempt matters more than the refusal. Somebody reaching for
            // a role above their own is exactly what an audit trail is for.
            AuditLog::record($request, 'identity.user.roles.denied', 'user', $id, ['roles' => $current], ['roles' => $desired], [
                'reason' => $refusal,
                'actor_role' => $principal->primaryRole(),
            ]);

            throw HttpException::forbidden($refusal);
        }

        Connection::transaction(function () use ($id, $current, $desired, $principal): void {
            foreach (array_diff($desired, $current) as $role) {
                $this->userRoles->grant($id, $role, $principal->userId);
            }

            foreach (array_diff($current, $desired) as $role) {
                $this->userRoles->revoke($id, $role);
            }
        });

        // Roles are stamped into an access token when it is issued, so the
        // person keeps their old ones until their current token expires.
        // Ending their sessions bounds that to a single token lifetime instead
        // of a full refresh period.
        $endedSessions = $this->tokens->revokeAllSessions($id, 'roles_changed');

        AuditLog::record($request, 'identity.user.roles.changed', 'user', $id, ['roles' => $current], ['roles' => $desired], [
            'sessions_ended' => $endedSessions,
        ]);

        return Response::ok($this->profiles->withRoles($target, $desired));
    }

    /** Puts a suspended or locked account back into service. */
    public function activate(Request $request): Response
    {
        $id = $this->routeId($request);
        $before = $this->users->find($id);

        if ($before === null) {
            throw HttpException::notFound('That account does not exist.');
        }

        $after = $this->users->update($id, [
            'is_active' => true,
            'locked_until' => null,
            'failed_login_count' => 0,
        ]);

        AuditLog::record($request, 'identity.user.activated', 'user', $id, $before, $after ?? []);

        return Response::ok($this->profiles->withRoles($after ?? $before));
    }

    /** Takes an account out of service and ends every session it holds. */
    public function deactivate(Request $request): Response
    {
        $principal = $request->principal();
        $id = $this->routeId($request);

        if (!UserPolicy::canDeactivate($principal, $id)) {
            AuditLog::record($request, 'identity.user.deactivation.denied', 'user', $id, [], [], [
                'reason' => 'self_deactivation',
            ]);

            throw HttpException::forbidden('You cannot deactivate your own account.');
        }

        $before = $this->users->find($id);

        if ($before === null) {
            throw HttpException::notFound('That account does not exist.');
        }

        $after = $this->users->update($id, ['is_active' => false]);

        // Their access token stays valid until it expires, because it is
        // stateless and its id is not known here. Ending every session stops it
        // being renewed, so the longest they can remain signed in is one
        // access-token lifetime.
        $endedSessions = $this->tokens->revokeAllSessions($id, 'account_deactivated');

        AuditLog::record($request, 'identity.user.deactivated', 'user', $id, $before, $after ?? [], [
            'sessions_ended' => $endedSessions,
        ]);

        return Response::ok($this->profiles->withRoles($after ?? $before));
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The roles a request is asking for, from either shape of the field.
     *
     * @return list<string>
     */
    private function requestedRoles(mixed $roles, mixed $single): array
    {
        $candidates = is_array($roles) ? array_values($roles) : [];

        if (is_string($single) && $single !== '') {
            $candidates[] = $single;
        }

        if ($candidates === []) {
            return [Roles::EMPLOYEE];
        }

        $clean = [];

        foreach ($candidates as $role) {
            if (!is_string($role) || !Roles::isValid($role)) {
                throw HttpException::unprocessable('The information supplied is not valid.', [
                    'roles' => ['Every role must be one of: ' . implode(', ', Roles::HIERARCHY) . '.'],
                ]);
            }

            $clean[] = $role;
        }

        return array_values(array_unique($clean));
    }

    /** Route parameters are caller-supplied, so they go through the validator too. */
    private function routeId(Request $request): string
    {
        $data = Validator::make($request->routeParameters(), [
            'id' => 'required|uuid',
        ])->validated();

        return (string) $data['id'];
    }
}
