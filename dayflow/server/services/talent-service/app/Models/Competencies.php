<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;
use Dayflow\Kernel\Support\Clock;

final class Competencies extends Repository
{
    protected string $table = 'competencies';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'name', 'description', 'category', 'applies_to_designation_id',
        'display_order', 'is_active', 'created_at',
    ];

    protected array $casts = [
        'display_order' => 'int',
        'is_active' => 'bool',
    ];

    /** Reference data: rows are created once and never edited through the API. */
    protected bool $timestamps = false;

    public function create(array $attributes): array
    {
        return parent::create($attributes + ['created_at' => Clock::iso()]);
    }

    /**
     * The framework a review form should render.
     *
     * A competency tied to a designation only appears for people holding that
     * designation; everything else is company-wide.
     *
     * @return list<array<string, mixed>>
     */
    public function frameworkFor(?string $designationId): array
    {
        $sql = 'SELECT * FROM competencies WHERE is_active = TRUE';
        $bindings = [];

        if ($designationId === null) {
            $sql .= ' AND applies_to_designation_id IS NULL';
        } else {
            $sql .= ' AND (applies_to_designation_id IS NULL OR applies_to_designation_id = :designation_id)';
            $bindings['designation_id'] = $designationId;
        }

        $sql .= ' ORDER BY display_order, name';

        return array_map([$this, 'present'], $this->raw($sql, $bindings));
    }

    public function isSelectable(string $competencyId): bool
    {
        return $this->query()
            ->where('id', '=', $competencyId)
            ->where('is_active', '=', true)
            ->exists();
    }
}
