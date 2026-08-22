<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * Where somebody's salary is paid.
 *
 * The ciphertext columns and the blind index are listed as hidden, so even a
 * controller that returned a whole row by accident could not leak them: they
 * are stripped before the record ever reaches a response.
 */
final class BankAccounts extends Repository
{
    protected string $table = 'bank_accounts';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'employee_id', 'account_number_encrypted', 'account_number_blind',
        'account_last4', 'bank_name', 'ifsc_code', 'account_holder_name',
        'tax_identifier_encrypted', 'tax_identifier_last4', 'verified_at',
    ];

    protected array $hidden = [
        'account_number_encrypted',
        'account_number_blind',
        'tax_identifier_encrypted',
    ];

    protected bool $softDeletes = false;

    public function forEmployee(string $employeeId): ?array
    {
        return $this->findBy('employee_id', $employeeId);
    }

    /**
     * Finds another employee already using this account number.
     *
     * The comparison runs against the keyed blind index, so the plaintext is
     * never queried and never appears in a log of slow statements.
     */
    public function otherHolderOf(string $blindIndex, string $employeeId): ?array
    {
        $row = $this->rawOne(
            'SELECT id, employee_id FROM bank_accounts
              WHERE account_number_blind = :blind AND employee_id <> :employee_id
              LIMIT 1',
            ['blind' => $blindIndex, 'employee_id' => $employeeId]
        );

        return $row === null ? null : $this->present($row);
    }
}
