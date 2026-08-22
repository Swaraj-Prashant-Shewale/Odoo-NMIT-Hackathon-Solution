<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Turns a payroll run into a cost per department.
 *
 * Payroll stores an employee id and nothing else about a person, so the
 * department a cost belongs to only exists in the roster. Joining the two is
 * this class's whole job, and it is done here rather than twice over in the
 * dashboard and the analytics endpoint.
 *
 * Only the aggregate leaves: individual payslips are read to be added up and
 * are never returned. The caller already holds payroll.view.all - the route
 * requires it and the payroll service enforces it again - so nothing here
 * widens what they could see, it only narrows what they are shown.
 */
final class PayrollCost
{
    /**
     * @param array<string, mixed>|null $run A run record carrying its payslips.
     * @return list<array<string, mixed>>|null Null when the split cannot be produced.
     */
    public static function byDepartment(?array $run, EmployeeDirectory $directory): ?array
    {
        if ($run === null || !$directory->available()) {
            return null;
        }

        $payslips = Payload::rows($run['payslips'] ?? []);

        if ($payslips === []) {
            return null;
        }

        $people = $directory->index();
        $departments = [];

        foreach ($payslips as $payslip) {
            $person = $people[Payload::text($payslip, ['employee_id'], '')] ?? [];
            $key = Payload::text($person, ['department_id'], 'unassigned');

            $departments[$key] ??= [
                'department_id' => $key === 'unassigned' ? '' : $key,
                'department_name' => Payload::text($person, ['department_name', 'department'], 'Unassigned'),
                'employee_count' => 0,
                'gross_minor' => 0,
                'net_minor' => 0,
            ];

            $departments[$key]['employee_count']++;
            $departments[$key]['gross_minor'] += Payload::int($payslip, ['gross_minor', 'total_earnings_minor']);
            $departments[$key]['net_minor'] += Payload::int($payslip, ['net_minor', 'net_pay_minor']);
        }

        $rows = array_values($departments);

        usort($rows, static fn (array $a, array $b): int => $b['net_minor'] <=> $a['net_minor']);

        return $rows;
    }
}
