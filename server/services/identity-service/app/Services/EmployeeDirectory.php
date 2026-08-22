<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\ServiceClient;
use Dayflow\Kernel\Security\Jwt;
use Dayflow\Kernel\Support\Logger;

/**
 * Reads the person record behind an account.
 *
 * The access token carries department_id and manager_id so that every other
 * service can answer "is this person on my team?" without a lookup. Those two
 * values belong to employee-service, so they have to be fetched once, at
 * sign-in, and stamped into the token.
 *
 * That call is decoration, never a gate. If employee-service is restarting,
 * people must still be able to sign in; they simply get a token whose team
 * claims are null until they next authenticate.
 */
final class EmployeeDirectory
{
    /**
     * How long the token minted purely to make this one call stays valid.
     *
     * Employee-service applies its own rules to the request, so the call has to
     * carry a real credential for the person signing in. Keeping that credential
     * alive for seconds rather than the full session length means the extra
     * token is spent and worthless long before it could be useful to anyone.
     */
    private const LOOKUP_TOKEN_TTL = 20;

    /**
     * Team context for a person, or nulls when it cannot be established.
     *
     * @param array<string, mixed> $tokenClaims The claims already known about the
     *                                          account, minus the two being sought.
     * @return array{department_id: ?string, manager_id: ?string}
     */
    public static function teamContext(?string $employeeId, array $tokenClaims): array
    {
        $empty = ['department_id' => null, 'manager_id' => null];

        if ($employeeId === null || $employeeId === '') {
            return $empty;
        }

        $record = self::fetch($employeeId, self::lookupToken($tokenClaims));

        if ($record === null) {
            return $empty;
        }

        return [
            'department_id' => self::nullableString($record['department_id'] ?? null),
            'manager_id' => self::nullableString($record['manager_id'] ?? null),
        ];
    }

    /**
     * The canonical employee record, or null when it cannot be read.
     *
     * @return array<string, mixed>|null
     */
    public static function fetch(string $employeeId, ?string $bearerToken): ?array
    {
        try {
            $record = ServiceClient::for('employee', $bearerToken)
                ->tryGet('/employees/' . rawurlencode($employeeId));

            return is_array($record) ? $record : null;
        } catch (\Throwable $exception) {
            // Reaching here means the service address is not configured at all,
            // which tryGet cannot swallow because it happens before the call.
            Logger::warning('Employee lookup unavailable', [
                'employee_id' => $employeeId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Mints the short-lived credential used for the sign-in lookup.
     *
     * @param array<string, mixed> $claims
     */
    private static function lookupToken(array $claims): ?string
    {
        try {
            return Jwt::issue($claims + ['type' => 'access'], self::LOOKUP_TOKEN_TTL);
        } catch (\Throwable $exception) {
            Logger::warning('Could not mint lookup token', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
