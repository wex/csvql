<?php

use Phi\CSVql\CSVql;

beforeEach(function () {
    $this->csvFile = tempnam(sys_get_temp_dir(), 'csvql_');

    $handle = fopen($this->csvFile, 'w');
    fputcsv(
        stream: $handle,
        fields: ['id', 'name', 'value'],
        separator: ',',
        enclosure: '"',
        escape: '\\',
        eol: "\n"
    );

    for ($i = 1; $i <= 100; $i++) {
        fputcsv(
            stream: $handle,
            fields: [$i, "name_{$i}", $i * 10],
            separator: ',',
            enclosure: '"',
            escape: '\\',
            eol: "\n"
        );
    }

    fclose($handle);

    $this->csv = new CSVql($this->csvFile, ',', '"', '\\', true);
});

afterEach(function () {
    unlink($this->csvFile);
});

// --- Constructor ---

test('it can initialize CSVql instance', function () {
    expect($this->csv)->toBeInstanceOf(CSVql::class);
});

test('constructor throws when file does not exist', function () {
    expect(fn () => new CSVql('/nonexistent/path/file.csv'))->toThrow(RuntimeException::class);
});

test('constructor throws when file is not readable', function () {
    $file = tempnam(sys_get_temp_dir(), 'csvql_unreadable_');
    chmod($file, 0000);

    try {
        expect(fn () => new CSVql($file))->toThrow(RuntimeException::class);
    } finally {
        chmod($file, 0644);
        unlink($file);
    }
});

// --- _import ---

test('import throws for empty file', function () {
    $file = tempnam(sys_get_temp_dir(), 'csvql_empty_');

    try {
        expect(fn () => new CSVql($file))->toThrow(RuntimeException::class);
    } finally {
        unlink($file);
    }
});

// --- header = false ---

test('header=false: iterator yields numeric arrays', function () {
    $file = tempnam(sys_get_temp_dir(), 'csvql_noheader_');
    $handle = fopen($file, 'w');
    fputcsv(stream: $handle, fields: ['id', 'name', 'value'], separator: ',', enclosure: '"', escape: '\\', eol: "\n");
    for ($i = 1; $i <= 100; $i++) {
        fputcsv(stream: $handle, fields: [$i, "name_{$i}", $i * 10], separator: ',', enclosure: '"', escape: '\\', eol: "\n");
    }
    fclose($handle);

    $csv = new CSVql($file, header: false);
    unlink($file);

    $rows = iterator_to_array($csv);
    $first = reset($rows);
    expect(array_keys($first))->toBe([0, 1, 2]);
});

test('header=false: count includes all rows including header row', function () {
    $file = tempnam(sys_get_temp_dir(), 'csvql_noheader2_');
    $handle = fopen($file, 'w');
    fputcsv(stream: $handle, fields: ['id', 'name', 'value'], separator: ',', enclosure: '"', escape: '\\', eol: "\n");
    for ($i = 1; $i <= 100; $i++) {
        fputcsv(stream: $handle, fields: [$i, "name_{$i}", $i * 10], separator: ',', enclosure: '"', escape: '\\', eol: "\n");
    }
    fclose($handle);

    $csv = new CSVql($file, header: false);
    unlink($file);

    expect($csv->count())->toBe(101);
});

// --- count / iterate (existing, kept) ---

test('it can count rows', function () {
    expect($this->csv->count())->toBe(100);
});

test('it can iterate over rows', function () {
    $rows = [];
    foreach ($this->csv as $row) {
        $rows[] = $row;
    }
    expect($rows)->toHaveCount(100);
});

// --- Aggregate methods ---

test('max() by column name', function () {
    expect($this->csv->max('value'))->toBe(1000.0);
});

test('min() by column name', function () {
    expect($this->csv->min('value'))->toBe(10.0);
});

test('sum() by column name', function () {
    expect($this->csv->sum('value'))->toBe(50500.0);
});

test('avg() by column name', function () {
    expect($this->csv->avg('value'))->toBe(505.0);
});

test('max() by integer column index', function () {
    expect($this->csv->max(2))->toBe(1000.0);
});

// --- distinct ---

test('distinct() returns unique values', function () {
    $values = $this->csv->distinct('name');
    expect($values)->toHaveCount(100);
});

// --- where ---

test('it can filter rows', function () {
    $rows = $this->csv->where('id', 1);
    expect($rows)->toHaveCount(1);
    $output = iterator_to_array($rows);
    expect($output[0]['id'])->toBe("1");
});

test('where() with string value uses TEXT cast', function () {
    $output = iterator_to_array($this->csv->where('name', 'name_1'));
    expect($output)->toHaveCount(1);
    expect($output[0]['name'])->toBe('name_1');
});

test('where() with custom operator', function () {
    expect($this->csv->where('id', 50, '>'))->toHaveCount(50);
});

test('where() with integer column index', function () {
    $output = iterator_to_array($this->csv->where(0, 1));
    expect($output)->toHaveCount(1);
    expect($output[0]['id'])->toBe('1');
});

// --- _getColumn exceptions ---

test('_getColumn throws for unknown column name', function () {
    expect(fn () => $this->csv->max('nonexistent'))->toThrow(RuntimeException::class);
});

test('_getColumn throws for negative integer index', function () {
    expect(fn () => $this->csv->max(-1))->toThrow(RuntimeException::class);
});

test('_getColumn throws for out-of-bounds integer index', function () {
    expect(fn () => $this->csv->max(999))->toThrow(RuntimeException::class);
});

// --- group ---

test('group() produces one row per unique value', function () {
    $rows = iterator_to_array($this->csv->group('name'));
    expect($rows)->toHaveCount(100);
});

// --- reset ---

test('reset() clears where and group state', function () {
    $this->csv->where('id', 1)->group('name');
    $this->csv->reset();
    expect($this->csv->count())->toBe(100);
});
