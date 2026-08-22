<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * The saved report catalogue.
 *
 * required_permission is the only thing standing between a caller and a bulk
 * extract of other people's records, so it is read from the stored definition
 * on every run rather than inferred from the route that reached it.
 */
final class ReportDefinitions extends Repository
{
    protected string $table = 'report_definitions';

    protected array $fillable = [
        'name', 'slug', 'description', 'report_type', 'default_filters', 'required_permission', 'is_active',
    ];

    protected array $casts = [
        'default_filters' => 'json',
        'is_active' => 'bool',
    ];

    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        $rows = $this->query()
            ->where('is_active', '=', true)
            ->orderBy('report_type')
            ->orderBy('name')
            ->get();

        return array_map([$this, 'present'], $rows);
    }

    public function activeBySlug(string $slug): ?array
    {
        $row = $this->query()
            ->where('slug', '=', $slug)
            ->where('is_active', '=', true)
            ->first();

        return $row === null ? null : $this->present($row);
    }
}
