<?php

declare(strict_types=1);

namespace App\Services;

/**
 * How each domain event is presented once it becomes a notification.
 *
 * The wording of a message lives in the email_templates table, where HR can
 * edit it. What lives here is the part that is structural rather than
 * editorial: which category gates it, who it is addressed to, how loud it is,
 * and where clicking it should lead.
 *
 * Anything not listed still works. An unrecognised event falls back to a
 * neutral presentation and to whichever identifier its payload happens to
 * carry, so a service can start publishing something new without this one
 * having to be changed first.
 */
final class EventCatalogue
{
    /**
     * The categories a person can switch on or off.
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        'account' => 'Account and security',
        'people' => 'Profile and people records',
        'attendance' => 'Attendance and time',
        'leave' => 'Leave and time off',
        'payroll' => 'Payroll and expenses',
        'learning' => 'Learning and development',
        'talent' => 'Goals and reviews',
        'general' => 'General updates',
    ];

    /** Maps the first segment of an event name onto a category. */
    private const CATEGORY_BY_PREFIX = [
        'identity' => 'account',
        'employee' => 'people',
        'attendance' => 'attendance',
        'leave' => 'leave',
        'payroll' => 'payroll',
        'learning' => 'learning',
        'talent' => 'talent',
    ];

    /**
     * recipient_kind:
     *   user     - the payload carries the login account and its email address
     *   employee - the payload carries an employee id to look up
     *   none     - the event announces something company-wide and names nobody
     *
     * @var array<string, array<string, mixed>>
     */
    private const EVENTS = [
        'identity.account.registered' => [
            'category' => 'account',
            'recipient_kind' => 'user',
            'recipient_key' => 'user_id',
            'severity' => 'info',
            'icon' => 'user-plus',
            'action_url' => '{{app_url}}/verify-email?token={{verification_token}}',
            'email' => true,
        ],
        'identity.email.verified' => [
            'category' => 'account',
            'recipient_kind' => 'user',
            'recipient_key' => 'user_id',
            'severity' => 'success',
            'icon' => 'circle-check',
            'action_url' => '/dashboard',
            'email' => true,
        ],
        'identity.password.reset_requested' => [
            'category' => 'account',
            'recipient_kind' => 'user',
            'recipient_key' => 'user_id',
            'severity' => 'warning',
            'icon' => 'key',
            'action_url' => '{{app_url}}/reset-password?token={{reset_token}}',
            'email' => true,
        ],
        'identity.password.changed' => [
            'category' => 'account',
            'recipient_kind' => 'user',
            'recipient_key' => 'user_id',
            'severity' => 'success',
            'icon' => 'shield-halved',
            'action_url' => '/account/security',
            'email' => true,
        ],
        'identity.account.locked' => [
            'category' => 'account',
            'recipient_kind' => 'user',
            'recipient_key' => 'user_id',
            'severity' => 'critical',
            'icon' => 'lock',
            'action_url' => '/account/security',
            'email' => true,
        ],
        'employee.onboarded' => [
            'category' => 'people',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'success',
            'icon' => 'user-plus',
            'action_url' => '/profile',
            'email' => true,
        ],
        'employee.profile.updated' => [
            'category' => 'people',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'info',
            'icon' => 'user-pen',
            'action_url' => '/profile',
            'email' => true,
        ],
        'employee.document.expiring' => [
            'category' => 'people',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'warning',
            'icon' => 'file-circle-exclamation',
            'action_url' => '/documents',
            'email' => true,
        ],
        'employee.offboarded' => [
            'category' => 'people',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'info',
            'icon' => 'right-from-bracket',
            'action_url' => '/profile',
            'email' => true,
        ],
        // A punch in or out is worth a badge on the dashboard and nothing more.
        // Mailing somebody every morning about their own arrival is the fastest
        // way to teach the whole company to ignore mail from this platform.
        'attendance.checked_in' => [
            'category' => 'attendance',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'info',
            'icon' => 'right-to-bracket',
            'action_url' => '/attendance',
            'email' => false,
        ],
        'attendance.checked_out' => [
            'category' => 'attendance',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'info',
            'icon' => 'left-from-bracket',
            'action_url' => '/attendance',
            'email' => false,
        ],
        'attendance.absent_flagged' => [
            'category' => 'attendance',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'warning',
            'icon' => 'user-clock',
            'action_url' => '/attendance',
            'email' => true,
        ],
        // Raised requests are addressed to the approver named on the request,
        // not to the person who submitted it.
        'attendance.regularisation.raised' => [
            'category' => 'attendance',
            'recipient_kind' => 'employee',
            'recipient_key' => 'approver_id',
            'severity' => 'info',
            'icon' => 'clock-rotate-left',
            'action_url' => '/regularisations/{{request_id}}',
            'email' => true,
        ],
        'attendance.regularisation.decided' => [
            'category' => 'attendance',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'success',
            'icon' => 'clock-rotate-left',
            'action_url' => '/regularisations/{{request_id}}',
            'email' => true,
        ],
        'leave.request.submitted' => [
            'category' => 'leave',
            'recipient_kind' => 'employee',
            'recipient_key' => 'approver_id',
            'severity' => 'info',
            'icon' => 'calendar-plus',
            'action_url' => '/approvals/{{request_id}}',
            'email' => true,
        ],
        'leave.request.decided' => [
            'category' => 'leave',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'success',
            'icon' => 'calendar-check',
            'action_url' => '/leave/requests/{{request_id}}',
            'email' => true,
        ],
        'leave.request.cancelled' => [
            'category' => 'leave',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'info',
            'icon' => 'calendar-xmark',
            'action_url' => '/leave/requests/{{request_id}}',
            'email' => true,
        ],
        'leave.balance.adjusted' => [
            'category' => 'leave',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'info',
            'icon' => 'scale-balanced',
            'action_url' => '/leave-balances',
            'email' => true,
        ],
        // A completed payroll run belongs to the finance team as a whole and
        // names no individual, so it is recorded and left without a recipient.
        'payroll.run.approved' => [
            'category' => 'payroll',
            'recipient_kind' => 'none',
            'recipient_key' => null,
            'severity' => 'success',
            'icon' => 'money-check-dollar',
            'action_url' => '/payroll/runs/{{run_id}}',
            'email' => true,
        ],
        'payroll.payslip.published' => [
            'category' => 'payroll',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'success',
            'icon' => 'file-invoice-dollar',
            'action_url' => '/payslips/{{payslip_id}}',
            'email' => true,
        ],
        'payroll.salary.revised' => [
            'category' => 'payroll',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'success',
            'icon' => 'arrow-trend-up',
            'action_url' => '/payroll/salary',
            'email' => true,
        ],
        'payroll.expense.decided' => [
            'category' => 'payroll',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'info',
            'icon' => 'receipt',
            'action_url' => '/expenses/{{claim_id}}',
            'email' => true,
        ],
        'learning.course.assigned' => [
            'category' => 'learning',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'info',
            'icon' => 'graduation-cap',
            'action_url' => '/courses/{{course_id}}',
            'email' => true,
        ],
        'learning.course.completed' => [
            'category' => 'learning',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'success',
            'icon' => 'award',
            'action_url' => '/courses/{{course_id}}',
            'email' => true,
        ],
        'talent.review.opened' => [
            'category' => 'talent',
            'recipient_kind' => 'employee',
            'recipient_key' => 'reviewer_id',
            'severity' => 'info',
            'icon' => 'clipboard-list',
            'action_url' => '/reviews/{{cycle_id}}',
            'email' => true,
        ],
        'talent.review.submitted' => [
            'category' => 'talent',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'success',
            'icon' => 'clipboard-check',
            'action_url' => '/reviews/{{cycle_id}}',
            'email' => true,
        ],
        // Goal progress moves often; the feed carries it, mail would bury it.
        'talent.goal.updated' => [
            'category' => 'talent',
            'recipient_kind' => 'employee',
            'recipient_key' => 'employee_id',
            'severity' => 'info',
            'icon' => 'bullseye',
            'action_url' => '/goals/{{goal_id}}',
            'email' => false,
        ],
    ];

    /**
     * The presentation rules for one event, with defaults filled in.
     *
     * @param array<string, mixed> $payload
     * @return array{category: string, recipient_kind: string, recipient_key: ?string,
     *               severity: string, icon: string, action_url: ?string, email: bool}
     */
    public static function for(string $eventName, array $payload = []): array
    {
        $definition = self::EVENTS[$eventName] ?? null;

        if ($definition !== null) {
            return [
                'category' => (string) $definition['category'],
                'recipient_kind' => (string) $definition['recipient_kind'],
                'recipient_key' => $definition['recipient_key'] === null ? null : (string) $definition['recipient_key'],
                'severity' => (string) $definition['severity'],
                'icon' => (string) $definition['icon'],
                'action_url' => $definition['action_url'] === null ? null : (string) $definition['action_url'],
                'email' => (bool) $definition['email'],
            ];
        }

        return [
            'category' => self::categoryFor($eventName),
            'recipient_kind' => self::inferRecipientKind($payload),
            'recipient_key' => isset($payload['employee_id']) ? 'employee_id' : 'user_id',
            'severity' => 'info',
            'icon' => 'bell',
            'action_url' => null,
            'email' => true,
        ];
    }

    /** @return array<string, string> Category key => human readable label. */
    public static function categories(): array
    {
        return self::CATEGORIES;
    }

    public static function isCategory(string $category): bool
    {
        return array_key_exists($category, self::CATEGORIES);
    }

    /** @return list<string> Every event name a template is expected for. */
    public static function eventNames(): array
    {
        return array_keys(self::EVENTS);
    }

    private static function categoryFor(string $eventName): string
    {
        $prefix = explode('.', $eventName)[0];

        return self::CATEGORY_BY_PREFIX[$prefix] ?? 'general';
    }

    /** @param array<string, mixed> $payload */
    private static function inferRecipientKind(array $payload): string
    {
        if (isset($payload['employee_id'])) {
            return 'employee';
        }

        return isset($payload['user_id']) ? 'user' : 'none';
    }
}
