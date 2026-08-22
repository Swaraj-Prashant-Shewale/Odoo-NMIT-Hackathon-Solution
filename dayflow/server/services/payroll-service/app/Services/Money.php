<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Str;

/** Presentation of minor-unit amounts. Arithmetic never happens here. */
final class Money
{
    private function __construct()
    {
    }

    public static function currencyCode(): string
    {
        $code = strtoupper((string) Env::get('CURRENCY_CODE', 'INR'));

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : 'INR';
    }

    /**
     * Formats an amount for a printed document, e.g. "INR 45,000.00".
     *
     * The currency code is spelled out rather than symbolised because the PDF
     * core fonts are WinAnsi and cannot encode the rupee sign at all.
     */
    public static function forDocument(int $minorUnits): string
    {
        return self::currencyCode() . ' ' . Str::money($minorUnits);
    }
}
