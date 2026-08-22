<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Http;

use Dayflow\Kernel\Database\Connection;
use Dayflow\Kernel\Database\Migrator;
use Dayflow\Kernel\Events\EventPublisher;
use Dayflow\Kernel\Security\InternalSignature;
use Dayflow\Kernel\Security\Jwt;
use Dayflow\Kernel\Security\Principal;
use Dayflow\Kernel\Security\TokenException;
use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;
use Dayflow\Kernel\Validation\ValidationException;

/**
 * Boots a microservice and handles one request.
 *
 * Every service front controller is the same four lines:
 *
 *   $kernel = new Kernel(__DIR__ . '/..');
 *   $kernel->migrate();
 *   $kernel->routes(require __DIR__ . '/../routes.php');
 *   $kernel->run();
 *
 * The kernel owns the pieces that must behave identically everywhere:
 * schema migration on boot, gateway signature verification, token
 * verification, permission enforcement and error shaping.
 */
final class Kernel
{
    private Router $router;

    public function __construct(private readonly string $basePath)
    {
        $this->router = new Router();

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    /**
     * Applies any pending migrations and seeds for this service.
     *
     * The front controller calls this on every request, but the work is done
     * at most once per container. A marker file records that this exact set of
     * migration and seed files has already been applied; while it is valid,
     * this returns immediately.
     *
     * Without that guard, every single request would re-check the migration
     * ledger and re-run the seed file — a dozen wasted queries on the way to
     * rendering one page.
     *
     * The marker is keyed on the contents of the migration and seed files, so
     * editing or adding one during development takes effect on the next
     * request without needing a restart.
     */
    public function migrate(): void
    {
        $marker = sys_get_temp_dir() . '/dayflow-schema-' . Env::get('SERVICE_NAME', 'service') . '.marker';
        $fingerprint = $this->schemaFingerprint();

        if (is_file($marker) && @file_get_contents($marker) === $fingerprint) {
            return;
        }

        try {
            $migrator = new Migrator($this->basePath . '/database/migrations', Connection::schema());
            $applied = $migrator->run();

            if ($applied !== []) {
                Logger::info('Schema brought up to date', ['migrations' => $applied]);
            }

            $seeder = $this->basePath . '/database/seeds/seed.php';
            if (is_file($seeder)) {
                // Seeds are written to be idempotent, so running them again is
                // harmless; this simply avoids doing so needlessly.
                (static function (string $file): void {
                    require $file;
                })($seeder);
            }

            @file_put_contents($marker, $fingerprint, LOCK_EX);
        } catch (\Throwable $exception) {
            // The marker is deliberately not written on failure, so the next
            // request tries again rather than assuming the schema is ready.
            Logger::exception($exception, ['stage' => 'migrate']);

            throw $exception;
        }
    }

    /**
     * A digest of every migration and seed file, by name, size and timestamp.
     *
     * Cheap to compute and changes whenever any of them is edited or added.
     */
    private function schemaFingerprint(): string
    {
        $parts = [];

        foreach ([$this->basePath . '/database/migrations/*.sql', $this->basePath . '/database/seeds/*.php'] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $parts[] = basename($file) . ':' . (filesize($file) ?: 0) . ':' . (filemtime($file) ?: 0);
            }
        }

        sort($parts);

        return hash('sha256', implode('|', $parts));
    }

    /** @param callable(Router): void $definition */
    public function routes(callable $definition): void
    {
        $definition($this->router);

        // Every service exposes the same operational endpoints.
        $this->router->get('/health', function (Request $request): Response {
            return Response::ok([
                'service' => Env::get('SERVICE_NAME', 'dayflow'),
                'status' => Connection::healthy() ? 'healthy' : 'degraded',
                'time' => Clock::iso(),
            ]);
        })->allowPublic();

        $this->router->get('/_routes', function (Request $request): Response {
            if (Env::isProduction()) {
                throw HttpException::notFound();
            }

            return Response::ok($this->router->inventory());
        })->allowPublic();
    }

    public function run(): void
    {
        $request = Request::capture();

        try {
            $response = $this->handle($request);
        } catch (HttpException $exception) {
            $response = $exception->toResponse();

            if ($exception->status() >= 500) {
                Logger::exception($exception, ['path' => $request->path]);
            }
        } catch (ValidationException $exception) {
            $response = Response::error(422, 'validation_failed', $exception->getMessage(), $exception->errors());
        } catch (\Throwable $exception) {
            Logger::exception($exception, ['path' => $request->path, 'method' => $request->method]);

            // Internal details are never returned to a caller in production;
            // the request id is the thread back to the log entry.
            $response = Response::error(
                500,
                'internal_error',
                Env::isDebug() ? $exception->getMessage() : 'Something went wrong while processing this request.',
                Env::isDebug() ? ['type' => $exception::class, 'file' => $exception->getFile(), 'line' => $exception->getLine()] : []
            );
        }

        $response->withHeaders(['X-Request-Id' => $request->requestId])->send();

        // Queued domain events are dispatched once the response is on the wire
        // so a slow subscriber never delays the caller.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        EventPublisher::flush();
    }

    private function handle(Request $request): Response
    {
        $this->assertGatewayOrigin($request);

        $matched = $this->router->match($request->method, $request->path);
        $route = $matched['route'];

        $request = $request->withRouteParameters($matched['parameters']);

        if (!$route['public']) {
            $request = $request->withPrincipal($this->authenticate($request));

            if ($route['permission'] !== null && !$request->principal()->can($route['permission'])) {
                Logger::warning('Permission denied', [
                    'user_id' => $request->principal()->userId,
                    'permission' => $route['permission'],
                    'path' => $request->path,
                ]);

                throw HttpException::forbidden();
            }
        }

        $result = ($route['handler'])($request);

        if ($result instanceof Response) {
            return $result;
        }

        return Response::ok($result);
    }

    /**
     * Confirms the request arrived through the API gateway.
     *
     * Services listen only on the internal network, but that is a perimeter
     * control. Requiring a signature means a service also refuses direct calls
     * from anything else that happens to be on that network.
     */
    private function assertGatewayOrigin(Request $request): void
    {
        if (!Env::bool('REQUIRE_GATEWAY_SIGNATURE', true)) {
            return;
        }

        // Health checks come from the container runtime, which cannot sign.
        if (in_array($request->path, ['/health', '/_routes'], true)) {
            return;
        }

        $valid = InternalSignature::verify(
            $request->method,
            $request->path,
            $request->rawBody,
            $request->headers
        );

        if (!$valid) {
            Logger::warning('Rejected unsigned internal request', [
                'path' => $request->path,
                'ip' => $request->clientIp,
            ]);

            throw HttpException::forbidden('This service only accepts requests from the API gateway.');
        }
    }

    private function authenticate(Request $request): Principal
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw HttpException::unauthorized();
        }

        try {
            $claims = Jwt::verify($token);
        } catch (TokenException $exception) {
            throw HttpException::unauthorized($exception->getMessage());
        }

        if (($claims['type'] ?? 'access') !== 'access') {
            // A refresh token must never be usable as an access token.
            throw HttpException::unauthorized('This token cannot be used to call the API.');
        }

        return Principal::fromClaims($claims);
    }
}
