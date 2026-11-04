<?php

/**
 * Unit checks for the CSV import helpers (no database needed).
 *
 *   php tests/csv_test.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/lib/csv.php';

$failures = 0;
function check(string $label, mixed $expected, mixed $actual): void
{
    global $failures;
    if ($expected === $actual) {
        echo "ok   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     expected " . var_export($expected, true) . "\n     got      " . var_export($actual, true) . "\n";
}

// Table names
check('lower-cases and strips symbols', 'mypeople', sanitize_table_name(' My People! '));
check('blank becomes imported_data', 'imported_data', sanitize_table_name('!!!'));
check('leading digit gets a prefix', 't_2024data', sanitize_table_name('2024data'));
check('63-character limit', 63, strlen(sanitize_table_name(str_repeat('a', 100))));

// Column names
check(
    'headers: BOM, symbols, blanks, id, duplicates',
    ['csv_id', 'First_Name', 'column_3', 'Name', 'name_2', 'column_6'],
    clean_headers(["\xEF\xBB\xBFid", 'First Name', '', 'Name', 'name', '%%'])
);

// Types
check('whole numbers -> BIGINT', 'BIGINT', infer_column_type(['1', '-20', '0', null, '']));
check('decimals stay exact -> NUMERIC', 'NUMERIC', infer_column_type(['1', '0.12345', '1e3']));
check('leading zeros stay TEXT (ZIP codes)', 'TEXT', infer_column_type(['94105', '02134']));
check('mixed -> TEXT', 'TEXT', infer_column_type(['1', 'two']));
check('too big for BIGINT -> NUMERIC', 'NUMERIC', infer_column_type(['12345678901234567890']));
check('inference looks past the first 10 rows', 'TEXT', infer_column_type([...array_fill(0, 50, '1'), 'x']));
check('all blank -> TEXT', 'TEXT', infer_column_type([null, '']));

// Reading
$csv = read_csv(__DIR__ . '/fixtures/sample.csv');
check('reads header', ['csv_id', 'name', 'zip', 'price', 'note'], $csv['headers']);
check('reads rows, keeps quotes/commas, blanks are null', [
    ['1', 'Alice', '02134', '0.12345', 'multi, line "quoted"'],
    ['2', 'Bob', '94105', '12.5', null],
    ['3', 'Carol', '00501', '1000', 'last'],
], $csv['rows']);

try {
    read_csv(__DIR__ . '/fixtures/header_only.csv');
    check('header-only file is rejected', true, false);
} catch (ImportError $e) {
    check('header-only file is rejected', 'The CSV file has no data rows', $e->getMessage());
}

echo $failures ? "\n$failures failed\n" : "\nall passed\n";
exit($failures ? 1 : 0);
