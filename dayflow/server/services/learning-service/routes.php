<?php

declare(strict_types=1);

use App\Controllers\CertificateController;
use App\Controllers\CourseController;
use App\Controllers\EnrolmentController;
use App\Controllers\LearningController;
use App\Controllers\LessonController;
use App\Controllers\QuizController;
use Dayflow\Kernel\Http\Router;
use Dayflow\Kernel\Security\Permissions;

return static function (Router $router): void {
    $courses = new CourseController();
    $lessons = new LessonController();
    $enrolments = new EnrolmentController();
    $quiz = new QuizController();
    $certificates = new CertificateController();
    $learning = new LearningController();

    // --- Catalogue ---------------------------------------------------------
    $router->get('/courses', [$courses, 'index'])
        ->requires(Permissions::LEARNING_VIEW_CATALOGUE);
    $router->post('/courses', [$courses, 'store'])
        ->requires(Permissions::LEARNING_MANAGE_CATALOGUE);
    $router->get('/courses/{id}', [$courses, 'show'])
        ->requires(Permissions::LEARNING_VIEW_CATALOGUE);
    $router->patch('/courses/{id}', [$courses, 'update'])
        ->requires(Permissions::LEARNING_MANAGE_CATALOGUE);
    $router->delete('/courses/{id}', [$courses, 'destroy'])
        ->requires(Permissions::LEARNING_MANAGE_CATALOGUE);

    // --- Lessons -----------------------------------------------------------
    $router->post('/courses/{id}/lessons', [$lessons, 'store'])
        ->requires(Permissions::LEARNING_MANAGE_CATALOGUE);
    $router->patch('/courses/{id}/lessons/{lessonId}', [$lessons, 'update'])
        ->requires(Permissions::LEARNING_MANAGE_CATALOGUE);
    $router->delete('/courses/{id}/lessons/{lessonId}', [$lessons, 'destroy'])
        ->requires(Permissions::LEARNING_MANAGE_CATALOGUE);

    // --- Assessment --------------------------------------------------------
    // Authenticated rather than permission gated: sitting a quiz depends on
    // holding an enrolment for this course, which the controller verifies.
    $router->get('/courses/{id}/quiz', [$quiz, 'show'])->authenticated();
    $router->post('/courses/{id}/quiz/submit', [$quiz, 'submit'])->authenticated();

    // --- Enrolment ---------------------------------------------------------
    $router->post('/enrolments', [$enrolments, 'store'])
        ->requires(Permissions::LEARNING_ENROL_SELF);
    // Either assign permission opens this endpoint, and each of them carries a
    // different reach, so the decision belongs in the controller's policy.
    $router->post('/enrolments/assign', [$enrolments, 'assign'])->authenticated();
    $router->get('/enrolments', [$enrolments, 'index'])->authenticated();
    $router->post('/enrolments/{id}/progress', [$enrolments, 'progress'])->authenticated();

    // --- Learner and HR views ---------------------------------------------
    $router->get('/learning/categories', [$learning, 'categories'])
        ->requires(Permissions::LEARNING_VIEW_CATALOGUE);
    $router->get('/learning/my-dashboard', [$learning, 'myDashboard'])->authenticated();
    $router->get('/learning/compliance', [$learning, 'compliance'])
        ->requires(Permissions::LEARNING_ASSIGN_ANY);

    // --- Certificates ------------------------------------------------------
    $router->get('/certificates', [$certificates, 'index'])->authenticated();
    $router->get('/certificates/{id}/download', [$certificates, 'download'])->authenticated();
};
