<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class LeaveTypes extends Repository
{
    public const CATEGORIES = ['paid', 'sick', 'unpaid', 'casual', 'maternity', 'paternity', 'comp_off', 'bereavement'];
    public const ACCRUAL_FREQUENCIES = ['none', 'monthly', 'quarterly', 'yearly'];
    public const GENDERS = ['any', 'female', 'male'];

    protected string $table = 'leave_types';

    protected array $fillable = [
        'name', 'code', 'category', 'colour', 'annual_quota_days',
        'accrual_frequency', 'accrual_days', 'max_carry_forward_days',
        'allows_half_day', 'requires_document_after_days', 'min_notice_days',
        'max_consecutive_days', 'is_paid', 'applies_to_gender', 'is_active',
    ];

    protected array $casts = [
        'annual_quota_days' => 'float',
        'accrual_days' => 'float',
        'max_carry_forward_days' => 'float',
        'allows_half_day' => 'bool',
        'requires_document_after_days' => 'int',
        'min_notice_days' => 'int',
        'max_consecutive_days' => 'int',
        'is_paid' => 'bool',
        'is_active' => 'bool',
    ];

    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        $rows = $this->query()->where('is_active', '=', true)->orderBy('name')->get();

        return array_map([$this, 'present'], $rows);
    }

    /**
     * Every type, active or retired, keyed by id.
     *
     * Historical requests can reference a type that has since been retired, so
     * decorating a list of requests must not be limited to active types.
     *
     * @return array<string, array<string, mixed>>
     */
    public function keyedById(): array
    {
        $keyed = [];

        foreach ($this->all('name', 'asc') as $type) {
            $keyed[(string) $type['id']] = $type;
        }

        return $keyed;
    }

    /** @return list<array<string, mixed>> */
    public function accruing(): array
    {
        $rows = $this->query()
            ->where('is_active', '=', true)
            ->where('accrual_frequency', '!=', 'none')
            ->orderBy('name')
            ->get();

        return array_map([$this, 'present'], $rows);
    }
}
