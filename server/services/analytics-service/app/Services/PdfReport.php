<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Pdf\PdfDocument;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;

/**
 * Lays a report out as a printable PDF.
 *
 * The filters that produced the data are printed on the first page. A report
 * that shows figures without saying which window and which scope produced them
 * is worse than no report, because a reader has no way to tell one month's
 * export from another once it has been printed or emailed on.
 */
final class PdfReport
{
    private const MARGIN = 12.0;
    private const ROW_HEIGHT = 6.5;
    private const FOOTER_HEIGHT = 14.0;

    /** Height of the title block on the first page. */
    private const HEADER_HEIGHT = 38.0;

    /** Height of the running header on continuation pages. */
    private const CONTINUATION_HEIGHT = 16.0;

    /**
     * @param array<string, mixed>                                  $definition
     * @param list<array{key: string, label: string, type?: string}> $columns
     * @param list<array<string, mixed>>                             $rows
     * @param array<string, mixed>                                   $filters
     */
    public static function render(array $definition, array $columns, array $rows, array $filters, string $generatedBy): string
    {
        $title = (string) ($definition['name'] ?? 'Report');
        $document = new PdfDocument('A4', $title);

        $contentWidth = $document->pageWidth() - (self::MARGIN * 2);
        $widths = self::columnWidths($document, $columns, $contentWidth);
        $alignments = array_map(
            static fn (array $column): string => in_array((string) ($column['type'] ?? 'text'), ['number', 'money'], true) ? 'right' : 'left',
            $columns
        );
        $headers = array_map(static fn (array $column): string => (string) $column['label'], $columns);

        $pages = self::paginate($document->pageHeight(), $rows);
        $pageCount = count($pages);

        foreach ($pages as $index => $pageRows) {
            if ($index > 0) {
                $document->addPage();
            }

            $cursor = $index === 0
                ? self::drawTitleBlock($document, $title, $definition, $filters, $generatedBy, $contentWidth, count($rows))
                : self::drawContinuation($document, $title, $contentWidth);

            $document->table(
                self::MARGIN,
                $cursor,
                $headers,
                array_map(
                    static fn (array $row): array => array_map(
                        static fn (array $column): string => self::cell($row[$column['key']] ?? ''),
                        $columns
                    ),
                    $pageRows
                ),
                $widths,
                $alignments,
                8,
                self::ROW_HEIGHT
            );

            self::drawFooter($document, $index + 1, $pageCount, $contentWidth);
        }

        return $document->output();
    }

    /**
     * Splits rows across pages.
     *
     * The first page gives room to the title block, so it holds fewer rows than
     * the ones after it. An empty report still produces one page, which is a
     * meaningful answer rather than a corrupt file.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<list<array<string, mixed>>>
     */
    private static function paginate(float $pageHeight, array $rows): array
    {
        $usable = $pageHeight - self::FOOTER_HEIGHT - self::MARGIN;

        $firstCapacity = (int) floor((($usable - self::HEADER_HEIGHT) / self::ROW_HEIGHT) - 1);
        $nextCapacity = (int) floor((($usable - self::CONTINUATION_HEIGHT) / self::ROW_HEIGHT) - 1);

        $firstCapacity = max(1, $firstCapacity);
        $nextCapacity = max(1, $nextCapacity);

        if ($rows === []) {
            return [[]];
        }

        $pages = [array_slice($rows, 0, $firstCapacity)];
        $remaining = array_slice($rows, $firstCapacity);

        foreach (array_chunk($remaining, $nextCapacity) as $chunk) {
            $pages[] = $chunk;
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $filters
     */
    private static function drawTitleBlock(
        PdfDocument $document,
        string $title,
        array $definition,
        array $filters,
        string $generatedBy,
        float $contentWidth,
        int $rowCount,
    ): float {
        $right = self::MARGIN + $contentWidth;

        $document->rect(self::MARGIN, self::MARGIN, $contentWidth, 1.2, [0.13, 0.23, 0.42], true);
        $document->text(self::MARGIN, self::MARGIN + 3.5, Env::get('APP_NAME', 'Dayflow'), 8, 'B', [0.42, 0.45, 0.5]);
        $document->textRight($right, self::MARGIN + 3.5, 'Generated ' . Clock::now()->format('d M Y H:i'), 8, '', [0.42, 0.45, 0.5]);

        $document->text(self::MARGIN, self::MARGIN + 9.0, $title, 16, 'B', [0.09, 0.13, 0.24]);

        $description = (string) ($definition['description'] ?? '');
        if ($description !== '') {
            $document->text(self::MARGIN, self::MARGIN + 17.0, $description, 8.5, '', [0.35, 0.39, 0.45]);
        }

        $document->text(self::MARGIN, self::MARGIN + 23.0, 'Filters applied', 8, 'B', [0.09, 0.13, 0.24]);
        $document->text(self::MARGIN, self::MARGIN + 27.5, self::describeFilters($filters), 8, '', [0.35, 0.39, 0.45]);

        $document->textRight($right, self::MARGIN + 23.0, sprintf('%d row%s', $rowCount, $rowCount === 1 ? '' : 's'), 8, 'B', [0.09, 0.13, 0.24]);
        $document->textRight($right, self::MARGIN + 27.5, 'Run by ' . $generatedBy, 8, '', [0.35, 0.39, 0.45]);

        return self::HEADER_HEIGHT;
    }

    private static function drawContinuation(PdfDocument $document, string $title, float $contentWidth): float
    {
        $document->text(self::MARGIN, self::MARGIN, $title . ' (continued)', 9, 'B', [0.35, 0.39, 0.45]);
        $document->line(self::MARGIN, self::MARGIN + 5.0, self::MARGIN + $contentWidth, self::MARGIN + 5.0, 0.2, [0.8, 0.83, 0.87]);

        return self::CONTINUATION_HEIGHT;
    }

    private static function drawFooter(PdfDocument $document, int $pageNumber, int $pageCount, float $contentWidth): void
    {
        $y = $document->pageHeight() - self::FOOTER_HEIGHT;

        $document->line(self::MARGIN, $y, self::MARGIN + $contentWidth, $y, 0.2, [0.8, 0.83, 0.87]);
        $document->text(self::MARGIN, $y + 2.0, 'Confidential - contains personal data', 7.5, '', [0.55, 0.58, 0.62]);
        $document->textRight(
            self::MARGIN + $contentWidth,
            $y + 2.0,
            sprintf('Page %d of %d', $pageNumber, $pageCount),
            7.5,
            '',
            [0.55, 0.58, 0.62]
        );
    }

    /**
     * Distributes the page width across the columns.
     *
     * Numbers and dates need a predictable, narrow column; free text should
     * absorb whatever is left, because that is where truncation hurts most.
     *
     * @param list<array{key: string, label: string, type?: string}> $columns
     * @return list<float>
     */
    private static function columnWidths(PdfDocument $document, array $columns, float $contentWidth): array
    {
        if ($columns === []) {
            return [];
        }

        $weights = array_map(
            static fn (array $column): float => match ((string) ($column['type'] ?? 'text')) {
                'number' => 1.0,
                'money' => 1.2,
                'date' => 1.1,
                default => 2.0,
            },
            $columns
        );

        $totalWeight = array_sum($weights);
        $widths = [];

        foreach ($weights as $index => $weight) {
            $label = (string) $columns[$index]['label'];
            $minimum = min($document->widthOf($label, 8, 'B') + 4.0, $contentWidth / count($columns));
            $widths[] = max($minimum, ($contentWidth * $weight) / $totalWeight);
        }

        // Rounding and the per-column minimum can push the row past the page,
        // so the widths are scaled back to exactly the available space.
        $total = array_sum($widths);
        if ($total > $contentWidth) {
            $scale = $contentWidth / $total;
            $widths = array_map(static fn (float $width): float => $width * $scale, $widths);
        }

        return $widths;
    }

    /** @param array<string, mixed> $filters */
    private static function describeFilters(array $filters): string
    {
        $parts = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $parts[] = sprintf(
                '%s: %s',
                ucfirst(str_replace('_', ' ', (string) $key)),
                is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value
            );
        }

        return $parts === [] ? 'None' : implode('   |   ', $parts);
    }

    private static function cell(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return implode('; ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
        }

        return $value === null ? '' : (string) $value;
    }
}
