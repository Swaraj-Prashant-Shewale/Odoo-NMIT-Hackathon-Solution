<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Recognises the identifiers this service uses as primary keys.
 *
 * Every record here is keyed by a UUID, and PostgreSQL answers a comparison
 * against anything else with a type error rather than an empty result. A
 * request for "/employees/me", or a token whose employee_id claim is an empty
 * string, would therefore surface as a 500 carrying a database message instead
 * of the 404 or the empty page the caller should see. Checking the shape first
 * keeps those paths honest.
 */
final class RecordId
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function isUuid(?string $value): bool
    {
        return $value !== null && preg_match(self::PATTERN, $value) === 1;
    }

    /** The value when it is a usable identifier, otherwise null. */
    public static function orNull(?string $value): ?string
    {
        return self::isUuid($value) ? strtolower((string) $value) : null;
    }
}
