<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Departments;
use App\Models\Designations;
use App\Models\Employees;
use App\Models\Locations;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Validation\ValidationException;

/**
 * Checks that the organisation references on an employee record point at
 * something real before the write is attempted.
 *
 * The foreign keys would catch these anyway, but a constraint violation
 * surfaces as a 500 with a database message. Checking first turns the same
 * mistake into a 422 that names the offending field.
 */
final class ReferenceGuard
{
    public function __construct(
        private readonly Departments $departments,
        private readonly Designations $designations,
        private readonly Locations $locations,
        private readonly Employees $employees,
    ) {
    }

    /**
     * @param array<string, mixed> $data Validated attributes.
     */
    public function assertOrganisationReferences(array $data): void
    {
        $errors = [];

        if (!empty($data['department_id']) && $this->departments->find((string) $data['department_id']) === null) {
            $errors['department_id'] = ['That department does not exist.'];
        }

        if (!empty($data['designation_id']) && $this->designations->find((string) $data['designation_id']) === null) {
            $errors['designation_id'] = ['That designation does not exist.'];
        }

        if (!empty($data['location_id']) && $this->locations->find((string) $data['location_id']) === null) {
            $errors['location_id'] = ['That location does not exist.'];
        }

        if ($errors !== []) {
            throw new ValidationException('Some of the information supplied is not valid.', $errors);
        }
    }

    /**
     * Resolves a proposed manager, rejecting a choice that would close a loop
     * in the reporting line.
     *
     * @return array<string, mixed>|null The manager record, or null when the
     *                                   employee is to have no manager.
     */
    public function assertManagerAssignment(?string $managerId, ?string $employeeId): ?array
    {
        if ($managerId === null || $managerId === '') {
            return null;
        }

        $manager = $this->employees->find($managerId);

        if ($manager === null) {
            throw new ValidationException('Some of the information supplied is not valid.', [
                'manager_id' => ['That manager does not exist.'],
            ]);
        }

        if ($employeeId !== null && $this->employees->wouldCreateReportingCycle($employeeId, $managerId)) {
            throw HttpException::conflict(
                'That reporting line would make this person their own manager.',
                ['manager_id' => $managerId]
            );
        }

        return $manager;
    }
}
