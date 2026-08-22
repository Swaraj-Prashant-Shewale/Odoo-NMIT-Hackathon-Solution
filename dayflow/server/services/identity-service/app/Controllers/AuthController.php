<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserRoles;
use App\Models\Users;
use App\Services\AccountService;
use App\Services\ClientAddress;
use App\Services\EmployeeDirectory;
use App\Services\LoginThrottle;
use App\Services\PasswordPolicy;
use App\Services\TokenIssuer;
use App\Services\UserProfile;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Jwt;
use Dayflow\Kernel\Security\Password;
use Dayflow\Kernel\Security\Roles;
use Dayflow\Kernel\Security\TokenException;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;
use Dayflow\Kernel\Validation\Validator;

/**
 * Sign-up, sign-in and everything that changes a credential.
 *
 * Two ideas run through the whole class. The first is that an error message
 * must never answer a question the caller was not entitled to ask, so a wrong
 * password and an address that was never registered produce the same words,
 * the same status and the same amount of work. The second is that every
 * attempt, successful or not, leaves a trace in both the sign-in log and the
 * audit trail, because the attempts that fail are the interesting ones.
 */
final class AuthController
{
    /** Deliberately says nothing about which half of the pair was wrong. */
    private const GENERIC_CREDENTIALS = 'Those credentials do not match our records.';

    /**
     * A hash of a value that exists nowhere and was never a password.
     *
     * When an address is unknown there is nothing to verify against, and
     * skipping the verification would make "no such account" return in a
     * fraction of the time "wrong password" takes. Anyone can then enumerate
     * the staff list with a stopwatch. Verifying against this instead keeps
     * both answers the same shape and the same cost. Both algorithms are
     * present so the cost still matches whichever one the runtime provides.
     */
    private const ABSENT_ACCOUNT_ARGON2ID =
        '$argon2id$v=19$m=65536,t=4,p=2$QW9EWFZ2Y2UyU0VCQmhyOA$bSAdQfCWjn3LxTNWzSKuG2yBxcuqez2SFF4anp0ajZE';

    private const ABSENT_ACCOUNT_BCRYPT = '$2y$12$rOurJHRhpoL5SYIWnrDH1OKIdRyhL1AIZfoPVhC1FM38oWaOYfT8G';

    private Users $users;
    private UserRoles $userRoles;
    private AccountService $accounts;
    private TokenIssuer $tokens;
    private LoginThrottle $throttle;
    private UserProfile $profiles;

    public function __construct()
    {
        $this->users = new Users();
        $this->userRoles = new UserRoles();
        $this->accounts = new AccountService();
        $this->tokens = new TokenIssuer();
        $this->throttle = new LoginThrottle();
        $this->profiles = new UserProfile();
    }

    // -----------------------------------------------------------------------
    // Public endpoints
    // -----------------------------------------------------------------------

    /**
     * Creates an unverified account.
     *
     * The response is identical whether or not the address was already taken.
     * A sign-up form that says "this email is already registered" is a
     * membership oracle: point it at a list of addresses and it tells you which
     * of them work here. The person who really owns the address learns what
     * happened from the message that arrives, or does not arrive, in the inbox.
     */
    public function register(Request $request): Response
    {
        $selfService = Roles::selfServiceRoles();

        $data = Validator::make($request->all(), [
            'email' => 'required|email|max:190',
            'password' => 'required|password',
            'password_confirmation' => 'required|same:password',
            'first_name' => 'required|safe_text|max:80',
            'last_name' => 'required|safe_text|max:80',
            'employee_code' => 'nullable|employee_code',
            'role' => 'nullable|in:' . implode(',', $selfService),
        ])->validated();

        $role = (string) ($data['role'] ?? Roles::EMPLOYEE);

        // The validation rule above already narrows the choice, but the role
        // catalogue is the authority on what a stranger may ask for, so it is
        // asked directly as well rather than trusting a rule string to stay in
        // step with it.
        if (!in_array($role, $selfService, true)) {
            throw HttpException::forbidden('That role cannot be chosen when signing up.');
        }

        // Hashing before the existence check, rather than after, keeps both
        // outcomes the same length of work.
        $passwordHash = Password::hash((string) $data['password']);
        $email = strtolower((string) $data['email']);

        if ($this->users->emailTaken($email)) {
            AuditLog::recordAuthEvent($request, 'identity.account.registration.rejected', null, $email, false, [
                'reason' => 'address_already_registered',
                'client_ip' => ClientAddress::of($request),
            ]);

            return $this->registrationReceipt();
        }

        $employeeCode = isset($data['employee_code']) ? (string) $data['employee_code'] : null;

        // A code typed in by a stranger is unverified data that HR confirms
        // during onboarding. Dropping a clash keeps this response identical to
        // every other one instead of revealing which codes are already issued.
        if ($employeeCode !== null && $this->users->employeeCodeTaken($employeeCode)) {
            $employeeCode = null;
        }

        $user = Connection::transaction(function () use ($data, $email, $passwordHash, $employeeCode, $role): array {
            $created = $this->accounts->create([
                'email' => $email,
                'password_hash' => $passwordHash,
                'first_name' => (string) $data['first_name'],
                'last_name' => (string) $data['last_name'],
                'employee_code' => $employeeCode,
                'is_active' => true,
                'must_change_password' => false,
                'failed_login_count' => 0,
            ], [$role], null);

            $token = $this->accounts->issueVerificationToken((string) $created['id']);

            // Queued inside the transaction so the invitation can never be
            // announced for an account whose creation was rolled back.
            EventPublisher::publish('identity.account.registered', [
                'user_id' => (string) $created['id'],
                'email' => (string) $created['email'],
                'first_name' => (string) $created['first_name'],
                'verification_token' => $token,
            ]);

            return $created;
        });

        AuditLog::recordAuthEvent($request, 'identity.account.registered', (string) $user['id'], $email, true, [
            'role' => $role,
            'client_ip' => ClientAddress::of($request),
        ]);

        return $this->registrationReceipt();
    }

    /** Exchanges an address and a password for a session. */
    public function login(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'email' => 'required|email|max:190',
            'password' => 'required|string|max:200',
        ])->validated();

        $email = strtolower((string) $data['email']);
        $clientIp = ClientAddress::of($request);

        $this->throttle->guard($email, $clientIp);

        $user = $this->users->findForAuthentication($email);
        $exists = $user !== null && $user['deleted_at'] === null;

        // Runs on every path, against a hash belonging to nobody when the
        // address is unknown. See ABSENT_ACCOUNT_ARGON2ID.
        $passwordMatches = Password::verify(
            (string) $data['password'],
            $exists ? (string) $user['password_hash'] : self::absentAccountHash()
        );

        if (!$exists) {
            $this->recordFailedSignIn($request, $email, $clientIp, null, 'unknown_account');

            throw new HttpException(401, self::GENERIC_CREDENTIALS, 'invalid_credentials');
        }

        $userId = (string) $user['id'];

        if (self::isLocked($user)) {
            $this->recordFailedSignIn($request, $email, $clientIp, $userId, 'account_locked');

            throw new HttpException(
                423,
                'This account is temporarily locked after too many failed attempts.',
                'account_locked',
                ['locked_until' => (string) $user['locked_until']]
            );
        }

        if (!$passwordMatches) {
            $this->penaliseFailedPassword($request, $user, $email, $clientIp);

            throw new HttpException(401, self::GENERIC_CREDENTIALS, 'invalid_credentials');
        }

        if (!Users::isTrue($user['is_active'])) {
            $this->recordFailedSignIn($request, $email, $clientIp, $userId, 'account_inactive');

            throw new HttpException(
                403,
                'This account has been deactivated. Please contact your HR team.',
                'account_inactive'
            );
        }

        if ($user['email_verified_at'] === null) {
            $this->recordFailedSignIn($request, $email, $clientIp, $userId, 'email_unverified');

            throw new HttpException(
                403,
                'Confirm your email address before signing in. We can send the link again.',
                'email_unverified'
            );
        }

        // Cost settings are raised over time; rehashing on the one occasion the
        // plaintext is available moves old accounts forward without asking
        // anyone to change their password.
        if (Password::needsRehash((string) $user['password_hash'])) {
            $this->users->replacePassword(
                $userId,
                Password::hash((string) $data['password']),
                Users::isTrue($user['must_change_password'])
            );
        }

        $this->users->registerSuccessfulLogin($userId, $clientIp);
        $this->throttle->clear($email, $clientIp);
        $this->throttle->record($email, $clientIp, true, null);

        AuditLog::recordAuthEvent($request, 'identity.auth.signed_in', $userId, $email, true, [
            'client_ip' => $clientIp,
        ]);

        return Response::ok($this->openSession($request, $userId, $clientIp));
    }

    /**
     * Trades a refresh token for a new pair.
     *
     * Every refresh retires the token presented and issues a successor in the
     * same family, so a refresh token is only ever valid once. That turns theft
     * into something detectable: if a token is presented twice, either the
     * thief used it before the owner or the owner used it before the thief, and
     * there is no way to tell which. So the entire family is revoked and both
     * of them have to sign in again, which the real owner can do and the thief
     * cannot.
     */
    public function refresh(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'refresh_token' => 'required|string|max:255',
        ])->validated();

        $clientIp = ClientAddress::of($request);

        // A backstop against a client stuck in a retry loop rather than a
        // security control: a refresh token is 32 random bytes, so guessing one
        // is not a threat worth throttling for. The allowance is deliberately
        // generous because a whole office can share one address, and locking
        // all of them out to slow down an attack that cannot work would be a
        // poor trade.
        $this->throttle->guardRecovery('refresh', $clientIp, Env::int('REFRESH_MAX_ATTEMPTS', 600));

        $record = $this->tokens->findSession((string) $data['refresh_token']);

        if ($record === null) {
            AuditLog::recordAuthEvent($request, 'identity.auth.refresh.rejected', null, null, false, [
                'reason' => 'unknown_token',
                'client_ip' => $clientIp,
            ]);

            throw HttpException::unauthorized('This session is no longer valid. Please sign in again.');
        }

        $userId = (string) $record['user_id'];
        $familyId = (string) $record['family_id'];

        if ($record['used_at'] !== null) {
            $this->tokens->revokeSession($familyId, 'reuse_detected');

            Logger::warning('Refresh token reuse detected', [
                'user_id' => $userId,
                'family_id' => $familyId,
                'client_ip' => $clientIp,
            ]);

            AuditLog::recordAuthEvent($request, 'identity.auth.refresh.reuse_detected', $userId, null, false, [
                'family_id' => $familyId,
                'client_ip' => $clientIp,
            ]);

            throw HttpException::unauthorized('This session was ended for your security. Please sign in again.');
        }

        if (!$this->tokens->isUsable($record)) {
            AuditLog::recordAuthEvent($request, 'identity.auth.refresh.rejected', $userId, null, false, [
                'reason' => $record['revoked_at'] !== null ? 'revoked' : 'expired',
                'client_ip' => $clientIp,
            ]);

            throw HttpException::unauthorized('This session has ended. Please sign in again.');
        }

        $user = $this->users->find($userId);

        if ($user === null || !Users::isTrue($user['is_active'])) {
            $this->tokens->revokeSession($familyId, 'account_unavailable');

            throw HttpException::unauthorized('This account can no longer be used to sign in.');
        }

        $rotated = $this->tokens->rotate($record, $request->userAgent(), $clientIp);
        $roles = $this->userRoles->rolesFor($userId);

        AuditLog::recordAuthEvent($request, 'identity.auth.refreshed', $userId, (string) $user['email'], true, [
            'family_id' => $familyId,
            'client_ip' => $clientIp,
        ]);

        return Response::ok($this->sessionPayload($user, $roles, $rotated['token']));
    }

    /** Consumes a verification link. */
    public function verifyEmail(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'token' => 'required|string|max:255',
        ])->validated();

        $user = $this->accounts->consumeVerification((string) $data['token']);

        if ($user === null) {
            AuditLog::recordAuthEvent($request, 'identity.email.verification.rejected', null, null, false, [
                'client_ip' => ClientAddress::of($request),
            ]);

            throw new HttpException(
                400,
                'This confirmation link is no longer valid. Ask for a new one.',
                'invalid_token'
            );
        }

        $userId = (string) $user['id'];
        $alreadyConfirmed = $user['email_verified_at'] !== null;

        $this->users->markEmailVerified($userId);

        // Announced once only, so a link opened twice does not send a second
        // welcome message.
        if (!$alreadyConfirmed) {
            EventPublisher::publish('identity.email.verified', [
                'user_id' => $userId,
                'email' => (string) $user['email'],
            ]);
        }

        AuditLog::recordAuthEvent($request, 'identity.email.verified', $userId, (string) $user['email'], true, []);

        return Response::ok([
            'message' => 'Your email address is confirmed. You can sign in now.',
            'verified' => true,
        ]);
    }

    /** Sends the confirmation link again, saying nothing about whether it applied. */
    public function resendVerification(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'email' => 'required|email|max:190',
        ])->validated();

        $email = strtolower((string) $data['email']);
        $this->throttle->guardRecovery('verification', $email, Env::int('VERIFICATION_RESEND_MAX', 5));

        $user = $this->users->findForAuthentication($email);
        $eligible = $user !== null
            && $user['deleted_at'] === null
            && Users::isTrue($user['is_active'])
            && $user['email_verified_at'] === null;

        if ($eligible) {
            Connection::transaction(function () use ($user): void {
                $token = $this->accounts->issueVerificationToken((string) $user['id']);

                EventPublisher::publish('identity.account.registered', [
                    'user_id' => (string) $user['id'],
                    'email' => (string) $user['email'],
                    'first_name' => (string) $user['first_name'],
                    'verification_token' => $token,
                ]);
            });
        }

        AuditLog::recordAuthEvent(
            $request,
            'identity.email.verification.requested',
            $eligible ? (string) $user['id'] : null,
            $email,
            $eligible,
            ['client_ip' => ClientAddress::of($request)]
        );

        return Response::ok([
            'message' => 'If that address still needs confirming, a new link is on its way to it.',
        ]);
    }

    /** Starts a password reset, saying nothing about whether the account exists. */
    public function forgotPassword(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'email' => 'required|email|max:190',
        ])->validated();

        $email = strtolower((string) $data['email']);
        $clientIp = ClientAddress::of($request);

        $this->throttle->guardRecovery('reset', $email, Env::int('PASSWORD_RESET_MAX', 5));

        $user = $this->users->findForAuthentication($email);
        $eligible = $user !== null && $user['deleted_at'] === null && Users::isTrue($user['is_active']);

        if ($eligible) {
            Connection::transaction(function () use ($user, $clientIp): void {
                $token = $this->accounts->issueResetToken((string) $user['id'], $clientIp);

                EventPublisher::publish('identity.password.reset_requested', [
                    'user_id' => (string) $user['id'],
                    'email' => (string) $user['email'],
                    'first_name' => (string) $user['first_name'],
                    'reset_token' => $token,
                ]);
            });
        }

        AuditLog::recordAuthEvent(
            $request,
            'identity.password.reset_requested',
            $eligible ? (string) $user['id'] : null,
            $email,
            $eligible,
            ['client_ip' => $clientIp]
        );

        return Response::ok([
            'message' => 'If that address belongs to an active account, a reset link is on its way to it.',
        ]);
    }

    /** Completes a password reset and signs every device out. */
    public function resetPassword(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'token' => 'required|string|max:255',
            'password' => 'required|password',
            'password_confirmation' => 'required|same:password',
        ])->validated();

        $open = $this->accounts->findOpenReset((string) $data['token']);

        if ($open === null) {
            AuditLog::recordAuthEvent($request, 'identity.password.reset.rejected', null, null, false, [
                'reason' => 'invalid_or_expired_token',
                'client_ip' => ClientAddress::of($request),
            ]);

            throw new HttpException(
                400,
                'This reset link is no longer valid. Ask for a new one.',
                'invalid_token'
            );
        }

        $user = $open['user'];
        $userId = (string) $user['id'];

        // The policy runs a second time now that the account is known, with the
        // personal details it could not see while the caller was anonymous. The
        // link is still unspent at this point, so a rejected password costs the
        // person nothing more than another try.
        $this->assertPasswordSuitable((string) $data['password'], $user);

        if (!$this->accounts->consumeReset((string) $open['reset']['id'])) {
            throw new HttpException(400, 'This reset link has already been used.', 'invalid_token');
        }

        $this->users->replacePassword($userId, Password::hash((string) $data['password']), false);

        // A reset is how somebody recovers an account they may have lost
        // control of, so every existing session has to go with it.
        $endedSessions = $this->tokens->revokeAllSessions($userId, 'password_reset');

        EventPublisher::publish('identity.password.changed', [
            'user_id' => $userId,
            'email' => (string) $user['email'],
        ]);

        AuditLog::recordAuthEvent($request, 'identity.password.changed', $userId, (string) $user['email'], true, [
            'method' => 'reset_link',
            'sessions_ended' => $endedSessions,
            'client_ip' => ClientAddress::of($request),
        ]);

        return Response::ok([
            'message' => 'Your password has been changed. Sign in with it now.',
            'sessions_ended' => $endedSessions,
        ]);
    }

    /** The rules a strength meter needs, read back out of the kernel itself. */
    public function passwordPolicy(Request $request): Response
    {
        return Response::ok(PasswordPolicy::describe());
    }

    // -----------------------------------------------------------------------
    // Authenticated endpoints
    // -----------------------------------------------------------------------

    /** Who the caller is, and what they are currently allowed to do. */
    public function me(Request $request): Response
    {
        $principal = $request->principal();
        $user = $this->users->find($principal->userId);

        if ($user === null) {
            throw HttpException::unauthorized('This account no longer exists.');
        }

        // Read from the database rather than echoed from the token, so a role
        // removed five minutes ago is gone from this answer straight away.
        $roles = $this->userRoles->rolesFor($principal->userId);

        return Response::ok($this->profiles->withRoles($user, $roles) + [
            'user_id' => (string) $user['id'],
            'department_id' => $principal->departmentId,
            'manager_id' => $principal->managerId,
        ]);
    }

    /** Ends the caller's session on this device. */
    public function logout(Request $request): Response
    {
        $principal = $request->principal();

        $data = Validator::make($request->all(), [
            'refresh_token' => 'nullable|string|max:255',
        ])->validated();

        $endedSessions = 0;
        $refreshToken = $data['refresh_token'] ?? null;

        if (is_string($refreshToken) && $refreshToken !== '') {
            $record = $this->tokens->findSession($refreshToken);

            // A token belonging to somebody else is ignored rather than acted
            // on: signing out is not a way to end another person's session.
            if ($record !== null && hash_equals((string) $record['user_id'], $principal->userId)) {
                $endedSessions = $this->tokens->revokeSession((string) $record['family_id'], 'signed_out');
            }
        }

        // The access token is stateless, so signing out means recording its id
        // until the moment it would have expired anyway.
        $this->tokens->revokeAccessToken($this->presentedClaims($request));

        AuditLog::recordAuthEvent($request, 'identity.auth.signed_out', $principal->userId, $principal->email, true, [
            'sessions_ended' => $endedSessions,
        ]);

        return Response::ok([
            'message' => 'You are signed out.',
            'sessions_ended' => $endedSessions,
        ]);
    }

    /** Replaces the caller's own password, keeping only this device signed in. */
    public function changePassword(Request $request): Response
    {
        $principal = $request->principal();

        $data = Validator::make($request->all(), [
            'current_password' => 'required|string|max:200',
            'password' => 'required|password',
            'password_confirmation' => 'required|same:password',
            'refresh_token' => 'nullable|string|max:255',
        ])->validated();

        $currentHash = $this->users->passwordHashFor($principal->userId);
        $user = $this->users->find($principal->userId);

        if ($currentHash === null || $user === null) {
            throw HttpException::unauthorized('This account no longer exists.');
        }

        if (!Password::verify((string) $data['current_password'], $currentHash)) {
            AuditLog::recordAuthEvent(
                $request,
                'identity.password.change.rejected',
                $principal->userId,
                $principal->email,
                false,
                ['reason' => 'current_password_incorrect']
            );

            throw HttpException::unprocessable('The password you supplied is not correct.', [
                'current_password' => ['That is not your current password.'],
            ]);
        }

        $this->assertPasswordSuitable((string) $data['password'], $user);

        if (Password::verify((string) $data['password'], $currentHash)) {
            throw HttpException::unprocessable('The information supplied is not valid.', [
                'password' => ['Choose a password you are not already using here.'],
            ]);
        }

        $this->users->replacePassword($principal->userId, Password::hash((string) $data['password']), false);

        $endedSessions = $this->tokens->revokeAllSessions(
            $principal->userId,
            'password_changed',
            $this->familyOwnedBy($data['refresh_token'] ?? null, $principal->userId)
        );

        EventPublisher::publish('identity.password.changed', [
            'user_id' => $principal->userId,
            'email' => (string) $user['email'],
        ]);

        AuditLog::record($request, 'identity.password.changed', 'user', $principal->userId, [], [], [
            'method' => 'self_service',
            'sessions_ended' => $endedSessions,
        ]);

        return Response::ok([
            'message' => 'Your password has been changed.',
            'sessions_ended' => $endedSessions,
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Builds the payload returned by sign-in.
     *
     * @return array<string, mixed>
     */
    private function openSession(Request $request, string $userId, string $clientIp): array
    {
        $user = $this->users->find($userId);

        if ($user === null) {
            throw HttpException::unauthorized(self::GENERIC_CREDENTIALS);
        }

        $roles = $this->userRoles->rolesFor($userId);
        $session = $this->tokens->startSession($userId, $request->userAgent(), $clientIp);

        return $this->sessionPayload($user, $roles, $session['token']);
    }

    /**
     * Assembles tokens and profile for a freshly opened or rotated session.
     *
     * @param array<string, mixed> $user
     * @param list<string>         $roles
     * @return array<string, mixed>
     */
    private function sessionPayload(array $user, array $roles, string $refreshToken): array
    {
        $baseClaims = $this->tokens->claimsFor($user, $roles, ['department_id' => null, 'manager_id' => null]);

        $context = EmployeeDirectory::teamContext(
            $user['employee_id'] === null ? null : (string) $user['employee_id'],
            $baseClaims
        );

        return [
            'access_token' => $this->tokens->issueAccessToken($this->tokens->claimsFor($user, $roles, $context)),
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->tokens->accessTokenLifetime(),
            'refresh_expires_in' => $this->tokens->refreshTokenLifetime(),
            'must_change_password' => Users::isTrue($user['must_change_password'] ?? false),
            'user' => $this->profiles->withRoles($user, $roles) + $context,
        ];
    }

    /** Registers a failed attempt and locks the account once the limit is reached. */
    private function penaliseFailedPassword(Request $request, array $user, string $email, string $clientIp): void
    {
        $userId = (string) $user['id'];
        $failures = $this->users->registerFailedAttempt($userId);

        if ($failures < $this->throttle->maxAttempts()) {
            $this->recordFailedSignIn($request, $email, $clientIp, $userId, 'invalid_password');

            return;
        }

        $lockedUntil = $this->throttle->lockedUntil();
        $this->users->lock($userId, $lockedUntil);

        EventPublisher::publish('identity.account.locked', [
            'user_id' => $userId,
            'email' => (string) $user['email'],
            'until' => $lockedUntil,
        ]);

        $this->recordFailedSignIn($request, $email, $clientIp, $userId, 'account_locked');
    }

    /** Writes one failure to both the sign-in log and the audit trail. */
    private function recordFailedSignIn(
        Request $request,
        string $email,
        string $clientIp,
        ?string $userId,
        string $reason,
    ): void {
        $this->throttle->record($email, $clientIp, false, $reason);

        AuditLog::recordAuthEvent($request, 'identity.auth.sign_in.failed', $userId, $email, false, [
            'reason' => $reason,
            'client_ip' => $clientIp,
        ]);
    }

    /**
     * Applies the password policy with the account's own details in hand.
     *
     * The Validator runs the same rules while the caller is still anonymous,
     * which catches everything except "this is just your own name". That check
     * needs the record, so it happens here.
     *
     * @param array<string, mixed> $user
     */
    private function assertPasswordSuitable(string $password, array $user): void
    {
        $problems = Password::problems($password, [
            (string) $user['first_name'],
            (string) $user['last_name'],
            explode('@', (string) $user['email'])[0],
        ]);

        if ($problems !== []) {
            throw HttpException::unprocessable('The information supplied is not valid.', ['password' => $problems]);
        }
    }

    /** The session a refresh token belongs to, but only when the caller owns it. */
    private function familyOwnedBy(mixed $refreshToken, string $userId): ?string
    {
        if (!is_string($refreshToken) || $refreshToken === '') {
            return null;
        }

        $record = $this->tokens->findSession($refreshToken);

        if ($record === null || !hash_equals((string) $record['user_id'], $userId)) {
            return null;
        }

        return (string) $record['family_id'];
    }

    /**
     * The claims of the token the caller presented.
     *
     * The kernel has already verified it to get this far; verifying again is
     * how the exp claim is read without ever treating an unverified value as
     * fact.
     *
     * @return array<string, mixed>
     */
    private function presentedClaims(Request $request): array
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return [];
        }

        try {
            return Jwt::verify($token);
        } catch (TokenException) {
            return [];
        }
    }

    /** The one response registration ever gives, whatever actually happened. */
    private function registrationReceipt(): Response
    {
        return Response::created([
            'message' => 'Thanks. If that address can be registered, a confirmation link is on its way to it.',
            'verification_required' => true,
        ]);
    }

    /** @param array<string, mixed> $user */
    private static function isLocked(array $user): bool
    {
        return $user['locked_until'] !== null
            && strtotime((string) $user['locked_until']) > Clock::timestamp();
    }

    private static function absentAccountHash(): string
    {
        return defined('PASSWORD_ARGON2ID') ? self::ABSENT_ACCOUNT_ARGON2ID : self::ABSENT_ACCOUNT_BCRYPT;
    }
}
