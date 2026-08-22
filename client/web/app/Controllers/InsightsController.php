<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Api;
use App\Core\Controller;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;
use Dayflow\Kernel\Support\Env;

/**
 * Workforce insights.
 *
 * The analytics service publishes five analyses that go considerably deeper
 * than the dashboard cards: attendance grouped by day, week, month or
 * department; leave by type, status and month; headcount with joiners,
 * leavers, attrition and tenure bands; payroll cost by month and by
 * department; and training completion by course. Every one of them is computed
 * from the owning service's records at the moment it is asked.
 *
 * Until now nothing rendered them. This page does.
 *
 * Each analysis is fetched independently and each one degrades on its own: a
 * service that cannot answer costs its own section and nothing else, which is
 * the same contract the dashboard cards keep. Permission is not decided here -
 * the gateway declares what each route requires and the service checks it
 * again - but a section the caller plainly cannot read is not requested at
 * all, so the page does not fill with "could not be loaded" panels for things
 * that were never theirs to see.
 */
final class InsightsController extends Controller
{
    /** How the attendance analysis may be grouped, and what to call each one. */
    private const GROUPINGS = [
        'day' => 'By day',
        'week' => 'By week',
        'month' => 'By month',
        'department' => 'By department',
    ];

    public function index(): void
    {
        $groupBy = $this->input('group_by', 'week');

        if (!array_key_exists($groupBy, self::GROUPINGS)) {
            $groupBy = 'week';
        }

        $range = $this->range();

        // Attendance and leave are declared ->authenticated() by the service
        // because two different permissions grant them, and it works out the
        // width of the answer from the caller's own scope. The other three
        // each name one permission, so asking without it is a wasted round
        // trip and a panel that says nothing useful.
        $sections = [
            'attendance' => Api::data('/analytics/attendance', $range + ['group_by' => $groupBy]),
            'leave' => Api::data('/analytics/leave', $range),
            'headcount' => Session::can(Permissions::REPORT_VIEW_ALL)
                ? Api::data('/analytics/headcount', $range)
                : null,
            'payroll' => Session::can(Permissions::PAYROLL_VIEW_ALL)
                ? Api::data('/analytics/payroll', $range)
                : null,
            'learning' => Session::can(Permissions::REPORT_VIEW_ALL)
                ? Api::data('/analytics/learning', $range)
                : null,
        ];

        View::render('insights/index', [
            'pageTitle' => 'Insights',
            'breadcrumbs' => [['Insights', '']],
            'sections' => array_map([$this, 'asArray'], $sections),
            'charts' => $this->charts($sections),
            'groupings' => self::GROUPINGS,
            'groupBy' => $groupBy,
            'range' => $range,
            'currency' => (string) Env::get('CURRENCY_SYMBOL', '₹'),
        ]);
    }

    /**
     * The window every analysis is asked for.
     *
     * Defaults to the last twelve months so the trends have something to be a
     * trend of. Both ends are passed through only when they look like dates;
     * the service validates them again and would refuse anything else.
     *
     * @return array<string, string>
     */
    private function range(): array
    {
        $from = $this->input('from');
        $to = $this->input('to');

        $valid = static fn (string $value): bool =>
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;

        if (!$valid($from) || !$valid($to) || $from > $to) {
            $to = date('Y-m-d');
            $from = date('Y-m-d', strtotime('-11 months', strtotime(date('Y-m-01'))));
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Chart specifications for the renderer in charts.js.
     *
     * Every series is built from values the service returned. Nothing here
     * invents a number, and a section that did not answer contributes no
     * chart rather than an empty one.
     *
     * @param array<string, mixed> $sections
     * @return array<string, array<string, mixed>>
     */
    private function charts(array $sections): array
    {
        $charts = [];

        $attendance = $this->asArray($sections['attendance'] ?? null);
        $series = $this->rows($attendance['series'] ?? null);

        if ($series !== []) {
            $charts['attendance_rate'] = [
                'type' => 'line',
                'labels' => array_map(static fn (array $r): string => (string) ($r['label'] ?? ''), $series),
                'values' => array_map(static fn (array $r): float => (float) ($r['attendance_rate'] ?? 0), $series),
                'format' => 'percent',
                'colour' => '#16a34a',
            ];

            $charts['worked_hours'] = [
                'type' => 'bar',
                'labels' => array_map(static fn (array $r): string => (string) ($r['label'] ?? ''), $series),
                'values' => array_map(static fn (array $r): float => (float) ($r['worked_hours'] ?? 0), $series),
                'format' => 'number',
            ];
        }

        $leave = $this->asArray($sections['leave'] ?? null);
        $byType = $this->rows($leave['by_type'] ?? null);

        if ($byType !== []) {
            $charts['leave_by_type'] = [
                'type' => 'donut',
                'labels' => array_map(static fn (array $r): string => (string) ($r['leave_type'] ?? 'Leave'), $byType),
                'values' => array_map(static fn (array $r): float => (float) ($r['approved_days'] ?? 0), $byType),
                'format' => 'number',
                'centreLabel' => 'days approved',
            ];
        }

        $byMonth = $this->rows($leave['by_month'] ?? null);

        if ($byMonth !== []) {
            $charts['leave_by_month'] = [
                'type' => 'bar',
                'labels' => array_map(static fn (array $r): string => (string) ($r['label'] ?? ''), $byMonth),
                'values' => array_map(static fn (array $r): float => (float) ($r['days'] ?? 0), $byMonth),
                'format' => 'number',
            ];
        }

        $headcount = $this->asArray($sections['headcount'] ?? null);
        $trend = $this->rows($headcount['trend'] ?? null);

        if ($trend !== []) {
            $charts['headcount_trend'] = [
                'type' => 'line',
                'labels' => array_map(static fn (array $r): string => (string) ($r['label'] ?? ''), $trend),
                'values' => array_map(static fn (array $r): int => (int) ($r['headcount'] ?? 0), $trend),
                'format' => 'number',
            ];
        }

        $tenure = $this->rows($headcount['tenure']['bands'] ?? null);

        if ($tenure !== []) {
            $charts['tenure'] = [
                'type' => 'bar',
                'labels' => array_map(static fn (array $r): string => (string) ($r['label'] ?? ''), $tenure),
                'values' => array_map(static fn (array $r): int => (int) ($r['value'] ?? 0), $tenure),
                'format' => 'number',
            ];
        }

        $payroll = $this->asArray($sections['payroll'] ?? null);
        $months = $this->rows($payroll['months'] ?? null);

        // Months with no run are dropped rather than drawn as a zero bar: the
        // company did not spend nothing that month, payroll has simply not been
        // run for it yet.
        $months = array_values(array_filter(
            $months,
            static fn (array $r): bool => (int) ($r['run_count'] ?? 0) > 0
        ));

        if ($months !== []) {
            $charts['payroll_cost'] = [
                'type' => 'bar',
                'labels' => array_map(static fn (array $r): string => (string) ($r['label'] ?? ''), $months),
                'values' => array_map(static fn (array $r): float => ((int) ($r['net_minor'] ?? 0)) / 100, $months),
                'format' => 'currency',
            ];
        }

        $learning = $this->asArray($sections['learning'] ?? null);
        $byCourse = array_slice($this->rows($learning['by_course'] ?? null), 0, 10);

        if ($byCourse !== []) {
            $charts['course_completion'] = [
                'type' => 'bar',
                'labels' => array_map(static fn (array $r): string => (string) ($r['title'] ?? $r['course'] ?? ''), $byCourse),
                'values' => array_map(static fn (array $r): float => (float) ($r['completion_rate'] ?? 0), $byCourse),
                'format' => 'percent',
            ];
        }

        return $charts;
    }

    /** @return array<string, mixed> */
    private function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
