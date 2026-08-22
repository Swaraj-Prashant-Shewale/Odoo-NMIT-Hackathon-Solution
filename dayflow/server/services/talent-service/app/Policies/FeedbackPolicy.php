<?php

declare(strict_types=1);

namespace App\Policies;

use Dayflow\Kernel\Security\Principal;

/**
 * What a reader is allowed to learn from a piece of feedback.
 *
 * Anonymity here is a promise to the sender, not a hole in the record: the row
 * and the audit trail both keep the true author, so abuse can still be traced
 * by whoever is entitled to read the trail. This is the single place that
 * decides whether the name travels out over the API, and the answer is no for
 * every reader except the author.
 *
 * There is deliberately no permission that lifts this. An HR officer is often
 * the person anonymous feedback is *about* or addressed *to*, so a
 * "talent.view.all" escape hatch would unmask precisely the feedback anonymity
 * exists to protect.
 */
final class FeedbackPolicy
{
    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function present(array $item, Principal $principal): array
    {
        if (!(bool) ($item['is_anonymous'] ?? false)) {
            return $item;
        }

        $sender = isset($item['from_employee_id']) ? (string) $item['from_employee_id'] : '';

        // The sender obviously already knows who they are.
        if ($principal->owns($sender)) {
            return $item;
        }

        $item['from_employee_id'] = null;

        return $item;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function presentAll(array $items, Principal $principal): array
    {
        return array_map(
            static fn (array $item): array => self::present($item, $principal),
            $items
        );
    }

    /**
     * Feedback a manager is entitled to read about their reports.
     *
     * Private feedback stays between the two people involved, whatever the
     * reporting line looks like.
     *
     * @return list<string>
     */
    public static function managerVisibleLevels(): array
    {
        return ['manager', 'public'];
    }
}
