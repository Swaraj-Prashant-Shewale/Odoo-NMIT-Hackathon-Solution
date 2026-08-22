<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ChecklistTemplates;
use App\Models\OffboardingTasks;

/**
 * The leaver checklist.
 *
 * Guarded by onboarding.manage rather than a permission of its own: the
 * catalogue treats the joiner and leaver processes as one HR responsibility,
 * and inventing a permission here would leave it unassigned to every role.
 */
final class OffboardingController extends ChecklistController
{
    public function __construct()
    {
        parent::__construct(new OffboardingTasks(), ChecklistTemplates::OFFBOARDING);
    }
}
