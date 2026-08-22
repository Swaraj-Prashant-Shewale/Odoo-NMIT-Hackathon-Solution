<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * The message body for each domain event, keyed by event name.
 *
 * The template table is what decides whether an event produces anything at
 * all: an event with no row here is acknowledged and dropped, so a service can
 * start publishing something new without this one being redeployed first.
 */
final class EmailTemplates extends Repository
{
    protected string $table = 'email_templates';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'event_name', 'subject', 'body_html', 'body_text', 'is_active',
    ];

    protected array $casts = ['is_active' => 'bool'];

    public function activeFor(string $eventName): ?array
    {
        $row = $this->rawOne(
            'SELECT * FROM email_templates WHERE event_name = :event_name AND is_active = TRUE',
            ['event_name' => $eventName]
        );

        return $row === null ? null : $this->present($row);
    }
}
