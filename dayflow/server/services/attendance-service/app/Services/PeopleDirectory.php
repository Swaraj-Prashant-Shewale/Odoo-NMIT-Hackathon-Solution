<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\ServiceClient;
use Dayflow\Kernel\Support\Logger;

/**
 * The little this service needs to know about people, fetched from the
 * employee service rather than duplicated here.
 *
 * Attendance stores an employee_id and nothing else about a person, so names,
 * offices and reporting lines are always read across the wire. Every call is
 * decoration: it is made with tryGet so a slow or restarting employee service
 * degrades a board to bare identifiers instead of failing the request.
 */
final class PeopleDirectory
{
    /** @var array<string, array<string, mixed>|null> */
    private array $employees = [];

    /** @var array<string, list<string>> */
    private array $reports = [];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $directory = null;

    /** @return array<string, mixed>|null */
    public function employee(Request $request, string $employeeId): ?array
    {
        if (array_key_exists($employeeId, $this->employees)) {
            return $this->employees[$employeeId];
        }

        $record = $this->fetch($request, '/employees/' . rawurlencode($employeeId), [], null);

        return $this->employees[$employeeId] = is_array($record) ? $record : null;
    }

    /** The office an employee sits in, which decides their holiday calendar. */
    public function locationOf(Request $request, string $employeeId): ?string
    {
        $employee = $this->employee($request, $employeeId);
        $locationId = $employee['location_id'] ?? null;

        return is_string($locationId) && $locationId !== '' ? $locationId : null;
    }

    /**
     * The employee ids reporting directly to a manager.
     *
     * @return list<string>
     */
    public function directReportIds(Request $request, string $managerId): array
    {
        if (isset($this->reports[$managerId])) {
            return $this->reports[$managerId];
        }

        $rows = $this->fetch($request, '/employees', ['manager_id' => $managerId, 'per_page' => 100], []);

        $ids = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['id']) && is_string($row['id'])) {
                $ids[] = $row['id'];
            }
        }

        return $this->reports[$managerId] = array_values(array_unique($ids));
    }

    /**
     * Every employee id the directory knows about.
     *
     * @return list<string>
     */
    public function allIds(Request $request): array
    {
        $this->directory ??= $this->loadDirectory($request);

        return array_values(array_keys($this->directory));
    }

    /**
     * A compact person summary for a board row.
     *
     * One directory call covers a whole board; asking for each person in turn
     * would put a dozen round trips behind a single screen refresh.
     *
     * @return array<string, mixed>
     */
    public function summarise(Request $request, string $employeeId): array
    {
        $this->directory ??= $this->loadDirectory($request);
        $person = $this->directory[$employeeId] ?? $this->employees[$employeeId] ?? null;

        return [
            'employee_id' => $employeeId,
            'employee_code' => $person['employee_code'] ?? null,
            'full_name' => $person['full_name'] ?? null,
            'department_name' => $person['department_name'] ?? null,
            'designation_name' => $person['designation_name'] ?? null,
        ];
    }

    /**
     * Reads from the employee service, degrading to $default on any failure.
     *
     * The address lookup sits inside the guard as well: a board should still
     * draw when the employee service is missing from the environment, not only
     * when it is refusing connections.
     *
     * @param array<string, mixed> $query
     */
    private function fetch(Request $request, string $path, array $query, mixed $default): mixed
    {
        try {
            return ServiceClient::for('employee', $request->bearerToken())->tryGet($path, $query, $default);
        } catch (\Throwable $exception) {
            Logger::warning('Employee lookup unavailable', ['path' => $path, 'error' => $exception->getMessage()]);

            return $default;
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function loadDirectory(Request $request): array
    {
        $rows = $this->fetch($request, '/directory', ['per_page' => 100], []);

        $keyed = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['id']) && is_string($row['id'])) {
                $keyed[$row['id']] = $row;
            }
        }

        return $keyed;
    }
}
