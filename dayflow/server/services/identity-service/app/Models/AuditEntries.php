<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * Read access to the platform-wide audit trail.
 *
 * The trail is append-only by database grant: this role holds INSERT and
 * SELECT on it and nothing else, so there is deliberately no update or delete
 * here to match. Entries are written through the kernel's AuditLog; this class
 * exists purely to serve the screen that reads them back.
 */
final class AuditEntries extends Repository
{
    protected string $table = 'audit_log';

    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    private const COLUMNS = 'id, occurred_at, service, action, subject_type, subject_id,
                             actor_id, actor_email, actor_role, ip_address, user_agent,
                             before_state, after_state, context, request_id';

    /**
     * One page of the trail.
     *
     * Filters are assembled from a fixed set of clauses and every value is
     * bound, so nothing a caller sends can reach the statement text.
     *
     * @param array<string, mixed> $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        [$where, $bindings] = $this->conditions($filters);

        $count = $this->rawOne(
            'SELECT COUNT(*) AS aggregate FROM platform.audit_log WHERE ' . $where,
            $bindings
        );

        $total = (int) ($count['aggregate'] ?? 0);

        $rows = $this->raw(
            'SELECT ' . self::COLUMNS . '
               FROM platform.audit_log
              WHERE ' . $where . '
              ORDER BY occurred_at DESC
              LIMIT :page_size OFFSET :page_offset',
            $bindings + [
                'page_size' => $perPage,
                'page_offset' => (max(1, $page) - 1) * $perPage,
            ]
        );

        return [
            'data' => array_map([$this, 'decode'], $rows),
            'meta' => [
                'page' => max(1, $page),
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }

    /**
     * The matching entries for a file export, newest first.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function export(array $filters, int $limit): array
    {
        [$where, $bindings] = $this->conditions($filters);

        $rows = $this->raw(
            'SELECT ' . self::COLUMNS . '
               FROM platform.audit_log
              WHERE ' . $where . '
              ORDER BY occurred_at DESC
              LIMIT :row_limit',
            $bindings + ['row_limit' => $limit]
        );

        return array_map([$this, 'decode'], $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function conditions(array $filters): array
    {
        $clauses = ['TRUE'];
        $bindings = [];

        if (($filters['actor'] ?? null) !== null) {
            $clauses[] = 'actor_id = :actor';
            $bindings['actor'] = (string) $filters['actor'];
        }

        if (($filters['action'] ?? null) !== null) {
            // Prefix matching lets "leave." select a whole family of actions
            // without the caller needing to know every leaf name. The wildcard
            // is concatenated inside the statement so the bound value itself
            // stays a literal.
            $clauses[] = "action LIKE :action || '%'";
            $bindings['action'] = (string) $filters['action'];
        }

        if (($filters['subject'] ?? null) !== null) {
            $clauses[] = 'subject_type = :subject';
            $bindings['subject'] = (string) $filters['subject'];
        }

        if (($filters['subject_id'] ?? null) !== null) {
            $clauses[] = 'subject_id = :subject_id';
            $bindings['subject_id'] = (string) $filters['subject_id'];
        }

        if (($filters['service'] ?? null) !== null) {
            $clauses[] = 'service = :service';
            $bindings['service'] = (string) $filters['service'];
        }

        // The controller hands these over as full instants in the configured
        // business timezone; comparing a bare date against a TIMESTAMPTZ would
        // silently shift the window by the UTC offset.
        if (($filters['from'] ?? null) !== null) {
            $clauses[] = 'occurred_at >= :occurred_from';
            $bindings['occurred_from'] = (string) $filters['from'];
        }

        if (($filters['to'] ?? null) !== null) {
            $clauses[] = 'occurred_at <= :occurred_to';
            $bindings['occurred_to'] = (string) $filters['to'];
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    /** @param array<string, mixed> $row */
    private function decode(array $row): array
    {
        foreach (['before_state', 'after_state', 'context'] as $column) {
            $row[$column] = $row[$column] === null ? null : json_decode((string) $row[$column], true);
        }

        return $row;
    }
}
