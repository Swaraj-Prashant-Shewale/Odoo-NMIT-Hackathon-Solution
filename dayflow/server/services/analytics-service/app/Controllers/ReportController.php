<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ReportDefinitions;
use App\Models\ReportRuns;
use App\Policies\ReportPolicy;
use App\Services\CsvWriter;
use App\Services\Downstream;
use App\Services\EmployeeDirectory;
use App\Services\PdfReport;
use App\Services\ReportRunner;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/**
 * The report catalogue, and running or exporting one report from it.
 *
 * Two checks stand between a caller and a report: the route establishes that
 * they may use the reporting screen, and the permission recorded on the stored
 * definition establishes that they may run this particular one. The second
 * check is performed on every request, including the export, because a
 * catalogue that merely hides a report a caller could still run by guessing its
 * slug would be no protection at all.
 */
final class ReportController
{
    private ReportDefinitions $definitions;

    private ReportRuns $runs;

    public function __construct()
    {
        $this->definitions = new ReportDefinitions();
        $this->runs = new ReportRuns();
    }

    /** The reports this caller may actually run. */
    public function index(Request $request): Response
    {
        $principal = $request->principal();
        $visible = ReportPolicy::visible($principal, $this->definitions->active());

        $catalogue = array_map(
            static fn (array $definition): array => [
                'id' => $definition['id'],
                'name' => $definition['name'],
                'slug' => $definition['slug'],
                'description' => $definition['description'],
                'report_type' => $definition['report_type'],
                'default_filters' => $definition['default_filters'] ?? [],
                'required_permission' => $definition['required_permission'],
                'can_export' => ReportPolicy::mayExport($principal),
            ],
            $visible
        );

        return Response::ok($catalogue, ['total' => count($catalogue)]);
    }

    /** Runs one report and returns its rows together with the column metadata. */
    public function show(Request $request): Response
    {
        $principal = $request->principal();
        $definition = $this->definition($request);

        ReportPolicy::assertMayRun($principal, $definition);

        $runner = $this->runner($request);
        $filters = $runner->resolveFilters($definition, $this->filters($request));

        $startedAt = hrtime(true);
        $result = $runner->run($definition, $filters, $principal);
        $duration = $this->elapsedMs($startedAt);

        $this->recordRun($definition, $principal, $filters, count($result['rows']), 'json', $duration);

        return Response::ok([
            'report' => [
                'name' => $definition['name'],
                'slug' => $definition['slug'],
                'description' => $definition['description'],
                'report_type' => $definition['report_type'],
            ],
            'filters' => $filters,
            'columns' => $result['columns'],
            'rows' => $result['rows'],
            'summary' => $result['summary'],
        ], [
            'row_count' => count($result['rows']),
            'duration_ms' => $duration,
            'generated_at' => Clock::iso(),
        ]);
    }

    /**
     * Exports one report as CSV or PDF.
     *
     * An export is the moment personal data leaves the platform, so it is
     * recorded twice over: as a report run with the exact filters used, and in
     * the audit trail with the actor, the address they called from and the
     * number of rows they took.
     */
    public function export(Request $request): Response
    {
        $principal = $request->principal();

        // The route already requires report.export. Asserting it again here
        // keeps the rule true for any future caller of this method.
        ReportPolicy::assertMayExport($principal);

        $definition = $this->definition($request);
        ReportPolicy::assertMayRun($principal, $definition);

        $format = (string) (Validator::make($request->all(), [
            'format' => 'nullable|in:csv,pdf',
        ])->validated()['format'] ?? 'csv');

        $runner = $this->runner($request);
        $filters = $runner->resolveFilters($definition, $this->filters($request));

        $startedAt = hrtime(true);
        $result = $runner->run($definition, $filters, $principal);
        $duration = $this->elapsedMs($startedAt);
        $rowCount = count($result['rows']);

        $this->recordRun($definition, $principal, $filters, $rowCount, $format, $duration);

        AuditLog::record(
            $request,
            'report.exported',
            'report_definition',
            (string) $definition['id'],
            [],
            [
                'slug' => $definition['slug'],
                'format' => $format,
                'row_count' => $rowCount,
                'filters' => $filters,
            ]
        );

        $filename = sprintf('%s-%s.%s', $definition['slug'], Clock::today(), $format);

        if ($format === 'pdf') {
            return Response::download(
                PdfReport::render($definition, $result['columns'], $result['rows'], $filters, $principal->displayName),
                $filename,
                'application/pdf'
            );
        }

        return Response::download(
            CsvWriter::render($result['columns'], $result['rows']),
            $filename,
            'text/csv; charset=utf-8'
        );
    }

    /**
     * @return array<string, mixed>
     * @throws HttpException 404 when no active definition carries the slug.
     */
    private function definition(Request $request): array
    {
        $slug = Validator::make(
            ['slug' => $request->route('slug')],
            ['slug' => 'required|slug|max:80']
        )->validated()['slug'];

        $definition = $this->definitions->activeBySlug((string) $slug);

        if ($definition === null) {
            throw HttpException::notFound('No report with that name is available.');
        }

        return $definition;
    }

    /**
     * The validated filters a report accepts.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $data = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'period' => 'nullable|max:7',
            'department_id' => 'nullable|uuid',
            'cycle_id' => 'nullable|uuid',
            'status' => 'nullable|in:draft,pending,submitted,approved,rejected,cancelled,withdrawn,reimbursed,processing,paid',
            'days' => 'nullable|integer|between:1,365',
        ], [
            'from' => 'Start date',
            'to' => 'End date',
        ])->validated();

        if (isset($data['period']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $data['period']) !== 1) {
            throw HttpException::unprocessable('A period must be written as YYYY-MM.', [
                'period' => ['A period must be written as YYYY-MM.'],
            ]);
        }

        return array_filter($data, static fn (mixed $value): bool => $value !== null);
    }

    private function runner(Request $request): ReportRunner
    {
        $downstream = new Downstream($request->bearerToken());

        return new ReportRunner($downstream, new EmployeeDirectory($downstream));
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $filters
     */
    private function recordRun(
        array $definition,
        Principal $principal,
        array $filters,
        int $rowCount,
        string $format,
        int $durationMs,
    ): void {
        $this->runs->record(
            (string) $definition['id'],
            $principal->userId,
            $filters,
            $rowCount,
            $format,
            $durationMs
        );
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
