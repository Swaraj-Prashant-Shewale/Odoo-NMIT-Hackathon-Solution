<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * The catalogue of things that can appear on a payslip.
 *
 * A component describes how an amount is arrived at, not the amount itself:
 * the money lives on the salary structure line or is derived from the tax
 * slabs, so changing a rate here changes every future payslip at once.
 */
final class PayComponents extends Repository
{
    protected string $table = 'pay_components';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'name', 'code', 'component_type', 'calculation', 'percentage',
        'is_taxable', 'is_statutory', 'display_order', 'is_active',
    ];

    protected array $casts = [
        'percentage' => 'float',
        'is_taxable' => 'bool',
        'is_statutory' => 'bool',
        'display_order' => 'int',
        'is_active' => 'bool',
    ];

    protected bool $softDeletes = false;

    /** @return list<array<string, mixed>> Every component, payslip order first. */
    public function ordered(bool $activeOnly = false): array
    {
        $builder = $this->query();

        if ($activeOnly) {
            $builder->where('is_active', '=', true);
        }

        return array_map([$this, 'present'], $builder->orderBy('display_order')->orderBy('name')->get());
    }

    public function findByCode(string $code): ?array
    {
        return $this->findBy('code', $code);
    }
}
