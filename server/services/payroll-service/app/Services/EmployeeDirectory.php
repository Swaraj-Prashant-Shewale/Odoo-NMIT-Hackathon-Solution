<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\ServiceClient;

/**
 * Reads people records from employee-service.
 *
 * Payroll stores nothing about a person beyond their employee_id, so names,
 * departments and joining dates are always fetched. The caller's own token is
 * carried forward, which means employee-service applies its own rules and a
 * payroll request can never see more than the person behind it could.
 */
final class EmployeeDirectory
{
    /** Refuses to walk an unbounded number of pages if a peer misreports its metadata. */
    private const MAX_PAGES = 50;

    private const PAGE_SIZE = 100;

    public function __construct(private readonly ?string $bearerToken)
    {
    }

    /** Decoration only: a missing record must not stop a payslip rendering. */
    public function find(string $employeeId): ?array
    {
        $record = $this->client()->tryGet('/employees/' . rawurlencode($employeeId));

        return is_array($record) ? $record : null;
    }

    /**
     * Whether employee-service recognises this person.
     *
     * Used before payroll writes something durable against an identifier that
     * arrived in a request body. Only a 404 counts as a denial: any other
     * failure means the directory could not answer, and an unreachable peer
     * must not become a reason to refuse legitimate payroll work.
     */
    public function isKnown(string $employeeId): bool
    {
        try {
            $this->client()->get('/employees/' . rawurlencode($employeeId));

            return true;
        } catch (HttpException $exception) {
            return $exception->status() !== 404;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Everyone currently on the payroll.
     *
     * This one is essential rather than decorative: a run that quietly
     * processed nobody because employee-service was unreachable would look
     * like a completed payroll, so the failure is allowed to surface.
     *
     * @return list<array<string, mixed>>
     */
    public function activeEmployees(): array
    {
        $employees = [];
        $page = 1;
        $totalPages = 1;

        while ($page <= $totalPages && $page <= self::MAX_PAGES) {
            $envelope = $this->client()->get('/employees', [
                'page' => $page,
                'per_page' => self::PAGE_SIZE,
                'is_active' => 'true',
            ]);

            foreach ((array) ($envelope['data'] ?? []) as $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    continue;
                }

                if (($row['is_active'] ?? true) === false) {
                    continue;
                }

                $employees[] = $row;
            }

            $totalPages = max(1, (int) ($envelope['meta']['total_pages'] ?? 1));
            $page++;
        }

        return $employees;
    }

    /**
     * A compact person summary safe to embed in a payroll response.
     *
     * @return array<string, mixed>
     */
    public function summary(string $employeeId): array
    {
        $record = $this->find($employeeId);

        if ($record === null) {
            return ['id' => $employeeId];
        }

        return [
            'id' => $employeeId,
            'employee_code' => $record['employee_code'] ?? null,
            'full_name' => $record['full_name'] ?? trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')),
            'work_email' => $record['work_email'] ?? null,
            'department_name' => $record['department_name'] ?? null,
            'designation_name' => $record['designation_name'] ?? null,
            'joined_on' => $record['joined_on'] ?? null,
            'manager_id' => $record['manager_id'] ?? null,
        ];
    }

    private function client(): ServiceClient
    {
        return ServiceClient::for('employee', $this->bearerToken);
    }
}
