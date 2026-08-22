<?php
declare(strict_types=1);
require '/var/www/shared/bootstrap.php';
use Dayflow\Kernel\Database\QueryBuilder;
$q = QueryBuilder::table('employees')
    ->select('id')
    ->whereAnyLike(['first_name','last_name'], 'Priya')
    ->where('is_active', '=', true);
echo "SQL: ", $q->toSql(), PHP_EOL;
echo "BINDINGS: ", json_encode($q->bindings()), PHP_EOL;
try { echo "rows: ", count($q->get()), PHP_EOL; }
catch (Throwable $e) { echo "ERROR: ", get_class($e), ': ', $e->getMessage(), PHP_EOL; }
