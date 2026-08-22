<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * The component breakdown of one salary structure.
 *
 * A line carries either a fixed monthly amount or the percentage that
 * overrides the component default, and reads are joined to the component so
 * the calculator never has to look a rule up a second time.
 */
final class SalaryStructureLines extends Repository
{
    protected string $table = 'salary_structure_lines';

    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'salary_structure_id', 'pay_component_id',
        'amount_monthly_minor', 'percentage', 'created_at',
    ];

    protected array $casts = [
        'amount_monthly_minor' => 'int',
        'percentage' => 'float',
        'component_percentage' => 'float',
        'is_taxable' => 'bool',
        'is_statutory' => 'bool',
        'display_order' => 'int',
    ];

    // Lines are replaced wholesale with their parent structure, never edited.
    protected bool $timestamps = false;

    protected bool $softDeletes = false;

    /**
     * Lines with their component rule attached, in payslip order.
     *
     * @return list<array<string, mixed>>
     */
    public function forStructure(string $structureId): array
    {
        $rows = $this->raw(
            'SELECT l.id,
                    l.salary_structure_id,
                    l.pay_component_id,
                    l.amount_monthly_minor,
                    l.percentage,
                    c.name           AS component_name,
                    c.code           AS component_code,
                    c.component_type,
                    c.calculation,
                    c.percentage     AS component_percentage,
                    c.is_taxable,
                    c.is_statutory,
                    c.display_order
               FROM salary_structure_lines l
               JOIN pay_components c ON c.id = l.pay_component_id
              WHERE l.salary_structure_id = :structure_id
              ORDER BY c.display_order, c.name',
            ['structure_id' => $structureId]
        );

        return array_map([$this, 'present'], $rows);
    }
}
