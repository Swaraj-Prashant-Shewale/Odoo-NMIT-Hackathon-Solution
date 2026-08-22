<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

final class Feedback extends Repository
{
    protected string $table = 'feedback';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'from_employee_id', 'to_employee_id', 'feedback_type', 'visibility',
        'subject', 'body', 'related_goal_id', 'is_anonymous', 'created_at',
    ];

    protected array $casts = ['is_anonymous' => 'bool'];

    /** Feedback is a moment in time: it is written once and never revised. */
    protected bool $timestamps = false;

    public function create(array $attributes): array
    {
        return parent::create($attributes + ['created_at' => Clock::iso()]);
    }

    /** @return array{received: int, sent: int, appreciation: int, constructive: int, request: int} */
    public function summaryFor(string $employeeId): array
    {
        $row = $this->rawOne(
            'SELECT
                COUNT(*) FILTER (WHERE to_employee_id = :recipient_id)                          AS received,
                COUNT(*) FILTER (WHERE from_employee_id = :sender_id)                            AS sent,
                COUNT(*) FILTER (WHERE to_employee_id = :appreciation_to AND feedback_type = :appreciation) AS appreciation,
                COUNT(*) FILTER (WHERE to_employee_id = :constructive_to AND feedback_type = :constructive) AS constructive,
                COUNT(*) FILTER (WHERE to_employee_id = :request_to AND feedback_type = :request)           AS request
             FROM feedback
             WHERE to_employee_id = :either_recipient OR from_employee_id = :either_sender',
            [
                'recipient_id' => $employeeId,
                'sender_id' => $employeeId,
                'appreciation_to' => $employeeId,
                'appreciation' => 'appreciation',
                'constructive_to' => $employeeId,
                'constructive' => 'constructive',
                'request_to' => $employeeId,
                'request' => 'request',
                'either_recipient' => $employeeId,
                'either_sender' => $employeeId,
            ]
        ) ?? [];

        return [
            'received' => (int) ($row['received'] ?? 0),
            'sent' => (int) ($row['sent'] ?? 0),
            'appreciation' => (int) ($row['appreciation'] ?? 0),
            'constructive' => (int) ($row['constructive'] ?? 0),
            'request' => (int) ($row['request'] ?? 0),
        ];
    }
}
