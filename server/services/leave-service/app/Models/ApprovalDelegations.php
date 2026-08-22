<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class ApprovalDelegations extends Repository
{
    protected string $table = 'approval_delegations';

    protected array $fillable = [
        'delegator_id', 'delegate_id', 'starts_on', 'ends_on', 'reason', 'is_active',
    ];

    protected array $casts = ['is_active' => 'bool'];

    // A delegation is revoked by clearing is_active, which the repository's
    // update() writes; there is no separate modification history to keep.
    protected bool $timestamps = false;

    /**
     * True when this delegate may act for this approver today.
     *
     * Both the flag and the window are checked, so revoking a delegation takes
     * effect immediately rather than at the end of the booked period.
     */
    public function isActiveBetween(string $delegatorId, string $delegateId, string $onDate): bool
    {
        $sql = <<<'SQL'
            SELECT 1
            FROM approval_delegations
            WHERE delegator_id = :delegator_id
              AND delegate_id = :delegate_id
              AND is_active = TRUE
              AND starts_on <= CAST(:on_date AS date)
              AND ends_on   >= CAST(:on_date_end AS date)
            LIMIT 1
        SQL;

        return $this->rawOne($sql, [
            'delegator_id' => $delegatorId,
            'delegate_id' => $delegateId,
            'on_date' => $onDate,
            'on_date_end' => $onDate,
        ]) !== null;
    }

    /**
     * The approvers this person is currently standing in for.
     *
     * @return list<string>
     */
    public function delegatorsFor(string $delegateId, string $onDate): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT delegator_id
            FROM approval_delegations
            WHERE delegate_id = :delegate_id
              AND is_active = TRUE
              AND starts_on <= CAST(:on_date AS date)
              AND ends_on   >= CAST(:on_date_end AS date)
        SQL;

        return array_map(
            static fn (array $row): string => (string) $row['delegator_id'],
            $this->raw($sql, ['delegate_id' => $delegateId, 'on_date' => $onDate, 'on_date_end' => $onDate])
        );
    }

    /**
     * Delegations where this person is either side of the arrangement.
     *
     * @return list<array<string, mixed>>
     */
    public function involving(string $employeeId): array
    {
        $sql = <<<'SQL'
            SELECT *
            FROM approval_delegations
            WHERE delegator_id = :delegator_id OR delegate_id = :delegate_id
            ORDER BY starts_on DESC
        SQL;

        $rows = $this->raw($sql, ['delegator_id' => $employeeId, 'delegate_id' => $employeeId]);

        return array_map([$this, 'present'], $rows);
    }

    /**
     * A delegation already covering part of the same window.
     *
     * Overlapping delegations from one approver would make it ambiguous who is
     * standing in, so a new one is refused rather than silently layered on.
     */
    public function overlapping(string $delegatorId, string $startsOn, string $endsOn, ?string $ignoreId = null): ?array
    {
        $sql = <<<'SQL'
            SELECT *
            FROM approval_delegations
            WHERE delegator_id = :delegator_id
              AND is_active = TRUE
              AND starts_on <= CAST(:ends_on AS date)
              AND ends_on   >= CAST(:starts_on AS date)
              AND (CAST(:ignore_id AS uuid) IS NULL OR id <> CAST(:ignore_id_again AS uuid))
            LIMIT 1
        SQL;

        return $this->rawOne($sql, [
            'delegator_id' => $delegatorId,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'ignore_id' => $ignoreId,
            'ignore_id_again' => $ignoreId,
        ]);
    }
}
