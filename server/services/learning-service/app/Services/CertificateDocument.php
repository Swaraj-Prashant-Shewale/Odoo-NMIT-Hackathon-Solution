<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Pdf\PdfDocument;
use Dayflow\Kernel\Support\Clock;

/**
 * Renders a completion certificate as a real PDF.
 *
 * Everything printed is read from the stored certificate and course rows, so a
 * document can never be produced for an achievement that was not recorded.
 */
final class CertificateDocument
{
    private const INK = [0.09, 0.17, 0.33];
    private const ACCENT = [0.72, 0.55, 0.21];
    private const MUTED = [0.38, 0.42, 0.50];

    /**
     * @param array<string, mixed> $certificate Row from certificates.
     * @param array<string, mixed> $course      Row from courses.
     */
    public function render(array $certificate, array $course, string $recipientName): string
    {
        $number = (string) $certificate['certificate_number'];
        $pdf = new PdfDocument('A4', 'Certificate ' . $number);

        $width = $pdf->pageWidth();
        $left = 14.0;
        $right = $width - 14.0;

        $this->drawBorder($pdf, $width, $pdf->pageHeight());

        $span = $right - $left;
        $company = strtoupper(CompanyProfile::name());

        $pdf->textCentre($left, $right, 34, $company, $this->fitSize($pdf, $company, $span - 30, 15, 'B'), 'B', self::INK);
        $pdf->line($width / 2 - 26, 46, $width / 2 + 26, 46, 0.8, self::ACCENT);

        $heading = 'CERTIFICATE OF COMPLETION';
        $pdf->textCentre($left, $right, 58, $heading, $this->fitSize($pdf, $heading, $span - 20, 24, 'B'), 'B', self::INK);
        $pdf->textCentre($left, $right, 80, 'This is to certify that', 11, 'I', self::MUTED);

        // A long name must shrink rather than run through the border.
        $nameSize = $this->fitSize($pdf, $recipientName, $span - 30, 26, 'B');
        $pdf->textCentre($left, $right, 92 + ((26 - $nameSize) / 3), $recipientName, $nameSize, 'B', self::ACCENT);
        $pdf->line($width / 2 - 60, 108, $width / 2 + 60, 108, 0.4, [0.80, 0.83, 0.88]);

        $pdf->textCentre($left, $right, 118, 'has successfully completed the course', 11, 'I', self::MUTED);

        $cursor = $this->courseTitle($pdf, $left, $right, 130, (string) $course['title']);

        $summary = trim((string) ($course['summary'] ?? ''));
        if ($summary !== '') {
            $cursor = $pdf->paragraph($left + 26, $cursor + 4, ($right - $left) - 52, $summary, 9.5, 1.5, 'I');
        }

        $this->drawFacts($pdf, $left, $right, max($cursor + 10, 168), $certificate, $course);
        $this->drawFooter($pdf, $left, $right, $pdf->pageHeight(), $number);

        return $pdf->output();
    }

    /** The largest point size at which a single line still fits the width given. */
    private function fitSize(PdfDocument $pdf, string $text, float $available, float $preferred, string $style): float
    {
        $size = $preferred;

        while ($size > 8 && $pdf->widthOf($text, $size, $style) > $available) {
            $size -= 0.5;
        }

        return $size;
    }

    private function drawBorder(PdfDocument $pdf, float $width, float $height): void
    {
        $pdf->rect(10, 10, $width - 20, $height - 20, self::INK, false, 1.6);
        $pdf->rect(13.5, 13.5, $width - 27, $height - 27, self::ACCENT, false, 0.5);

        // Corner ticks turn a plain double rule into something that reads as a
        // printed award rather than a form.
        foreach ([[13.5, 13.5, 1, 1], [$width - 13.5, 13.5, -1, 1], [13.5, $height - 13.5, 1, -1], [$width - 13.5, $height - 13.5, -1, -1]] as $corner) {
            [$x, $y, $dx, $dy] = $corner;
            $pdf->line($x, $y, $x + (10 * $dx), $y, 1.4, self::ACCENT);
            $pdf->line($x, $y, $x, $y + (10 * $dy), 1.4, self::ACCENT);
        }
    }

    /** Long course titles wrap and stay centred, so nothing runs into the border. */
    private function courseTitle(PdfDocument $pdf, float $left, float $right, float $y, string $title): float
    {
        $available = ($right - $left) - 40;

        foreach ($pdf->wrap($title, $available, 17, 'B') as $line) {
            $pdf->textCentre($left, $right, $y, $line, 17, 'B', self::INK);
            $y += 9;
        }

        return $y;
    }

    /**
     * @param array<string, mixed> $certificate
     * @param array<string, mixed> $course
     */
    private function drawFacts(PdfDocument $pdf, float $left, float $right, float $y, array $certificate, array $course): void
    {
        $issuedOn = Clock::parse((string) $certificate['issued_on'])->format('j F Y');
        $minutes = (int) ($course['estimated_minutes'] ?? 0);

        $facts = [
            ['Score achieved', sprintf('%d%%', (int) $certificate['score_percent'])],
            ['Level', ucfirst((string) ($course['level'] ?? 'beginner'))],
            ['Learning time', $minutes > 0 ? sprintf('%d minutes', $minutes) : 'Self paced'],
            ['Date of issue', $issuedOn],
        ];

        $columnWidth = ($right - $left) / count($facts);
        $cursor = $left;

        foreach ($facts as [$label, $value]) {
            $pdf->textCentre($cursor, $cursor + $columnWidth, $y, strtoupper($label), 7.5, 'B', self::MUTED);
            $pdf->textCentre($cursor, $cursor + $columnWidth, $y + 7, $value, 11.5, 'B', self::INK);
            $cursor += $columnWidth;
        }
    }

    private function drawFooter(PdfDocument $pdf, float $left, float $right, float $height, string $number): void
    {
        $signatureY = $height - 62;

        $pdf->line($left + 24, $signatureY, $left + 84, $signatureY, 0.4, self::INK);
        $pdf->text($left + 24, $signatureY + 2, 'Head of People', 9, 'B', self::INK);
        $pdf->text($left + 24, $signatureY + 8, CompanyProfile::name(), 8, '', self::MUTED);

        $pdf->line($right - 84, $signatureY, $right - 24, $signatureY, 0.4, self::INK);
        $pdf->textRight($right - 24, $signatureY + 2, 'Learning and Development', 9, 'B', self::INK);
        $pdf->textRight($right - 24, $signatureY + 8, 'Dayflow HRMS', 8, '', self::MUTED);

        $pdf->textCentre($left, $right, $height - 34, 'Certificate number ' . $number, 9, 'B', self::INK);
        $pdf->textCentre(
            $left,
            $right,
            $height - 27,
            'Verify this certificate against the learning record held by the People team.',
            7.5,
            'I',
            self::MUTED
        );
    }
}
