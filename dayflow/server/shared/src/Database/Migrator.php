<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Database;

use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Logger;

/**
 * Applies a service's SQL migrations.
 *
 * Each service owns a database/migrations directory of numbered .sql files.
 * Applied files are recorded in <schema>.schema_migrations, so running the
 * migrator repeatedly is safe: it only ever applies what is missing. Services
 * call this on boot, which is what makes "tables are created if they do not
 * exist" true without any manual database step.
 */
final class Migrator
{
    public function __construct(
        private readonly string $migrationsPath,
        private readonly string $schema,
    ) {
    }

    /**
     * Runs every pending migration inside a transaction.
     *
     * @return list<string> Names of the migrations that were applied.
     */
    public function run(): array
    {
        $pdo = Connection::pdo();

        $this->ensureSchema($pdo);
        $this->ensureLedger($pdo);
        $this->ensureEventOutbox($pdo);

        $applied = $this->appliedMigrations($pdo);
        $pending = array_values(array_diff($this->availableMigrations(), $applied));

        if ($pending === []) {
            return [];
        }

        sort($pending);
        $ran = [];

        foreach ($pending as $migration) {
            $sql = file_get_contents($this->migrationsPath . '/' . $migration);
            if ($sql === false || trim($sql) === '') {
                continue;
            }

            // An advisory lock keeps concurrently starting replicas of the same
            // service from applying the same migration twice.
            $lockKey = crc32($this->schema . ':' . $migration);
            $pdo->prepare('SELECT pg_advisory_lock(:key)')->execute(['key' => $lockKey]);

            try {
                if (in_array($migration, $this->appliedMigrations($pdo), true)) {
                    continue;
                }

                $pdo->beginTransaction();
                $pdo->exec($sql);

                $statement = $pdo->prepare(sprintf(
                    'INSERT INTO %s.schema_migrations (migration, applied_at) VALUES (:migration, :applied_at)',
                    Connection::quoteIdentifier($this->schema)
                ));
                $statement->execute(['migration' => $migration, 'applied_at' => Clock::iso()]);

                $pdo->commit();
                $ran[] = $migration;

                Logger::info('Migration applied', ['schema' => $this->schema, 'migration' => $migration]);
            } catch (\Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                Logger::error('Migration failed', [
                    'schema' => $this->schema,
                    'migration' => $migration,
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            } finally {
                $pdo->prepare('SELECT pg_advisory_unlock(:key)')->execute(['key' => $lockKey]);
            }
        }

        return $ran;
    }

    /** @return list<string> */
    public function pending(): array
    {
        $pdo = Connection::pdo();
        $this->ensureLedger($pdo);

        return array_values(array_diff($this->availableMigrations(), $this->appliedMigrations($pdo)));
    }

    /**
     * Creates the service's schema only when it is genuinely absent.
     *
     * The container bootstrap normally provisions it, so checking first avoids
     * requiring the service role to hold CREATE on the database just to run a
     * statement that would be a no-op.
     */
    private function ensureSchema(\PDO $pdo): void
    {
        $statement = $pdo->prepare('SELECT 1 FROM information_schema.schemata WHERE schema_name = :schema');
        $statement->execute(['schema' => $this->schema]);

        if ($statement->fetchColumn() !== false) {
            return;
        }

        $pdo->exec(sprintf('CREATE SCHEMA IF NOT EXISTS %s', Connection::quoteIdentifier($this->schema)));
    }

    /**
     * Guarantees the transactional outbox exists.
     *
     * Every service publishes domain events through the same mechanism, so the
     * table is created by the kernel rather than repeated in nine migrations.
     */
    private function ensureEventOutbox(\PDO $pdo): void
    {
        $schema = Connection::quoteIdentifier($this->schema);

        $pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s.event_outbox (
                id              UUID PRIMARY KEY,
                event_name      TEXT        NOT NULL,
                payload         JSONB       NOT NULL,
                attempts        INTEGER     NOT NULL DEFAULT 0,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                last_attempt_at TIMESTAMPTZ,
                delivered_at    TIMESTAMPTZ
            )',
            $schema
        ));

        $pdo->exec(sprintf(
            'CREATE INDEX IF NOT EXISTS event_outbox_pending_idx
             ON %s.event_outbox (created_at) WHERE delivered_at IS NULL',
            $schema
        ));
    }

    private function ensureLedger(\PDO $pdo): void
    {
        $pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s.schema_migrations (
                migration   TEXT PRIMARY KEY,
                applied_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )',
            Connection::quoteIdentifier($this->schema)
        ));
    }

    /** @return list<string> */
    private function appliedMigrations(\PDO $pdo): array
    {
        $statement = $pdo->query(sprintf(
            'SELECT migration FROM %s.schema_migrations ORDER BY migration',
            Connection::quoteIdentifier($this->schema)
        ));

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /** @return list<string> */
    private function availableMigrations(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        $names = array_map('basename', $files);
        sort($names);

        return $names;
    }
}
