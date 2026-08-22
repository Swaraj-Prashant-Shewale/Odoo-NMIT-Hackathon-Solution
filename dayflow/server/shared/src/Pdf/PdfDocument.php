<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Pdf;

/**
 * A small, self-contained PDF writer.
 *
 * Payslips and report exports have to be real PDF files, but pulling in a full
 * typesetting library for a one-page salary statement is not a good trade. This
 * class emits valid PDF 1.4 using the fourteen standard fonts, which every
 * reader has built in, so no font data has to be embedded.
 *
 * Coordinates are given in millimetres from the top-left corner, which is how
 * a page is naturally described; the conversion to PDF's bottom-left origin in
 * points happens internally.
 */
final class PdfDocument
{
    private const MM_TO_PT = 2.834645669;

    /** Appended when a table cell has to be shortened to fit its column. */
    private const ELLIPSIS = '...';

    /** Widths of Helvetica glyphs, in 1/1000 em, indexed by character code. */
    private const HELVETICA_WIDTHS = [
        32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191,
        40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
        48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
        56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556,
        64 => 1015, 65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
        72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778,
        80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
        88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556,
        96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
        104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556,
        111 => 556, 112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556,
        118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260,
        125 => 334, 126 => 584,
    ];

    private float $pageWidth;
    private float $pageHeight;

    /** @var list<string> Content stream for each page. */
    private array $pages = [];

    private string $buffer = '';

    private string $currentFont = 'F1';
    private float $currentSize = 10.0;

    /** @param 'A4'|'LETTER' $size */
    public function __construct(string $size = 'A4', private readonly string $title = 'Document')
    {
        [$this->pageWidth, $this->pageHeight] = $size === 'LETTER' ? [215.9, 279.4] : [210.0, 297.0];
        $this->addPage();
    }

    public function addPage(): self
    {
        if ($this->buffer !== '') {
            $this->pages[] = $this->buffer;
        }

        $this->buffer = '';

        return $this;
    }

    public function pageWidth(): float
    {
        return $this->pageWidth;
    }

    public function pageHeight(): float
    {
        return $this->pageHeight;
    }

    /**
     * Draws a line of text.
     *
     * @param string $style '' regular, 'B' bold, 'I' italic, 'BI' bold italic.
     */
    public function text(float $x, float $y, string $text, float $size = 10, string $style = '', array $rgb = [0, 0, 0]): self
    {
        $font = match (strtoupper($style)) {
            'B' => 'F2',
            'I' => 'F3',
            'BI' => 'F4',
            default => 'F1',
        };

        $this->currentFont = $font;
        $this->currentSize = $size;

        $this->buffer .= sprintf(
            "BT %s %s %s rg /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $this->colour($rgb[0]),
            $this->colour($rgb[1]),
            $this->colour($rgb[2]),
            $font,
            $size,
            $this->px($x),
            $this->py($y) - $size,
            $this->escape($text)
        );

        return $this;
    }

    /** Draws text right-aligned to $right. */
    public function textRight(float $right, float $y, string $text, float $size = 10, string $style = '', array $rgb = [0, 0, 0]): self
    {
        return $this->text($right - $this->widthOf($text, $size, $style), $y, $text, $size, $style, $rgb);
    }

    /** Draws text centred inside the horizontal span $left..$right. */
    public function textCentre(float $left, float $right, float $y, string $text, float $size = 10, string $style = '', array $rgb = [0, 0, 0]): self
    {
        $x = $left + (($right - $left) - $this->widthOf($text, $size, $style)) / 2;

        return $this->text($x, $y, $text, $size, $style, $rgb);
    }

    /**
     * Wraps text to a column width and returns the y position after the block.
     */
    public function paragraph(float $x, float $y, float $width, string $text, float $size = 10, float $leading = 1.4, string $style = ''): float
    {
        foreach ($this->wrap($text, $width, $size, $style) as $line) {
            $this->text($x, $y, $line, $size, $style);
            $y += ($size / self::MM_TO_PT) * $leading;
        }

        return $y;
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $thickness = 0.2, array $rgb = [0, 0, 0]): self
    {
        $this->buffer .= sprintf(
            "%.2F w %s %s %s RG %.2F %.2F m %.2F %.2F l S\n",
            $thickness,
            $this->colour($rgb[0]),
            $this->colour($rgb[1]),
            $this->colour($rgb[2]),
            $this->px($x1),
            $this->py($y1),
            $this->px($x2),
            $this->py($y2)
        );

        return $this;
    }

    public function rect(float $x, float $y, float $width, float $height, array $rgb = [0, 0, 0], bool $filled = true, float $thickness = 0.2): self
    {
        $this->buffer .= sprintf(
            "%.2F w %s %s %s %s %.2F %.2F %.2F %.2F re %s\n",
            $thickness,
            $this->colour($rgb[0]),
            $this->colour($rgb[1]),
            $this->colour($rgb[2]),
            $filled ? 'rg' : 'RG',
            $this->px($x),
            $this->py($y + $height),
            $width * self::MM_TO_PT,
            $height * self::MM_TO_PT,
            $filled ? 'f' : 'S'
        );

        return $this;
    }

    /**
     * Renders a simple table and returns the y position below it.
     *
     * @param list<string>        $headers
     * @param list<list<string>>  $rows
     * @param list<float>         $widths     Column widths in millimetres.
     * @param list<'left'|'right'> $alignments Per column; defaults to left.
     */
    public function table(
        float $x,
        float $y,
        array $headers,
        array $rows,
        array $widths,
        array $alignments = [],
        float $size = 9,
        float $rowHeight = 7,
    ): float {
        $totalWidth = array_sum($widths);

        // Header band.
        $this->rect($x, $y, $totalWidth, $rowHeight, [0.13, 0.23, 0.42], true);

        $cursor = $x;
        foreach ($headers as $index => $header) {
            $width = $widths[$index] ?? 20;
            $align = $alignments[$index] ?? 'left';

            if ($align === 'right') {
                $this->textRight($cursor + $width - 2, $y + 1.8, $header, $size, 'B', [1, 1, 1]);
            } else {
                $this->text($cursor + 2, $y + 1.8, $header, $size, 'B', [1, 1, 1]);
            }

            $cursor += $width;
        }

        $y += $rowHeight;

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex % 2 === 1) {
                $this->rect($x, $y, $totalWidth, $rowHeight, [0.96, 0.97, 0.99], true);
            }

            $cursor = $x;
            foreach ($row as $index => $cell) {
                $width = $widths[$index] ?? 20;
                $align = $alignments[$index] ?? 'left';
                $value = $this->truncate((string) $cell, $width - 4, $size);

                if ($align === 'right') {
                    $this->textRight($cursor + $width - 2, $y + 1.8, $value, $size);
                } else {
                    $this->text($cursor + 2, $y + 1.8, $value, $size);
                }

                $cursor += $width;
            }

            $this->line($x, $y + $rowHeight, $x + $totalWidth, $y + $rowHeight, 0.1, [0.85, 0.87, 0.9]);
            $y += $rowHeight;
        }

        return $y;
    }

    /** Width of a string in millimetres at the given size. */
    public function widthOf(string $text, float $size, string $style = ''): float
    {
        $units = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $units += self::HELVETICA_WIDTHS[ord($text[$i])] ?? 556;
        }

        // Bold glyphs are marginally wider; a flat factor is close enough for
        // layout purposes and avoids carrying a second width table.
        $factor = str_contains(strtoupper($style), 'B') ? 1.05 : 1.0;

        return (($units / 1000) * $size * $factor) / self::MM_TO_PT;
    }

    /** @return list<string> */
    public function wrap(string $text, float $width, float $size, string $style = ''): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $text) ?: [$text] as $paragraph) {
            $current = '';

            foreach (explode(' ', $paragraph) as $word) {
                $candidate = $current === '' ? $word : $current . ' ' . $word;

                if ($this->widthOf($candidate, $size, $style) <= $width) {
                    $current = $candidate;
                    continue;
                }

                if ($current !== '') {
                    $lines[] = $current;
                }
                $current = $word;
            }

            $lines[] = $current;
        }

        return $lines;
    }

    /** Shortens a cell value that would otherwise overflow its column. */
    private function truncate(string $text, float $width, float $size): string
    {
        if ($this->widthOf($text, $size) <= $width) {
            return $text;
        }

        while ($text !== '' && $this->widthOf($text . self::ELLIPSIS, $size) > $width) {
            $text = substr($text, 0, -1);
        }

        return $text . self::ELLIPSIS;
    }

    /** Assembles the file and returns its bytes. */
    public function output(): string
    {
        $this->addPage();

        $objects = [];
        $pageCount = count($this->pages);

        // 1: catalogue, 2: page tree, 3..: fonts, then page + content pairs.
        $fontIds = [3, 4, 5, 6];
        $firstPageId = 7;

        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = sprintf('%d 0 R', $firstPageId + ($i * 2));
        }

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = sprintf(
            "<< /Type /Pages /Kids [%s] /Count %d /MediaBox [0 0 %.2F %.2F] >>",
            implode(' ', $kids),
            $pageCount,
            $this->pageWidth * self::MM_TO_PT,
            $this->pageHeight * self::MM_TO_PT
        );

        $fonts = [
            3 => 'Helvetica',
            4 => 'Helvetica-Bold',
            5 => 'Helvetica-Oblique',
            6 => 'Helvetica-BoldOblique',
        ];

        foreach ($fonts as $id => $name) {
            $objects[$id] = sprintf(
                "<< /Type /Font /Subtype /Type1 /BaseFont /%s /Encoding /WinAnsiEncoding >>",
                $name
            );
        }

        foreach ($this->pages as $index => $content) {
            $pageId = $firstPageId + ($index * 2);
            $contentId = $pageId + 1;

            $objects[$pageId] = sprintf(
                "<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 %d 0 R /F2 %d 0 R /F3 %d 0 R /F4 %d 0 R >> >> /Contents %d 0 R >>",
                $fontIds[0],
                $fontIds[1],
                $fontIds[2],
                $fontIds[3],
                $contentId
            );

            $objects[$contentId] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($content),
                $content
            );
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $body);
        }

        $xrefOffset = strlen($pdf);
        $maxId = max(array_keys($objects));

        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $maxId + 1);
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info << /Title (%s) /Producer (Dayflow HRMS) >> >>\nstartxref\n%d\n%%%%EOF",
            $maxId + 1,
            $this->escape($this->title),
            $xrefOffset
        );

        return $pdf;
    }

    private function px(float $mm): float
    {
        return $mm * self::MM_TO_PT;
    }

    /** Converts a millimetre offset from the top into PDF points from the bottom. */
    private function py(float $mm): float
    {
        return ($this->pageHeight - $mm) * self::MM_TO_PT;
    }

    private function colour(float $component): string
    {
        return sprintf('%.3F', max(0.0, min(1.0, $component)));
    }

    /**
     * Escapes a string for a PDF literal.
     *
     * The backslash must be handled first, otherwise the escapes added for
     * parentheses would themselves be escaped a second time.
     */
    private function escape(string $text): string
    {
        // The standard fonts use WinAnsi, so anything outside it is transliterated.
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        $text = $converted === false ? $text : $converted;

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);
    }
}
