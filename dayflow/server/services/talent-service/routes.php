<?php

declare(strict_types=1);

use App\Controllers\FeedbackController;
use App\Controllers\GoalController;
use App\Controllers\ReviewController;
use App\Controllers\ReviewCycleController;
use App\Controllers\TalentController;
use Dayflow\Kernel\Http\Router;
use Dayflow\Kernel\Security\Permissions;

/**
 * The router returns the first pattern that matches a path, so every fixed
 * segment is registered ahead of the "{id}" pattern that would otherwise
 * swallow it: "/goals/team" before "/goals/{id}", "/reviews/cycles" before
 * "/reviews/{id}", and so on.
 */
return static function (Router $router): void {
    $goals = new GoalController();
    $cycles = new ReviewCycleController();
    $reviews = new ReviewController();
    $feedback = new FeedbackController();
    $talent = new TalentController();

    // --- Goals ------------------------------------------------------------
    $router->get('/goals/team', [$goals, 'team'])->requires(Permissions::TALENT_VIEW_TEAM);
    $router->get('/goals/cycles', [$goals, 'cycles'])->authenticated();
    $router->post('/goals/cycles', [$goals, 'storeCycle'])->requires(Permissions::TALENT_MANAGE_CYCLES);

    // Own goals need talent.goals.update.self; a manager setting a goal for a
    // report needs talent.review.team. One route, two answers, so the check
    // belongs in the controller.
    $router->get('/goals', [$goals, 'index'])->authenticated();
    $router->post('/goals', [$goals, 'store'])->authenticated();
    $router->get('/goals/{id}', [$goals, 'show'])->authenticated();
    $router->patch('/goals/{id}', [$goals, 'update'])->authenticated();
    $router->post('/goals/{id}/progress', [$goals, 'progress'])->authenticated();

    // --- Review cycles ----------------------------------------------------
    $router->get('/reviews/competencies', [$reviews, 'competencies'])->authenticated();
    $router->get('/reviews/cycles', [$cycles, 'index'])->authenticated();
    $router->post('/reviews/cycles', [$cycles, 'store'])->requires(Permissions::TALENT_MANAGE_CYCLES);
    $router->post('/reviews/cycles/{id}/open', [$cycles, 'open'])->requires(Permissions::TALENT_MANAGE_CYCLES);
    $router->get('/reviews/cycles/{id}/summary', [$cycles, 'summary'])->requires(Permissions::TALENT_VIEW_ALL);

    // --- Reviews ----------------------------------------------------------
    // Every one of these is scoped per record by ReviewVisibility, which is
    // stricter than any single permission could express.
    $router->get('/reviews', [$reviews, 'index'])->authenticated();
    $router->get('/reviews/{id}', [$reviews, 'show'])->authenticated();
    $router->patch('/reviews/{id}', [$reviews, 'update'])->authenticated();
    $router->post('/reviews/{id}/submit', [$reviews, 'submit'])->authenticated();
    $router->post('/reviews/{id}/acknowledge', [$reviews, 'acknowledge'])->authenticated();

    // --- Feedback ---------------------------------------------------------
    $router->post('/feedback', [$feedback, 'store'])->authenticated();
    $router->get('/feedback/received', [$feedback, 'received'])->authenticated();
    $router->get('/feedback/sent', [$feedback, 'sent'])->authenticated();
    $router->get('/feedback/team', [$feedback, 'team'])->requires(Permissions::TALENT_VIEW_TEAM);

    // --- Personal roll-up --------------------------------------------------
    $router->get('/talent/my-summary', [$talent, 'mySummary'])->authenticated();
};
