<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * Money an employee spent on the company's behalf and wants back.
 *
 * approver_id holds the employee who was routed the claim when it was
 * submitted; decided_by holds the account that actually acted on it. Keeping
 * them apart means a reorganisation cannot rewrite who approved what.
 */
final class ExpenseClaims extends Repository
{
    protected string $table = 'expense_claims';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'employee_id', 'claim_number', 'category', 'title', 'description',
        'incurred_on', 'amount_minor', 'currency', 'receipt_document_id', 'status',
        'approver_id', 'decided_by', 'decided_at', 'decision_note',
        'reimbursed_at', 'reimbursed_reference',
    ];

    protected array $casts = [
        'amount_minor' => 'int',
    ];

    protected bool $softDeletes = false;

    /**
     * Reserves the next claim reference.
     *
     * A sequence rather than a counted SELECT, because two people submitting
     * at the same moment would otherwise be handed the same number.
     */
    public function nextClaimNumber(): string
    {
        $row = $this->rawOne("SELECT nextval('expense_claim_number_seq') AS value") ?? [];

        return sprintf('EXP-%s-%06d', date('Y'), (int) ($row['value'] ?? 1));
    }

    /**
     * Reads a claim and holds its row until the surrounding transaction ends.
     *
     * Deciding and reimbursing both read a status, judge it, and then write a
     * new one. Without the lock two approvers pressing the button at the same
     * moment would each see "submitted" and each be allowed to proceed, and a
     * claim would be paid twice against two different references.
     */
    public function lockForUpdate(string $id): ?array
    {
        $row = $this->rawOne('SELECT * FROM expense_claims WHERE id = :id FOR UPDATE', ['id' => $id]);

        return $row === null ? null : $this->present($row);
    }
}
