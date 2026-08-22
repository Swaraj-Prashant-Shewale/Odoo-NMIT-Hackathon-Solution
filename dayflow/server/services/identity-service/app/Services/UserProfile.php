<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserRoles;
use Dayflow\Kernel\Security\Roles;

/**
 * Shapes an account for the outside world.
 *
 * Roles are always attached from the database rather than from the caller's
 * token, so an administrator looking at somebody's record sees what that
 * person can do now, not what their last token happened to say.
 */
final class UserProfile
{
    private UserRoles $userRoles;

    public function __construct()
    {
        $this->userRoles = new UserRoles();
    }

    /**
     * @param array<string, mixed> $user Already presented by the repository.
     * @return array<string, mixed>
     */
    public function withRoles(array $user, ?array $roles = null): array
    {
        $roles = $roles ?? $this->userRoles->rolesFor((string) $user['id']);
        $primary = Roles::primary($roles);

        return $user + [
            'roles' => array_values($roles),
            'primary_role' => $primary,
            'role_label' => Roles::label($primary),
            'permissions' => Roles::permissionsForAll($roles),
        ];
    }

    /**
     * The same shape for a whole page, with one query for every account's roles.
     *
     * @param list<array<string, mixed>> $users
     * @return list<array<string, mixed>>
     */
    public function collection(array $users): array
    {
        $grouped = $this->userRoles->rolesForMany(
            array_map(static fn (array $user): string => (string) $user['id'], $users)
        );

        return array_map(
            fn (array $user): array => $this->withRoles($user, $grouped[(string) $user['id']] ?? []),
            $users
        );
    }
}
