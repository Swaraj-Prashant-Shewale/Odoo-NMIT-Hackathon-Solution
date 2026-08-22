<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Database;

use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Logger;

/**
 * PostgreSQL connection manager.
 *
 * Each microservice connects with its own database role, and that role is only
 * granted rights on its own schema. Isolation between services is therefore
 * enforced by PostgreSQL itself rather than by convention in application code.
 *
 * Every statement issued through this class is prepared and bound; string
 * interpolation of user input into SQL never happens anywhere in the platform.
 */
final class Connection
{
    private static ?\PDO $pdo = null;

    private function __construct()
    {
    }

    public static function pdo(): \PDO
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        $host = Env::get('DB_HOST', 'postgres');
        $port = Env::int('DB_PORT', 5432);
        $database = Env::get('DB_NAME', 'dayflow');
        $user = Env::require('DB_USER');
        $password = Env::require('DB_PASSWORD');
        $schema = Env::require('DB_SCHEMA');

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);

        // The database container may still be starting when a service boots,
        // so connection attempts are retried with a short backoff.
        $attempts = Env::int('DB_CONNECT_ATTEMPTS', 30);
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $pdo = new \PDO($dsn, $user, $password, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    // Real prepared statements on the server, never emulated.
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::ATTR_STRINGIFY_FETCHES => false,
                ]);

                // Resolve unqualified table names against this service's schema
                // first, then the shared "platform" schema for common lookups.
                $pdo->exec(sprintf('SET search_path TO %s, platform, public', self::quoteIdentifier($schema)));
                $pdo->exec("SET TIME ZONE 'UTC'");

                return self::$pdo = $pdo;
            } catch (\PDOException $exception) {
                $lastError = $exception;
                usleep(500_000);
            }
        }

        Logger::error('Database connection failed', [
            'host' => $host,
            'database' => $database,
            'schema' => $schema,
            'attempts' => $attempts,
        ]);

        throw new \RuntimeException(
            'Unable to establish a database connection.',
            0,
            $lastError
        );
    }

    /** Runs a callback inside a transaction, rolling back on any exception. */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();

        // Nested calls join the transaction already in flight.
        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Escapes an identifier (schema, table or column name).
     *
     * Identifiers cannot be bound as parameters, so anything interpolated into
     * SQL as an identifier must pass through here. Only word characters survive.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $identifier) ?? '';

        if ($clean === '') {
            throw new \InvalidArgumentException('Invalid SQL identifier supplied.');
        }

        return '"' . $clean . '"';
    }

    public static function schema(): string
    {
        return Env::require('DB_SCHEMA');
    }

    /** Confirms the connection is alive; used by the /health endpoint. */
    public static function healthy(): bool
    {
        try {
            return self::pdo()->query('SELECT 1')->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
