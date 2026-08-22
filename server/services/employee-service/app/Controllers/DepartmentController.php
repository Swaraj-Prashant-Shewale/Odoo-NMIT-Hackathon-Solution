<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Departments;
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
 * Departments, arranged as a real hierarchy through parent_id.
 */
final class DepartmentController
{
    private Departments $departments;
    private Employees $employees;

    public function __construct()
    {
        $this->departments = new Departments();
        $this->employees = new Employees();
    }

    public function index(Request $request): Response
    {
        return Response::ok($this->departments->withHeadcount());
    }

    public function show(Request $request): Response
    {
        $department = $this->requireDepartment($request->route('id'));

        return Response::ok($department + [
            'employee_count' => $this->employees->countInDepartment((string) $department['id']),
            'has_children' => $this->departments->hasChildren((string) $department['id']),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request);

        if ($this->departments->findByCode((string) $data['code']) !== null) {
            throw HttpException::conflict('A department already uses that code.');
        }

        if ($this->departments->findByName((string) $data['name']) !== null) {
            throw HttpException::conflict('A department already uses that name.');
        }

        $this->assertReferences($data, null);

        $department = $this->departments->create($data);

        AuditLog::record($request, 'department.created', 'department', $department['id'], [], $department);

        return Response::created($department);
    }

    public function update(Request $request): Response
    {
        $before = $this->requireDepartment($request->route('id'));
        $data = $this->validate($request, false);

        if ($data === []) {
            throw HttpException::badRequest('No changes were supplied.');
        }

        if (isset($data['code'])) {
            $clash = $this->departments->findByCode((string) $data['code']);

            if ($clash !== null && (string) $clash['id'] !== (string) $before['id']) {
                throw HttpException::conflict('A department already uses that code.');
            }
        }

        if (isset($data['name'])) {
            $clash = $this->departments->findByName((string) $data['name']);

            if ($clash !== null && (string) $clash['id'] !== (string) $before['id']) {
                throw HttpException::conflict('A department already uses that name.');
            }
        }

        $this->assertReferences($data, (string) $before['id']);

        $after = $this->departments->update((string) $before['id'], $data);

        if ($after === null) {
            throw HttpException::notFound('That department does not exist.');
        }

        AuditLog::record($request, 'department.updated', 'department', $after['id'], $before, $after);

        return Response::ok($after);
    }

    public function destroy(Request $request): Response
    {
        $department = $this->requireDepartment($request->route('id'));
        $id = (string) $department['id'];

        // Removing a department that still holds people would leave those
        // records pointing at nothing, so the caller is told to move them
        // first rather than having the platform decide where they go.
        $headcount = $this->employees->countInDepartment($id);

        if ($headcount > 0) {
            throw HttpException::conflict(
                'That department still has people in it. Move them before deleting it.',
                ['employee_count' => $headcount]
            );
        }

        // Archived people keep their department, and the key is ON DELETE
        // RESTRICT, so the database would refuse the delete after the live
        // headcount said it was safe.
        $archived = $this->employees->countReferencingDepartment($id) - $headcount;

        if ($archived > 0) {
            throw HttpException::conflict(
                'Archived person records still belong to that department, so it cannot be removed.',
                ['archived_employee_count' => $archived]
            );
        }

        if ($this->departments->hasChildren($id)) {
            throw HttpException::conflict('That department still has sub-departments beneath it.');
        }

        $this->departments->delete($id);

        AuditLog::record($request, 'department.deleted', 'department', $id, $department, []);

        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function validate(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'nullable';

        $data = Validator::make($request->all(), [
            'name' => $required . '|safe_text|max:100',
            'code' => $required . '|alpha_num|max:20',
            'description' => 'nullable|safe_text|max:500',
            'parent_id' => 'nullable|uuid',
            'head_employee_id' => 'nullable|uuid',
            'cost_centre' => 'nullable|safe_text|max:40',
            'is_active' => 'nullable|boolean',
        ])->validated();

        if (isset($data['code'])) {
            // Codes are compared case-insensitively in the database, so they
            // are stored in one casing to keep listings tidy.
            $data['code'] = strtoupper((string) $data['code']);
        }

        return RequiredColumns::stripNulls($data, ['name', 'code', 'is_active']);
    }

    /** @param array<string, mixed> $data */
    private function assertReferences(array $data, ?string $departmentId): void
    {
        if (!empty($data['head_employee_id']) && $this->employees->find((string) $data['head_employee_id']) === null) {
            throw new ValidationException('Some of the information supplied is not valid.', [
                'head_employee_id' => ['That person does not exist.'],
            ]);
        }

        if (empty($data['parent_id'])) {
            return;
        }

        $parentId = (string) $data['parent_id'];

        if ($this->departments->find($parentId) === null) {
            throw new ValidationException('Some of the information supplied is not valid.', [
                'parent_id' => ['That parent department does not exist.'],
            ]);
        }

        if ($departmentId !== null && ($parentId === $departmentId || $this->departments->wouldCycle($departmentId, $parentId))) {
            throw HttpException::conflict('That parent would make the department its own ancestor.');
        }
    }

    /** @return array<string, mixed> */
    private function requireDepartment(string $id): array
    {
        $id = RecordId::orNull($id);
        $department = $id === null ? null : $this->departments->find($id);

        if ($department === null) {
            throw HttpException::notFound('That department does not exist.');
        }

        return $department;
    }
}
