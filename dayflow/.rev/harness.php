<?php
declare(strict_types=1);

require '/var/www/shared/bootstrap.php';
dayflow_autoload_app('App', '/app/server/services/employee-service/app');

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Database\Migrator;

$pdo = Connection::pdo();
$m = new Migrator('/app/server/services/employee-service/database/migrations', 'employee');
$applied = $m->run();
echo "migrations applied: ", json_encode($applied), PHP_EOL;

// run the seed
(static function (string $f): void { require $f; })('/app/server/services/employee-service/database/seeds/seed.php');
echo "seed run 1 ok\n";
$counts = function () use ($pdo) {
    $out = [];
    foreach (['departments','designations','locations','checklist_templates','employees','employee_documents','onboarding_tasks','offboarding_tasks','company_assets'] as $t) {
        $out[$t] = (int) $pdo->query("SELECT COUNT(*) FROM employee.$t")->fetchColumn();
    }
    return $out;
};
$a = $counts();
echo "counts1: ", json_encode($a), PHP_EOL;
(static function (string $f): void { require $f; })('/app/server/services/employee-service/database/seeds/seed.php');
$b = $counts();
echo "counts2: ", json_encode($b), PHP_EOL;
echo "IDEMPOTENT: ", ($a === $b ? 'YES' : 'NO'), PHP_EOL;
