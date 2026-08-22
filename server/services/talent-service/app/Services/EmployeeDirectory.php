<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\ServiceClient;

/**
 * Everything this service needs to know about people.
 *
 * Talent records store an employee_id and nothing else, so "is this person my
 * report?" is answered by the employee service rather than by a manager_id
 * that arrived in a request body. The caller's own token is forwarded, so the
 * far side applies its own rules instead of trusting this one.
 */
final class EmployeeDirectory
{
    private const PAGE_SIZE = 100;

    /** Enough pages to cover an organisation far larger than this one. */
    private const MAX_PAGES = 10;

    /** @var array<string, array<string, mixed>|null> */
    private array $cache = [];

    private function __construct(private readonly ?string $token)
    {
    }

    public static function forRequest(Request $request): self
    {
        return new self($request->bearerToken());
    }

    /** @return array<string, mixed>|null Null when the record cannot be read. */
    public function find(string $employeeId): ?array
    {
        if (array_key_exists($employeeId, $this->cache)) {
            return $this->cache[$employeeId];
        }

        $record = ServiceClient::for('employee', $this->token)
            ->tryGet('/employees/' . rawurlencode($employeeId));

        return $this->cache[$employeeId] = is_array($record) ? $record : null;
    }

    /** @return array<string, mixed> */
    public function require(string $employeeId): array
    {
        $record = $this->find($employeeId);

        if ($record === null) {
            throw HttpException::notFound('That employee record does not exist.');
        }

        return $record;
    }

    /**
     * Confirms a reporting line against the record owner.
     *
     * Fails closed: when the employee service cannot be reached, or will not
     * disclose the record to this caller, the answer is no.
     */
    public function isDirectReport(?string $managerEmployeeId, string $employeeId): bool
    {
        if ($managerEmployeeId === null || $managerEmployeeId === '') {
            return false;
        }

        $record = $this->find($employeeId);
        if ($record === null) {
            return false;
        }

        $managerOfRecord = isset($record['manager_id']) ? (string) $record['manager_id'] : '';

        return $managerOfRecord !== '' && hash_equals($managerEmployeeId, $managerOfRecord);
    }

    /**
     * The people who report directly to one manager.
     *
     * @return list<array<string, mixed>>
     */
    public function directReports(string $managerEmployeeId): array
    {
        $people = $this->fetchPage(['manager_id' => $managerEmployeeId]);

        // The filter is re-applied here so that a directory which ignores the
        // query parameter can never silently widen a manager's team.
        return array_values(array_filter(
            $people,
            static fn (array $person): bool =>
                isset($person['manager_id']) && (string) $person['manager_id'] === $managerEmployeeId
        ));
    }

    /**
     * Everybody currently employed, optionally narrowed to one department.
     *
     * @return list<array<string, mixed>>
     */
    public function activeEmployees(?string $departmentId = null): array
    {
        $filters = ['is_active' => 'true'];
        if ($departmentId !== null) {
            $filters['department_id'] = $departmentId;
        }

        return array_values(array_filter(
            $this->fetchPage($filters),
            static function (array $person) use ($departmentId): bool {
                if (array_key_exists('is_active', $person) && $person['is_active'] === false) {
                    return false;
                }

                return $departmentId === null
                    || (string) ($person['department_id'] ?? '') === $departmentId;
            }
        ));
    }

    /**
     * Display names for a set of records already in hand.
     *
     * @param list<array<string, mixed>> $people
     * @return array<string, string>
     */
    public static function nameMap(array $people): array
    {
        $names = [];

        foreach ($people as $person) {
            if (isset($person['id'])) {
                $names[(string) $person['id']] = self::displayName($person);
            }
        }

        return $names;
    }

    /** @param array<string, mixed> $person */
    public static function displayName(array $person): string
    {
        $full = trim((string) ($person['full_name'] ?? ''));
        if ($full !== '') {
            return $full;
        }

        return trim(((string) ($person['first_name'] ?? '')) . ' ' . ((string) ($person['last_name'] ?? '')));
    }

    /**
     * Reads a filtered employee list, following pagination.
     *
     * Team membership drives authorisation, so a failure here is surfaced
     * rather than swallowed: an empty team would silently look like a valid
     * answer when it is really an outage.
     *
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    private function fetchPage(array $filters): array
    {
        $client = ServiceClient::for('employee', $this->token);
        $collected = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $envelope = $client->get('/employees', $filters + ['page' => $page, 'per_page' => self::PAGE_SIZE]);
            $rows = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

            foreach ($rows as $row) {
                if (is_array($row) && isset($row['id'])) {
                    $collected[(string) $row['id']] = $row;
                    $this->cache[(string) $row['id']] = $row;
                }
            }

            $totalPages = (int) ($envelope['meta']['total_pages'] ?? 1);

            if (count($rows) < self::PAGE_SIZE || $page >= $totalPages) {
                break;
            }
        }

        return array_values($collected);
    }
}
