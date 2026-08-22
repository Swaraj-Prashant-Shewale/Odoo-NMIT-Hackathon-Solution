<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\BankAccounts;
use App\Policies\PayrollAccessPolicy;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Encryptor;
use Dayflow\Kernel\Validation\Validator;

/**
 * Salary bank details.
 *
 * The account number is written once, encrypted, and is never read back out
 * of this service by anybody, HR and finance included. Only the last four
 * digits leave the boundary, which is enough to recognise an account and
 * useless to somebody who steals the response.
 */
final class BankAccountController
{
    private BankAccounts $accounts;

    public function __construct()
    {
        $this->accounts = new BankAccounts();
    }

    /** The masked record for the caller, or for a named employee with payroll rights. */
    public function show(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'employee_id' => 'nullable|uuid',
        ])->validated();

        $employeeId = PayrollAccessPolicy::resolveSubject($request->principal(), $filters['employee_id'] ?? null);
        $account = $this->accounts->forEmployee($employeeId);

        if ($account === null) {
            return Response::ok([
                'employee_id' => $employeeId,
                'has_bank_account' => false,
            ]);
        }

        return Response::ok($this->masked($account));
    }

    /** Adds or replaces the caller's own details. */
    public function update(Request $request): Response
    {
        $principal = $request->principal();
        $employeeId = $principal->employeeId;

        if ($employeeId === null || $employeeId === '') {
            throw HttpException::forbidden('This account is not linked to an employee record.');
        }

        // Lengths are checked with explicit patterns rather than the min/max
        // rules: an all-digit account number would be compared as a number,
        // not as a string of characters.
        $data = Validator::make($request->all(), [
            'account_number' => 'required|alpha_num',
            'bank_name' => 'required|safe_text|max:120',
            'ifsc_code' => 'required|alpha_num',
            'account_holder_name' => 'required|safe_text|max:120',
            'tax_identifier' => 'nullable|alpha_num',
        ])->validated();

        $accountNumber = (string) $data['account_number'];
        $ifsc = strtoupper((string) $data['ifsc_code']);

        if (preg_match('/^[0-9]{6,20}$/', $accountNumber) !== 1) {
            throw HttpException::unprocessable('An account number must be between 6 and 20 digits.');
        }

        if (preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc) !== 1) {
            throw HttpException::unprocessable('That IFSC code is not in a recognised format.');
        }

        if (isset($data['tax_identifier']) && preg_match('/^[A-Za-z0-9]{10,20}$/', (string) $data['tax_identifier']) !== 1) {
            throw HttpException::unprocessable('That tax identifier is not in a recognised format.');
        }

        $blindIndex = Encryptor::blindIndex($accountNumber);

        if ($this->accounts->otherHolderOf($blindIndex, $employeeId) !== null) {
            // The clash is reported without naming the other employee: the
            // caller is entitled to know their entry was rejected, not who
            // already holds the account.
            throw HttpException::conflict('That account number is already registered against another employee.');
        }

        $taxIdentifier = isset($data['tax_identifier']) ? strtoupper((string) $data['tax_identifier']) : null;

        $attributes = [
            'employee_id' => $employeeId,
            'account_number_encrypted' => Encryptor::encrypt($accountNumber),
            'account_number_blind' => $blindIndex,
            'account_last4' => substr($accountNumber, -4),
            'bank_name' => $data['bank_name'],
            'ifsc_code' => $ifsc,
            'account_holder_name' => $data['account_holder_name'],
            'tax_identifier_encrypted' => $taxIdentifier === null ? null : Encryptor::encrypt($taxIdentifier),
            'tax_identifier_last4' => $taxIdentifier === null ? null : substr($taxIdentifier, -4),
            // Changing the destination account resets verification: the new
            // one has not been confirmed by finance yet.
            'verified_at' => null,
        ];

        $existing = $this->accounts->forEmployee($employeeId);

        $account = $existing === null
            ? $this->accounts->create($attributes)
            : $this->accounts->update((string) $existing['id'], $attributes);

        if ($account === null) {
            throw HttpException::notFound();
        }

        AuditLog::record(
            $request,
            $existing === null ? 'payroll.bank_account.added' : 'payroll.bank_account.updated',
            'bank_account',
            (string) $account['id'],
            $existing === null ? [] : $this->masked($existing),
            $this->masked($account)
        );

        return Response::ok($this->masked($account));
    }

    /**
     * The only shape a bank record is ever allowed to leave in.
     *
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    private function masked(array $account): array
    {
        return [
            'id' => $account['id'] ?? null,
            'employee_id' => $account['employee_id'] ?? null,
            'has_bank_account' => true,
            'bank_name' => $account['bank_name'] ?? null,
            'ifsc_code' => $account['ifsc_code'] ?? null,
            'account_holder_name' => $account['account_holder_name'] ?? null,
            'account_last4' => $account['account_last4'] ?? null,
            'account_number_masked' => isset($account['account_last4'])
                ? '******' . (string) $account['account_last4']
                : null,
            'tax_identifier_last4' => $account['tax_identifier_last4'] ?? null,
            'verified_at' => $account['verified_at'] ?? null,
            'updated_at' => $account['updated_at'] ?? null,
        ];
    }
}
