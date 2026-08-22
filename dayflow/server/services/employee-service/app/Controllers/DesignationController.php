<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Departments;
use App\Models\Designations;
use App\Models\Employees;
use App\Services\RecordId;
use App\Services\RequiredColumns;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Validation\ValidationException;
use Dayflow\Kernel\Validation\Validator;

/**
 * Job titles. Level carries seniority so a numeric comparison answers
 * questions such as "who outranks whom" without parsing the title.
 */
final class DesignationController
{
    private Designations $designations;
    private Departments $departments;
    private Employees $employees;

    public function __construct()
    {
        $this->designations = new Designations();
        $this->departments = new Departments();
        $this->employees = new Employees();
    }

    public function index(Request $request): Response
    {
        return Response::ok($this->designations->withDepartment());
    }

    public function show(Request $request): Response
    {
        $designation = $this->requireDesignation($request->route('id'));

        return Response::ok($designation + [
            'employee_count' => $this->employees->countWithDesignation((string) $designation['id']),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request);

        if ($this->designations->findByCode((string) $data['code']) !== null) {
            throw HttpException::conflict('A designation already uses that code.');
        }

        $this->assertDepartment($data);

        $designation = $this->designations->create($data);

        AuditLog::record($request, 'designation.created', 'designation', $designation['id'], [], $designation);

        return Response::created($designation);
    }

    public function update(Request $request): Response
    {
        $before = $this->requireDesignation($request->route('id'));
        $data = $this->validate($request, false);

        if ($data === []) {
            throw HttpException::badRequest('No changes were supplied.');
        }

        if (isset($data['code'])) {
            $clash = $this->designations->findByCode((string) $data['code']);

            if ($clash !== null && (string) $clash['id'] !== (string) $before['id']) {
                throw HttpException::conflict('A designation already uses that code.');
            }
        }

        $this->assertDepartment($data);

        $after = $this->designations->update((string) $before['id'], $data);

        if ($after === null) {
            throw HttpException::notFound('That designation does not exist.');
        }

        AuditLog::record($request, 'designation.updated', 'designation', $after['id'], $before, $after);

        return Response::ok($after);
    }

    public function destroy(Request $request): Response
    {
        $designation = $this->requireDesignation($request->route('id'));
        $id = (string) $designation['id'];

        $headcount = $this->employees->countWithDesignation($id);

        if ($headcount > 0) {
            throw HttpException::conflict(
                'People still hold that designation. Reassign them before deleting it.',
                ['employee_count' => $headcount]
            );
        }

        // Archived people keep their designation, and the key is ON DELETE
        // RESTRICT, so the database would refuse the delete after the live
        // headcount said it was safe.
        $archived = $this->employees->countReferencingDesignation($id) - $headcount;

        if ($archived > 0) {
            throw HttpException::conflict(
                'Archived person records still hold that designation, so it cannot be removed.',
                ['archived_employee_count' => $archived]
            );
        }

        $this->designations->delete($id);

        AuditLog::record($request, 'designation.deleted', 'designation', $id, $designation, []);

        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function validate(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'nullable';

        $data = Validator::make($request->all(), [
            'title' => $required . '|safe_text|max:100',
            'code' => $required . '|alpha_num|max:20',
            'level' => 'nullable|integer|between:1,20',
            'department_id' => 'nullable|uuid',
            'description' => 'nullable|safe_text|max:500',
            'is_active' => 'nullable|boolean',
        ])->validated();

        if (isset($data['code'])) {
            $data['code'] = strtoupper((string) $data['code']);
        }

        return RequiredColumns::stripNulls($data, ['title', 'code', 'level', 'is_active']);
    }

    /** @param array<string, mixed> $data */
    private function assertDepartment(array $data): void
    {
        if (!empty($data['department_id']) && $this->departments->find((string) $data['department_id']) === null) {
            throw new ValidationException('Some of the information supplied is not valid.', [
                'department_id' => ['That department does not exist.'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function requireDesignation(string $id): array
    {
        $id = RecordId::orNull($id);
        $designation = $id === null ? null : $this->designations->find($id);

        if ($designation === null) {
            throw HttpException::notFound('That designation does not exist.');
        }

        return $designation;
    }
}
