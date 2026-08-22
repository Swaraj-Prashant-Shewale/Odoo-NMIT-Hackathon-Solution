<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Api;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Security\Permissions;

/**
 * The report catalogue, and running or exporting one report from it.
 *
 * The report set is data driven: analytics-service stores each definition with
 * its name, its type, the permission that governs it and the filters it
 * defaults to, and returns the column metadata alongside the rows when it runs.
 * So there is one page here, not twelve. The filter panel and the table headers
 * are both built from what the service describes, which means a report added to
 * the catalogue appears and works without this client being touched.
 *
 * Nothing here decides who may run what. The catalogue already contains only
 * the reports this caller may run, and the service checks the stored permission
 * again on every run and every export.
 */
final class ReportController extends Controller
{
    /** Report types whose runners narrow their query by department. */
    private const DEPARTMENT_SCOPED = ['attendance', 'leave', 'learning'];

    /** Claim statuses the expense report accepts. */
    private const CLAIM_STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'reimbursed'];

    /** A chart is only worth drawing while the categories still fit across one. */
    private const MAX_CHART_CATEGORIES = 20;

    // -----------------------------------------------------------------------
    // Catalogue
    // -----------------------------------------------------------------------

    public function index(): void
    {
        $response = Api::get('/reports');
        $this->guard($response, '/');

        $reports = $this->rows($response);
        $grouped = [];

        foreach ($reports as $report) {
            $grouped[(string) ($report['report_type'] ?? 'other')][] = $report;
        }

        ksort($grouped);

        View::render('reports/index', [
            'pageTitle' => 'Reports',
            'breadcrumbs' => [['Reports', '']],
            'grouped' => $grouped,
            'total' => count($reports),
            'mayExport' => Session::can(Permissions::REPORT_EXPORT),
        ]);
    }

    // -----------------------------------------------------------------------
    // Running one report
    // -----------------------------------------------------------------------

    public function show(array $parameters = []): void
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $requested = $this->reportFilters();

        $response = Api::get('/reports/' . rawurlencode($slug), $requested);
        $this->guard($response, '/reports');

        $data = is_array($response['data']) ? $response['data'] : [];
        $report = is_array($data['report'] ?? null) ? $data['report'] : ['name' => $slug, 'slug' => $slug];
        $columns = $this->records($data['columns'] ?? null);
        $rows = $this->records($data['rows'] ?? null);
        $applied = is_array($data['filters'] ?? null) ? $data['filters'] : [];

        $definition = $this->definition($slug, $report);
        $fields = $this->filterFields($definition, $applied);

        View::render('reports/show', [
            'pageTitle' => (string) ($report['name'] ?? 'Report'),
            'breadcrumbs' => [['Reports', '/reports'], [(string) ($report['name'] ?? 'Report'), '']],
            'report' => $report,
            'slug' => $slug,
            'columns' => $columns,
            'rows' => $rows,
            'summary' => is_array($data['summary'] ?? null) ? $data['summary'] : [],
            'applied' => $applied,
            'requested' => $requested,
            'fields' => $fields,
            'departments' => in_array('department', $fields, true) ? Api::collection('/departments') : [],
            'cycles' => in_array('cycle', $fields, true) ? $this->cycles() : [],
            'statuses' => self::CLAIM_STATUSES,
            'chart' => $this->chart($columns, $rows),
            'rowCount' => (int) ($response['meta']['row_count'] ?? count($rows)),
            'durationMs' => (int) ($response['meta']['duration_ms'] ?? 0),
            'generatedAt' => (string) ($response['meta']['generated_at'] ?? ''),
            'mayExport' => Session::can(Permissions::REPORT_EXPORT),
        ]);
    }

    /**
     * Streams the report as a file.
     *
     * Analytics-service records the export twice over before handing it back:
     * once as a report run with the exact filters used, and once in the audit
     * trail with the actor and the number of rows they took. That is the point
     * at which personal data leaves the platform.
     */
    public function export(array $parameters = []): void
    {
        $slug = (string) ($parameters['slug'] ?? '');
        $format = $this->input('format', 'csv') === 'pdf' ? 'pdf' : 'csv';

        $query = $this->reportFilters() + ['format' => $format];

        if (Api::stream('/reports/' . rawurlencode($slug) . '/export', $query)) {
            return;
        }

        Flash::error('That export could not be produced. Please try again in a moment.');
        $this->back('/reports/' . rawurlencode($slug));
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The filters a report accepts, taken straight from the query string.
     *
     * Everything is passed through as text and validated by the service, which
     * is the only place that knows what a given report will accept. Empty
     * fields are dropped so the definition's own defaults still apply.
     *
     * @return array<string, string>
     */
    private function reportFilters(): array
    {
        $filters = [
            'from' => $this->input('from'),
            'to' => $this->input('to'),
            'period' => $this->input('period'),
            'department_id' => $this->input('department_id'),
            'cycle_id' => $this->input('cycle_id'),
            'status' => $this->input('status'),
            'days' => $this->input('days'),
        ];

        return array_filter($filters, static fn (string $value): bool => $value !== '');
    }

    /**
     * The stored definition behind a slug.
     *
     * The run itself does not return the default filters, and those are what
     * say whether a report thinks in date ranges or in a number of days ahead,
     * so the catalogue is read alongside it.
     *
     * @param array<string, mixed> $fallback
     * @return array<string, mixed>
     */
    private function definition(string $slug, array $fallback): array
    {
        foreach (Api::collection('/reports') as $candidate) {
            if ((string) ($candidate['slug'] ?? '') === $slug) {
                return $candidate;
            }
        }

        return $fallback;
    }

    /**
     * Which filter controls this report should be drawn with.
     *
     * Derived from the two things the service publishes about a report: the
     * defaults it carries, which say whether it thinks in a window or in a
     * number of days, and its type, which says whether its runner narrows by
     * department, by claim status or by review cycle.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $applied The filters the service resolved.
     * @return list<string>
     */
    private function filterFields(array $definition, array $applied): array
    {
        $defaults = is_array($definition['default_filters'] ?? null) ? $definition['default_filters'] : [];
        $type = (string) ($definition['report_type'] ?? '');

        $fields = [];

        if (isset($defaults['range']) || isset($defaults['period']) || isset($applied['from']) || isset($applied['to'])) {
            $fields[] = 'range';
        }

        if (isset($defaults['days']) || isset($applied['days'])) {
            $fields[] = 'days';
        }

        if (in_array($type, self::DEPARTMENT_SCOPED, true)) {
            $fields[] = 'department';
        }

        if ($type === 'expense') {
            $fields[] = 'status';
        }

        if ($type === 'performance') {
            $fields[] = 'cycle';
        }

        return $fields;
    }

    /**
     * Review cycles, for the one report that narrows by them.
     *
     * @return list<array<string, mixed>>
     */
    private function cycles(): array
    {
        $response = Api::get('/reviews/cycles', ['per_page' => 100]);

        return $response['ok'] ? $this->rows($response) : [];
    }

    /**
     * A chart specification for this result, or null when the shape does not
     * suit one.
     *
     * Two shapes are worth drawing. A table carrying a share of a whole reads
     * best as a donut; a short table of categories against one measure reads
     * best as bars. A per-person register of four hundred rows reads best as a
     * table and nothing else, so it gets no chart at all.
     *
     * @param list<array<string, mixed>> $columns
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function chart(array $columns, array $rows): ?array
    {
        $count = count($rows);

        if ($count < 2 || $count > self::MAX_CHART_CATEGORIES) {
            return null;
        }

        $labelColumn = null;
        $shareColumn = null;
        $valueColumn = null;

        foreach ($columns as $column) {
            $key = (string) ($column['key'] ?? '');
            $type = (string) ($column['type'] ?? 'text');

            if ($key === '') {
                continue;
            }

            if ($labelColumn === null && $type === 'text') {
                $labelColumn = $column;

                continue;
            }

            if ($shareColumn === null && str_ends_with($key, '_percent')) {
                $shareColumn = $column;

                continue;
            }

            if ($valueColumn === null && in_array($type, ['number', 'money'], true)) {
                $valueColumn = $column;
            }
        }

        $measure = $shareColumn ?? $valueColumn;

        if ($labelColumn === null || $measure === null) {
            return null;
        }

        $labels = [];
        $values = [];

        foreach ($rows as $row) {
            $labels[] = (string) ($row[(string) $labelColumn['key']] ?? '');
            $values[] = (float) ($row[(string) $measure['key']] ?? 0);
        }

        // Every value zero draws an empty frame that says nothing.
        if (array_sum($values) <= 0.0) {
            return null;
        }

        $isShare = $shareColumn !== null;

        return [
            'type' => $isShare ? 'donut' : 'bar',
            'labels' => $labels,
            'values' => $values,
            'format' => $isShare
                ? 'percent'
                : ((string) ($measure['type'] ?? 'number') === 'money' ? 'money' : 'number'),
            'legend' => $isShare,
            'colourByIndex' => !$isShare,
            'title' => (string) ($measure['label'] ?? ''),
        ];
    }

    /**
     * @param array{data?: mixed} $response
     * @return list<array<string, mixed>>
     */
    private function rows(array $response): array
    {
        return $this->records($response['data'] ?? null);
    }

    /**
     * Keeps only the array members of a decoded list.
     *
     * @return list<array<string, mixed>>
     */
    private function records(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
