<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Api;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Session;
use App\Core\View;

/**
 * The signed-in person's own record.
 *
 * Three services answer these pages. Employee-service holds the record, the
 * photograph and the paperwork; identity-service holds the account, its
 * password and its live sessions; payroll-service holds the salary bank
 * details. Nothing is combined here beyond putting the answers on one screen.
 *
 * The division that runs through the whole area is which fields a person may
 * change about themselves. Employee-service refuses an HR-only field outright
 * rather than ignoring it, so a form that offered one would fail on submit
 * with a message about a field the person could not have known was locked.
 * Every self-service form below therefore offers exactly the editable set and
 * shows the rest as text.
 */
final class ProfileController extends Controller
{
    /** Mirrors ProfileFieldPolicy::SELF_EDITABLE in employee-service. */
    private const SELF_EDITABLE = [
        'phone',
        'alternate_phone',
        'personal_email',
        'marital_status',
        'blood_group',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'country',
        'postal_code',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relation',
    ];

    /** @var list<string> */
    private const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /** @var list<string> */
    private const MARITAL_STATUSES = ['single', 'married', 'divorced', 'widowed', 'undisclosed'];

    /** Categories employee-service accepts from a self-service upload. */
    private const DOCUMENT_CATEGORIES = [
        'identity',
        'address',
        'education',
        'employment',
        'contract',
        'payroll',
        'medical',
        'other',
    ];

    /** A document falling due inside this many days is flagged on the page. */
    private const EXPIRY_WARNING_DAYS = 30;

    public function show(): void
    {
        $employee = $this->requireOwnRecord();

        $salary = Api::data('/salary-structures/' . rawurlencode((string) ($employee['id'] ?? '')), [], null);
        $documents = Api::collection('/documents', ['per_page' => 100]);

        View::render('profile/show', [
            'pageTitle' => 'My profile',
            'breadcrumbs' => [['My profile', '']],
            'employee' => $employee,
            'salary' => is_array($salary) ? $salary : null,
            'documentCount' => count($documents),
            'expiringCount' => count($this->expiringSoon($documents)),
            'selfEditable' => self::SELF_EDITABLE,
        ]);
    }

    public function edit(): void
    {
        $employee = $this->requireOwnRecord();

        View::render('profile/edit', [
            'pageTitle' => 'Edit my details',
            'breadcrumbs' => [['My profile', '/profile'], ['Edit my details', '']],
            'employee' => $employee,
            'bloodGroups' => self::BLOOD_GROUPS,
            'maritalStatuses' => self::MARITAL_STATUSES,
        ]);
    }

    public function update(): void
    {
        $employeeId = $this->requireLinkedRecord();

        // Only the self-editable set is ever sent. An empty box is submitted as
        // an empty string, which the service reads as "clear this field", so a
        // person can remove a detail as well as change one.
        $payload = $this->collect(...self::SELF_EDITABLE);

        $response = Api::patch('/employees/' . rawurlencode($employeeId), $payload);

        if (!$response['ok']) {
            $this->backWithErrors($response, $payload, '/profile/edit');
        }

        Flash::success('Your details have been updated.');
        $this->redirect('/profile');
    }

    public function uploadPhoto(): void
    {
        $employeeId = $this->requireLinkedRecord();

        $file = $this->uploadedFile('photo');

        if ($file === null) {
            Flash::error('Please choose a photograph to upload.');
            $this->redirect('/profile');
        }

        $response = Api::upload('/employees/' . rawurlencode($employeeId) . '/photo', [], $file, 'photo');

        if (!$response['ok']) {
            Flash::error((string) ($response['error']['message'] ?? 'That photograph could not be saved.'));
            $this->redirect('/profile');
        }

        Flash::success('Your photograph has been updated.');
        $this->redirect('/profile');
    }

    public function documents(): void
    {
        // Without a person record there is nothing to hold paperwork against,
        // and employee-service would be asked to scope the listing to an empty
        // identifier. /profile explains the state properly.
        $this->requireLinkedRecord();

        $response = Api::get('/documents', [
            'category' => $this->input('category'),
            'status' => $this->input('status'),
            'page' => $this->page(),
            'per_page' => 20,
        ]);
        $this->guard($response, '/profile');

        $documents = is_array($response['data']) ? $response['data'] : [];

        View::render('profile/documents', [
            'pageTitle' => 'My documents',
            'breadcrumbs' => [['My profile', '/profile'], ['Documents', '']],
            'documents' => $documents,
            'meta' => $response['meta'],
            'expiringIds' => array_column($this->expiringSoon($documents), 'id'),
            'categories' => self::DOCUMENT_CATEGORIES,
            'filters' => [
                'category' => $this->input('category'),
                'status' => $this->input('status'),
            ],
            'warningDays' => self::EXPIRY_WARNING_DAYS,
        ]);
    }

    public function uploadDocument(): void
    {
        $this->requireLinkedRecord();

        $fields = [
            'category' => $this->input('category'),
            'title' => $this->input('title'),
            'issued_on' => $this->input('issued_on'),
            'expires_on' => $this->input('expires_on'),
        ];

        $file = $this->uploadedFile('file');

        if ($file === null) {
            Flash::error('Please choose a file to upload.');
            Flash::withInput($fields);
            $this->redirect('/profile/documents');
        }

        // Empty dates are dropped rather than sent as empty strings, so the
        // multipart request carries only the fields that were filled in.
        $response = Api::upload(
            '/documents',
            array_filter($fields, static fn (string $value): bool => $value !== ''),
            $file
        );

        if (!$response['ok']) {
            $this->backWithErrors($response, $fields, '/profile/documents');
        }

        Flash::success('Your document has been uploaded and is waiting to be verified.');
        $this->redirect('/profile/documents');
    }

    public function downloadDocument(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');

        // Employee-service re-checks the caller against the row that owns the
        // file on every download, so a guessed identifier gets nothing.
        if (!Api::stream('/documents/' . rawurlencode($id) . '/download')) {
            Flash::error('That document could not be downloaded.');

            // /people/{id}/documents links this same route, so redirecting to
            // the caller's own documents would strand an HR user on an empty
            // page with no way back to the record they were reading.
            $this->back('/profile/documents');
        }
    }

    public function security(): void
    {
        $account = Api::get('/auth/me');
        $this->guard($account, '/profile');

        $sessions = Api::collection('/sessions');

        View::render('profile/security', [
            'pageTitle' => 'Security',
            'breadcrumbs' => [['My profile', '/profile'], ['Security', '']],
            'account' => is_array($account['data']) ? $account['data'] : [],
            'sessions' => $sessions,
            'currentSessionId' => $this->currentSessionId($sessions),
            'policy' => Api::data('/auth/password-policy', [], []),
        ]);
    }

    public function changePassword(): void
    {
        $current = $this->input('current_password');
        $password = $this->input('password');
        $confirmation = $this->input('password_confirmation');

        if ($password !== $confirmation) {
            Flash::error('The two new passwords do not match.');
            $this->redirect('/profile/security');
        }

        $response = Api::post('/auth/change-password', [
            'current_password' => $current,
            'password' => $password,
            'password_confirmation' => $confirmation,
            // Naming this session keeps it alive while every other one is
            // ended, so changing a password does not sign the person out of
            // the very browser they are using to change it.
            'refresh_token' => Session::refreshToken() ?? '',
        ]);

        if (!$response['ok']) {
            $details = $response['error']['details'] ?? [];

            if (is_array($details) && $details !== []) {
                Flash::withErrors($details);
            }

            Flash::error((string) ($response['error']['message'] ?? 'That password could not be changed.'));
            $this->redirect('/profile/security');
        }

        $ended = (int) (is_array($response['data']) ? ($response['data']['sessions_ended'] ?? 0) : 0);

        Flash::success($ended > 0
            ? sprintf('Your password has been changed and %d other %s ended.', $ended, $ended === 1 ? 'session was' : 'sessions were')
            : 'Your password has been changed.');

        $this->redirect('/profile/security');
    }

    public function revokeSession(array $parameters): void
    {
        $id = (string) ($parameters['id'] ?? '');

        $response = Api::delete('/sessions/' . rawurlencode($id));

        if (!$response['ok']) {
            Flash::error((string) ($response['error']['message'] ?? 'That session could not be ended.'));
            $this->redirect('/profile/security');
        }

        Flash::success('That session has been ended. The device will have to sign in again.');
        $this->redirect('/profile/security');
    }

    public function bankDetails(): void
    {
        // Payroll-service refuses this outright for an account with no person
        // record, which would land on an access-denied page for what is a
        // normal state before onboarding finishes.
        $this->requireLinkedRecord();

        $response = Api::get('/payroll/bank-account');
        $this->guard($response, '/profile');

        View::render('profile/bank', [
            'pageTitle' => 'Salary bank details',
            'breadcrumbs' => [['My profile', '/profile'], ['Bank details', '']],
            'account' => is_array($response['data']) ? $response['data'] : [],
        ]);
    }

    public function updateBankDetails(): void
    {
        $this->requireLinkedRecord();

        $payload = [
            'account_number' => $this->input('account_number'),
            'bank_name' => $this->input('bank_name'),
            'ifsc_code' => strtoupper($this->input('ifsc_code')),
            'account_holder_name' => $this->input('account_holder_name'),
            'tax_identifier' => strtoupper($this->input('tax_identifier')),
        ];

        $response = Api::put('/payroll/bank-account', $payload);

        if (!$response['ok']) {
            // The account number is deliberately not remembered for the redisplay:
            // it must not end up in the session or back in the page source.
            unset($payload['account_number']);

            $this->backWithErrors($response, $payload, '/profile/bank');
        }

        Flash::success('Your bank details have been saved. Finance will confirm them before the next pay run.');
        $this->redirect('/profile/bank');
    }

    /**
     * The caller's employee identifier, or a clean stop if the account has none.
     *
     * An account can exist before onboarding has created the person record
     * behind it. Every page below this point is about that record, so the
     * person is sent to /profile, which explains the state, rather than being
     * shown an access-denied page or an error from a service asked to scope a
     * query to an identifier that is not there.
     */
    private function requireLinkedRecord(): string
    {
        $employeeId = Session::employeeId();

        if ($employeeId === null || $employeeId === '') {
            Flash::info('Your account is not linked to an employee record yet.');
            $this->redirect('/profile');
        }

        return $employeeId;
    }

    /**
     * The caller's own employee record, or a clean stop if there is not one.
     *
     * @return array<string, mixed>
     */
    private function requireOwnRecord(): array
    {
        $employeeId = Session::employeeId();

        if ($employeeId === null || $employeeId === '') {
            View::render('profile/unlinked', [
                'pageTitle' => 'My profile',
                'breadcrumbs' => [['My profile', '']],
            ]);

            exit;
        }

        $response = Api::get('/employees/' . rawurlencode($employeeId));
        $this->guard($response, '/');

        return is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * Documents due to lapse inside the warning window.
     *
     * Anything already past its date has been moved to "expired" by the
     * service, so this only ever reports the ones still worth chasing.
     *
     * @param list<array<string, mixed>> $documents
     * @return list<array<string, mixed>>
     */
    private function expiringSoon(array $documents): array
    {
        $now = time();
        $limit = $now + (self::EXPIRY_WARNING_DAYS * 86400);

        return array_values(array_filter($documents, static function (array $document) use ($now, $limit): bool {
            $expiresOn = (string) ($document['expires_on'] ?? '');

            if ($expiresOn === '') {
                return false;
            }

            $timestamp = strtotime($expiresOn);

            return $timestamp !== false && $timestamp >= $now && $timestamp <= $limit;
        }));
    }

    /**
     * Which of the live sessions is the one being used right now.
     *
     * Identity-service does not label it, and it cannot be matched by token:
     * refresh tokens are stored hashed and the client only holds the plain
     * one. The browser signature plus the most recent use is the closest
     * honest answer, and being wrong only means the Revoke button is offered
     * on a row where it is hidden — never that somebody else's session is
     * shown as this one. The page says as much next to the marker.
     *
     * @param list<array<string, mixed>> $sessions Newest first.
     */
    private function currentSessionId(array $sessions): ?string
    {
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        if ($userAgent === '') {
            return null;
        }

        foreach ($sessions as $session) {
            if ((string) ($session['user_agent'] ?? '') === $userAgent) {
                return (string) ($session['id'] ?? '');
            }
        }

        return null;
    }

    /**
     * One uploaded file, in the shape Api::upload expects.
     *
     * @return array{tmp_name: string, name: string, type: string}|null
     */
    private function uploadedFile(string $field): ?array
    {
        $file = $_FILES[$field] ?? null;

        if (!is_array($file) || is_array($file['tmp_name'] ?? null)) {
            return null;
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        return [
            'tmp_name' => (string) $file['tmp_name'],
            'name' => (string) ($file['name'] ?? 'upload'),
            'type' => (string) ($file['type'] ?? 'application/octet-stream'),
        ];
    }
}
