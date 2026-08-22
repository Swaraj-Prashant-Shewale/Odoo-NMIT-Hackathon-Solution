<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\ServiceClient;

/**
 * Reads the canonical person record from the service that owns it.
 *
 * Leave stores an employee_id and nothing else about a person, so the few
 * rules that depend on who somebody is — a leave type restricted by gender,
 * for instance — have to ask employee-service. The answer is cached for the
 * life of the request so one submission never fetches the same record twice.
 */
final class EmployeeProfile
{
    /** @var array<string, array<string, mixed>|null> */
    private array $cache = [];

    public function __construct(private readonly ?string $bearerToken = null)
    {
    }

    /**
     * The canonical record, or null when it cannot be read.
     *
     * Null covers both "no such person" and "the service did not answer": the
     * callers here treat an unknown answer as no reason to refuse, so the two
     * do not need telling apart.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $employeeId): ?array
    {
        if (array_key_exists($employeeId, $this->cache)) {
            return $this->cache[$employeeId];
        }

        $record = ServiceClient::for('employee', $this->bearerToken)
            ->tryGet('/employees/' . rawurlencode($employeeId));

        return $this->cache[$employeeId] = is_array($record) ? $record : null;
    }
}
