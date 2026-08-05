# CSVql

[![ci](https://github.com/wex/csvql/actions/workflows/ci.yaml/badge.svg)](https://github.com/wex/csvql/actions/workflows/ci.yaml)
[![codecov](https://codecov.io/gh/wex/csvql/graph/badge.svg)](https://codecov.io/gh/wex/csvql)

A SQLite-powered CSV reader for PHP that lets you query CSV files using a fluent, SQL-like interface.

## Features

- **Fast queries**: Imports CSV into in-memory SQLite for efficient querying
- **Fluent interface**: Chain methods like `where()`, `group()` for readable code
- **Aggregation functions**: `max()`, `min()`, `sum()`, `avg()`, `distinct()`, `count()`
- **Flexible column access**: Reference columns by name or index
- **CSV customization**: Configure separators, enclosures, escape characters
- **Standard interfaces**: Implements `IteratorAggregate` and `Countable`
- **Header support**: Optional header row for named column access

## Requirements

- PHP >= 8.5
- PDO extension
- SQLite3 extension

## Installation

```bash
composer require phi/csvql
```

## Usage

### Basic Example

```php
use Phi\CSVql\CSVql;

$csv = new CSVql('data.csv');

// Count all rows
echo count($csv);

// Iterate over all rows
foreach ($csv as $row) {
    print_r($row);
}
```

### Filtering with WHERE

```php
$csv = new CSVql('customers.csv');

// Filter by column name
$csv->where('Country', 'Finland');

// Filter by column index
$csv->where(2, 'Finland');

// Use different operators
$csv->where('Age', 25, '>');
$csv->where('Age', 30, '<=');

// Chain multiple conditions
$csv->where('Country', 'Finland')
    ->where('Age', 25, '>');

// Count filtered results
echo count($csv);
```

### Aggregation Functions

```php
$csv = new CSVql('sales.csv');

// Maximum value
$max = $csv->max('Amount');

// Minimum value
$min = $csv->min('Amount');

// Sum
$total = $csv->sum('Amount');

// Average
$avg = $csv->avg('Amount');

// Distinct values
$countries = $csv->distinct('Country');
```

### Grouping

```php
$csv = new CSVql('sales.csv');

$csv->group('Country');

foreach ($csv as $row) {
    print_r($row);
}
```

### Resetting Filters

```php
$csv = new CSVql('data.csv');

$csv->where('Status', 'active');
echo count($csv); // Count with filter

$csv->reset();
echo count($csv); // Count all rows
```

### Custom CSV Configuration

```php
$csv = new CSVql(
    source: 'data.csv',
    separator: ';',        // Default: ','
    enclosure: "'",         // Default: '"'
    escape: '\\',          // Default: '\\'
    header: false          // Default: true
);
```

### Without Headers

When `header` is set to `false`, columns are accessed by index:

```php
$csv = new CSVql('data.csv', header: false);

foreach ($csv as $row) {
    echo $row[0]; // First column
    echo $row[1]; // Second column
}
```

## Quality Assurance

This project includes several tools to ensure code quality:

### Running Tests

Tests are written using [Pest](https://pestphp.com/). To run the test suite:

```bash
composer test
```

Or directly with Pest:

```bash
./vendor/bin/pest
```

### Code Style

[Laravel Pint](https://github.com/laravel/pint) is used for code formatting and linting. To check and fix code style issues:

```bash
composer lint
```

Or directly with Pint:

```bash
./vendor/bin/pint
```

### Static Analysis

[PHPStan](https://phpstan.org/) is used for static analysis at level 6. To run static analysis:

```bash
composer analyze
```

Or directly with PHPStan:

```bash
./vendor/bin/phpstan analyze src
```

### Running All Checks

To run all quality checks (static analysis, linting, and tests) in sequence:

```bash
composer ci
```

This is the command used in the CI pipeline and should be run before committing changes.

## API Reference

### Constructor

```php
new CSVql(
    string $source,      // Path to CSV file
    string $separator = ',',
    string $enclosure = '"',
    string $escape = '\\',
    bool $header = true
)
```

### Methods

- `where(int|string $column, int|float|string $value, string $operator = '='): self` - Add a WHERE condition
- `group(int|string $column): self` - Add a GROUP BY clause
- `reset(): self` - Clear all WHERE and GROUP conditions
- `count(): int` - Count matching rows
- `max(int|string $column): mixed` - Get maximum value
- `min(int|string $column): mixed` - Get minimum value
- `sum(int|string $column): mixed` - Get sum of values
- `avg(int|string $column): mixed` - Get average value
- `distinct(int|string $column): array` - Get distinct values

## License

MIT License - see [LICENSE](LICENSE) file for details.

## Author

Niko Hujanen
