<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Principal;

/**
 * Splits the employee record into what a person may change about themselves
 * and what only HR may change.
 *
 * The division matters because the second group drives money and entitlement:
 * department, designation, manager, employment type and the joining dates are
 * all read by payroll and leave. Letting someone edit their own joined_on
 * would let them award themselves leave balance.
 *
 * A third group ends employment. Those columns are what the offboarding
 * endpoint writes, and the catalogue puts that behind employee.deactivate
 * rather than profile.edit.all, so they are gated on the same permission here.
 * Without that split an HR officer could retire somebody with a PATCH and walk
 * straight past the permission the platform reserved for it.
 *
 * An attempt to edit a field out of reach is refused outright rather than
 * quietly dropped. Silently ignoring part of a request tells the caller their
 * change succeeded when it did not.
 */
final class ProfileFieldPolicy
{
    /**
     * Fields an employee may maintain on their own record.
     *
     * @var array<string, string> Field => validation rule.
     */
    public const SELF_EDITABLE = [
        'phone' => 'nullable|phone|max:20',
        'alternate_phone' => 'nullable|phone|max:20',
        'personal_email' => 'nullable|email|max:150',
        'marital_status' => 'nullable|in:single,married,divorced,widowed,undisclosed',
        'blood_group' => 'nullable|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
        'address_line1' => 'nullable|safe_text|max:150',
        'address_line2' => 'nullable|safe_text|max:150',
        'city' => 'nullable|safe_text|max:80',
        'state' => 'nullable|safe_text|max:80',
        'country' => 'nullable|safe_text|max:80',
        'postal_code' => 'nullable|safe_text|max:20',
        'emergency_contact_name' => 'nullable|safe_text|max:120',
        'emergency_contact_phone' => 'nullable|phone|max:20',
        'emergency_contact_relation' => 'nullable|safe_text|max:60',
    ];

    /**
     * Fields only an HR role may change.
     *
     * @var array<string, string> Field => validation rule.
     */
    public const HR_ONLY = [
        'first_name' => 'nullable|safe_text|max:80',
        'last_name' => 'nullable|safe_text|max:80',
        'work_email' => 'nullable|email|max:150',
        'user_id' => 'nullable|uuid',
        'date_of_birth' => 'nullable|date|before_or_equal:today',
        'gender' => 'nullable|in:male,female,other,undisclosed',
        'department_id' => 'nullable|uuid',
        'designation_id' => 'nullable|uuid',
        'location_id' => 'nullable|uuid',
        'manager_id' => 'nullable|uuid',
        'employment_type' => 'nullable|in:full_time,part_time,contract,intern,consultant',
        'employment_status' => 'nullable|in:probation,confirmed,notice_period,resigned,terminated',
        'joined_on' => 'nullable|date',
        'probation_end_on' => 'nullable|date',
        'confirmed_on' => 'nullable|date',
        'notice_start_on' => 'nullable|date',
    ];

    /**
     * Fields that end somebody's employment.
     *
     * @var array<string, string> Field => validation rule.
     */
    public const DEACTIVATION_ONLY = [
        'exit_date' => 'nullable|date',
        'exit_reason' => 'nullable|safe_text|max:500',
        'is_active' => 'nullable|boolean',
    ];

    /**
     * Employment statuses that end employment as surely as clearing is_active.
     *
     * @var list<string>
     */
    public const TERMINAL_STATUSES = ['resigned', 'terminated'];

    /**
     * The rule set this caller is permitted to submit, for this record.
     *
     * @param list<string> $submittedFields Keys present in the request.
     * @return array<string, string>
     */
    public function rulesFor(Principal $principal, string $targetEmployeeId, array $submittedFields): array
    {
        if ($principal->can(Permissions::PROFILE_EDIT_ALL)) {
            if ($principal->can(Permissions::EMPLOYEE_DEACTIVATE)) {
                return self::SELF_EDITABLE + self::HR_ONLY + self::DEACTIVATION_ONLY;
            }

            $this->refuse(
                $submittedFields,
                self::DEACTIVATION_ONLY,
                'Ending somebody\'s employment needs the deactivation permission.'
            );

            return self::SELF_EDITABLE + self::HR_ONLY;
        }

        if (!$principal->can(Permissions::PROFILE_EDIT_SELF) || !$principal->owns($targetEmployeeId)) {
            throw HttpException::forbidden('You may only change your own profile.');
        }

        $this->refuse(
            $submittedFields,
            self::HR_ONLY + self::DEACTIVATION_ONLY,
            'Some of these fields can only be changed by an HR administrator.'
        );

        return self::SELF_EDITABLE;
    }

    /**
     * Refuses a status change that retires somebody.
     *
     * The field itself is HR's to maintain - probation to confirmed is routine
     * - but the two values that close employment belong with the rest of the
     * offboarding controls.
     *
     * @param array<string, mixed> $data Validated attributes.
     */
    public function assertStatusChangePermitted(Principal $principal, array $data): void
    {
        if (!isset($data['employment_status'])
            || !in_array((string) $data['employment_status'], self::TERMINAL_STATUSES, true)
            || $principal->can(Permissions::EMPLOYEE_DEACTIVATE)) {
            return;
        }

        throw new HttpException(
            403,
            'Ending somebody\'s employment needs the deactivation permission.',
            'forbidden',
            ['fields' => ['employment_status']]
        );
    }

    /**
     * @param list<string>          $submittedFields
     * @param array<string, string> $outOfReach
     */
    private function refuse(array $submittedFields, array $outOfReach, string $message): void
    {
        $restricted = array_values(array_intersect($submittedFields, array_keys($outOfReach)));

        if ($restricted === []) {
            return;
        }

        throw new HttpException(403, $message, 'forbidden', ['fields' => $restricted]);
    }
}
