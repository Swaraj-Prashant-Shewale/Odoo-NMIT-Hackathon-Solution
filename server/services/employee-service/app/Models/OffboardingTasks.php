<?php

declare(strict_types=1);

namespace App\Models;

/** The leaver checklist. */
final class OffboardingTasks extends ChecklistTasks
{
    protected string $table = 'offboarding_tasks';
}
