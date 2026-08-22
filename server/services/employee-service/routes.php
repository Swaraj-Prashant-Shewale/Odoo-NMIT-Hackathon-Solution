<?php

declare(strict_types=1);

use App\Controllers\AssetController;
use App\Controllers\DepartmentController;
use App\Controllers\DesignationController;
use App\Controllers\DirectoryController;
use App\Controllers\DocumentController;
use App\Controllers\EmployeeController;
use App\Controllers\LocationController;
use App\Controllers\OffboardingController;
use App\Controllers\OnboardingController;
use App\Controllers\OrgChartController;
use Dayflow\Kernel\Http\Router;
use Dayflow\Kernel\Security\Permissions;

return static function (Router $router): void {
    $employees = new EmployeeController();
    $directory = new DirectoryController();
    $departments = new DepartmentController();
    $designations = new DesignationController();
    $locations = new LocationController();
    $orgChart = new OrgChartController();
    $documents = new DocumentController();
    $onboarding = new OnboardingController();
    $offboarding = new OffboardingController();
    $assets = new AssetController();

    // ----------------------------------------------------------------------
    // Directory: the one people-listing every employee may read.
    // ----------------------------------------------------------------------
    $router->get('/directory', [$directory, 'index'])->requires(Permissions::DIRECTORY_VIEW);

    // ----------------------------------------------------------------------
    // People records. The listing and single-record reads are authenticated
    // rather than permission-gated because the answer depends on the record:
    // HR sees everyone, a manager sees their team, everybody else sees
    // themselves. The controller decides that, not the route.
    // ----------------------------------------------------------------------
    $router->get('/employees', [$employees, 'index'])->authenticated();
    $router->post('/employees', [$employees, 'store'])->requires(Permissions::EMPLOYEE_CREATE);
    $router->get('/employees/{id}', [$employees, 'show'])->authenticated();
    $router->patch('/employees/{id}', [$employees, 'update'])->authenticated();
    $router->get('/employees/{id}/team', [$employees, 'team'])->authenticated();
    $router->post('/employees/{id}/photo', [$employees, 'photo'])->authenticated();
    $router->post('/employees/{id}/offboard', [$employees, 'offboard'])->requires(Permissions::EMPLOYEE_DEACTIVATE);

    // ----------------------------------------------------------------------
    // Organisation structure: readable by anyone in the directory, writable
    // only by whoever owns the org design.
    // ----------------------------------------------------------------------
    $router->get('/departments', [$departments, 'index'])->requires(Permissions::DIRECTORY_VIEW);
    $router->post('/departments', [$departments, 'store'])->requires(Permissions::ORG_MANAGE);
    $router->get('/departments/{id}', [$departments, 'show'])->requires(Permissions::DIRECTORY_VIEW);
    $router->patch('/departments/{id}', [$departments, 'update'])->requires(Permissions::ORG_MANAGE);
    $router->delete('/departments/{id}', [$departments, 'destroy'])->requires(Permissions::ORG_MANAGE);

    $router->get('/designations', [$designations, 'index'])->requires(Permissions::DIRECTORY_VIEW);
    $router->post('/designations', [$designations, 'store'])->requires(Permissions::ORG_MANAGE);
    $router->get('/designations/{id}', [$designations, 'show'])->requires(Permissions::DIRECTORY_VIEW);
    $router->patch('/designations/{id}', [$designations, 'update'])->requires(Permissions::ORG_MANAGE);
    $router->delete('/designations/{id}', [$designations, 'destroy'])->requires(Permissions::ORG_MANAGE);

    $router->get('/locations', [$locations, 'index'])->requires(Permissions::DIRECTORY_VIEW);
    $router->post('/locations', [$locations, 'store'])->requires(Permissions::ORG_MANAGE);
    $router->get('/locations/{id}', [$locations, 'show'])->requires(Permissions::DIRECTORY_VIEW);
    $router->patch('/locations/{id}', [$locations, 'update'])->requires(Permissions::ORG_MANAGE);
    $router->delete('/locations/{id}', [$locations, 'destroy'])->requires(Permissions::ORG_MANAGE);

    $router->get('/org-chart', [$orgChart, 'index'])->requires(Permissions::DIRECTORY_VIEW);

    // ----------------------------------------------------------------------
    // Documents. The two fixed paths are registered before the parameterised
    // ones so "expiring" and "photo" can never be read as a record id.
    // ----------------------------------------------------------------------
    $router->get('/documents/expiring', [$documents, 'expiring'])->requires(Permissions::DOCUMENT_VIEW_ALL);
    $router->get('/documents/photo/{employee_id}', [$documents, 'photo'])->authenticated();
    $router->get('/documents', [$documents, 'index'])->authenticated();
    $router->post('/documents', [$documents, 'store'])->requires(Permissions::DOCUMENT_UPLOAD_SELF);
    $router->get('/documents/{id}/download', [$documents, 'download'])->authenticated();
    $router->post('/documents/{id}/verify', [$documents, 'verify'])->requires(Permissions::DOCUMENT_MANAGE);
    $router->delete('/documents/{id}', [$documents, 'destroy'])->requires(Permissions::DOCUMENT_MANAGE);

    // ----------------------------------------------------------------------
    // Joiner and leaver checklists.
    // ----------------------------------------------------------------------
    $router->get('/onboarding', [$onboarding, 'index'])->requires(Permissions::ONBOARDING_MANAGE);
    $router->get('/onboarding/{employee_id}', [$onboarding, 'forEmployee'])->authenticated();
    $router->post('/onboarding/{id}/complete', [$onboarding, 'complete'])->authenticated();
    $router->post('/onboarding/{employee_id}/tasks', [$onboarding, 'storeTask'])->requires(Permissions::ONBOARDING_MANAGE);

    $router->get('/offboarding', [$offboarding, 'index'])->requires(Permissions::ONBOARDING_MANAGE);
    $router->get('/offboarding/{employee_id}', [$offboarding, 'forEmployee'])->authenticated();
    $router->post('/offboarding/{id}/complete', [$offboarding, 'complete'])->authenticated();
    $router->post('/offboarding/{employee_id}/tasks', [$offboarding, 'storeTask'])->requires(Permissions::ONBOARDING_MANAGE);

    // ----------------------------------------------------------------------
    // Company assets. Reading is authenticated because an employee may see
    // what has been issued to them; everything else belongs to org.manage.
    // ----------------------------------------------------------------------
    $router->get('/assets', [$assets, 'index'])->authenticated();
    $router->post('/assets', [$assets, 'store'])->requires(Permissions::ORG_MANAGE);
    $router->get('/assets/{id}', [$assets, 'show'])->authenticated();
    $router->patch('/assets/{id}', [$assets, 'update'])->requires(Permissions::ORG_MANAGE);
    $router->delete('/assets/{id}', [$assets, 'destroy'])->requires(Permissions::ORG_MANAGE);
    $router->post('/assets/{id}/assign', [$assets, 'assign'])->requires(Permissions::ORG_MANAGE);
    $router->post('/assets/{id}/return', [$assets, 'returnAsset'])->requires(Permissions::ORG_MANAGE);
};
