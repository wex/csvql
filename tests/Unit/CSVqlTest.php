<?php

use Phi\CSVql\CSVql;

/**
 * Stream wrapper that satisfies file_exists() and is_readable() via url_stat()
 * but always returns false from stream_open(), simulating an fopen() race failure.
 */
final class FailingOpenStreamWrapper
{
    /** @var resource|null Required by the PHP stream wrapper contract */
    public $context;

    public function url_stat(string $path, int $flags): array
    {
        return [
            'dev' => 0, 'ino' => 0, 'mode' => 0100777,
            'nlink' => 1, 'uid' => 0, 'gid' => 0,
            'rdev' => 0, 'size' => 0, 'atime' => 0,
            'mtime' => 0, 'ctime' => 0, 'blksize' => -1, 'blocks' => -1,
        ];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }
}

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

test('import throws when fopen fails after existence check', function () {
    stream_wrapper_register('failopen', FailingOpenStreamWrapper::class);

    try {
        expect(fn () => new CSVql('failopen://fake.csv'))
            ->toThrow(RuntimeException::class, 'Failed to open file');
    } finally {
        stream_wrapper_unregister('failopen');
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

test('where() throws for invalid operator', function () {
    expect(fn () => $this->csv->where('id', 1, 'INVALID'))->toThrow(RuntimeException::class);
});

test('where() returns empty iterator when no rows match', function () {
    $output = iterator_to_array($this->csv->where('id', 9999));
    expect($output)->toBeEmpty();
});

test('where() returns count of 0 when no rows match', function () {
    expect($this->csv->where('id', 9999)->count())->toBe(0);
});

test('where() chained twice on same column with same value still matches', function () {
    expect($this->csv->where('id', 1)->where('id', 1)->count())->toBe(1);
});

test('where() chained with conflicting values on same column returns empty result', function () {
    expect($this->csv->where('id', 1)->where('id', 2)->count())->toBe(0);
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

// --- edge cases ---

test('rows with fewer columns than header are imported with null for missing columns', function () {
    $file = tempnam(sys_get_temp_dir(), 'csvql_mismatch_');
    // Short row must come first: an unbound PDO positional parameter defaults to NULL,
    // but a reused prepared statement retains the previous execution's binding.
    file_put_contents($file, "id,name,value\n1,Bob\n2,Alice,100\n");

    try {
        $csv = new CSVql($file);
        $rows = iterator_to_array($csv);

        expect($rows)->toHaveCount(2);
        expect($rows[0]['value'])->toBeNull();
        expect($rows[1]['value'])->toBe('100');
    } finally {
        unlink($file);
    }
});

test('handles unicode and special character values', function () {
    $file = tempnam(sys_get_temp_dir(), 'csvql_unicode_');
    file_put_contents($file, "id,name\n1,こんにちは\n2,Ångström\n3,café\n4,\u{1F600}\n", FILE_APPEND);

    try {
        $csv = new CSVql($file);
        $rows = iterator_to_array($csv);

        expect($rows)->toHaveCount(4);
        expect($rows[0]['name'])->toBe('こんにちは');
        expect($rows[1]['name'])->toBe('Ångström');
        expect($rows[2]['name'])->toBe('café');
        expect($rows[3]['name'])->toBe("\u{1F600}");
    } finally {
        unlink($file);
    }
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
