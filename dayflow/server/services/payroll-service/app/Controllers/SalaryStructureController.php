<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\PayComponents;
use App\Models\SalaryStructureLines;
use App\Models\SalaryStructures;
use App\Policies\PayrollAccessPolicy;
use App\Services\EmployeeDirectory;
use App\Services\Period;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;
use Dayflow\Kernel\Validation\Validator;

/** Salary structures: what somebody is contracted to be paid, and since when. */
final class SalaryStructureController
{
    private SalaryStructures $structures;

    private SalaryStructureLines $lines;

    private PayComponents $components;

    public function __construct()
    {
        $this->structures = new SalaryStructures();
        $this->lines = new SalaryStructureLines();
        $this->components = new PayComponents();
    }

    /** The structure in force today for one employee. */
    public function current(Request $request): Response
    {
        $employeeId = $this->subjectFromRoute($request);

        $structure = $this->structures->effectiveOn($employeeId, Clock::today())
            ?? $this->structures->latestFor($employeeId);

        if ($structure === null) {
            throw HttpException::notFound('No salary structure has been recorded for this employee.');
        }

        return Response::ok($this->withLines($structure));
    }

    /** Every revision, newest first. */
    public function history(Request $request): Response
    {
        $employeeId = $this->subjectFromRoute($request);

        $revisions = array_map(
            fn (array $structure): array => $this->withLines($structure),
            $this->structures->historyFor($employeeId)
        );

        return Response::ok($revisions, ['total' => count($revisions), 'employee_id' => $employeeId]);
    }

    /**
     * Records a revision.
     *
     * The previous structure is closed the day before the new one starts, in
     * the same transaction that writes the new one, so the two can never both
     * be open and history is preserved instead of overwritten.
     */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'employee_id' => 'required|uuid',
            'effective_from' => 'required|date',
            'ctc_annual' => 'required|money|min:1',
            'gross_monthly' => 'required|money|min:1',
            'basic_monthly' => 'required|money|min:1',
            'currency' => 'nullable|string|min:3|max:3',
            'revision_reason' => 'nullable|safe_text|max:500',
        ])->validated();

        $principal = $request->principal();

        // Holding payroll.structure.edit says you may set what people are paid.
        // It does not say you may set what you are paid: the record would carry
        // the same account as author and approver, which is precisely what the
        // separation-of-duties rule on a payroll run refuses.
        if ($principal->owns($data['employee_id'])) {
            throw HttpException::forbidden(
                'A salary revision cannot be recorded by the person it pays. Ask another payroll administrator to record it.'
            );
        }

        $lines = $this->validateLines($request->array('lines'));

        if ($data['basic_monthly'] > $data['gross_monthly']) {
            throw HttpException::unprocessable('Basic pay cannot exceed the monthly gross.');
        }

        $this->assertEarningsBalance($lines, $data);

        // The employee_id arrived in the body rather than from the token, so
        // the person it names is confirmed to exist before pay is recorded
        // against them.
        $this->assertEmployeeExists($request, (string) $data['employee_id']);

        $previous = $this->structures->latestFor($data['employee_id']);

        $structure = Connection::transaction(function () use ($data, $lines, $principal): array {
            // Everything from here reads and then writes this employee's
            // structures, so no second revision for them may interleave.
            $this->structures->lockEmployee((string) $data['employee_id']);

            $latest = $this->structures->latestFor($data['employee_id']);

            if ($latest !== null && strcmp((string) $latest['effective_from'], $data['effective_from']) >= 0) {
                throw HttpException::conflict(
                    'A salary structure already exists on or after that date. Revisions must move forward in time.',
                    ['latest_effective_from' => $latest['effective_from']]
                );
            }

            $open = $this->structures->openFor($data['employee_id']);

            if ($open !== null) {
                $this->structures->closeOn((string) $open['id'], Period::dayBefore($data['effective_from']));
            }

            $created = $this->structures->create([
                'employee_id' => $data['employee_id'],
                'effective_from' => $data['effective_from'],
                'effective_to' => null,
                'ctc_annual_minor' => $data['ctc_annual'],
                'gross_monthly_minor' => $data['gross_monthly'],
                'basic_monthly_minor' => $data['basic_monthly'],
                'currency' => strtoupper((string) ($data['currency'] ?? 'INR')),
                'revision_reason' => $data['revision_reason'] ?? null,
                'created_by' => $principal->userId,
                'approved_by' => $principal->userId,
                'approved_at' => Clock::iso(),
            ]);

            foreach ($lines as $line) {
                $this->lines->create([
                    'id' => Str::uuid(),
                    'salary_structure_id' => (string) $created['id'],
                    'pay_component_id' => $line['pay_component_id'],
                    'amount_monthly_minor' => $line['amount_monthly_minor'],
                    'percentage' => $line['percentage'],
                    'created_at' => Clock::iso(),
                ]);
            }

            return $created;
        });

        AuditLog::record(
            $request,
            'payroll.salary.revised',
            'salary_structure',
            (string) $structure['id'],
            $previous ?? [],
            $structure
        );

        EventPublisher::publish('payroll.salary.revised', [
            'employee_id' => $data['employee_id'],
            'effective_from' => $data['effective_from'],
            'new_ctc_minor' => $data['ctc_annual'],
        ]);

        return Response::created($this->withLines($structure));
    }

    /**
     * Refuses a revision for somebody employee-service does not recognise.
     *
     * Only a definite "no such person" is treated as an answer. If the
     * directory cannot be reached at all, the revision is allowed through:
     * blocking finance from recording a pay rise because a peer is restarting
     * would be a worse failure than a structure that has to be tidied up.
     */
    private function assertEmployeeExists(Request $request, string $employeeId): void
    {
        $directory = new EmployeeDirectory($request->bearerToken());

        if (!$directory->isKnown($employeeId)) {
            throw HttpException::unprocessable(
                'No employee record exists for that identifier.',
                ['employee_id' => ['This person is not in the employee directory.']]
            );
        }
    }

    /** Self-service unless the caller may see everybody's pay. */
    private function subjectFromRoute(Request $request): string
    {
        $employeeId = strtolower($request->route('employee_id'));

        $validated = Validator::make(['employee_id' => $employeeId], [
            'employee_id' => 'required|uuid',
        ])->validated();

        $principal = $request->principal();

        if (!PayrollAccessPolicy::seesEveryone($principal) && !$principal->owns($validated['employee_id'])) {
            throw HttpException::forbidden('You may only view your own salary structure.');
        }

        return $validated['employee_id'];
    }

    /**
     * @param array<int|string, mixed> $submitted
     * @return list<array{pay_component_id: string, amount_monthly_minor: int, percentage: float|null, component: array<string, mixed>}>
     */
    private function validateLines(array $submitted): array
    {
        if ($submitted === []) {
            throw HttpException::unprocessable('A salary structure needs at least one pay component.');
        }

        $lines = [];
        $seen = [];

        foreach ($submitted as $index => $raw) {
            if (!is_array($raw)) {
                throw HttpException::unprocessable(sprintf('Component %d is not a valid entry.', (int) $index + 1));
            }

            $line = Validator::make($raw, [
                'pay_component_id' => 'required|uuid',
                'amount_monthly' => 'nullable|money',
                'percentage' => 'nullable|numeric|between:0,100',
            ])->validated();

            $componentId = (string) $line['pay_component_id'];

            if (isset($seen[$componentId])) {
                throw HttpException::unprocessable('The same pay component was listed twice.');
            }

            $component = $this->components->find($componentId);

            if ($component === null || $component['is_active'] !== true) {
                throw HttpException::unprocessable('One of the pay components does not exist or is no longer active.');
            }

            $percentage = $line['percentage'] ?? null;
            $calculation = (string) $component['calculation'];

            if (in_array($calculation, ['percent_of_basic', 'percent_of_ctc'], true) && $percentage === null) {
                $percentage = $component['percentage'];
            }

            $seen[$componentId] = true;

            $lines[] = [
                'pay_component_id' => $componentId,
                'amount_monthly_minor' => (int) ($line['amount_monthly'] ?? 0),
                'percentage' => $percentage === null ? null : (float) $percentage,
                'component' => $component,
            ];
        }

        return $lines;
    }

    /**
     * Refuses a structure whose earnings do not add up to its own gross.
     *
     * Left unchecked, the mismatch would surface months later as a payslip
     * that disagrees with the offer letter, which is far more expensive to
     * unpick than a rejected request.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed>       $data
     */
    private function assertEarningsBalance(array $lines, array $data): void
    {
        $basic = (int) $data['basic_monthly'];
        $ctcMonthly = intdiv((int) $data['ctc_annual'], 12);
        $earnings = 0;

        foreach ($lines as $line) {
            $component = $line['component'];

            if ((string) $component['component_type'] !== 'earning') {
                continue;
            }

            $earnings += match ((string) $component['calculation']) {
                'percent_of_basic' => (int) round(($basic * (float) $line['percentage']) / 100),
                'percent_of_ctc' => (int) round(($ctcMonthly * (float) $line['percentage']) / 100),
                'slab' => 0,
                default => (int) $line['amount_monthly_minor'],
            };
        }

        if ($earnings !== (int) $data['gross_monthly']) {
            throw HttpException::unprocessable(
                'The earning components do not add up to the monthly gross.',
                [
                    'gross_monthly_minor' => (int) $data['gross_monthly'],
                    'components_total_minor' => $earnings,
                    'difference_minor' => (int) $data['gross_monthly'] - $earnings,
                ]
            );
        }
    }

    /** @param array<string, mixed> $structure */
    private function withLines(array $structure): array
    {
        return $structure + ['lines' => $this->lines->forStructure((string) $structure['id'])];
    }
}
