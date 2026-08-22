<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditEntries;
use Dayflow\Kernel\Audit\AuditLog;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Validation\Validator;

/**
 * Reading the platform-wide audit trail.
 *
 * Every service writes to one table in the shared schema, so a single query
 * answers "what happened to this person, everywhere?" rather than nine. The
 * trail is append-only by database grant, and this service is the only one
 * permitted to read it back, which is why both routes sit behind their own
 * permissions rather than being open to anyone who can administer accounts.
 */
final class AuditController
{
    /** A hard ceiling on an export, so one click cannot pull the whole trail into memory. */
    private const EXPORT_LIMIT = 10000;

    private AuditEntries $entries;

    public function __construct()
    {
        $this->entries = new AuditEntries();
    }

    /** A filtered page of the trail. */
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Response::page($this->entries->search($filters, $request->page(), $request->perPage(50)));
    }

    /**
     * The same view as a spreadsheet.
     *
     * Reading the trail is itself an event worth recording: an export copies
     * the security history of every person in the company out of the system,
     * so the fact that it happened, and who did it, belongs in the trail too.
     */
    public function export(Request $request): Response
    {
        $filters = $this->filters($request);
        $rows = $this->entries->export($filters, self::EXPORT_LIMIT);

        AuditLog::record($request, 'identity.audit.exported', 'audit_log', null, [], [], [
            'filters' => $filters,
            'row_count' => count($rows),
        ]);

        $filename = sprintf('dayflow-audit-%s.csv', Clock::now()->format('Y-m-d-His'));

        return Response::download($this->toCsv($rows), $filename, 'text/csv; charset=utf-8');
    }

    /**
     * Reads and normalises the filter set.
     *
     * Dates arrive as calendar days and are widened into the instants that
     * bracket them in the configured business timezone; comparing a bare date
     * against a TIMESTAMPTZ stored in UTC would quietly shift the window.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $data = Validator::make($request->all(), [
            'actor' => 'nullable|uuid',
            'action' => 'nullable|safe_text|max:80',
            'subject' => 'nullable|slug|max:60',
            'subject_id' => 'nullable|safe_text|max:80',
            'service' => 'nullable|safe_text|max:60',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ])->validated();

        $filters = array_filter($data, static fn (mixed $value): bool => $value !== null);

        if (isset($filters['from'])) {
            $filters['from'] = Clock::parse((string) $filters['from'])
                ->setTime(0, 0, 0)
                ->format(\DateTimeInterface::ATOM);
        }

        if (isset($filters['to'])) {
            $filters['to'] = Clock::parse((string) $filters['to'])
                ->setTime(23, 59, 59)
                ->format(\DateTimeInterface::ATOM);
        }

        return $filters;
    }

    /**
     * Renders the trail as RFC 4180 CSV.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        // A byte order mark so spreadsheet software reads the file as UTF-8
        // rather than guessing a local codepage and mangling every name.
        fwrite($handle, "\xEF\xBB\xBF");

        $headers = [
            'occurred_at', 'service', 'action', 'subject_type', 'subject_id',
            'actor_id', 'actor_email', 'actor_role', 'ip_address', 'request_id', 'context',
        ];

        fputcsv($handle, $headers, ',', '"', '');

        foreach ($rows as $row) {
            $line = [];

            foreach ($headers as $column) {
                $value = $row[$column] ?? null;

                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $line[] = self::neutralise((string) ($value ?? ''));
            }

            fputcsv($handle, $line, ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Stops a cell being executed as a formula.
     *
     * Spreadsheet software treats a value opening with =, +, - or @ as a
     * formula, so a user agent string crafted to start with one becomes code
     * the moment an administrator opens the export. Prefixing a quote keeps the
     * text readable and inert.
     */
    private static function neutralise(string $value): string
    {
        if ($value === '' || !in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return $value;
        }

        return "'" . $value;
    }
}
