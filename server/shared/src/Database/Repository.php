<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Database;

use Dayflow\Kernel\Support\Clock;
use Dayflow\Kernel\Support\Str;

/**
 * Base data-access class.
 *
 * Concrete repositories declare their table, primary key and the columns that
 * may be written. Anything outside $fillable is dropped before an INSERT or
 * UPDATE is built, which stops mass-assignment attacks even if a controller
 * forwards a whole request body.
 */
abstract class Repository
{
    /** Table name inside the service's own schema. */
    protected string $table = '';

    /** Primary key column. Records use UUIDs so ids are never guessable. */
    protected string $primaryKey = 'id';

    /** Columns a client is allowed to write. */
    protected array $fillable = [];

    /** Columns stripped from every record before it leaves the service. */
    protected array $hidden = [];

    /** Columns cast to a PHP type when reading. */
    protected array $casts = [];

    protected bool $timestamps = true;

    protected bool $softDeletes = false;

    public function query(): QueryBuilder
    {
        $builder = QueryBuilder::table($this->table);

        if ($this->softDeletes) {
            $builder->whereNull('deleted_at');
        }

        return $builder;
    }

    public function find(string $id): ?array
    {
        $row = $this->query()->where($this->primaryKey, '=', $id)->first();

        return $row === null ? null : $this->present($row);
    }

    public function findBy(string $column, mixed $value): ?array
    {
        $row = $this->query()->where($column, '=', $value)->first();

        return $row === null ? null : $this->present($row);
    }

    /** @return list<array<string, mixed>> */
    public function all(string $orderBy = 'created_at', string $direction = 'desc'): array
    {
        $rows = $this->query()->orderBy($orderBy, $direction)->get();

        return array_map([$this, 'present'], $rows);
    }

    /** @return list<array<string, mixed>> */
    public function where(string $column, mixed $value): array
    {
        $rows = $this->query()->where($column, '=', $value)->get();

        return array_map([$this, 'present'], $rows);
    }

    /**
     * Inserts a record and returns it.
     *
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): array
    {
        $data = $this->filterFillable($attributes);

        if (!isset($data[$this->primaryKey])) {
            $data[$this->primaryKey] = Str::uuid();
        }

        if ($this->timestamps) {
            $now = Clock::iso();
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
        }

        $columns = array_keys($data);
        $quoted = array_map([$this, 'quote'], $columns);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) RETURNING *',
            $this->quote($this->table),
            implode(', ', $quoted),
            implode(', ', $placeholders)
        );

        $statement = Connection::pdo()->prepare($sql);
        $statement->execute($this->normaliseBindings($data));

        return $this->present($statement->fetch() ?: []);
    }

    /**
     * Updates a record by primary key and returns the fresh row.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(string $id, array $attributes): ?array
    {
        $data = $this->filterFillable($attributes);

        if ($data === []) {
            return $this->find($id);
        }

        if ($this->timestamps) {
            $data['updated_at'] = Clock::iso();
        }

        $assignments = array_map(
            fn (string $column): string => sprintf('%s = :%s', $this->quote($column), $column),
            array_keys($data)
        );

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = :__id RETURNING *',
            $this->quote($this->table),
            implode(', ', $assignments),
            $this->quote($this->primaryKey)
        );

        $bindings = $this->normaliseBindings($data);
        $bindings['__id'] = $id;

        $statement = Connection::pdo()->prepare($sql);
        $statement->execute($bindings);

        $row = $statement->fetch();

        return $row === false ? null : $this->present($row);
    }

    /** Removes a record, honouring soft deletes when the table supports them. */
    public function delete(string $id): bool
    {
        if ($this->softDeletes) {
            $sql = sprintf(
                'UPDATE %s SET deleted_at = :deleted_at WHERE %s = :id AND deleted_at IS NULL',
                $this->quote($this->table),
                $this->quote($this->primaryKey)
            );
            $statement = Connection::pdo()->prepare($sql);
            $statement->execute(['deleted_at' => Clock::iso(), 'id' => $id]);

            return $statement->rowCount() > 0;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s = :id',
            $this->quote($this->table),
            $this->quote($this->primaryKey)
        );
        $statement = Connection::pdo()->prepare($sql);
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    /**
     * Runs a prepared statement written by hand.
     *
     * Used for reporting queries that the builder is not meant to express. The
     * SQL is always a constant in the calling class; values stay parameterised.
     *
     * @param array<string, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function raw(string $sql, array $bindings = []): array
    {
        $statement = Connection::pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll();
    }

    /** @param array<string, mixed> $bindings */
    public function rawOne(string $sql, array $bindings = []): ?array
    {
        $rows = $this->raw($sql, $bindings);

        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $bindings */
    public function execute(string $sql, array $bindings = []): int
    {
        $statement = Connection::pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    /**
     * Returns one page of results together with its pagination metadata.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function paginate(QueryBuilder $builder, int $page, int $perPage = 20): array
    {
        $perPage = max(1, min($perPage, 100));
        $total = $builder->count();
        $rows = $builder->page($page, $perPage)->get();

        return [
            'data' => array_map([$this, 'present'], $rows),
            'meta' => [
                'page' => max(1, $page),
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Shapes a raw database row for the outside world.
     *
     * Hidden columns are removed and declared casts applied. Override in a
     * concrete repository to add computed fields.
     */
    public function present(array $row): array
    {
        foreach ($this->hidden as $column) {
            unset($row[$column]);
        }

        foreach ($this->casts as $column => $type) {
            if (!array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }

            $row[$column] = match ($type) {
                'int' => (int) $row[$column],
                'float' => (float) $row[$column],
                'bool' => filter_var($row[$column], FILTER_VALIDATE_BOOLEAN),
                'json' => is_string($row[$column]) ? json_decode($row[$column], true) : $row[$column],
                default => $row[$column],
            };
        }

        return $row;
    }

    /** @param array<string, mixed> $attributes */
    protected function filterFillable(array $attributes): array
    {
        if ($this->fillable === []) {
            return $attributes;
        }

        return array_intersect_key($attributes, array_flip($this->fillable));
    }

    /** Encodes arrays as JSON and booleans in a form PostgreSQL accepts. */
    protected function normaliseBindings(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value)) {
                $data[$key] = $value ? 'true' : 'false';
            }
        }

        return $data;
    }

    protected function quote(string $identifier): string
    {
        return Connection::quoteIdentifier($identifier);
    }

    public function tableName(): string
    {
        return $this->table;
    }
}
