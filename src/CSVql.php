<?php

namespace Phi\CSVql;

use PDO;
use Countable;
use IteratorAggregate;
use Generator;
use PDOStatement;
use RuntimeException;

/**
 * @implements IteratorAggregate<string|int, mixed>
 */
class CSVql implements Countable, IteratorAggregate
{
    protected PDO $pdo;

    /**
     * @var array<int, string>
     */
    protected array $where = [];

    /**
     * @var array<int, string>
     */
    protected array $group = [];

    /**
     * @var array<int, string>
     */
    protected array $columns = [];

    /**
     * @var array<string, int>
     */
    protected array $columnIndex = [];

    public function __construct(
        public readonly string $source,
        public readonly string $separator = ',',
        public readonly string $enclosure = '"',
        public readonly string $escape = '\\',
        public readonly bool $header = true,
    ) {
        if (!file_exists($this->source)) {
            throw new RuntimeException("File {$this->source} does not exist");
        }

        if (!is_readable($this->source)) {
            throw new RuntimeException("File {$this->source} is not readable");
        }

        $this->pdo = new PDO('sqlite::memory:');

        $this->_import();
    }

    protected function _import(): void
    {
        $handle = @fopen($this->source, 'r');

        if ($handle === false) {
            throw new RuntimeException("Failed to open file {$this->source}");
        }

        $firstRow = fgetcsv($handle, null, $this->separator, $this->enclosure, $this->escape);

        if ($firstRow === false) {
            throw new RuntimeException("File '{$this->source}' is empty");
        }

        $columns = array_map(fn ($column) => "col{$column}", range(0, count($firstRow) - 1));
        $this->pdo->exec(sprintf(
            'CREATE TABLE "data" (%s)',
            implode(', ', array_map(fn ($column) => sprintf('"%s" TEXT NULL DEFAULT NULL', $column), $columns))
        ));
        if ($this->header) {
            $this->columns = $firstRow;
        } else {
            $this->columns = $columns;
            rewind($handle);
        }

        // Use prepared statement for better performance
        $stmt = $this->pdo->prepare(sprintf(
            'INSERT INTO "data" VALUES (%s)',
            implode(', ', array_fill(0, count($columns), '?'))
        ));

        // Disable synchronous and journal mode for faster inserts
        $this->pdo->exec('PRAGMA synchronous = OFF');
        $this->pdo->exec('PRAGMA journal_mode = MEMORY');

        // Use transaction for faster inserts
        $this->pdo->beginTransaction();

        while (($row = fgetcsv($handle, null, $this->separator, $this->enclosure, $this->escape)) !== false) {
            if ($stmt->execute($row) === false) {
                $this->pdo->rollBack();
                fclose($handle);
                throw new RuntimeException("Failed to insert row: " . implode(', ', $row));
            }
        }

        $this->pdo->commit();

        fclose($handle);

        $this->columnIndex = array_flip($this->columns);
    }

    protected function _getWhere(): string
    {
        if (count($this->where) === 0) {
            return '';
        }

        return 'WHERE ' . implode(' AND ', $this->where);
    }

    protected function _getGroup(): string
    {
        if (count($this->group) === 0) {
            return '';
        }

        return 'GROUP BY ' . implode(', ', $this->group);
    }

    protected function _getQuery(string $columns = '*'): string
    {
        return sprintf('SELECT %s FROM "data" %s %s', $columns, $this->_getWhere(), $this->_getGroup());
    }

    protected function _getColumn(int|string $column): int
    {
        if (is_int($column)) {
            $columnIndex = $column;
        } else {
            if (!array_key_exists($column, $this->columnIndex)) {
                throw new RuntimeException("Column '{$column}' not found");
            }

            $columnIndex = $this->columnIndex[$column];
        }

        if ($columnIndex < 0 || $columnIndex >= count($this->columns)) {
            throw new RuntimeException("Column {$columnIndex} is out of bounds");
        }

        return $columnIndex;
    }

    protected function _execute(string $query): PDOStatement
    {
        $result =  $this->pdo->query($query);

        if ($result === false) {
            throw new RuntimeException("Failed to execute query: {$query}");
        }

        return $result;
    }

    public function getIterator(): Generator
    {
        $result = $this->_execute($this->_getQuery());

        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            if ($this->header) {
                yield array_combine($this->columns, $row);
            } else {
                yield array_values($row);
            }
        }
    }

    public function count(): int
    {
        return intval($this->_execute($this->_getQuery('COUNT(*)'))->fetchColumn());
    }

    public function max(int|string $column): mixed
    {
        $columnNumber = $this->_getColumn($column);

        return $this->_execute($this->_getQuery(sprintf('MAX(CAST("col%d" AS REAL))', $columnNumber)))->fetchColumn();
    }

    public function min(int|string $column): mixed
    {
        $columnNumber = $this->_getColumn($column);

        return $this->_execute($this->_getQuery(sprintf('MIN(CAST("col%d" AS REAL))', $columnNumber)))->fetchColumn();
    }

    public function sum(int|string $column): mixed
    {
        $columnNumber = $this->_getColumn($column);

        return $this->_execute($this->_getQuery(sprintf('SUM(CAST("col%d" AS REAL))', $columnNumber)))->fetchColumn();
    }

    public function avg(int|string $column): mixed
    {
        $columnNumber = $this->_getColumn($column);

        return $this->_execute($this->_getQuery(sprintf('AVG(CAST("col%d" AS REAL))', $columnNumber)))->fetchColumn();
    }

    /**
     * @return array<string>
     */
    public function distinct(int|string $column): array
    {
        $columnNumber = $this->_getColumn($column);

        return $this->_execute($this->_getQuery(sprintf('DISTINCT "col%d"', $columnNumber)))->fetchAll(PDO::FETCH_COLUMN);
    }

    public function where(int|string $column, int|float|string $value, string $operator = '='): self
    {
        $validOperators = ['=', '<>', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'IS', 'IS NOT'];

        if (!in_array($operator, $validOperators)) {
            throw new RuntimeException("Invalid operator: {$operator}");
        }

        $castAs = is_string($value) ? 'TEXT' : 'REAL';
        $columnNumber = $this->_getColumn($column);

        $this->where[] = sprintf('CAST("col%d" AS %s) %s %s', $columnNumber, $castAs, $operator, $this->pdo->quote($value));

        return $this;
    }

    public function group(int|string $column): self
    {
        $columnNumber = $this->_getColumn($column);

        $this->group[] = sprintf('"col%d"', $columnNumber);

        return $this;
    }

    public function reset(): self
    {
        $this->where = [];
        $this->group = [];

        return $this;
    }
}
