<?php

declare(strict_types=1);

namespace App\Controllers;

use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Security\Roles;

/**
 * The access model, rendered for the administration screen.
 *
 * Nothing here reads the database, because none of it is data: roles and the
 * permissions behind them are declared in code precisely so that widening
 * somebody's access is a reviewed change rather than an UPDATE. This endpoint
 * simply reports what those declarations currently say, so the screen can
 * never drift out of step with what the platform actually enforces.
 */
final class RoleController
{
    /** The full role-to-permission matrix, with labels and inheritance resolved. */
    public function index(Request $request): Response
    {
        $matrix = Roles::matrix();
        $roles = [];

        foreach ($matrix as $role => $permissions) {
            $roles[] = [
                'role' => $role,
                'label' => Roles::label($role),
                'description' => Roles::description($role),
                'seniority' => (int) array_search($role, Roles::HIERARCHY, true),
                'is_administrative' => in_array($role, Roles::administrative(), true),
                'self_service' => in_array($role, Roles::selfServiceRoles(), true),
                'permission_count' => count($permissions),
                'permissions' => $permissions,
            ];
        }

        return Response::ok($roles, [
            'total' => count($roles),
            // What an anonymous visitor may choose during sign-up, so the
            // registration form and this service agree on one answer.
            'self_service_roles' => Roles::selfServiceRoles(),
        ]);
    }

    /** Every permission the platform knows about, grouped for display. */
    public function permissions(Request $request): Response
    {
        $groups = [];

        foreach (Permissions::groups() as $group => $permissions) {
            $groups[] = [
                'group' => $group,
                'permissions' => array_map(
                    static fn (string $permission): array => [
                        'key' => $permission,
                        'label' => Permissions::label($permission),
                        'held_by' => self::rolesHolding($permission),
                    ],
                    $permissions
                ),
            ];
        }

        return Response::ok($groups, ['total' => count(Permissions::all())]);
    }

    /**
     * Which roles end up with a permission once inheritance is resolved.
     *
     * @return list<string>
     */
    private static function rolesHolding(string $permission): array
    {
        $holders = [];

        foreach (Roles::matrix() as $role => $permissions) {
            if (in_array($permission, $permissions, true)) {
                $holders[] = $role;
            }
        }

        return $holders;
    }
}
