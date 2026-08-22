<?php

declare(strict_types=1);

/**
 * Dayflow API gateway - front controller.
 *
 * Every call from the web client enters here. The gateway is the only backend
 * component published to the host; the nine microservices behind it are
 * reachable only on the private container network.
 */

require (getenv('DAYFLOW_SHARED') ?: __DIR__ . '/../../shared') . '/bootstrap.php';

dayflow_autoload_app('Gateway', __DIR__ . '/../app');

(new Gateway\Gateway())->handle();
