<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;

/**
 * Reads a record identifier out of the URL.
 *
 * Every primary key in this schema is a UUID column, so a path segment that is
 * not one can never match a row. Checking the shape here turns that into an
 * ordinary 404 instead of letting PostgreSQL raise a type error, which would
 * surface as a 500 and put a fragment of a query into the log.
 */
final class RouteId
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private function __construct()
    {
    }

    public static function of(Request $request, string $key = 'id'): string
    {
        $value = $request->route($key);

        if (preg_match(self::UUID, $value) !== 1) {
            throw HttpException::notFound();
        }

        return strtolower($value);
    }
}
