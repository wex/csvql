<?php

namespace Phi\CSVql;

use PDO;
use Countable;
use IteratorAggregate;
use Generator;
use PDOStatement;
use RuntimeException;
use Throwable;

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
     * SQL-quoted identifiers for each column, indexed by position.
     *
     * @var array<int, string>
     */
    protected array $columnSqls = [];

    /**
     * Maps column name → SQL-quoted identifier for O(1) lookup.
     *
     * @var array<string, string>
     */
    protected array $columnIndex = [];

    /** @var int */
    protected int $columnCount = 0;

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

        $this->pdo = new PDO(
            'sqlite::memory:',
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]
        );

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

        $colCount    = count($firstRow);
        $internalNames = array_map(fn ($i) => "col{$i}", range(0, $colCount - 1));

        if ($this->header) {
            $this->columns = $firstRow;
        } else {
            $this->columns = $internalNames;
            rewind($handle);
        }

        $this->columnCount = $colCount;

        // Build SQL-quoted identifiers; escape any " in column names as ""
        $this->columnSqls = array_map(
            fn ($name) => '"' . str_replace('"', '""', $name) . '"',
            $this->columns
        );

        // name → SQL id for O(1) lookup in _getColumn()
        $this->columnIndex = array_combine($this->columns, $this->columnSqls);

        // Ensure temp tables (used by GROUP BY / DISTINCT) stay in memory
        $this->pdo->exec('PRAGMA temp_store = MEMORY');
        $this->pdo->exec('PRAGMA synchronous = OFF');

        $this->pdo->exec(sprintf(
            'CREATE TABLE "data" (%s)',
            implode(', ', array_map(fn ($id) => "{$id} TEXT NULL DEFAULT NULL", $this->columnSqls))
        ));

        // Use prepared statement for better performance
        $stmt = $this->pdo->prepare(sprintf(
            'INSERT INTO "data" VALUES (%s)',
            implode(', ', array_fill(0, $colCount, '?'))
        ));

        // Use transaction for faster inserts
        $this->pdo->beginTransaction();

        while (($row = fgetcsv($handle, null, $this->separator, $this->enclosure, $this->escape)) !== false) {
            try {
                if ($stmt->execute($row) === false) {
                    throw new RuntimeException("Failed to insert row: " . implode(', ', $row));
                }
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                fclose($handle);
                throw $e;
            }
        }

        $this->pdo->commit();

        fclose($handle);
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

    protected function _getColumn(int|string $column): string
    {
        if (is_int($column)) {
            if ($column < 0 || $column >= $this->columnCount) {
                throw new RuntimeException("Column {$column} is out of bounds");
            }

            return $this->columnSqls[$column];
        }

        if (!array_key_exists($column, $this->columnIndex)) {
            throw new RuntimeException("Column '{$column}' not found");
        }

        return $this->columnIndex[$column];
    }

    protected function _execute(string $query): PDOStatement
    {
        $result = $this->pdo->query($query);

        if ($result === false) {
            throw new RuntimeException("Failed to execute query: {$query}");
        }

        return $result;
    }

    public function getIterator(): Generator
    {
        $result    = $this->_execute($this->_getQuery());
        $fetchMode = $this->header ? PDO::FETCH_ASSOC : PDO::FETCH_NUM;

        while ($row = $result->fetch($fetchMode)) {
            yield $row;
        }
    }

    public function count(): int
    {
        return intval($this->_execute($this->_getQuery('COUNT(*)'))->fetchColumn());
    }

    public function max(int|string $column): mixed
    {
        $colId = $this->_getColumn($column);

        return $this->_execute($this->_getQuery(sprintf('MAX(CAST(%s AS REAL))', $colId)))->fetchColumn();
    }

    public function min(int|string $column): mixed
    {
        $colId = $this->_getColumn($column);

        return $this->_execute($this->_getQuery(sprintf('MIN(CAST(%s AS REAL))', $colId)))->fetchColumn();
    }

    public function sum(int|string $column): mixed
    {
        $colId = $this->_getColumn($column);

        return $this->_execute($this->_getQuery(sprintf('SUM(CAST(%s AS REAL))', $colId)))->fetchColumn();
    }

    public function avg(int|string $column): mixed
    {
        $colId = $this->_getColumn($column);

        return $this->_execute($this->_getQuery(sprintf('AVG(CAST(%s AS REAL))', $colId)))->fetchColumn();
    }

    /**
     * @return array<string>
     */
    public function distinct(int|string $column): array
    {
        $colId = $this->_getColumn($column);

        return $this->_execute($this->_getQuery(sprintf('DISTINCT %s', $colId)))->fetchAll(PDO::FETCH_COLUMN);
    }

    public function where(int|string $column, int|float|string $value, string $operator = '='): self
    {
        $validOperators = ['=', '<>', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'IS', 'IS NOT'];

        if (!in_array($operator, $validOperators)) {
            throw new RuntimeException("Invalid operator: {$operator}");
        }

        $colId = $this->_getColumn($column);

        // Columns are stored as TEXT; only cast to REAL for numeric comparisons
        if (is_string($value)) {
            $this->where[] = sprintf('%s %s %s', $colId, $operator, $this->pdo->quote($value));
        } else {
            $this->where[] = sprintf('CAST(%s AS REAL) %s %s', $colId, $operator, $this->pdo->quote((string) $value));
        }

        return $this;
    }

    public function group(int|string $column): self
    {
        $this->group[] = $this->_getColumn($column);

        return $this;
    }

    public function reset(): self
    {
        $this->where = [];
        $this->group = [];

        return $this;
    }
}
