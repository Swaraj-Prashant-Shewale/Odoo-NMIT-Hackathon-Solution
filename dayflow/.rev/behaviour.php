<?php

declare(strict_types=1);

require '/var/www/shared/bootstrap.php';
dayflow_autoload_app('App', '/app/server/services/employee-service/app');

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Security\Roles;
use Dayflow\Kernel\Support\Str;
use Dayflow\Kernel\Validation\ValidationException;

$P = [
    'ceo' => '28010836-0cfb-4b4d-aa20-b0b0f2bedfe3',
    'hr_admin' => 'a9f5c390-db56-42fa-96d9-fb1ff57ca041',
    'hr_off' => 'bf5b3018-b6eb-4646-8507-8b6e6fdca490',
    'manager' => 'e3dbeba9-d9d2-4153-9934-471a08bb9cd6',
    'priya' => '5f2fc57a-9d26-4279-bbdf-054496fd35ea',
    'vikram' => 'c55da0e9-62e4-4f6d-a1c8-0ba0d6d17700',
    'divya' => '2b5d3904-9f2f-4ae3-9248-88adac38023a',
];

function principal(string $role, ?string $employeeId): Principal
{
    return Principal::fromClaims([
        'sub' => '00000000-0000-4000-8000-000000000001',
        'employee_id' => $employeeId,
        'email' => $role . '@dayflow.local',
        'name' => ucfirst($role),
        'roles' => [$role],
        'type' => 'access',
    ]);
}

function req(string $method, array $body = [], array $query = [], array $route = [], ?Principal $p = null): Request
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = '/test';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_GET = $query;
    $_POST = $body;
    $_FILES = [];

    $r = Request::capture()->withRouteParameters(array_map('strval', $route));

    return $p === null ? $r : $r->withPrincipal($p);
}

$pass = 0;
$fail = 0;

function check(string $name, callable $fn, string $expect): void
{
    global $pass, $fail;

    try {
        $r = $fn();
        $actual = $r instanceof Response ? 'status=' . $r->status : 'value=' . (is_scalar($r) ? (string) $r : json_encode($r));
    } catch (HttpException $e) {
        $actual = 'status=' . $e->status() . ' (' . $e->getMessage() . ')';
    } catch (ValidationException $e) {
        $actual = 'status=422 (' . json_encode($e->errors()) . ')';
    } catch (Throwable $e) {
        $actual = 'THROWN ' . get_class($e) . ': ' . $e->getMessage();
    }

    if (str_contains($actual, $expect)) {
        $pass++;
        echo "PASS  $name  ->  $actual\n";
    } else {
        $fail++;
        echo "FAIL  $name  ->  expected [$expect] got [$actual]\n";
    }
}

$emps = new App\Controllers\EmployeeController();
$docs = new App\Controllers\DocumentController();
$assetsC = new App\Controllers\AssetController();
$deps = new App\Controllers\DepartmentController();
$desigs = new App\Controllers\DesignationController();
$onb = new App\Controllers\OnboardingController();
$dir = new App\Controllers\DirectoryController();

$hrOfficer = principal(Roles::HR_OFFICER, $P['hr_off']);
$hrAdmin = principal(Roles::HR_ADMIN, $P['hr_admin']);
$manager = principal(Roles::MANAGER, $P['manager']);
$employee = principal(Roles::EMPLOYEE, $P['priya']);
$noRecord = principal(Roles::EMPLOYEE, null);
$blankRec = principal(Roles::EMPLOYEE, '');

echo "=== 1. scope ===\n";
check('employee lists only self', fn () => count($emps->index(req('GET', [], [], [], $employee))->payload['data']), 'value=1');
check('manager lists self + team', fn () => count($emps->index(req('GET', [], [], [], $manager))->payload['data']), 'value=5');
check('hr lists everyone', fn () => count($emps->index(req('GET', [], ['per_page' => '100'], [], $hrOfficer))->payload['data']), 'value=12');
check('employee cannot read a peer', fn () => $emps->show(req('GET', [], [], ['id' => $P['vikram']], $employee)), 'status=403');
check('manager can read a report', fn () => $emps->show(req('GET', [], [], ['id' => $P['vikram']], $manager)), 'status=200');
check('manager cannot read another team', fn () => $emps->show(req('GET', [], [], ['id' => $P['divya']], $manager)), 'status=403');
check('employee cannot read another team roster', fn () => $emps->team(req('GET', [], [], ['id' => $P['manager']], $employee)), 'status=403');

echo "\n=== 2. non-uuid route ids ===\n";
check('GET /employees/me', fn () => $emps->show(req('GET', [], [], ['id' => 'me'], $hrOfficer)), 'status=404');
check('DELETE /documents/expiring', fn () => $docs->destroy(req('DELETE', [], [], ['id' => 'expiring'], $hrOfficer)), 'status=404');
check('GET /assets/latest', fn () => $assetsC->show(req('GET', [], [], ['id' => 'latest'], $hrAdmin)), 'status=404');
check('GET /departments/all', fn () => $deps->show(req('GET', [], [], ['id' => 'all'], $hrOfficer)), 'status=404');
check('GET /onboarding/nobody', fn () => $onb->forEmployee(req('GET', [], [], ['employee_id' => 'nobody'], $hrOfficer)), 'status=404');
check('GET /documents/photo/none', fn () => $docs->photo(req('GET', [], [], ['employee_id' => 'none'], $employee)), 'status=404');

echo "\n=== 3. account with no person record ===\n";
check('documents index (null)', fn () => count($docs->index(req('GET', [], [], [], $noRecord))->payload['data']), 'value=0');
check('documents index (blank)', fn () => count($docs->index(req('GET', [], [], [], $blankRec))->payload['data']), 'value=0');
check('assets index (null)', fn () => count($assetsC->index(req('GET', [], [], [], $noRecord))->payload['data']), 'value=0');
check('assets index (blank)', fn () => count($assetsC->index(req('GET', [], [], [], $blankRec))->payload['data']), 'value=0');
check('employees index (blank)', fn () => count($emps->index(req('GET', [], [], [], $blankRec))->payload['data']), 'value=0');

echo "\n=== 4. ending employment by PATCH ===\n";
check('hr_officer is_active=false', fn () => $emps->update(req('PATCH', ['is_active' => 'false'], [], ['id' => $P['vikram']], $hrOfficer)), 'status=403');
check('hr_officer exit_date', fn () => $emps->update(req('PATCH', ['exit_date' => '2026-01-01'], [], ['id' => $P['vikram']], $hrOfficer)), 'status=403');
check('hr_officer status=terminated', fn () => $emps->update(req('PATCH', ['employment_status' => 'terminated'], [], ['id' => $P['vikram']], $hrOfficer)), 'status=403');
check('hr_officer may confirm probation', fn () => $emps->update(req('PATCH', ['employment_status' => 'confirmed'], [], ['id' => $P['vikram']], $hrOfficer)), 'status=200');
check('hr_admin may set exit_reason', fn () => $emps->update(req('PATCH', ['exit_reason' => 'Review test'], [], ['id' => $P['vikram']], $hrAdmin)), 'status=200');
check('employee cannot edit HR fields', fn () => $emps->update(req('PATCH', ['department_id' => '922f3d9b-20be-461f-9b69-90928701ce93'], [], ['id' => $P['priya']], $employee)), 'status=403');
check('employee cannot edit is_active', fn () => $emps->update(req('PATCH', ['is_active' => 'false'], [], ['id' => $P['priya']], $employee)), 'status=403');
check('employee cannot edit a peer', fn () => $emps->update(req('PATCH', ['phone' => '+91 90000 00000'], [], ['id' => $P['vikram']], $employee)), 'status=403');
check('employee edits own phone', fn () => $emps->update(req('PATCH', ['phone' => '+91 90000 11111'], [], ['id' => $P['priya']], $employee)), 'status=200');
check('duplicate user_id refused', fn () => $emps->update(req('PATCH', ['user_id' => '208bb9bb-b438-4fe1-9f7d-7ee1ff3fd237'], [], ['id' => $P['vikram']], $hrAdmin)), 'status=409');

echo "\n=== 5. self verification ===\n";
$docsModel = new App\Models\EmployeeDocuments();
$own = $docsModel->create([
    'employee_id' => $P['hr_off'], 'category' => 'education', 'title' => 'Review self doc ' . Str::token(4),
    'original_filename' => 'x.pdf', 'stored_filename' => Str::uuid() . '.pdf', 'mime_type' => 'application/pdf',
    'size_bytes' => 10, 'checksum' => str_repeat('a', 64), 'status' => 'pending',
]);
$other = $docsModel->rawOne('SELECT id FROM employee_documents WHERE employee_id = :e LIMIT 1', ['e' => $P['priya']]);
check('verifies own document', fn () => $docs->verify(req('POST', ['status' => 'verified'], [], ['id' => (string) $own['id']], $hrOfficer)), 'status=403');
check('verifies another person', fn () => $docs->verify(req('POST', ['status' => 'verified'], [], ['id' => (string) $other['id']], $hrOfficer)), 'status=200');

echo "\n=== 6. document access ===\n";
check('employee cannot download a peer document', fn () => $docs->download(req('GET', [], [], ['id' => (string) $own['id']], $employee)), 'status=403');
check('employee upload aimed at a peer', fn () => $docs->store(req('POST', ['employee_id' => $P['vikram'], 'category' => 'identity', 'title' => 'Nope'], [], [], $employee)), 'status=403');
check('no file attached', fn () => $docs->store(req('POST', ['category' => 'identity', 'title' => 'Nope'], [], [], $employee)), 'status=422');

echo "\n=== 7. asset lifecycle ===\n";
$tag = 'DF-REV-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$made = $assetsC->store(req('POST', ['asset_tag' => $tag, 'category' => 'laptop', 'name' => 'Review Laptop', 'value' => '1234.56'], [], [], $hrAdmin));
$aid = (string) $made->payload['data']['id'];
check('value stored as minor units', fn () => $made->payload['data']['value_minor'], 'value=123456');
check('assign', fn () => $assetsC->assign(req('POST', ['employee_id' => $P['priya']], [], ['id' => $aid], $hrAdmin)), 'status=200');
check('double assign refused', fn () => $assetsC->assign(req('POST', ['employee_id' => $P['vikram']], [], ['id' => $aid], $hrAdmin)), 'status=409');
check('holder can see it', fn () => $assetsC->show(req('GET', [], [], ['id' => $aid], $employee)), 'status=200');
check('non-holder cannot', fn () => $assetsC->show(req('GET', [], [], ['id' => $aid], principal(Roles::EMPLOYEE, $P['vikram']))), 'status=403');
check('delete while issued refused', fn () => $assetsC->destroy(req('DELETE', [], [], ['id' => $aid], $hrAdmin)), 'status=409');
check('status edit while issued refused', fn () => $assetsC->update(req('PATCH', ['status' => 'retired'], [], ['id' => $aid], $hrAdmin)), 'status=409');
check('return', fn () => $assetsC->returnAsset(req('POST', ['condition' => 'fair'], [], ['id' => $aid], $hrAdmin)), 'status=200');
check('double return refused', fn () => $assetsC->returnAsset(req('POST', [], [], ['id' => $aid], $hrAdmin)), 'status=409');
check('delete after return', fn () => $assetsC->destroy(req('DELETE', [], [], ['id' => $aid], $hrAdmin)), 'status=204');

echo "\n=== 8. org structure delete guards ===\n";
check('delete populated department', fn () => $deps->destroy(req('DELETE', [], [], ['id' => '7ec7b7c3-126b-412a-babf-fd79d002e921'], $hrAdmin)), 'status=409');
check('delete populated designation', fn () => $desigs->destroy(req('DELETE', [], [], ['id' => 'e3e8d1ec-c401-4979-8dc4-7034fc84ed23'], $hrAdmin)), 'status=409');

echo "\n=== 9. directory shape ===\n";
check('directory hides personal data', function () use ($dir, $employee) {
    $row = $dir->index(req('GET', [], [], [], $employee))->payload['data'][0];
    $leaks = array_intersect(array_keys($row), ['date_of_birth', 'personal_email', 'address_line1', 'phone', 'search_text', 'deleted_at', 'exit_reason']);

    return $leaks === [] ? 'clean' : implode(',', $leaks);
}, 'value=clean');

echo "\n=== 10. search is literal ===\n";
check('search % matches nothing', fn () => count($emps->index(req('GET', [], ['search' => '%'], [], $hrOfficer))->payload['data']), 'value=0');
check('search full name matches', fn () => count($emps->index(req('GET', [], ['search' => 'Priya Sharma'], [], $hrOfficer))->payload['data']), 'value=1');

$docsModel->delete((string) $own['id']);

echo "\nPASS=$pass FAIL=$fail\n";
