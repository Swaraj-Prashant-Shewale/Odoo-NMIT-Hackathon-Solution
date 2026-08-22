<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Security\Principal;

/** Shared checks every talent endpoint starts from. */
final class TalentAccess
{
    /**
     * A record identifier taken from the path.
     *
     * Every primary key in this schema is a UUID, and PostgreSQL raises a type
     * error rather than returning no rows when something else is compared
     * against one. Checking the shape here turns "/goals/not-a-uuid" into the
     * 404 it should always have been instead of an internal error.
     */
    public static function recordId(Request $request, string $key = 'id'): string
    {
        $value = $request->route($key);

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw HttpException::notFound('The requested record does not exist.');
        }

        return strtolower($value);
    }

    /**
     * The caller's employee identifier.
     *
     * Every record in this service belongs to a person, so an account that has
     * not been linked to an employee record yet has nothing here to reach.
     */
    public static function employeeId(Principal $principal): string
    {
        if ($principal->employeeId === null || $principal->employeeId === '') {
            throw HttpException::forbidden('This account is not linked to an employee record yet.');
        }

        return $principal->employeeId;
    }
}
