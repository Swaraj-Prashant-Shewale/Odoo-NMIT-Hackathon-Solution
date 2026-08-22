<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LeavePolicies;
use App\Models\LeaveTypes;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\ServiceClient;
use Dayflow\Kernel\Support\Logger;

/**
 * Credits earned leave to everyone who qualifies for it.
 *
 * The run is keyed by period rather than by "now", so re-running August after
 * fixing a mistake credits nobody twice, and a run that failed halfway can
 * simply be started again. Employment data comes from the employee service,
 * which is the only place that knows who is still employed.
 */
final class AccrualRunner
{
    /** A run over a company of any realistic size finishes well inside this. */
    private const MAX_EMPLOYEE_PAGES = 50;

    private const DEFAULT_FINANCIAL_YEAR_START_MONTH = 4;

    private ?int $financialYearStartMonth = null;

    public function __construct(
        private readonly LeaveTypes $types,
        private readonly LeavePolicies $policies,
        private readonly BalanceLedger $ledger,
    ) {
    }

    /**
     * @return array<string, mixed> A summary of what the run did.
     */
    public function run(string $period, ?string $bearerToken): array
    {
        $employees = $this->activeEmployees($bearerToken);
        $accruingTypes = $this->types->accruing();

        $periodEnd = $this->periodEndDate($period);
        $policyIndex = $this->policies->inForceIndex($periodEnd);
        $year = (int) substr($period, 0, 4);

        $credited = 0;
        $alreadyCredited = 0;
        $notQualified = 0;
        $byType = [];

        foreach ($accruingTypes as $type) {
            $periodKey = $this->periodKey($type, $period);

            if ($periodKey === null) {
                // Not an accrual point for this frequency, e.g. a quarterly
                // type in the middle month of a quarter.
                continue;
            }

            $typeCredits = 0;

            foreach ($employees as $employee) {
                $employmentType = (string) ($employee['employment_type'] ?? 'full_time');
                $policy = $policyIndex[$type['id'] . '|' . $employmentType] ?? null;

                if (!$this->qualifies($employee, $policy, $periodEnd)) {
                    $notQualified++;
                    continue;
                }

                $quota = $policy !== null && $policy['quota_override_days'] !== null
                    ? (float) $policy['quota_override_days']
                    : (float) $type['annual_quota_days'];

                $days = $this->creditForPeriod($type, $quota);

                if ($days <= 0) {
                    continue;
                }

                $applied = Connection::transaction(function () use ($employee, $type, $year, $days, $periodKey, $quota): bool {
                    $balance = $this->ledger->ensure((string) $employee['id'], $type, $year);

                    return $this->ledger->accrue((string) $balance['id'], $days, $periodKey, $quota);
                });

                if ($applied) {
                    $credited++;
                    $typeCredits++;
                } else {
                    $alreadyCredited++;
                }
            }

            $byType[] = [
                'leave_type_id' => $type['id'],
                'leave_type_name' => $type['name'],
                'period' => $periodKey,
                'employees_credited' => $typeCredits,
            ];
        }

        return [
            'period' => $period,
            'year' => $year,
            'employees_considered' => count($employees),
            'credits_applied' => $credited,
            'already_credited' => $alreadyCredited,
            'not_yet_qualified' => $notQualified,
            'by_leave_type' => $byType,
        ];
    }

    /**
     * The accrual period a type is credited for, or null when this month is
     * not an accrual point for it.
     *
     * @param array<string, mixed> $type
     */
    private function periodKey(array $type, string $period): ?string
    {
        $year = substr($period, 0, 4);
        $month = (int) substr($period, 5, 2);
        $fyStart = $this->financialYearStartMonth();

        return match ($type['accrual_frequency']) {
            'monthly' => $period,
            'quarterly' => ($month - $fyStart + 12) % 3 === 0
                ? sprintf('%s-Q%d', $year, intdiv((($month - $fyStart + 12) % 12), 3) + 1)
                : null,
            'yearly' => $month === $fyStart ? $year . '-A' : null,
            default => null,
        };
    }

    /**
     * How many days one period is worth.
     *
     * accrual_days is authoritative; the quota is only used as a ceiling so a
     * mid-year policy change cannot push a balance past the entitlement.
     *
     * @param array<string, mixed> $type
     */
    private function creditForPeriod(array $type, float $quota): float
    {
        $days = (float) $type['accrual_days'];

        return $quota > 0 ? min($days, $quota) : $days;
    }

    /**
     * @param array<string, mixed>      $employee
     * @param array<string, mixed>|null $policy
     */
    private function qualifies(array $employee, ?array $policy, string $onDate): bool
    {
        $joinedOn = $employee['joined_on'] ?? null;

        if (!is_string($joinedOn) || $joinedOn === '') {
            // Without a joining date the qualifying period cannot be tested,
            // so the employee is treated as qualified rather than skipped and
            // quietly shorted days they are owed.
            return true;
        }

        $months = $policy !== null ? (int) $policy['applies_after_months'] : 0;

        if ($months <= 0) {
            return strtotime($joinedOn) <= strtotime($onDate);
        }

        $qualifiesFrom = strtotime(sprintf('+%d months', $months), strtotime($joinedOn));

        return $qualifiesFrom !== false && $qualifiesFrom <= strtotime($onDate);
    }

    private function periodEndDate(string $period): string
    {
        return date('Y-m-t', (int) strtotime($period . '-01'));
    }

    /**
     * Everyone still employed, from the service that owns that fact.
     *
     * @return list<array<string, mixed>>
     */
    private function activeEmployees(?string $bearerToken): array
    {
        $client = ServiceClient::for('employee', $bearerToken);
        $employees = [];
        $page = 1;
        $totalPages = 1;

        do {
            $response = $client->get('/employees', [
                'page' => $page,
                'per_page' => 100,
                'is_active' => 'true',
            ]);

            $rows = $response['data'] ?? [];

            if (!is_array($rows)) {
                break;
            }

            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    continue;
                }

                if (array_key_exists('is_active', $row) && $row['is_active'] === false) {
                    continue;
                }

                $employees[] = $row;
            }

            $totalPages = (int) ($response['meta']['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= self::MAX_EMPLOYEE_PAGES);

        if ($employees === []) {
            throw HttpException::unprocessable('No active employees were returned, so there is nothing to accrue.');
        }

        return $employees;
    }

    private function financialYearStartMonth(): int
    {
        if ($this->financialYearStartMonth !== null) {
            return $this->financialYearStartMonth;
        }

        try {
            $statement = Connection::pdo()->prepare('SELECT value FROM platform.settings WHERE key = :key');
            $statement->execute(['key' => 'company.financial_year_start']);
            $raw = $statement->fetchColumn();

            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $value = is_string($decoded) ? $decoded : null;

            if ($value !== null && preg_match('/^(0[1-9]|1[0-2])-\d{2}$/', $value) === 1) {
                return $this->financialYearStartMonth = (int) substr($value, 0, 2);
            }
        } catch (\Throwable $exception) {
            Logger::warning('Financial year setting unreadable, assuming April', [
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->financialYearStartMonth = self::DEFAULT_FINANCIAL_YEAR_START_MONTH;
    }
}
