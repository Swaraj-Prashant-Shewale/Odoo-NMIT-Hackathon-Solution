<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Security\Principal;

/**
 * Who may touch a single notification.
 *
 * There is no administrative view of somebody else's feed and no permission
 * that grants one. A notification is a private message, and the only person
 * entitled to read, dismiss or delete it is the person it was addressed to.
 */
final class NotificationPolicy
{
    private function __construct()
    {
    }

    /** @param array<string, mixed> $notification */
    public static function belongsTo(Principal $principal, array $notification): bool
    {
        $employeeId = $notification['employee_id'] ?? null;
        $userId = $notification['user_id'] ?? null;

        if (is_string($employeeId) && $principal->employeeId !== null
            && hash_equals($principal->employeeId, $employeeId)) {
            return true;
        }

        return is_string($userId) && hash_equals($principal->userId, $userId);
    }

    /**
     * Returns the notification, or refuses.
     *
     * A notification belonging to somebody else is reported as missing rather
     * than forbidden: confirming that a particular id exists would leak the
     * shape of another person's feed to anyone willing to guess.
     *
     * @param array<string, mixed>|null $notification
     * @return array<string, mixed>
     */
    public static function assertBelongsTo(Principal $principal, ?array $notification): array
    {
        if ($notification === null || !self::belongsTo($principal, $notification)) {
            throw HttpException::notFound('This notification does not exist.');
        }

        return $notification;
    }
}
