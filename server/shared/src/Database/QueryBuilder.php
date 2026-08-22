<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Database;

/**
 * A deliberately small, safe query builder.
 *
 * Values are always bound as parameters. Column and table names are validated
 * against a strict identifier pattern and quoted, so no caller can smuggle SQL
 * through a column name. Operators are restricted to a fixed allow-list.
 */
final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'ILIKE', 'IN', 'NOT IN', 'IS', 'IS NOT'];

    private array $wheres = [];
    private array $bindings = [];
    private array $orders = [];
    private array $selects = ['*'];
    private array $joins = [];
    private ?string $groupBy = null;
    private ?int $limit = null;
    private ?int $offset = null;
    private int $bindingIndex = 0;

    public function __construct(private readonly string $table)
    {
    }

    public static function table(string $table): self
    {
        return new self($table);
    }

    public function select(string ...$columns): self
    {
        $this->selects = array_map([$this, 'column'], $columns);

        return $this;
    }

    /** Adds a raw select expression. Never pass user input here. */
    public function selectRaw(string $expression): self
    {
        if ($this->selects === ['*']) {
            $this->selects = [];
        }
        $this->selects[] = $expression;

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $type = strtoupper($type);
        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT'], true)) {
            throw new \InvalidArgumentException('Unsupported join type.');
        }

        $this->joins[] = sprintf(
            '%s JOIN %s ON %s %s %s',
            $type,
            $this->identifier($table),
            $this->column($first),
            $this->operator($operator),
            $this->column($second)
        );

        return $this;
    }

    public function where(string $column, string $operator, mixed $value = null): self
    {
        // where('col', $value) shorthand.
        if ($value === null && !in_array(strtoupper($operator), self::OPERATORS, true)) {
            $value = $operator;
            $operator = '=';
        }

        $operator = $this->operator($operator);

        if ($value === null) {
            $this->wheres[] = sprintf('%s IS NULL', $this->column($column));

            return $this;
        }

        $placeholder = $this->bind($value);
        $this->wheres[] = sprintf('%s %s %s', $this->column($column), $operator, $placeholder);

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = sprintf('%s IS NULL', $this->column($column));

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = sprintf('%s IS NOT NULL', $this->column($column));

        return $this;
    }

    /** @param list<mixed> $values */
    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            // An empty IN list must match nothing rather than everything.
            $this->wheres[] = '1 = 0';

            return $this;
        }

        $placeholders = array_map([$this, 'bind'], array_values($values));
        $this->wheres[] = sprintf('%s IN (%s)', $this->column($column), implode(', ', $placeholders));

        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to): self
    {
        $this->wheres[] = sprintf(
            '%s BETWEEN %s AND %s',
            $this->column($column),
            $this->bind($from),
            $this->bind($to)
        );

        return $this;
    }

    /** Case-insensitive partial match, with wildcard characters neutralised. */
    public function whereLike(string $column, string $value): self
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        $this->wheres[] = sprintf("%s ILIKE %s ESCAPE '\\'", $this->column($column), $this->bind('%' . $escaped . '%'));

        return $this;
    }

    /**
     * Groups several columns into one OR-ed search clause.
     *
     * @param list<string> $columns
     */
    public function whereAnyLike(array $columns, string $value): self
    {
        if ($columns === [] || trim($value) === '') {
            return $this;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        $placeholder = $this->bind('%' . $escaped . '%');

        $clauses = array_map(
            fn (string $column): string => sprintf("%s ILIKE %s ESCAPE '\\'", $this->column($column), $placeholder),
            $columns
        );

        $this->wheres[] = '(' . implode(' OR ', $clauses) . ')';

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = $this->column($column) . ' ' . $direction;

        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        $this->groupBy = implode(', ', array_map([$this, 'column'], $columns));

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    public function page(int $page, int $perPage): self
    {
        $page = max(1, $page);

        return $this->limit($perPage)->offset(($page - 1) * $perPage);
    }

    public function toSql(): string
    {
        $sql = 'SELECT ' . implode(', ', $this->selects) . ' FROM ' . $this->identifier($this->table);

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        if ($this->groupBy !== null) {
            $sql .= ' GROUP BY ' . $this->groupBy;
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /** @return array<string, mixed> */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /** @return list<array<string, mixed>> */
    public function get(): array
    {
        $statement = Connection::pdo()->prepare($this->toSql());
        $statement->execute($this->bindings);

        return $statement->fetchAll();
    }

    public function first(): ?array
    {
        $rows = $this->limit(1)->get();

        return $rows[0] ?? null;
    }

    public function count(): int
    {
        $clone = clone $this;
        $clone->selects = ['COUNT(*) AS aggregate'];
        $clone->orders = [];
        $clone->limit = null;
        $clone->offset = null;

        $statement = Connection::pdo()->prepare($clone->toSql());
        $statement->execute($clone->bindings);

        return (int) ($statement->fetchColumn() ?: 0);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    private function bind(mixed $value): string
    {
        $key = 'p' . $this->bindingIndex++;

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        $this->bindings[$key] = $value;

        return ':' . $key;
    }

    private function operator(string $operator): string
    {
        $operator = strtoupper(trim($operator));

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException(sprintf('Operator "%s" is not permitted.', $operator));
        }

        return $operator;
    }

    /** Validates and quotes a possibly table-qualified column reference. */
    private function column(string $column): string
    {
        if ($column === '*') {
            return '*';
        }

        // Support "table.column" and "table.*".
        if (str_contains($column, '.')) {
            [$table, $name] = explode('.', $column, 2);

            return $this->identifier($table) . '.' . ($name === '*' ? '*' : $this->identifier($name));
        }

        return $this->identifier($column);
    }

    private function identifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid SQL identifier "%s".', $identifier));
        }

        return '"' . $identifier . '"';
    }
}
