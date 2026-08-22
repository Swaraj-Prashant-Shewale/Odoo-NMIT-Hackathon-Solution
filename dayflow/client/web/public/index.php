<?php

declare(strict_types=1);

/**
 * Dayflow web client - front controller.
 *
 * This is the only part of the platform a person opens in a browser. It holds
 * no business logic and talks to no database: every piece of data on every
 * screen is fetched from the API gateway, which fans out to the microservices.
 */

require (getenv('DAYFLOW_SHARED') ?: __DIR__ . '/../../server/shared') . '/bootstrap.php';

dayflow_autoload_app('App', __DIR__ . '/../app');

require __DIR__ . '/../app/Core/helpers.php';

use App\Core\Csrf;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use Dayflow\Kernel\Support\Env;

// ---------------------------------------------------------------------------
// Response headers
//
// The Content-Security-Policy is the client's main defence in depth. Scripts
// and styles may only come from this origin, so an injected <script src="…">
// pointing elsewhere is refused by the browser even if it somehow reached the
// page. Training videos are the single deliberate exception: YouTube is allowed
// as a frame source and nothing else is.
// ---------------------------------------------------------------------------
header("Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self'; "
    . "style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data: https://img.youtube.com https://i.ytimg.com; "
    . "font-src 'self'; "
    . "connect-src 'self'; "
    . "frame-src https://www.youtube-nocookie.com https://www.youtube.com; "
    . "form-action 'self'; "
    . "frame-ancestors 'none'; "
    . "base-uri 'self'; "
    . "object-src 'none'");

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(self), camera=(), microphone=(), payment=()');

if (Env::bool('SESSION_SECURE_COOKIE', false)) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

Session::start();

// Available to every template without being threaded through each controller.
View::share([
    'appName' => Env::get('APP_NAME', 'Dayflow'),
    'currentUser' => Session::user(),
    'csrfToken' => Csrf::token(),
]);

$router = new Router();

(require __DIR__ . '/../routes.php')($router);

$router->dispatch();
