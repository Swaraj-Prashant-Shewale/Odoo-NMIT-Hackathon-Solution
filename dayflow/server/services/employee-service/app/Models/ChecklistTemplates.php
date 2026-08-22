<?php

declare(strict_types=1);

namespace App\Models;

use Dayflow\Kernel\Database\Repository;

/**
 * The standard joiner and leaver checklists.
 *
 * Reference data rather than code, so HR can add a step without a deployment
 * and every person created afterwards receives it automatically.
 */
final class ChecklistTemplates extends Repository
{
    public const ONBOARDING = 'onboarding';
    public const OFFBOARDING = 'offboarding';

    protected string $table = 'checklist_templates';
    protected string $primaryKey = 'id';

    protected array $fillable = [
        'id', 'kind', 'title', 'description', 'category',
        'sequence', 'owner_role', 'due_offset_days', 'is_active',
    ];

    protected array $casts = [
        'sequence' => 'int',
        'due_offset_days' => 'int',
        'is_active' => 'bool',
    ];

    protected bool $softDeletes = false;

    /** @return list<array<string, mixed>> */
    public function activeFor(string $kind): array
    {
        $rows = $this->query()
            ->where('kind', '=', $kind)
            ->where('is_active', '=', true)
            ->orderBy('sequence')
            ->orderBy('title')
            ->get();

        return array_map([$this, 'present'], $rows);
    }
}
