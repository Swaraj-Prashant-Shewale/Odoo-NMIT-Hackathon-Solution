<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

final class CourseCategories extends Repository
{
    protected string $table = 'course_categories';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'name', 'slug', 'description', 'icon', 'colour', 'display_order', 'is_active',
    ];

    protected array $casts = [
        'display_order' => 'int',
        'is_active' => 'bool',
    ];

    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        $rows = $this->query()
            ->where('is_active', '=', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return array_map([$this, 'present'], $rows);
    }
}
