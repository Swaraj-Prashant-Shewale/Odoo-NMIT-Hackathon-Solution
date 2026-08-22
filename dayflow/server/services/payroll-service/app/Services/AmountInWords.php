<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Spells a minor-unit amount out in words for a payslip.
 *
 * Grouping follows the Indian system (thousand, lakh, crore) rather than
 * western thousands, because that is how the figure is read aloud on a salary
 * statement here and a mismatch between the numeral and the words is exactly
 * the sort of thing that gets a payslip queried.
 */
final class AmountInWords
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];

    private function __construct()
    {
    }

    /** e.g. 4500050 becomes "Rupees Forty Five Thousand and Fifty Paise Only". */
    public static function rupees(int $minorUnits): string
    {
        $minorUnits = max(0, $minorUnits);

        $major = intdiv($minorUnits, 100);
        $minor = $minorUnits % 100;

        $text = 'Rupees ' . ($major === 0 ? 'Zero' : self::number($major));

        if ($minor > 0) {
            $text .= ' and ' . self::number($minor) . ' Paise';
        }

        return $text . ' Only';
    }

    public static function number(int $value): string
    {
        if ($value <= 0) {
            return '';
        }

        if ($value < 20) {
            return self::ONES[$value];
        }

        if ($value < 100) {
            return self::squash(self::TENS[intdiv($value, 10)] . ' ' . self::ONES[$value % 10]);
        }

        if ($value < 1_000) {
            return self::squash(self::ONES[intdiv($value, 100)] . ' Hundred ' . self::number($value % 100));
        }

        if ($value < 100_000) {
            return self::squash(self::number(intdiv($value, 1_000)) . ' Thousand ' . self::number($value % 1_000));
        }

        if ($value < 10_000_000) {
            return self::squash(self::number(intdiv($value, 100_000)) . ' Lakh ' . self::number($value % 100_000));
        }

        return self::squash(self::number(intdiv($value, 10_000_000)) . ' Crore ' . self::number($value % 10_000_000));
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
