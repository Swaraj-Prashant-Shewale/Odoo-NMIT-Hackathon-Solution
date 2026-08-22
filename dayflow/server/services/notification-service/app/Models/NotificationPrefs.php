<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;

/**
 * Per-account channel preferences, one row per category the account has
 * actually changed.
 *
 * The absence of a row means both channels are on, so a new joiner is reachable
 * from their first day without anything being seeded for them, and a category
 * introduced later is on by default for everybody.
 */
final class NotificationPrefs extends Repository
{
    protected string $table = 'notification_prefs';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'user_id', 'category', 'in_app_enabled', 'email_enabled',
    ];

    protected array $casts = [
        'in_app_enabled' => 'bool',
        'email_enabled' => 'bool',
    ];

    /**
     * Every stored preference for one account, keyed by category.
     *
     * @return array<string, array{in_app_enabled: bool, email_enabled: bool}>
     */
    public function forUser(string $userId): array
    {
        $rows = $this->raw(
            'SELECT category, in_app_enabled, email_enabled FROM notification_prefs WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        $byCategory = [];
        foreach ($rows as $row) {
            $byCategory[(string) $row['category']] = [
                'in_app_enabled' => filter_var($row['in_app_enabled'], FILTER_VALIDATE_BOOLEAN),
                'email_enabled' => filter_var($row['email_enabled'], FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $byCategory;
    }

    /** Creates or replaces the preference row for one account and category. */
    public function set(string $userId, string $category, bool $inApp, bool $email): array
    {
        $now = Clock::iso();

        $row = $this->rawOne(
            'INSERT INTO notification_prefs
                (id, user_id, category, in_app_enabled, email_enabled, created_at, updated_at)
             VALUES (:id, :user_id, :category, :in_app, :email, :created_at, :updated_at)
             ON CONFLICT (user_id, category) DO UPDATE
                SET in_app_enabled = EXCLUDED.in_app_enabled,
                    email_enabled  = EXCLUDED.email_enabled,
                    updated_at     = EXCLUDED.updated_at
             RETURNING *',
            [
                'id' => Str::uuid(),
                'user_id' => $userId,
                'category' => $category,
                'in_app' => $inApp ? 'true' : 'false',
                'email' => $email ? 'true' : 'false',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $row === null ? [] : $this->present($row);
    }
}
