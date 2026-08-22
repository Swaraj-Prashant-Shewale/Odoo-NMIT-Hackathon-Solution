<?php

declare(strict_types=1);

/**
 * Reference and demonstration data for the notification service.
 *
 * Runs on every boot, so everything here is written to be repeatable: the
 * email templates are inserted only where an event has none, and every demo
 * record carries an identifier derived from a fixed key rather than a fresh
 * random one, which is what stops a restart from filling the notice board with
 * copies of itself.
 */

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Str;

$pdo = Connection::pdo();
$now = Clock::iso();

// ---------------------------------------------------------------------------
// The HTML shell every message shares.
//
// One layout keeps the whole platform recognisable and, more usefully, keeps
// the styling inline: mail clients discard a stylesheet without asking.
// ---------------------------------------------------------------------------

/** @var callable(string, list<string>, ?array{label: string, href: string}): string $layout */
$layout = static function (string $heading, array $paragraphs, ?array $action = null): string {
    $body = '';

    foreach ($paragraphs as $paragraph) {
        $body .= '        <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#3e4c59;">'
            . $paragraph . "</p>\n";
    }

    if ($action !== null) {
        $body .= '        <p style="margin:24px 0;"><a href="' . $action['href']
            . '" style="display:inline-block;padding:12px 22px;background:#2f6feb;color:#ffffff;'
            . 'text-decoration:none;border-radius:6px;font-size:15px;font-weight:600;">'
            . $action['label'] . "</a></p>\n";
    }

    return '<div style="margin:0;padding:24px;background:#f4f5f7;'
        . "font-family:'Segoe UI',Helvetica,Arial,sans-serif;\">\n"
        . '  <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:10px;padding:32px;">' . "\n"
        . '    <p style="margin:0 0 20px;font-size:12px;letter-spacing:0.09em;text-transform:uppercase;'
        . 'color:#7b8794;">{{company_name}}</p>' . "\n"
        . '    <h1 style="margin:0 0 18px;font-size:21px;line-height:1.3;color:#1f2933;">'
        . $heading . "</h1>\n"
        . $body
        . '    <p style="margin:28px 0 0;padding-top:16px;border-top:1px solid #e4e7eb;font-size:12px;'
        . 'line-height:1.6;color:#7b8794;">You are receiving this because you have a {{company_name}} '
        . 'account. You can choose what reaches you under Settings &rsaquo; Notifications.</p>' . "\n"
        . "  </div>\n</div>\n";
};

/** @var callable(string, list<string>, ?array{label: string, href: string}): string $plain */
$plain = static function (string $heading, array $paragraphs, ?array $action = null): string {
    $lines = [$heading, ''];

    foreach ($paragraphs as $paragraph) {
        $lines[] = $paragraph;
        $lines[] = '';
    }

    if ($action !== null) {
        $lines[] = $action['label'] . ': ' . $action['href'];
        $lines[] = '';
    }

    $lines[] = 'The {{company_name}} team';

    return implode("\n", $lines);
};

// ---------------------------------------------------------------------------
// One template per event in the platform's published catalogue.
// ---------------------------------------------------------------------------

$templates = [
    'identity.account.registered' => [
        'subject' => 'Welcome to {{company_name}}: please confirm your email address',
        'heading' => 'Welcome aboard, {{first_name}}',
        'paragraphs' => [
            'Your {{company_name}} account has been created. Confirming your email address is the last step before you can sign in.',
            'If the button does not work, copy this address into your browser instead: {{app_url}}/verify-email?token={{verification_token}}',
            'If you were not expecting this message you can safely ignore it, and nothing further will happen.',
        ],
        'action' => [
            'label' => 'Confirm my email address',
            'href' => '{{app_url}}/verify-email?token={{verification_token}}',
        ],
    ],
    'identity.email.verified' => [
        'subject' => 'Your email address is confirmed',
        'heading' => 'You are all set, {{first_name}}',
        'paragraphs' => [
            'Thank you for confirming {{email}}. Your {{company_name}} account is active and you can sign in whenever you are ready.',
            'Your first stop is the dashboard, where your attendance, leave balance and payslips all live.',
        ],
        'action' => ['label' => 'Open {{company_name}}', 'href' => '{{app_url}}/dashboard'],
    ],
    'identity.password.reset_requested' => [
        'subject' => 'Reset your {{company_name}} password',
        'heading' => 'Choose a new password',
        'paragraphs' => [
            'We received a request to reset the password for {{email}}. Use the button below to choose a new one.',
            'The link is valid for a short time only, so please use it soon. If it has expired by the time you get here, simply ask for another.',
            'If the button does not work, copy this address into your browser instead: {{app_url}}/reset-password?token={{reset_token}}',
            'If you did not ask for this, no action is needed and your current password stays exactly as it is.',
        ],
        'action' => [
            'label' => 'Choose a new password',
            'href' => '{{app_url}}/reset-password?token={{reset_token}}',
        ],
    ],
    'identity.password.changed' => [
        'subject' => 'Your password has been changed',
        'heading' => 'Your password was updated',
        'paragraphs' => [
            'The password for {{email}} was changed on {{published_at}}.',
            'If that was you, there is nothing to do. If it was not, contact your HR team straight away so the account can be secured.',
        ],
        'action' => ['label' => 'Review my account security', 'href' => '{{app_url}}/account/security'],
    ],
    'identity.account.locked' => [
        'subject' => 'Your {{company_name}} account has been temporarily locked',
        'heading' => 'Your account is locked for now',
        'paragraphs' => [
            'After several unsuccessful sign-in attempts, the account for {{email}} has been locked until {{until}}.',
            'You can sign in again after that time, or reset your password now to get back in sooner.',
            'If none of those attempts were yours, please tell your HR team so they can look into it.',
        ],
        'action' => ['label' => 'Reset my password', 'href' => '{{app_url}}/forgot-password'],
    ],
    'employee.onboarded' => [
        'subject' => 'Welcome to the team, {{full_name}}',
        'heading' => 'Your employee record is ready',
        'paragraphs' => [
            'Welcome to {{company_name}}. Your employee number is {{employee_code}}, and you will find it on everything from your payslip to your ID card.',
            'Please take a few minutes to check your profile and add anything that is missing, such as your emergency contact.',
        ],
        'action' => ['label' => 'Open my profile', 'href' => '{{app_url}}/profile'],
    ],
    'employee.profile.updated' => [
        'subject' => 'Your profile has been updated',
        'heading' => 'A change was made to your record',
        'paragraphs' => [
            'The following details on your employee record were updated: {{changed_fields}}.',
            'If anything there does not look right, let the HR team know and they will put it straight.',
        ],
        'action' => ['label' => 'Review my profile', 'href' => '{{app_url}}/profile'],
    ],
    'employee.document.expiring' => [
        'subject' => 'A document on your record expires soon',
        'heading' => 'Time to refresh a document',
        'paragraphs' => [
            'One of the documents on your employee record is due to expire on {{expires_on}}.',
            'Uploading a current copy before then keeps your file complete and saves a reminder later.',
        ],
        'action' => ['label' => 'Upload a new copy', 'href' => '{{app_url}}/documents'],
    ],
    'employee.offboarded' => [
        'subject' => 'Your last working day is confirmed',
        'heading' => 'Your record has been updated',
        'paragraphs' => [
            'Your employee record now shows a last working day of {{exit_date}}.',
            'Your payslips and documents stay available to you until then, so please download anything you would like to keep.',
            'Thank you for everything you have brought to {{company_name}}. We wish you every success.',
        ],
        'action' => ['label' => 'Download my documents', 'href' => '{{app_url}}/documents'],
    ],
    'attendance.checked_in' => [
        'subject' => 'Checked in at {{time}}',
        'heading' => 'Good morning',
        'paragraphs' => [
            'Your attendance for {{date}} has been recorded from {{time}}.',
            'Have a good day.',
        ],
        'action' => ['label' => 'View my attendance', 'href' => '{{app_url}}/attendance'],
    ],
    'attendance.checked_out' => [
        'subject' => 'Your day on {{date}} is closed',
        'heading' => 'Checked out',
        'paragraphs' => [
            'Your working day for {{date}} has been closed, with {{worked_seconds}} recorded.',
            'Enjoy your evening.',
        ],
        'action' => ['label' => 'View my attendance', 'href' => '{{app_url}}/attendance'],
    ],
    'attendance.absent_flagged' => [
        'subject' => 'No attendance recorded for {{date}}',
        'heading' => 'A day is showing as absent',
        'paragraphs' => [
            'We did not receive a check-in from you on {{date}}, so the day is currently marked absent.',
            'If that is not right, raise a regularisation request and your manager can correct the record.',
        ],
        'action' => ['label' => 'Correct this day', 'href' => '{{app_url}}/attendance'],
    ],
    'attendance.regularisation.raised' => [
        'subject' => 'An attendance correction needs your approval',
        'heading' => 'A correction is waiting for you',
        'paragraphs' => [
            'A request to correct the attendance record for {{date}} has been raised and is waiting on your decision.',
            'The sooner it is reviewed, the sooner the month closes cleanly for payroll.',
        ],
        'action' => ['label' => 'Review the request', 'href' => '{{app_url}}/regularisations/{{request_id}}'],
    ],
    'attendance.regularisation.decided' => [
        'subject' => 'Your attendance correction was {{status}}',
        'heading' => 'A decision has been made',
        'paragraphs' => [
            'Your request to correct an attendance record has been {{status}}.',
            'Your attendance summary now reflects that decision.',
        ],
        'action' => ['label' => 'View the request', 'href' => '{{app_url}}/regularisations/{{request_id}}'],
    ],
    'leave.request.submitted' => [
        'subject' => 'A leave request is waiting for your decision',
        'heading' => 'A new leave request',
        'paragraphs' => [
            'A request for {{leave_type}} leave from {{starts_on}} to {{ends_on}} has been submitted and needs your approval.',
            'You can see how it sits against the rest of the team before you decide.',
        ],
        'action' => ['label' => 'Review the request', 'href' => '{{app_url}}/approvals/{{request_id}}'],
    ],
    'leave.request.decided' => [
        'subject' => 'Your leave request was {{status}}',
        'heading' => 'A decision on your leave',
        'paragraphs' => [
            'Your leave request has been {{status}}.',
            'Note from the approver: {{note}}',
            'Your leave balance has been updated to match.',
        ],
        'action' => ['label' => 'View my request', 'href' => '{{app_url}}/leave/requests/{{request_id}}'],
    ],
    'leave.request.cancelled' => [
        'subject' => 'Your leave request has been cancelled',
        'heading' => 'That request is now cancelled',
        'paragraphs' => [
            'The leave request has been cancelled and any days it held have been returned to your balance.',
            'You are welcome to apply again whenever you need to.',
        ],
        'action' => ['label' => 'View my leave', 'href' => '{{app_url}}/leave/requests/{{request_id}}'],
    ],
    'leave.balance.adjusted' => [
        'subject' => 'Your leave balance has been adjusted',
        'heading' => 'An adjustment to your balance',
        'paragraphs' => [
            'An adjustment of {{delta_days}} days has been applied to one of your leave balances.',
            'Reason given: {{reason}}',
        ],
        'action' => ['label' => 'View my balances', 'href' => '{{app_url}}/leave-balances'],
    ],
    'payroll.run.approved' => [
        'subject' => 'Payroll for {{period}} has been approved',
        'heading' => 'The run is approved',
        'paragraphs' => [
            'The payroll run for {{period}}, covering {{employee_count}} people, has been approved with a total net value of {{total_net_minor}}.',
            'Payslips can now be published to everybody included in the run.',
        ],
        'action' => ['label' => 'Open the payroll run', 'href' => '{{app_url}}/payroll/runs/{{run_id}}'],
    ],
    'payroll.payslip.published' => [
        'subject' => 'Your payslip for {{period}} is ready',
        'heading' => 'Your payslip is available',
        'paragraphs' => [
            'Your payslip for {{period}} has been published and is ready to view or download.',
            'Do keep a copy somewhere safe, as it is often asked for when renting or applying for a loan.',
        ],
        'action' => ['label' => 'View my payslip', 'href' => '{{app_url}}/payslips/{{payslip_id}}'],
    ],
    'payroll.salary.revised' => [
        'subject' => 'Your salary has been revised',
        'heading' => 'A change to your compensation',
        'paragraphs' => [
            'With effect from {{effective_from}}, your annual cost to company becomes {{new_ctc_minor}}.',
            'The full breakdown of earnings and deductions is on your salary page, and the change will appear on your next payslip.',
        ],
        'action' => ['label' => 'View my salary details', 'href' => '{{app_url}}/payroll/salary'],
    ],
    'payroll.expense.decided' => [
        'subject' => 'Your expense claim was {{status}}',
        'heading' => 'A decision on your claim',
        'paragraphs' => [
            'Your expense claim has been {{status}}.',
            'Approved claims are reimbursed with the next payroll run unless finance tells you otherwise.',
        ],
        'action' => ['label' => 'View my claim', 'href' => '{{app_url}}/expenses/{{claim_id}}'],
    ],
    'learning.course.assigned' => [
        'subject' => 'New training assigned: {{course_title}}',
        'heading' => 'A course has been added to your plan',
        'paragraphs' => [
            '{{course_title}} has been assigned to you, to be completed by {{due_on}}.',
            'Most people find it easiest to block out a little time for it rather than fit it around the day.',
        ],
        'action' => ['label' => 'Start the course', 'href' => '{{app_url}}/courses/{{course_id}}'],
    ],
    'learning.course.completed' => [
        'subject' => 'Well done on finishing your course',
        'heading' => 'Course completed',
        'paragraphs' => [
            'You have completed your course with a score of {{score}}.',
            'Your certificate is on your learning page whenever you need it.',
        ],
        'action' => ['label' => 'View my certificate', 'href' => '{{app_url}}/courses/{{course_id}}'],
    ],
    'talent.review.opened' => [
        'subject' => 'A performance review is ready for you to complete',
        'heading' => 'A review is waiting for you',
        'paragraphs' => [
            'A review has been opened for this cycle and is waiting for your input.',
            'Honest, specific comments are far more useful than a rating on its own, so do take your time over it.',
        ],
        'action' => ['label' => 'Complete the review', 'href' => '{{app_url}}/reviews/{{cycle_id}}'],
    ],
    'talent.review.submitted' => [
        'subject' => 'Your performance review has been submitted',
        'heading' => 'Your review is in',
        'paragraphs' => [
            'Your review for this cycle has been submitted with an overall rating of {{rating}}.',
            'Your manager will arrange a conversation to go through it with you.',
        ],
        'action' => ['label' => 'Read my review', 'href' => '{{app_url}}/reviews/{{cycle_id}}'],
    ],
    'talent.goal.updated' => [
        'subject' => 'Progress recorded on one of your goals',
        'heading' => 'Your goal has moved',
        'paragraphs' => [
            'One of your goals is now recorded at {{progress}} per cent complete.',
            'Keeping progress current makes the review conversation much easier when the cycle comes around.',
        ],
        'action' => ['label' => 'View my goals', 'href' => '{{app_url}}/goals/{{goal_id}}'],
    ],
];

// A template is only written where the event has none, so wording edited later
// through the platform is never quietly reverted by the next restart.
$insertTemplate = $pdo->prepare(
    'INSERT INTO email_templates (id, event_name, subject, body_html, body_text, is_active, created_at, updated_at)
     VALUES (:id, :event_name, :subject, :body_html, :body_text, TRUE, :created_at, :updated_at)
     ON CONFLICT (event_name) DO NOTHING'
);

foreach ($templates as $eventName => $template) {
    $action = $template['action'] ?? null;

    $insertTemplate->execute([
        'id' => Str::uuid(),
        'event_name' => $eventName,
        'subject' => $template['subject'],
        'body_html' => $layout($template['heading'], $template['paragraphs'], $action),
        'body_text' => $plain($template['heading'], $template['paragraphs'], $action),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// Channel preferences are deliberately not seeded. A missing row means both
// channels are on, so seeding one per person per category would only create
// thousands of rows that say what the default already says.

if (!Env::bool('SEED_DEMO_DATA', true)) {
    return;
}

// ---------------------------------------------------------------------------
// Demonstration data
// ---------------------------------------------------------------------------

/**
 * A repeatable identifier for a demo record.
 *
 * The seed runs on every boot, so a generated id would add another copy of the
 * same announcement each time. Deriving the id from a fixed key makes the
 * insert a no-op on the second run.
 */
$demoId = static function (string $key): string {
    $digest = md5('dayflow.notification.demo.' . $key);

    return substr($digest, 0, 8) . '-'
        . substr($digest, 8, 4) . '-'
        . '4' . substr($digest, 13, 3) . '-'
        . dechex(8 + (hexdec($digest[16]) % 4)) . substr($digest, 17, 3) . '-'
        . substr($digest, 20, 12);
};

// Identifiers are copied verbatim from docs/SEED-IDENTIFIERS.md: every service
// seeds independently and can only agree on a picture if it uses these.
$employees = [
    'DF-0001' => ['28010836-0cfb-4b4d-aa20-b0b0f2bedfe3', '8044f8e4-46c5-442a-bfcb-ae491dcc9ded'],
    'DF-0002' => ['a9f5c390-db56-42fa-96d9-fb1ff57ca041', 'b988b84d-bdef-4ef7-9809-a14bd4a07350'],
    'DF-0003' => ['bf5b3018-b6eb-4646-8507-8b6e6fdca490', '6b46a7fa-5737-4095-ba77-ec70b858dceb'],
    'DF-0004' => ['31033589-5508-433c-966e-508f653b54be', 'cc6201b4-274b-4599-9a97-42b368cedd53'],
    'DF-0005' => ['e3dbeba9-d9d2-4153-9934-471a08bb9cd6', '78886f55-a3df-4831-8ea0-36204747eb75'],
    'DF-0006' => ['5f2fc57a-9d26-4279-bbdf-054496fd35ea', '208bb9bb-b438-4fe1-9f7d-7ee1ff3fd237'],
    'DF-0007' => ['c55da0e9-62e4-4f6d-a1c8-0ba0d6d17700', 'a26c2525-f201-44d6-9ccc-d06ea655b536'],
    'DF-0008' => ['5419981a-cd0a-4f65-9ff0-bcd16dd43a91', '0008e98e-7d40-451a-88c5-53419b6c993f'],
    'DF-0009' => ['4449d500-6fb5-48f3-8b9f-4fac8516da38', '7569e1f1-3659-42b9-89d3-bb90ab97c323'],
    'DF-0010' => ['2b5d3904-9f2f-4ae3-9248-88adac38023a', '1c85286f-f790-4c8b-808d-bd63d619c205'],
    'DF-0011' => ['a2f82591-b4b7-4de2-99ae-1098b9111ae7', '23513a48-7f1d-483b-8280-75a1c98aa8b9'],
    'DF-0012' => ['87ae5bec-96ae-4226-92df-519a54dbda64', '051c1f79-ef38-41d1-a44b-f21077a131a4'],
];

// Authors are accounts that could really have written these: announcement
// publishing is granted to hr_officer and inherited upwards, so only the People
// and Culture line and the administrator hold it. Attributing a notice to
// Finance would show a byline the platform would refuse to let that person use.
$meera = 'b988b84d-bdef-4ef7-9809-a14bd4a07350';
$akshat = '8044f8e4-46c5-442a-bfcb-ae491dcc9ded';
$rahul = '6b46a7fa-5737-4095-ba77-ec70b858dceb';

$daysAgo = static fn (int $days): string => Clock::now()->modify(sprintf('-%d days', $days))->format(\DateTimeInterface::ATOM);
$daysAhead = static fn (int $days): string => Clock::now()->modify(sprintf('+%d days', $days))->format('Y-m-d');

// --- Announcements ---------------------------------------------------------

$announcements = [
    [
        'key' => 'announcement.leave-policy',
        'title' => 'Updated leave policy takes effect from the new quarter',
        'body' => "The leave policy has been revised after this year's employee survey.\n\n"
            . "Three things change. Carry-forward rises from five days to eight. Sick leave no longer needs a certificate for a single day. Comp-off must now be claimed within sixty days of the day worked.\n\n"
            . 'The full policy is on the leave page, and your balances have already been recalculated. If anything looks wrong on yours, speak to People and Culture and we will sort it out.',
        'category' => 'policy',
        'severity' => 'warning',
        'published_by' => $meera,
        'published_at' => $daysAgo(4),
        'expires_on' => null,
        'pinned' => true,
    ],
    [
        'key' => 'announcement.holiday-calendar',
        'title' => 'The holiday calendar for the coming year is published',
        'body' => "Next year's public holiday calendar is now on the holidays page.\n\n"
            . "There are twelve fixed holidays and two floating days you may take whenever you like. Two of the fixed holidays fall on a weekend and have been moved to the following Monday.\n\n"
            . 'Planning early makes life easier for everyone, so do put your longer breaks in as soon as you know them.',
        'category' => 'holidays',
        'severity' => 'info',
        'published_by' => $meera,
        'published_at' => $daysAgo(9),
        'expires_on' => null,
        'pinned' => false,
    ],
    [
        'key' => 'announcement.benefits',
        'title' => 'Wider health cover for you and your family',
        'body' => "Our group health cover has been renewed, and the sum insured rises from five lakh to eight lakh with no increase to your contribution.\n\n"
            . "Parents can now be added alongside a spouse and children, and outpatient consultations are included for the first time.\n\n"
            . 'Add or update your dependants before the end of the month. Anyone already on the policy stays on it and needs to do nothing.',
        'category' => 'benefits',
        'severity' => 'success',
        'published_by' => $rahul,
        'published_at' => $daysAgo(15),
        'expires_on' => null,
        'pinned' => false,
    ],
    [
        'key' => 'announcement.all-hands',
        'title' => 'All-hands on Friday: where we are and what comes next',
        'body' => "Join us on Friday at four for the quarterly all-hands.\n\n"
            . "We will cover how the quarter closed, what the next one asks of each team, and the two hires we are making in Customer Success. The last twenty minutes are for questions, and nothing is off the table.\n\n"
            . 'Mumbai HQ will use the ground-floor lounge. Everyone else has a video link in their calendar invitation.',
        'category' => 'events',
        'severity' => 'info',
        'published_by' => $akshat,
        'published_at' => $daysAgo(2),
        'expires_on' => $daysAhead(30),
        'pinned' => false,
    ],
    [
        'key' => 'announcement.office-move',
        'title' => 'Mumbai HQ moves up to the eleventh floor next month',
        'body' => "Our Mumbai office is moving from the seventh floor to the eleventh, in the same building, over a weekend next month.\n\n"
            . "Please clear your desk and label anything you want moved by the Friday before. Facilities will handle monitors, chairs and cabinets. Anything left unlabelled goes into storage.\n\n"
            . 'The new floor has two more meeting rooms, a proper quiet area and a much better pantry. Access cards keep working exactly as they do now.',
        'category' => 'facilities',
        'severity' => 'warning',
        'published_by' => $meera,
        'published_at' => $daysAgo(6),
        'expires_on' => $daysAhead(45),
        'pinned' => false,
    ],
];

$insertAnnouncement = $pdo->prepare(
    'INSERT INTO announcements
        (id, title, body, category, severity, published_by, published_at, expires_on,
         pinned, target_department_id, target_role, is_active, created_at, updated_at)
     VALUES
        (:id, :title, :body, :category, :severity, :published_by, :published_at, :expires_on,
         :pinned, NULL, NULL, TRUE, :created_at, :updated_at)
     ON CONFLICT (id) DO NOTHING'
);

foreach ($announcements as $announcement) {
    $insertAnnouncement->execute([
        'id' => $demoId($announcement['key']),
        'title' => $announcement['title'],
        'body' => $announcement['body'],
        'category' => $announcement['category'],
        'severity' => $announcement['severity'],
        'published_by' => $announcement['published_by'],
        'published_at' => $announcement['published_at'],
        'expires_on' => $announcement['expires_on'],
        'pinned' => $announcement['pinned'] ? 'true' : 'false',
        'created_at' => $announcement['published_at'],
        'updated_at' => $announcement['published_at'],
    ]);
}

// --- Notifications ---------------------------------------------------------
//
// Roughly thirty messages spread across the twelve demo employees, some read
// and some not, so the feed and the unread badge both have something honest to
// show on a fresh install.

$notifications = [
    ['DF-0001', 'payroll.run.approved', 'payroll', 'success', 'money-check-dollar', '/payroll/runs',
        'Payroll for last month has been approved',
        'The run covering 12 people has been approved with a total net value of Rs. 58,40,000.00. Payslips can now be published.', 6, true],
    ['DF-0001', 'talent.review.opened', 'talent', 'info', 'clipboard-list', '/reviews',
        'A performance review is ready for you to complete',
        'The mid-year cycle is open and your review of Meera Iyer is waiting for your input.', 3, false],

    ['DF-0002', 'leave.request.submitted', 'leave', 'info', 'calendar-plus', '/approvals',
        'A leave request is waiting for your decision',
        'Rahul Deshmukh has requested casual leave for two days at the start of next month.', 1, false],
    ['DF-0002', 'employee.document.expiring', 'people', 'warning', 'file-circle-exclamation', '/documents',
        'A document on your record expires soon',
        'The passport copy on your file expires in three months. Uploading a current one keeps your record complete.', 5, true],
    ['DF-0002', 'talent.review.submitted', 'talent', 'success', 'clipboard-check', '/reviews',
        'Your performance review has been submitted',
        'Your review for the mid-year cycle has been submitted with an overall rating of 4.5.', 9, true],

    ['DF-0003', 'leave.request.decided', 'leave', 'success', 'calendar-check', '/leave/requests',
        'Your leave request was approved',
        'Your casual leave has been approved. Note from the approver: enjoy the break, the handover looks fine.', 0, false],
    ['DF-0003', 'learning.course.assigned', 'learning', 'info', 'graduation-cap', '/courses',
        'New training assigned: Preventing Workplace Harassment',
        'The course has been added to your learning plan and is due at the end of next month.', 4, false],
    ['DF-0003', 'payroll.payslip.published', 'payroll', 'success', 'file-invoice-dollar', '/payslips',
        'Your payslip for last month is ready',
        'It is available to view or download whenever you need it.', 7, true],

    ['DF-0004', 'payroll.expense.decided', 'payroll', 'info', 'receipt', '/expenses',
        'Your expense claim was approved',
        'Your claim for travel to the Bengaluru office has been approved and will be reimbursed with the next payroll run.', 2, false],
    ['DF-0004', 'payroll.payslip.published', 'payroll', 'success', 'file-invoice-dollar', '/payslips',
        'Your payslip for last month is ready',
        'It is available to view or download whenever you need it.', 7, true],
    ['DF-0004', 'attendance.absent_flagged', 'attendance', 'warning', 'user-clock', '/attendance',
        'No attendance was recorded for one of your days',
        'We did not receive a check-in from you, so the day is currently marked absent. Raise a correction if that is not right.', 11, true],

    ['DF-0005', 'leave.request.submitted', 'leave', 'info', 'calendar-plus', '/approvals',
        'A leave request is waiting for your decision',
        'Priya Sharma has requested five days of paid leave later this month.', 1, false],
    ['DF-0005', 'attendance.regularisation.raised', 'attendance', 'info', 'clock-rotate-left', '/regularisations',
        'An attendance correction needs your approval',
        'Vikram Reddy has raised a correction for a day last week and it is waiting on your decision.', 3, false],
    ['DF-0005', 'talent.review.opened', 'talent', 'info', 'clipboard-list', '/reviews',
        'A performance review is ready for you to complete',
        'The mid-year cycle is open and your review of Ananya Bose is waiting for your input.', 5, true],
    ['DF-0005', 'payroll.payslip.published', 'payroll', 'success', 'file-invoice-dollar', '/payslips',
        'Your payslip for last month is ready',
        'It is available to view or download whenever you need it.', 7, true],

    ['DF-0006', 'leave.request.decided', 'leave', 'success', 'calendar-check', '/leave/requests',
        'Your leave request was approved',
        'Your paid leave has been approved. Note from the approver: please hand over to Vikram before you go.', 0, false],
    ['DF-0006', 'learning.course.completed', 'learning', 'success', 'award', '/courses',
        'Well done on finishing Secure Coding Essentials',
        'You completed the course with a score of 92. Your certificate is on your learning page.', 8, true],
    ['DF-0006', 'payroll.salary.revised', 'payroll', 'success', 'arrow-trend-up', '/payroll/salary',
        'Your salary has been revised',
        'Your revised annual cost to company takes effect from the start of this quarter and appears on your next payslip.', 20, true],

    ['DF-0007', 'attendance.regularisation.decided', 'attendance', 'success', 'clock-rotate-left', '/regularisations',
        'Your attendance correction was approved',
        'The record for last Thursday has been corrected and your attendance summary now reflects it.', 2, false],
    ['DF-0007', 'learning.course.assigned', 'learning', 'info', 'graduation-cap', '/courses',
        'New training assigned: Data Protection Basics',
        'The course has been added to your learning plan and is due in three weeks.', 6, false],
    ['DF-0007', 'attendance.checked_in', 'attendance', 'info', 'right-to-bracket', '/attendance',
        'Checked in at 09:41',
        'Your attendance for yesterday has been recorded from 09:41.', 1, true],

    ['DF-0008', 'talent.goal.updated', 'talent', 'info', 'bullseye', '/goals',
        'Progress recorded on one of your goals',
        'Your goal to cut the regression suite runtime by a third is now recorded at 60 per cent complete.', 3, false],
    ['DF-0008', 'employee.profile.updated', 'people', 'info', 'user-pen', '/profile',
        'Your profile has been updated',
        'The following details on your record were updated: phone, emergency contact.', 12, true],

    ['DF-0009', 'leave.request.submitted', 'leave', 'info', 'calendar-plus', '/approvals',
        'A leave request is waiting for your decision',
        'Divya Raghavan has requested a single day of sick leave.', 4, true],
    ['DF-0009', 'payroll.payslip.published', 'payroll', 'success', 'file-invoice-dollar', '/payslips',
        'Your payslip for last month is ready',
        'It is available to view or download whenever you need it.', 7, true],

    ['DF-0010', 'leave.request.decided', 'leave', 'success', 'calendar-check', '/leave/requests',
        'Your leave request was approved',
        'Your sick leave has been approved and your balance has been updated to match.', 4, true],
    ['DF-0010', 'learning.course.assigned', 'learning', 'info', 'graduation-cap', '/courses',
        'New training assigned: Consultative Selling',
        'The course has been added to your learning plan and is due at the end of next month.', 2, false],

    ['DF-0011', 'employee.document.expiring', 'people', 'warning', 'file-circle-exclamation', '/documents',
        'A document on your record expires soon',
        'Your address proof expires next month. Uploading a current copy saves a reminder later.', 1, false],
    ['DF-0011', 'talent.review.submitted', 'talent', 'success', 'clipboard-check', '/reviews',
        'Your performance review has been submitted',
        'Your review for the mid-year cycle has been submitted with an overall rating of 4.0.', 10, true],

    ['DF-0012', 'learning.course.assigned', 'learning', 'info', 'graduation-cap', '/courses',
        'New training assigned: Accessibility in Product Design',
        'The course has been added to your learning plan and is due in four weeks.', 5, false],
];

$insertNotification = $pdo->prepare(
    'INSERT INTO notifications
        (id, employee_id, user_id, category, event_name, title, body, action_url,
         icon, severity, read_at, created_at)
     VALUES
        (:id, :employee_id, :user_id, :category, :event_name, :title, :body, :action_url,
         :icon, :severity, :read_at, :created_at)
     ON CONFLICT (id) DO NOTHING'
);

foreach ($notifications as $index => $notification) {
    [$code, $eventName, $category, $severity, $icon, $actionUrl, $title, $body, $age, $isRead] = $notification;
    [$employeeId, $userId] = $employees[$code];

    $createdAt = Clock::now()->modify(sprintf('-%d days', $age));

    $insertNotification->execute([
        'id' => $demoId('notification.' . $index . '.' . $code),
        'employee_id' => $employeeId,
        'user_id' => $userId,
        'category' => $category,
        'event_name' => $eventName,
        'title' => $title,
        'body' => $body,
        'action_url' => $actionUrl,
        'icon' => $icon,
        'severity' => $severity,
        // A read message was opened a few hours after it arrived, which is what
        // makes the feed look like something people have actually used.
        'read_at' => $isRead ? $createdAt->modify('+5 hours')->format(\DateTimeInterface::ATOM) : null,
        'created_at' => $createdAt->format(\DateTimeInterface::ATOM),
    ]);
}
