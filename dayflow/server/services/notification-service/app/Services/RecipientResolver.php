<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\ServiceClient;
use Dayflow\Kernel\Support\Logger;

/**
 * Works out who a domain event is for and how to reach them.
 *
 * Only two identifiers ever cross a service boundary, so a payload names either
 * a login account or an employee. The identity events carry the address with
 * them, which they must: a welcome or password-reset message is sent before its
 * recipient can sign in, and there is no employee record to look up yet. Every
 * other event names an employee, and the address comes from employee-service.
 */
final class RecipientResolver
{
    /** @var array<string, array<string, mixed>|null> */
    private array $employeeCache = [];

    public function __construct(private readonly ?string $bearerToken)
    {
    }

    /**
     * @param array{recipient_kind: string, recipient_key: ?string} $definition
     * @param array<string, mixed>                                  $payload
     * @return array{employee_id: ?string, user_id: ?string, name: string,
     *               first_name: string, email: ?string, resolved: bool}
     */
    public function resolve(array $definition, array $payload): array
    {
        $blank = [
            'employee_id' => null,
            'user_id' => null,
            'name' => '',
            'first_name' => '',
            'email' => null,
            'resolved' => false,
        ];

        $key = $definition['recipient_key'];

        if ($definition['recipient_kind'] === 'none' || $key === null) {
            return $blank;
        }

        $identifier = $payload[$key] ?? null;
        if (!is_string($identifier) || $identifier === '') {
            return $blank;
        }

        if ($definition['recipient_kind'] === 'user') {
            $email = isset($payload['email']) && is_string($payload['email']) ? $payload['email'] : null;
            $first = isset($payload['first_name']) && is_string($payload['first_name']) ? $payload['first_name'] : '';
            $last = isset($payload['last_name']) && is_string($payload['last_name']) ? $payload['last_name'] : '';
            $name = trim($first . ' ' . $last);

            return [
                'employee_id' => isset($payload['employee_id']) && is_string($payload['employee_id'])
                    ? $payload['employee_id']
                    : null,
                'user_id' => $identifier,
                'name' => $name !== '' ? $name : self::nameFromEmail($email),
                'first_name' => $first !== '' ? $first : self::nameFromEmail($email),
                'email' => $email,
                'resolved' => true,
            ];
        }

        $employee = $this->employee($identifier);

        if ($employee === null) {
            // The in-app notification is still worth writing: the feed is keyed
            // by employee id and does not need a name or an address.
            return [
                'employee_id' => $identifier,
                'user_id' => self::accountFrom($payload, []),
                'name' => '',
                'first_name' => '',
                'email' => null,
                'resolved' => true,
            ];
        }

        $fullName = trim((string) ($employee['full_name'] ?? ''));
        $firstName = trim((string) ($employee['first_name'] ?? ''));
        $email = $employee['work_email'] ?? null;

        return [
            'employee_id' => $identifier,
            'user_id' => self::accountFrom($payload, $employee),
            'name' => $fullName !== '' ? $fullName : $firstName,
            'first_name' => $firstName !== '' ? $firstName : $fullName,
            'email' => is_string($email) && $email !== '' ? $email : null,
            'resolved' => true,
        ];
    }

    /**
     * Reads one employee record.
     *
     * Decoration, not a dependency: an event that cannot be enriched still
     * becomes a notification, so the lookup is allowed to fail quietly. A batch
     * of events for the same person is common enough to be worth caching within
     * the request.
     *
     * @return array<string, mixed>|null
     */
    private function employee(string $employeeId): ?array
    {
        if (array_key_exists($employeeId, $this->employeeCache)) {
            return $this->employeeCache[$employeeId];
        }

        $record = null;

        try {
            $result = ServiceClient::for('employee', $this->bearerToken)
                ->tryGet('/employees/' . rawurlencode($employeeId));

            if (is_array($result)) {
                $record = $result;
            }
        } catch (\Throwable $exception) {
            Logger::warning('Recipient lookup failed', [
                'employee_id' => $employeeId,
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->employeeCache[$employeeId] = $record;
    }

    /**
     * The login account behind an employee-addressed event, where one is known.
     *
     * Channel preferences are stored against the account, so finding it is what
     * decides whether somebody's choice to mute a category is honoured. The
     * canonical employee record does not promise a user_id, so the payload is
     * asked first and the record only consulted if it happens to carry one.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $employee
     */
    private static function accountFrom(array $payload, array $employee): ?string
    {
        foreach ([$payload['user_id'] ?? null, $employee['user_id'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /** A usable greeting when all that is known about somebody is an address. */
    private static function nameFromEmail(?string $email): string
    {
        if ($email === null || !str_contains($email, '@')) {
            return '';
        }

        $local = explode('@', $email, 2)[0];
        $local = str_replace(['.', '_', '-'], ' ', $local);

        return ucwords(trim($local));
    }
}
