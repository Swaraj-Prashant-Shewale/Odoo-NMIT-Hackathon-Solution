<?php

declare(strict_types=1);

require '/var/www/shared/bootstrap.php';
dayflow_autoload_app('App', '/app/server/services/employee-service/app');

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Request;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Security\Roles;
use Dayflow\Kernel\Validation\ValidationException;

$role = $argv[1] ?? 'a';
$assetId = $argv[2] ?? '';
$startAt = (int) ($argv[3] ?? 0);
$holder = $argv[4] ?? '';

while (time() < $startAt) {
    usleep(20_000);
}

if ($role === 'holder') {
    // Stands in for a concurrent request that has already taken the row lock.
    $pdo = Connection::pdo();
    $pdo->beginTransaction();
    $pdo->prepare('SELECT id FROM employee.company_assets WHERE id = :id FOR UPDATE')->execute(['id' => $assetId]);
    sleep(3);
    $pdo->prepare("UPDATE employee.company_assets SET status = 'assigned', assigned_to = :who, assigned_on = CURRENT_DATE WHERE id = :id")
        ->execute(['id' => $assetId, 'who' => $holder]);
    $pdo->commit();
    echo "holder: committed assignment to $holder\n";

    return;
}

if ($role === 'unlocked') {
    // The pre-fix sequence: read the status outside any lock, then write.
    $assets = new App\Models\CompanyAssets();
    $before = $assets->find($assetId);
    echo 'unlocked: read status=', (string) $before['status'], "\n";

    if ($before['status'] === 'assigned') {
        echo "unlocked: status=409 (already issued)\n";

        return;
    }

    $after = $assets->update($assetId, [
        'assigned_to' => $holder,
        'assigned_on' => date('Y-m-d'),
        'returned_on' => null,
        'status' => 'assigned',
    ]);
    echo 'unlocked: status=200 holder=', (string) $after['assigned_to'], "\n";

    return;
}

$principal = Principal::fromClaims([
    'sub' => '00000000-0000-4000-8000-000000000002',
    'employee_id' => 'a9f5c390-db56-42fa-96d9-fb1ff57ca041',
    'email' => 'hr@dayflow.local',
    'name' => 'HR',
    'roles' => [Roles::HR_ADMIN],
    'type' => 'access',
]);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/test';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = [];
$_POST = ['employee_id' => $holder];
$_FILES = [];

$request = Request::capture()->withRouteParameters(['id' => $assetId])->withPrincipal($principal);

try {
    $response = (new App\Controllers\AssetController())->assign($request);
    echo 'challenger: status=', $response->status, ' holder=', (string) $response->payload['data']['assigned_to'], "\n";
} catch (HttpException $e) {
    echo 'challenger: status=', $e->status(), ' (', $e->getMessage(), ")\n";
} catch (ValidationException $e) {
    echo 'challenger: status=422 ', json_encode($e->errors()), "\n";
}
