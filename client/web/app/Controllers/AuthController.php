<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Api;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Roles;

/**
 * Sign-in, registration, verification and password recovery.
 *
 * The client performs no authentication of its own: it forwards credentials to
 * the identity service and stores the resulting tokens in the server-side
 * session. Every decision about whether a credential is valid, whether an
 * account is locked, and how many attempts remain is made by that service.
 *
 * Two rules shape everything below. First, error messages must not tell an
 * anonymous visitor whether an email address exists: a page that says "no such
 * account" turns the sign-in form into a directory of who works here. Second,
 * anything that changes who is signed in regenerates the session and rotates
 * the CSRF token.
 */
final class AuthController extends Controller
{
    /**
     * The container's own liveness probe.
     *
     * Carries no information about anybody and needs no session: it answers
     * only "is this PHP process able to render a response". The runtime image
     * probes /health on every container, and without a route here the web
     * client answered every probe with a full rendered 404 and reported itself
     * permanently unhealthy.
     */
    public function health(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        echo json_encode([
            'data' => [
                'service' => 'web-client',
                'status' => 'healthy',
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    public function showLogin(): void
    {
        View::renderAuth('auth/login', [
            'pageTitle' => 'Sign in',
        ]);
    }

    public function login(): void
    {
        $email = strtolower($this->input('email'));
        $password = $this->input('password');

        if ($email === '' || $password === '') {
            Flash::error('Please enter both your email address and your password.');
            Flash::withInput(['email' => $email]);
            $this->redirect('/login');
        }

        $result = Api::login($email, $password);

        if (!$result['ok']) {
            $code = (string) ($result['error']['code'] ?? '');
            $message = (string) ($result['error']['message'] ?? 'Those credentials do not match our records.');

            // An unverified account is the one case where being specific helps
            // the real owner and tells an attacker nothing they could not
            // already learn by registering the address themselves.
            if ($code === 'email_unverified') {
                Session::put('_pending_verification_email', $email);
                Flash::warning($message);
                $this->redirect('/resend-verification');
            }

            Flash::error($message);
            Flash::withInput(['email' => $email]);
            $this->redirect('/login');
        }

        // Return the person to whatever they were trying to reach before the
        // sign-in page interrupted them.
        $intended = Session::get('_intended_url');
        Session::forget('_intended_url');

        $destination = is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')
            ? $intended
            : '/';

        Flash::success('Welcome back, ' . Session::firstName() . '.');
        $this->redirect($destination);
    }

    public function logout(): void
    {
        Api::logout();

        Flash::success('You have been signed out.');
        $this->redirect('/login');
    }

    public function showRegister(): void
    {
        View::renderAuth('auth/register', [
            'pageTitle' => 'Create your account',
            'roles' => array_map(
                static fn (string $role): array => [
                    'value' => $role,
                    'label' => Roles::label($role),
                    'description' => Roles::description($role),
                ],
                Roles::selfServiceRoles()
            ),
        ]);
    }

    public function register(): void
    {
        $payload = [
            'first_name' => $this->input('first_name'),
            'last_name' => $this->input('last_name'),
            'email' => strtolower($this->input('email')),
            'employee_code' => $this->input('employee_code'),
            'password' => $this->input('password'),
            'password_confirmation' => $this->input('password_confirmation'),
            'role' => $this->input('role', Roles::EMPLOYEE),
        ];

        // The identity service checks this too and is the authority. Doing it
        // here as well avoids a pointless round trip and gives a better message.
        if (!in_array($payload['role'], Roles::selfServiceRoles(), true)) {
            Flash::error('Please choose one of the available account types.');
            Flash::withInput($payload);
            $this->redirect('/register');
        }

        $response = Api::postPublic('/auth/register', $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/register');
        }

        // The service says whether it wants the address confirmed first. When
        // it does not - the default - there is nothing to wait for, so the new
        // account goes straight to the sign-in page with its address filled in
        // rather than to a page telling it to check an inbox nobody sent to.
        if ((bool) (($response['data']['verification_required'] ?? false))) {
            Session::put('_pending_verification_email', $payload['email']);

            Flash::success('Your account has been created. Check your email for the link that activates it.');
            $this->redirect('/resend-verification');
        }

        Flash::success('Your account is ready. Sign in to get started.');
        Flash::withInput(['email' => $payload['email']]);
        $this->redirect('/login');
    }

    public function verifyEmail(): void
    {
        $token = $this->input('token');

        if ($token === '') {
            Flash::error('That verification link is not complete. Please use the link from your email exactly as it was sent.');
            $this->redirect('/login');
        }

        $response = Api::postPublic('/auth/verify-email', ['token' => $token]);

        if (!$response['ok']) {
            Flash::error((string) ($response['error']['message'] ?? 'That verification link is no longer valid.'));
            $this->redirect('/resend-verification');
        }

        Flash::success('Your email address is verified. You can sign in now.');
        $this->redirect('/login');
    }

    public function showResendVerification(): void
    {
        View::renderAuth('auth/resend-verification', [
            'pageTitle' => 'Verify your email',
            'email' => (string) Session::get('_pending_verification_email', ''),
        ]);
    }

    public function resendVerification(): void
    {
        $email = strtolower($this->input('email'));

        Api::postPublic('/auth/resend-verification', ['email' => $email]);

        // Always the same outcome, whether or not the address is registered.
        Flash::info('If that address has an account waiting to be verified, a new link is on its way.');
        $this->redirect('/login');
    }

    public function showForgotPassword(): void
    {
        View::renderAuth('auth/forgot-password', [
            'pageTitle' => 'Reset your password',
        ]);
    }

    public function forgotPassword(): void
    {
        $email = strtolower($this->input('email'));

        Api::postPublic('/auth/forgot-password', ['email' => $email]);

        Flash::info('If that address has an account, a password reset link is on its way.');
        $this->redirect('/login');
    }

    public function showResetPassword(): void
    {
        $token = $this->input('token');

        if ($token === '') {
            Flash::error('That reset link is not complete. Please use the link from your email exactly as it was sent.');
            $this->redirect('/forgot-password');
        }

        View::renderAuth('auth/reset-password', [
            'pageTitle' => 'Choose a new password',
            'token' => $token,
        ]);
    }

    public function resetPassword(): void
    {
        $token = $this->input('token');
        $password = $this->input('password');
        $confirmation = $this->input('password_confirmation');

        if ($token === '') {
            Flash::error('That reset link is not complete.');
            $this->redirect('/forgot-password');
        }

        if ($password !== $confirmation) {
            Flash::error('The two passwords do not match.');
            $this->redirect('/reset-password?token=' . urlencode($token));
        }

        $response = Api::postPublic('/auth/reset-password', [
            'token' => $token,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ]);

        if (!$response['ok']) {
            $details = $response['error']['details'] ?? [];

            if (is_array($details) && $details !== []) {
                Flash::withErrors($details);
            }

            Flash::error((string) ($response['error']['message'] ?? 'That password could not be set.'));
            $this->redirect('/reset-password?token=' . urlencode($token));
        }

        // The token that reached this page is spent, and every other session
        // for the account was revoked by the identity service.
        Csrf::rotate();

        Flash::success('Your password has been changed. Please sign in with it.');
        $this->redirect('/login');
    }
}
