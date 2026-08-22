<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Security;

/**
 * The authenticated caller behind the current request.
 *
 * Built from verified token claims only. Nothing here is ever read straight
 * from a request body or a client-supplied header, so a caller cannot promote
 * themselves by editing a form field.
 */
final class Principal
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function __construct(
        public readonly string $userId,
        public readonly ?string $employeeId,
        public readonly string $email,
        public readonly string $displayName,
        public readonly array $roles,
        public readonly array $permissions,
        public readonly ?string $departmentId = null,
        public readonly ?string $managerId = null,
        public readonly ?string $tokenId = null,
    ) {
    }

    /** @param array<string, mixed> $claims Verified JWT claims. */
    public static function fromClaims(array $claims): self
    {
        $roles = array_values(array_filter(
            (array) ($claims['roles'] ?? []),
            static fn (mixed $role): bool => is_string($role) && Roles::isValid($role)
        ));

        if ($roles === []) {
            $roles = [Roles::EMPLOYEE];
        }

        return new self(
            userId: (string) ($claims['sub'] ?? ''),
            employeeId: isset($claims['employee_id']) ? (string) $claims['employee_id'] : null,
            email: (string) ($claims['email'] ?? ''),
            displayName: (string) ($claims['name'] ?? ''),
            roles: $roles,
            // Permissions are always recomputed from the role catalogue rather
            // than trusted from the token, so a stale token cannot carry a
            // permission that has since been removed from its role.
            permissions: Roles::permissionsForAll($roles),
            departmentId: isset($claims['department_id']) ? (string) $claims['department_id'] : null,
            managerId: isset($claims['manager_id']) ? (string) $claims['manager_id'] : null,
            tokenId: isset($claims['jti']) ? (string) $claims['jti'] : null,
        );
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** True when the principal holds every one of the listed permissions. */
    public function canAll(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->can($permission)) {
                return false;
            }
        }

        return true;
    }

    /** True when the principal holds at least one of the listed permissions. */
    public function canAny(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function primaryRole(): string
    {
        return Roles::primary($this->roles);
    }

    public function roleLabel(): string
    {
        return Roles::label($this->primaryRole());
    }

    public function isAdministrative(): bool
    {
        return array_intersect($this->roles, Roles::administrative()) !== [];
    }

    /** True when the given employee record belongs to this principal. */
    public function owns(?string $employeeId): bool
    {
        return $employeeId !== null && $this->employeeId !== null && hash_equals($this->employeeId, $employeeId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'employee_id' => $this->employeeId,
            'email' => $this->email,
            'name' => $this->displayName,
            'roles' => $this->roles,
            'primary_role' => $this->primaryRole(),
            'role_label' => $this->roleLabel(),
            'permissions' => $this->permissions,
            'department_id' => $this->departmentId,
            'manager_id' => $this->managerId,
        ];
    }
}
