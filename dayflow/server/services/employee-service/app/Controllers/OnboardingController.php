<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ChecklistTemplates;
use App\Models\OnboardingTasks;

/** The joiner checklist. */
final class OnboardingController extends ChecklistController
{
    public function __construct()
    {
        parent::__construct(new OnboardingTasks(), ChecklistTemplates::ONBOARDING);
    }
}
