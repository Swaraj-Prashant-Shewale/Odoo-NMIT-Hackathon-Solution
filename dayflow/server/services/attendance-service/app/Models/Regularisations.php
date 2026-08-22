<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\TimeFormat;
use Dayflow\Kernel\Database\Repository;

final class Regularisations extends Repository
{
    protected string $table = 'regularisations';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'employee_id', 'work_date', 'requested_check_in', 'requested_check_out',
        'requested_status', 'reason', 'status', 'approver_id',
        'decided_by', 'decided_at', 'decision_note',
    ];

    protected bool $softDeletes = false;

    /**
     * Loads a request and holds a row lock on it until the transaction ends.
     *
     * Two approvers reaching the same request at the same moment would
     * otherwise both read it as pending, both write the day it corrects and
     * both announce a decision. The lock makes the second one wait and then
     * see the decision the first one recorded.
     */
    public function lockById(string $id): ?array
    {
        $row = $this->rawOne('SELECT * FROM regularisations WHERE id = :id FOR UPDATE', ['id' => $id]);

        return $row === null ? null : $this->present($row);
    }

    public function pendingFor(string $employeeId, string $workDate): ?array
    {
        $row = $this->query()
            ->where('employee_id', '=', $employeeId)
            ->where('work_date', '=', $workDate)
            ->where('status', '=', 'pending')
            ->first();

        return $row === null ? null : $this->present($row);
    }

    /** @return array{pending: int, approved: int, rejected: int} */
    public function countsForApprover(string $approverId): array
    {
        $row = $this->rawOne(
            "SELECT
                 COUNT(*) FILTER (WHERE status = 'pending')::INTEGER  AS pending,
                 COUNT(*) FILTER (WHERE status = 'approved')::INTEGER AS approved,
                 COUNT(*) FILTER (WHERE status = 'rejected')::INTEGER AS rejected
             FROM regularisations
             WHERE approver_id = :approver_id",
            ['approver_id' => $approverId]
        );

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'approved' => (int) ($row['approved'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
        ];
    }

    public function present(array $row): array
    {
        $row = parent::present($row);

        foreach (['requested_check_in', 'requested_check_out', 'decided_at'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = TimeFormat::local($row[$column] === null ? null : (string) $row[$column]);
            }
        }

        return $row;
    }
}
