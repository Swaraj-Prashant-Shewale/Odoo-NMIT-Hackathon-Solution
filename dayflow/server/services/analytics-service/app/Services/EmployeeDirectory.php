<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Support\Clock;

/**
 * The people picture, assembled once per request from employee-service.
 *
 * Headcount, department splits, joiners, leavers, tenure and attrition are all
 * views of the same roster, so it is fetched once and every figure is derived
 * from it in memory. That keeps the number of calls to employee-service at one
 * regardless of how many cards the dashboard shows, and it means every figure
 * on the page is computed from the same instant rather than from several.
 *
 * The roster is whatever employee-service chose to return for this caller. A
 * manager sees their reports, HR sees the organisation - analytics never
 * widens that.
 */
final class EmployeeDirectory
{
    /** @var list<array<string, mixed>>|null */
    private ?array $roster = null;

    private bool $loaded = false;

    public function __construct(private readonly Downstream $downstream)
    {
    }

    /**
     * @return list<array<string, mixed>>|null Null when employee-service could not be reached.
     */
    public function roster(): ?array
    {
        if (!$this->loaded) {
            $this->loaded = true;
            // Leavers must be present for attrition and exit reporting, so the
            // inactive roster is asked for explicitly.
            $this->roster = $this->downstream->collect('employee', '/employees', ['include_inactive' => 'true']);
        }

        return $this->roster;
    }

    public function available(): bool
    {
        return $this->roster() !== null;
    }

    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        return array_values(array_filter(
            $this->roster() ?? [],
            static fn (array $row): bool => Payload::text($row, ['exit_date'], '') === ''
                && Payload::bool($row, ['is_active'], true)
        ));
    }

    /**
     * Direct reports of one manager.
     *
     * @return list<array<string, mixed>>
     */
    public function reportsTo(string $managerId): array
    {
        return array_values(array_filter(
            $this->active(),
            static fn (array $row): bool => Payload::text($row, ['manager_id'], '') === $managerId
        ));
    }

    public function headcount(): int
    {
        return count($this->active());
    }

    /** @return list<array{key: string, label: string, value: int}> */
    public function byDepartment(): array
    {
        return $this->countBy($this->active(), ['department_id'], ['department_name', 'department'], 'Unassigned');
    }

    /** @return list<array{key: string, label: string, value: int}> */
    public function byEmploymentType(): array
    {
        $counts = [];

        foreach ($this->active() as $row) {
            $type = Payload::text($row, ['employment_type'], 'unspecified');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        ksort($counts);

        return array_map(
            static fn (string $type): array => [
                'key' => $type,
                'label' => ucwords(str_replace('_', ' ', $type)),
                'value' => $counts[$type],
            ],
            array_keys($counts)
        );
    }

    /**
     * Everyone who joined inside the window.
     *
     * @return list<array<string, mixed>>
     */
    public function joinersBetween(string $from, string $to): array
    {
        return array_values(array_filter(
            $this->roster() ?? [],
            static function (array $row) use ($from, $to): bool {
                $joined = Payload::text($row, ['joined_on', 'joining_date'], '');

                return $joined !== '' && $joined >= $from && $joined <= $to;
            }
        ));
    }

    /**
     * Everyone whose exit falls inside the window.
     *
     * @return list<array<string, mixed>>
     */
    public function leaversBetween(string $from, string $to): array
    {
        return array_values(array_filter(
            $this->roster() ?? [],
            static function (array $row) use ($from, $to): bool {
                $exit = Payload::text($row, ['exit_date', 'last_working_day'], '');

                return $exit !== '' && $exit >= $from && $exit <= $to;
            }
        ));
    }

    /** Headcount as it stood at the close of business on one date. */
    public function headcountOn(string $date): int
    {
        $count = 0;

        foreach ($this->roster() ?? [] as $row) {
            $joined = Payload::text($row, ['joined_on', 'joining_date'], '');
            $exit = Payload::text($row, ['exit_date', 'last_working_day'], '');

            if ($joined === '' || $joined > $date) {
                continue;
            }

            if ($exit !== '' && $exit <= $date) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Headcount at the close of each of the last $months months.
     *
     * Reconstructed from joining and exit dates rather than from stored
     * snapshots, so a correction to somebody's start date is reflected across
     * the whole trend instead of only from today onwards.
     *
     * @return list<array{period: string, label: string, headcount: int, joiners: int, leavers: int}>
     */
    public function headcountTrend(int $months = 12): array
    {
        $trend = [];

        foreach (Period::lastMonths($months) as $month) {
            $trend[] = [
                'period' => $month['period'],
                'label' => $month['label'],
                'headcount' => $this->headcountOn($month['to']),
                'joiners' => count($this->joinersBetween($month['from'], $month['to'])),
                'leavers' => count($this->leaversBetween($month['from'], $month['to'])),
            ];
        }

        return $trend;
    }

    /**
     * Attrition over a window, as a percentage of average headcount.
     *
     * Average rather than closing headcount is used deliberately: a company
     * that grew during the period would otherwise report a rate that flatters
     * it, because the same number of exits is divided by a larger denominator.
     */
    public function attritionRate(string $from, string $to): float
    {
        $average = ($this->headcountOn($from) + $this->headcountOn($to)) / 2;

        return Payload::percent((float) count($this->leaversBetween($from, $to)), $average);
    }

    /**
     * How long the current workforce has been here.
     *
     * @return list<array{key: string, label: string, value: int}>
     */
    public function tenureBands(): array
    {
        $bands = [
            'under_6m' => ['label' => 'Under 6 months', 'max' => 0.5],
            '6m_to_1y' => ['label' => '6 months to 1 year', 'max' => 1.0],
            '1y_to_3y' => ['label' => '1 to 3 years', 'max' => 3.0],
            '3y_to_5y' => ['label' => '3 to 5 years', 'max' => 5.0],
            'over_5y' => ['label' => 'Over 5 years', 'max' => null],
        ];

        $counts = array_fill_keys(array_keys($bands), 0);
        $today = Clock::today();

        foreach ($this->active() as $row) {
            $joined = Payload::text($row, ['joined_on', 'joining_date'], '');
            if ($joined === '' || $joined > $today) {
                continue;
            }

            $years = Clock::inclusiveDays($joined, $today) / 365.25;

            foreach ($bands as $key => $band) {
                if ($band['max'] === null || $years < $band['max']) {
                    $counts[$key]++;
                    break;
                }
            }
        }

        return array_map(
            static fn (string $key): array => [
                'key' => $key,
                'label' => $bands[$key]['label'],
                'value' => $counts[$key],
            ],
            array_keys($bands)
        );
    }

    /** Average tenure of the current workforce, in years. */
    public function averageTenureYears(): float
    {
        $today = Clock::today();
        $years = [];

        foreach ($this->active() as $row) {
            $joined = Payload::text($row, ['joined_on', 'joining_date'], '');
            if ($joined !== '' && $joined <= $today) {
                $years[] = Clock::inclusiveDays($joined, $today) / 365.25;
            }
        }

        return $years === [] ? 0.0 : Payload::round(array_sum($years) / count($years));
    }

    /**
     * Employee id => display name, for decorating rows that carry only an id.
     *
     * @return array<string, string>
     */
    public function nameIndex(): array
    {
        $index = [];

        foreach ($this->roster() ?? [] as $row) {
            $id = Payload::text($row, ['id', 'employee_id'], '');
            if ($id === '') {
                continue;
            }

            $fallback = trim(Payload::text($row, ['first_name']) . ' ' . Payload::text($row, ['last_name']));
            $index[$id] = Payload::text($row, ['full_name'], $fallback);
        }

        return $index;
    }

    /**
     * Employee id => the record itself, for reports that need several fields.
     *
     * @return array<string, array<string, mixed>>
     */
    public function index(): array
    {
        $index = [];

        foreach ($this->roster() ?? [] as $row) {
            $id = Payload::text($row, ['id', 'employee_id'], '');
            if ($id !== '') {
                $index[$id] = $row;
            }
        }

        return $index;
    }

    /**
     * Counts rows by a keyed dimension, carrying a readable label alongside.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $keyFields
     * @param list<string>               $labelFields
     * @return list<array{key: string, label: string, value: int}>
     */
    private function countBy(array $rows, array $keyFields, array $labelFields, string $fallback): array
    {
        $counts = [];
        $labels = [];

        foreach ($rows as $row) {
            $key = Payload::text($row, $keyFields, $fallback);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $labels[$key] = Payload::text($row, $labelFields, $fallback);
        }

        arsort($counts);

        return array_map(
            static fn (string $key): array => [
                'key' => $key,
                'label' => $labels[$key],
                'value' => $counts[$key],
            ],
            array_keys($counts)
        );
    }
}
