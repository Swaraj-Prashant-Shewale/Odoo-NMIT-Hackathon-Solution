<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employees;

/**
 * Allocates the next employee code in the DF-nnnn series.
 *
 * Must be called inside the transaction that inserts the employee. The
 * repository takes a transaction-scoped advisory lock before reading the
 * highest number, so the read and the insert that depends on it cannot be
 * interleaved with another create.
 */
final class EmployeeCodeSequence
{
    private const PADDING = 4;

    public function __construct(private readonly Employees $employees)
    {
    }

    public function next(): string
    {
        $number = $this->employees->reserveNextCodeNumber();

        // Padding is a display convention, not a limit: once the company grows
        // past 9999 people the codes simply get longer rather than colliding.
        return Employees::CODE_PREFIX . str_pad((string) $number, self::PADDING, '0', STR_PAD_LEFT);
    }
}
