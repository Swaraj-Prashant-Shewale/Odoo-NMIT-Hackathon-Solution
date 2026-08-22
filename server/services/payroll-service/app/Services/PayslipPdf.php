<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Pdf\PdfDocument;
use Dayflow\Kernel\Support\Clock;

/**
 * Renders a payslip as a one-page PDF.
 *
 * Amounts are written as "INR 45,000.00". The fourteen standard PDF fonts are
 * WinAnsi encoded and have no rupee glyph at all, so printing the symbol would
 * put a random accented letter on every line of somebody's salary statement.
 */
final class PayslipPdf
{
    private const MARGIN = 14.0;

    private const HEADER_HEIGHT = 26.0;

    private const INK = [0.13, 0.16, 0.22];

    private const MUTED = [0.42, 0.46, 0.52];

    private const BRAND = [0.13, 0.23, 0.42];

    /**
     * @param array<string, mixed>       $payslip
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed>|null  $employee Canonical employee record, when reachable.
     *
     * @return string The PDF bytes.
     */
    public function render(array $payslip, array $lines, ?array $employee, string $companyName): string
    {
        $period = (string) $payslip['period'];
        $pdf = new PdfDocument('A4', sprintf('Payslip %s', $period));

        $width = $pdf->pageWidth();
        $right = $width - self::MARGIN;

        $this->drawHeader($pdf, $companyName, $period, $width, $right);

        $y = self::HEADER_HEIGHT + 10.0;
        $y = $this->drawEmployeeBlock($pdf, $payslip, $employee, $y, $right);
        $y = $this->drawAttendanceStrip($pdf, $payslip, $y, $right);

        $earnings = $this->linesOfType($lines, 'earning');
        $deductions = $this->linesOfType($lines, 'deduction');

        $y = $this->drawBreakdown($pdf, $payslip, $earnings, $deductions, $y, $right);
        $y = $this->drawNetPay($pdf, $payslip, $y, $right);

        $this->drawEmployerContributions($pdf, $this->linesOfType($lines, 'employer_contribution'), $y, $right);
        $this->drawFooter($pdf, $right);

        return $pdf->output();
    }

    public function filename(array $payslip, ?array $employee): string
    {
        $code = (string) ($employee['employee_code'] ?? substr((string) $payslip['employee_id'], 0, 8));

        return sprintf('payslip-%s-%s.pdf', $code, (string) $payslip['period']);
    }

    private function drawHeader(PdfDocument $pdf, string $companyName, string $period, float $width, float $right): void
    {
        $pdf->rect(0, 0, $width, self::HEADER_HEIGHT, self::BRAND, true);
        $pdf->text(self::MARGIN, 7.0, $companyName, 15, 'B', [1, 1, 1]);
        $pdf->text(self::MARGIN, 15.5, 'Statement of salary', 9, '', [0.78, 0.84, 0.92]);

        $pdf->textRight($right, 7.5, 'PAYSLIP', 13, 'B', [1, 1, 1]);
        $pdf->textRight($right, 15.5, Period::label($period), 9.5, '', [0.78, 0.84, 0.92]);
    }

    /** @param array<string, mixed>|null $employee */
    private function drawEmployeeBlock(PdfDocument $pdf, array $payslip, ?array $employee, float $y, float $right): float
    {
        $employeeId = (string) $payslip['employee_id'];
        $name = (string) ($employee['full_name'] ?? 'Employee ' . substr($employeeId, 0, 8));

        $pdf->text(self::MARGIN, $y, $name, 13, 'B', self::INK);
        $y += 7.0;

        $left = [
            ['Employee code', (string) ($employee['employee_code'] ?? '-')],
            ['Designation', (string) ($employee['designation_name'] ?? '-')],
            ['Department', (string) ($employee['department_name'] ?? '-')],
        ];

        $rightColumn = [
            ['Pay period', Period::label((string) $payslip['period'])],
            ['Date of joining', (string) ($employee['joined_on'] ?? '-')],
            ['Payslip issued', $this->issuedOn($payslip)],
        ];

        $midpoint = self::MARGIN + (($right - self::MARGIN) / 2);
        $rowTop = $y;

        foreach ($left as $index => [$label, $value]) {
            $this->labelledValue($pdf, self::MARGIN, $rowTop + ($index * 6.2), $label, $value);
        }

        foreach ($rightColumn as $index => [$label, $value]) {
            $this->labelledValue($pdf, $midpoint, $rowTop + ($index * 6.2), $label, $value);
        }

        $y = $rowTop + (max(count($left), count($rightColumn)) * 6.2) + 3.0;

        $pdf->line(self::MARGIN, $y, $right, $y, 0.3, [0.85, 0.87, 0.9]);

        return $y + 6.0;
    }

    private function drawAttendanceStrip(PdfDocument $pdf, array $payslip, float $y, float $right): float
    {
        $pdf->rect(self::MARGIN, $y, $right - self::MARGIN, 13.0, [0.96, 0.97, 0.99], true);

        $cells = [
            ['Payable days', $this->days($payslip['payable_days'] ?? 0)],
            ['Days present', $this->days($payslip['present_days'] ?? 0)],
            ['Paid leave', $this->days($payslip['leave_days'] ?? 0)],
            ['Loss of pay', $this->days($payslip['lop_days'] ?? 0)],
        ];

        $cellWidth = ($right - self::MARGIN) / count($cells);
        $cursor = self::MARGIN;

        foreach ($cells as [$label, $value]) {
            $pdf->textCentre($cursor, $cursor + $cellWidth, $y + 2.0, $label, 7.5, '', self::MUTED);
            $pdf->textCentre($cursor, $cursor + $cellWidth, $y + 7.0, $value, 10.5, 'B', self::INK);
            $cursor += $cellWidth;
        }

        return $y + 20.0;
    }

    /**
     * @param list<array<string, mixed>> $earnings
     * @param list<array<string, mixed>> $deductions
     */
    private function drawBreakdown(
        PdfDocument $pdf,
        array $payslip,
        array $earnings,
        array $deductions,
        float $y,
        float $right,
    ): float {
        $columnWidth = (($right - self::MARGIN) - 6.0) / 2;
        $rightColumnX = self::MARGIN + $columnWidth + 6.0;
        $widths = [$columnWidth * 0.58, $columnWidth * 0.42];

        $earningRows = array_map(
            fn (array $line): array => [(string) $line['component_name'], Money::forDocument((int) $line['amount_minor'])],
            $earnings
        );

        $deductionRows = array_map(
            fn (array $line): array => [(string) $line['component_name'], Money::forDocument((int) $line['amount_minor'])],
            $deductions
        );

        // Both tables must finish at the same height or the totals underneath
        // would sit at different levels on the page.
        $rowCount = max(count($earningRows), count($deductionRows));
        $earningRows = $this->pad($earningRows, $rowCount);
        $deductionRows = $this->pad($deductionRows, $rowCount);

        $endLeft = $pdf->table(self::MARGIN, $y, ['Earnings', 'Amount'], $earningRows, $widths, ['left', 'right']);
        $endRight = $pdf->table($rightColumnX, $y, ['Deductions', 'Amount'], $deductionRows, $widths, ['left', 'right']);

        $totalsY = max($endLeft, $endRight) + 1.5;

        $this->totalRow($pdf, self::MARGIN, $totalsY, $columnWidth, 'Gross earnings', (int) $payslip['gross_minor']);
        $this->totalRow($pdf, $rightColumnX, $totalsY, $columnWidth, 'Total deductions', (int) $payslip['total_deductions_minor']);

        return $totalsY + 15.0;
    }

    private function drawNetPay(PdfDocument $pdf, array $payslip, float $y, float $right): float
    {
        $net = (int) $payslip['net_minor'];

        $pdf->rect(self::MARGIN, $y, $right - self::MARGIN, 20.0, self::BRAND, true);
        $pdf->text(self::MARGIN + 4.0, $y + 4.0, 'NET PAY FOR THE MONTH', 8, 'B', [0.78, 0.84, 0.92]);
        $pdf->text(self::MARGIN + 4.0, $y + 10.0, Money::forDocument($net), 16, 'B', [1, 1, 1]);

        $y += 24.0;

        $pdf->text(self::MARGIN, $y, 'Amount in words', 7.5, '', self::MUTED);
        $pdf->paragraph(self::MARGIN, $y + 4.5, $right - self::MARGIN, AmountInWords::rupees($net), 9.5, 1.35, 'B');

        return $y + 15.0;
    }

    /** @param list<array<string, mixed>> $contributions */
    private function drawEmployerContributions(PdfDocument $pdf, array $contributions, float $y, float $right): void
    {
        if ($contributions === []) {
            return;
        }

        $pdf->text(self::MARGIN, $y, 'Employer contributions (not deducted from your pay)', 8, 'B', self::MUTED);
        $y += 5.5;

        foreach ($contributions as $line) {
            $pdf->text(self::MARGIN, $y, (string) $line['component_name'], 8.5, '', self::INK);
            $pdf->textRight($right, $y, Money::forDocument((int) $line['amount_minor']), 8.5, '', self::INK);
            $y += 5.0;
        }
    }

    private function drawFooter(PdfDocument $pdf, float $right): void
    {
        $footerY = $pdf->pageHeight() - 18.0;

        $pdf->line(self::MARGIN, $footerY, $right, $footerY, 0.2, [0.85, 0.87, 0.9]);
        $pdf->text(
            self::MARGIN,
            $footerY + 3.0,
            'This statement is issued electronically and is valid without a signature.',
            7.5,
            '',
            self::MUTED
        );
        $pdf->textRight($right, $footerY + 3.0, 'Issued on ' . Clock::now()->format('d M Y'), 7.5, '', self::MUTED);
    }

    private function totalRow(PdfDocument $pdf, float $x, float $y, float $width, string $label, int $amount): void
    {
        $pdf->rect($x, $y, $width, 9.0, [0.90, 0.93, 0.97], true);
        $pdf->text($x + 2.0, $y + 2.6, $label, 9, 'B', self::INK);
        $pdf->textRight($x + $width - 2.0, $y + 2.6, Money::forDocument($amount), 9, 'B', self::INK);
    }

    private function labelledValue(PdfDocument $pdf, float $x, float $y, string $label, string $value): void
    {
        $pdf->text($x, $y, $label, 7.5, '', self::MUTED);
        $pdf->text($x + 34.0, $y, $value, 9, 'B', self::INK);
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function linesOfType(array $lines, string $type): array
    {
        return array_values(array_filter(
            $lines,
            static fn (array $line): bool => (string) $line['component_type'] === $type
        ));
    }

    /**
     * @param list<list<string>> $rows
     * @return list<list<string>>
     */
    private function pad(array $rows, int $count): array
    {
        while (count($rows) < $count) {
            $rows[] = ['', ''];
        }

        return $rows;
    }

    private function days(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function issuedOn(array $payslip): string
    {
        $published = $payslip['published_at'] ?? null;

        if (!is_string($published) || $published === '') {
            return 'Not yet published';
        }

        return Clock::parse($published)->format('d M Y');
    }
}
