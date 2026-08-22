<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\QueryBuilder;
use Dayflow\Kernel\Database\Repository;

/**
 * Metadata for uploaded paperwork.
 *
 * The stored filename is hidden from every response: it is the only thing that
 * locates the bytes on disk, and nothing outside this service has any use for
 * it.
 */
final class EmployeeDocuments extends Repository
{
    public const CATEGORIES = [
        'identity', 'address', 'education', 'employment', 'contract',
        'payroll', 'medical', 'photo', 'other',
    ];

    protected string $table = 'employee_documents';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'employee_id', 'category', 'title', 'original_filename',
        'stored_filename', 'mime_type', 'size_bytes', 'checksum',
        'issued_on', 'expires_on', 'status', 'verified_by', 'verified_at', 'uploaded_by',
    ];

    protected array $hidden = ['stored_filename'];

    protected array $casts = ['size_bytes' => 'int'];

    protected bool $softDeletes = false;

    /** Reads the row including the columns hidden from API responses. */
    public function findInternal(string $id): ?array
    {
        return $this->query()->where('id', '=', $id)->first();
    }

    public function listQuery(): QueryBuilder
    {
        return $this->query()
            ->select('employee_documents.*')
            ->selectRaw('TRIM("employees"."first_name" || \' \' || "employees"."last_name") AS employee_name')
            ->selectRaw('"employees"."employee_code" AS employee_code')
            ->join('employees', 'employee_documents.employee_id', '=', 'employees.id', 'LEFT');
    }

    /**
     * Documents falling due within the window, newest expiry last.
     *
     * @return list<array<string, mixed>>
     */
    public function expiringWithin(string $fromDate, string $toDate): array
    {
        $sql = <<<'SQL'
            SELECT d.*,
                   TRIM(e.first_name || ' ' || e.last_name) AS employee_name,
                   e.employee_code,
                   e.work_email
            FROM employee_documents d
            JOIN employees e ON e.id = d.employee_id AND e.deleted_at IS NULL
            WHERE d.expires_on IS NOT NULL
              AND d.expires_on BETWEEN CAST(:from_date AS DATE) AND CAST(:to_date AS DATE)
              AND d.status <> 'rejected'
            ORDER BY d.expires_on
            SQL;

        return array_map([$this, 'present'], $this->raw($sql, [
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]));
    }

    /**
     * Claims the documents still owed an expiry reminder today.
     *
     * The claim and the read are one statement. Two HR screens opened at the
     * same moment would otherwise both announce the same document, and the
     * notification service turns every announcement into an email.
     *
     * @return list<array{id: string, employee_id: string, expires_on: string}>
     */
    public function claimExpiryReminders(string $today, string $until): array
    {
        $sql = <<<'SQL'
            UPDATE employee_documents
               SET expiry_notified_on = CAST(:claimed_on AS DATE), updated_at = NOW()
             WHERE id IN (
                 SELECT d.id
                 FROM employee_documents d
                 JOIN employees e ON e.id = d.employee_id AND e.deleted_at IS NULL
                 WHERE d.expires_on IS NOT NULL
                   AND d.expires_on BETWEEN CAST(:from_date AS DATE) AND CAST(:to_date AS DATE)
                   AND d.status <> 'rejected'
                   AND (d.expiry_notified_on IS NULL OR d.expiry_notified_on < CAST(:since AS DATE))
                 FOR UPDATE SKIP LOCKED
             )
            RETURNING id, employee_id, expires_on
            SQL;

        return $this->raw($sql, [
            'claimed_on' => $today,
            'from_date' => $today,
            'to_date' => $until,
            'since' => $today,
        ]);
    }

    /**
     * Moves anything already past its expiry date into the expired state.
     *
     * Keeping the column and the status in step means a report can filter on
     * status alone without every caller repeating the date arithmetic.
     */
    public function markLapsed(string $today): int
    {
        return $this->execute(
            "UPDATE employee_documents
                SET status = 'expired', updated_at = NOW()
              WHERE expires_on IS NOT NULL
                AND expires_on < CAST(:today AS DATE)
                AND status <> 'expired'",
            ['today' => $today]
        );
    }
}
