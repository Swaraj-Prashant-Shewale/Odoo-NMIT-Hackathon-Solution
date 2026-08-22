<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Api;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;

/**
 * The home screen.
 *
 * The whole page comes from one call to the analytics service, which assembles
 * it card by card from what the caller is entitled to see and marks every card
 * "available": true or false. This controller therefore decides almost nothing
 * about content: it decides ORDER. Somebody who came to check in should not
 * have to scroll past a headcount chart to find the button, and somebody who
 * came to look at the organisation should not have to scroll past a punch card
 * to find the charts. That is the one judgement made here.
 *
 * Chart values are prepared here rather than in the template for two reasons:
 * a money series has to be converted out of minor units before it is plotted,
 * and a trend that is missing a month must drop that month rather than plot it
 * as a zero. Neither belongs in markup.
 */
final class DashboardController extends Controller
{
    /** Sections whose absence from the payload means "not for this person". */
    private const SELF_SECTIONS = [
        'attendance_today',
        'attendance_this_month',
        'attendance_week',
        'leave_balances',
        'leave_pending',
        'latest_payslip',
        'learning',
        'goals',
        'inbox',
    ];

    public function index(): void
    {
        // The service caches an assembled dashboard for a minute. The refresh
        // link asks it to throw that away, which is what somebody who has just
        // checked in and come back here expects.
        $query = $this->inputBool('refresh') ? ['refresh' => 'true'] : [];

        $response = Api::get('/dashboard', $query);

        // A dead session is the one failure worth leaving the page for; guard()
        // ends it cleanly. Everything else is handled in place, because the
        // fallback for this page is this page and a redirect would loop.
        if (!$response['ok'] && $response['status'] === 401) {
            $this->guard($response, '/');
        }

        if (!$response['ok']) {
            Flash::warning((string) ($response['error']['message'] ?? 'Your dashboard could not be loaded just now.'));
        }

        $payload = is_array($response['data']) ? $response['data'] : [];
        $sections = $this->sections($payload);
        $audience = $this->audience($payload);

        $attendanceRate = $this->latestAttendanceRate($sections);

        View::render('dashboard/index', [
            'pageTitle' => 'Dashboard',
            'greeting' => $this->greeting(),
            'serverTime' => Clock::now()->format('g:i:s a'),
            'today' => (string) ($payload['as_of'] ?? Clock::today()),
            'viewer' => is_array($payload['viewer'] ?? null) ? $payload['viewer'] : [],
            'profile' => $this->profile(),
            'sections' => $sections,
            'audience' => $audience,
            'hasSelfSection' => $this->hasAny($sections, self::SELF_SECTIONS),
            // The company view goes first only for the people who opened the
            // page to see it: HR, an administrator, or finance.
            'companyFirst' => (in_array('organisation', $audience, true) || in_array('finance', $audience, true))
                && Session::canAny(Permissions::PROFILE_VIEW_ALL, Permissions::PAYROLL_VIEW_ALL),
            'charts' => $this->charts($sections),
            'attendanceRate' => $attendanceRate['rate'],
            'attendanceRatePeriod' => $attendanceRate['label'],
            'quickActions' => $this->quickActions(),
            'unavailableServices' => $this->unavailableServices($payload),
            'generatedAt' => (string) ($payload['generated_at'] ?? ''),
            'cached' => (bool) ($response['meta']['cached'] ?? false),
        ]);
    }

    /**
     * The section map exactly as the service sent it, with anything malformed
     * dropped so a template can trust the two keys it reads.
     *
     * @param array<string, mixed> $payload
     * @return array<string, array{available: bool, data: mixed}>
     */
    private function sections(array $payload): array
    {
        $raw = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        $sections = [];

        foreach ($raw as $key => $section) {
            if (!is_string($key) || !is_array($section)) {
                continue;
            }

            $sections[$key] = [
                'available' => ($section['available'] ?? false) === true,
                'data' => $section['data'] ?? null,
            ];
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function audience(array $payload): array
    {
        $audience = is_array($payload['audience'] ?? null) ? $payload['audience'] : [];

        return array_values(array_filter($audience, 'is_string'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function unavailableServices(array $payload): array
    {
        $services = is_array($payload['unavailable_services'] ?? null) ? $payload['unavailable_services'] : [];

        return array_values(array_filter($services, 'is_string'));
    }

    /**
     * The signed-in person's own record, for the designation and department in
     * the greeting.
     *
     * Fetched with data() rather than get() on purpose: if employee-service is
     * having a bad morning the greeting loses two words, and nothing else on
     * the page is affected.
     *
     * @return array<string, mixed>|null
     */
    private function profile(): ?array
    {
        $employeeId = Session::employeeId();

        if ($employeeId === null || $employeeId === '') {
            return null;
        }

        $record = Api::data('/employees/' . rawurlencode($employeeId));

        return is_array($record) ? $record : null;
    }

    /** "Good morning" and friends, from the clock in the company's timezone. */
    private function greeting(): string
    {
        $hour = (int) Clock::now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    /**
     * @param array<string, array{available: bool, data: mixed}> $sections
     * @param list<string> $keys
     */
    private function hasAny(array $sections, array $keys): bool
    {
        foreach ($keys as $key) {
            if (isset($sections[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * One section's data, or an empty array when it is absent or unavailable.
     *
     * @param array<string, array{available: bool, data: mixed}> $sections
     * @return array<array-key, mixed>
     */
    private function dataFor(array $sections, string $key): array
    {
        $section = $sections[$key] ?? null;

        if ($section === null || !$section['available'] || !is_array($section['data'])) {
            return [];
        }

        return $section['data'];
    }

    /**
     * A section whose data is itself a list of rows.
     *
     * @param array<string, array{available: bool, data: mixed}> $sections
     * @return list<array<string, mixed>>
     */
    private function listFor(array $sections, string $key): array
    {
        $section = $sections[$key] ?? null;

        return $section !== null && $section['available'] ? $this->rows($section['data']) : [];
    }

    /**
     * Rows of a nested list, with anything that is not a row discarded.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * The most recent month for which an attendance rate is actually known.
     *
     * @param array<string, array{available: bool, data: mixed}> $sections
     * @return array{rate: ?float, label: string}
     */
    private function latestAttendanceRate(array $sections): array
    {
        $months = $this->rows($this->dataFor($sections, 'attendance_rate_trend')['months'] ?? null);

        for ($index = count($months) - 1; $index >= 0; $index--) {
            if (is_numeric($months[$index]['rate'] ?? null)) {
                return [
                    'rate' => (float) $months[$index]['rate'],
                    'label' => (string) ($months[$index]['label'] ?? ''),
                ];
            }
        }

        return ['rate' => null, 'label' => ''];
    }

    /**
     * Every chart on the page, ready for ejs() in the template.
     *
     * @param array<string, array{available: bool, data: mixed}> $sections
     * @return array<string, array<string, mixed>>
     */
    private function charts(array $sections): array
    {
        $charts = [];
        $symbol = Env::get('CURRENCY_SYMBOL', '₹');

        $byDepartment = $this->rows($this->dataFor($sections, 'workforce_composition')['by_department'] ?? null);

        if ($byDepartment !== []) {
            $charts['headcount_by_department'] = [
                'type' => 'bar',
                'labels' => array_map(static fn (array $row): string => (string) ($row['label'] ?? 'Unassigned'), $byDepartment),
                'values' => array_map(static fn (array $row): int => (int) ($row['value'] ?? 0), $byDepartment),
                'format' => 'number',
            ];
        }

        // A month the attendance service could not answer for arrives with a
        // null rate. It is dropped rather than plotted as zero, because a zero
        // would read as "nobody came in" instead of "we do not know".
        $months = $this->rows($this->dataFor($sections, 'attendance_rate_trend')['months'] ?? null);
        $known = array_values(array_filter($months, static fn (array $month): bool => is_numeric($month['rate'] ?? null)));

        if ($known !== []) {
            $charts['attendance_trend'] = [
                'type' => 'line',
                'labels' => array_map(static fn (array $month): string => (string) ($month['label'] ?? ''), $known),
                'values' => array_map(static fn (array $month): float => (float) $month['rate'], $known),
                'format' => 'percent',
                'colour' => '#16a34a',
            ];
            $charts['attendance_trend_gaps'] = ['missing' => count($months) - count($known)];
        }

        $leaveTypes = $this->rows($this->dataFor($sections, 'leave_utilisation')['types'] ?? null);

        if ($leaveTypes !== []) {
            $charts['leave_utilisation'] = [
                'type' => 'donut',
                'labels' => array_map(static fn (array $row): string => (string) ($row['leave_type'] ?? 'Leave'), $leaveTypes),
                'values' => array_map(static fn (array $row): float => (float) ($row['days_taken'] ?? 0), $leaveTypes),
                'format' => 'number',
                'centreLabel' => 'days taken',
            ];
        }

        $trend = $this->listFor($sections, 'headcount_trend');

        if ($trend !== []) {
            $charts['headcount_trend'] = [
                'type' => 'line',
                'labels' => array_map(static fn (array $row): string => (string) ($row['label'] ?? ''), $trend),
                'values' => array_map(static fn (array $row): int => (int) ($row['headcount'] ?? 0), $trend),
                'format' => 'number',
            ];
        }

        $teamGoals = $this->dataFor($sections, 'team_goals');

        if ($teamGoals !== []) {
            $achieved = (int) ($teamGoals['achieved'] ?? 0);
            $active = (int) ($teamGoals['active'] ?? 0);

            $charts['team_goals'] = [
                'type' => 'donut',
                'labels' => ['Achieved', 'Still open'],
                'values' => [$achieved, $active],
                'format' => 'number',
                'centreValue' => round((float) ($teamGoals['completion_rate'] ?? 0)) . '%',
                'centreLabel' => 'complete',
            ];
        }

        $payrollMonths = $this->rows($this->dataFor($sections, 'payroll_cost_trend')['months'] ?? null);

        if ($payrollMonths !== []) {
            $charts['payroll_cost'] = [
                'type' => 'bar',
                'labels' => array_map(static fn (array $row): string => (string) ($row['label'] ?? ''), $payrollMonths),
                // Plotted in major units. The renderer abbreviates a large
                // number for the axis, and minor units would abbreviate a
                // hundredfold too large.
                'values' => array_map(
                    static fn (array $row): float => round(((int) ($row['net_minor'] ?? 0)) / 100, 2),
                    $payrollMonths
                ),
                'format' => 'money',
                'symbol' => $symbol,
            ];
        }

        return $charts;
    }

    /**
     * The row of shortcuts at the foot of the page, filtered by permission.
     *
     * The API enforces every one of these independently. Hiding a tile only
     * keeps the page free of buttons that would fail.
     *
     * @return list<array{label: string, href: string, icon: string}>
     */
    private function quickActions(): array
    {
        $actions = [
            [
                'label' => 'Apply for time off',
                'href' => '/leave/apply',
                'icon' => 'fa-umbrella-beach',
                'show' => Session::can(Permissions::LEAVE_APPLY),
            ],
            [
                'label' => 'My attendance',
                'href' => '/attendance',
                'icon' => 'fa-clock',
                'show' => Session::can(Permissions::ATTENDANCE_VIEW_SELF),
            ],
            [
                'label' => 'My payslips',
                'href' => '/payroll',
                'icon' => 'fa-file-invoice-dollar',
                'show' => Session::can(Permissions::PAYROLL_VIEW_SELF),
            ],
            [
                'label' => 'My documents',
                'href' => '/profile/documents',
                'icon' => 'fa-folder-open',
                'show' => Session::can(Permissions::DOCUMENT_VIEW_SELF),
            ],
            [
                'label' => 'Company directory',
                'href' => '/directory',
                'icon' => 'fa-address-book',
                'show' => Session::can(Permissions::DIRECTORY_VIEW),
            ],
            [
                'label' => 'Approvals',
                'href' => '/approvals',
                'icon' => 'fa-check-double',
                'show' => Session::canAny(
                    Permissions::LEAVE_APPROVE,
                    Permissions::ATTENDANCE_APPROVE_REGULARISATION,
                    Permissions::EXPENSE_APPROVE
                ),
            ],
            [
                'label' => 'Add an employee',
                'href' => '/people/new',
                'icon' => 'fa-user-plus',
                'show' => Session::can(Permissions::EMPLOYEE_CREATE),
            ],
            [
                'label' => 'Run payroll',
                'href' => '/payroll/runs',
                'icon' => 'fa-calculator',
                // The tile leads to the run list, which is guarded by
                // payroll.view.all rather than by payroll.run, so both are
                // required before it is offered.
                'show' => Session::can(Permissions::PAYROLL_RUN) && Session::can(Permissions::PAYROLL_VIEW_ALL),
            ],
        ];

        return array_values(array_map(
            static fn (array $action): array => [
                'label' => $action['label'],
                'href' => $action['href'],
                'icon' => $action['icon'],
            ],
            array_filter($actions, static fn (array $action): bool => $action['show'])
        ));
    }
}
