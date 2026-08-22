<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;

/**
 * Validates values taken from the URL path.
 *
 * A path segment reaches a controller as an arbitrary string, so it goes
 * through the same scrutiny as a body field before it is used to look
 * anything up.
 */
final class RouteInput
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private function __construct()
    {
    }

    /**
     * A UUID from the path.
     *
     * A malformed identifier is answered with 404 rather than 422: the caller
     * asked for something that cannot exist, and saying so in more detail only
     * helps somebody probing the shape of our identifiers.
     */
    public static function uuid(Request $request, string $key = 'id'): string
    {
        $value = $request->route($key);

        if (preg_match(self::UUID, $value) !== 1) {
            throw HttpException::notFound();
        }

        return strtolower($value);
    }
}
