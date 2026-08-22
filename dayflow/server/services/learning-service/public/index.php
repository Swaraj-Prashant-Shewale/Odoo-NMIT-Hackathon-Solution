<?php

declare(strict_types=1);

require (getenv('DAYFLOW_SHARED') ?: __DIR__ . '/../../shared') . '/bootstrap.php';

dayflow_autoload_app('App', __DIR__ . '/../app');

$kernel = new Dayflow\Kernel\Http\Kernel(__DIR__ . '/..');
$kernel->migrate();
$kernel->routes(require __DIR__ . '/../routes.php');
$kernel->run();
