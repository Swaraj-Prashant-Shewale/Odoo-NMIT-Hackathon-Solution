<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\ServiceClient;

/**
 * The learning service's window onto people records.
 *
 * This service stores an employee_id and nothing else about a person, so every
 * name, department and reporting line comes from employee-service over HTTP,
 * carrying the caller's own token. Lookups are memoised for the lifetime of a
 * request because a compliance report asks about the same person repeatedly.
 */
final class EmployeeDirectory
{
    private const PAGE_SIZE = 100;

    /** Guards against an unbounded loop if a downstream page count is wrong. */
    private const MAX_PAGES = 20;

    /** @var array<string, array<string, mixed>|null> */
    private array $byId = [];

    /** @var list<array<string, mixed>>|null */
    private ?array $everyone = null;

    public function __construct(private readonly ?string $bearerToken)
    {
    }

    /**
     * One person, or null when they cannot be resolved.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $employeeId): ?array
    {
        if (array_key_exists($employeeId, $this->byId)) {
            return $this->byId[$employeeId];
        }

        $record = $this->client()->tryGet('/employees/' . rawurlencode($employeeId));

        return $this->byId[$employeeId] = is_array($record) ? $record : null;
    }

    /**
     * True when the employee reports directly to the given manager.
     *
     * The reporting line is read from employee-service rather than from the
     * caller's token, because the token states who the caller's own manager is,
     * not who reports to them.
     */
    public function reportsTo(string $employeeId, string $managerId): bool
    {
        $employee = $this->find($employeeId);

        if ($employee === null || !isset($employee['manager_id'])) {
            return false;
        }

        return hash_equals((string) $employee['manager_id'], $managerId);
    }

    public function displayName(string $employeeId): string
    {
        $employee = $this->find($employeeId);

        if ($employee === null) {
            return 'Unknown employee';
        }

        $full = trim((string) ($employee['full_name'] ?? ''));
        if ($full !== '') {
            return $full;
        }

        return trim(sprintf(
            '%s %s',
            (string) ($employee['first_name'] ?? ''),
            (string) ($employee['last_name'] ?? '')
        )) ?: 'Unknown employee';
    }

    /**
     * Every employee record the caller is allowed to see.
     *
     * @return list<array<string, mixed>>
     */
    public function everyone(): array
    {
        if ($this->everyone !== null) {
            return $this->everyone;
        }

        $collected = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            try {
                $envelope = $this->client()->get('/employees', [
                    'page' => $page,
                    'per_page' => self::PAGE_SIZE,
                ]);
            } catch (\Throwable) {
                // A partial directory still produces a usable report; an empty
                // one would wrongly read as "nobody is out of compliance".
                break;
            }

            $rows = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    continue;
                }

                $collected[(string) $row['id']] = $row;
                $this->byId[(string) $row['id']] = $row;
            }

            $totalPages = (int) ($envelope['meta']['total_pages'] ?? 1);
            if ($rows === [] || $page >= max(1, $totalPages)) {
                break;
            }
        }

        return $this->everyone = array_values($collected);
    }

    /**
     * A compact card for embedding in a list response.
     *
     * @return array<string, mixed>
     */
    public function card(string $employeeId): array
    {
        $employee = $this->find($employeeId);

        return [
            'employee_id' => $employeeId,
            'employee_code' => $employee['employee_code'] ?? null,
            'full_name' => $this->displayName($employeeId),
            'department_name' => $employee['department_name'] ?? null,
            'designation_name' => $employee['designation_name'] ?? null,
        ];
    }

    private function client(): ServiceClient
    {
        return ServiceClient::for('employee', $this->bearerToken);
    }
}
