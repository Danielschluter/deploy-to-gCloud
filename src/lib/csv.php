<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** A problem with the uploaded file or target table, safe to show to the user. */
final class ImportError extends RuntimeException
{
}

const PG_MAX_IDENT = 63;

/**
 * Table names are lower-case letters, digits and underscores. (Unquoted names
 * were always folded to lower case by Postgres, so this matches tables
 * created by earlier versions.)
 */
function sanitize_table_name(string $raw): string
{
    $name = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', trim($raw)) ?? '');
    if ($name === '') {
        $name = 'imported_data';
    }
    if (ctype_digit($name[0])) {
        $name = 't_' . $name;
    }
    return substr($name, 0, PG_MAX_IDENT);
}

/**
 * Column names from the header row: invalid characters become "_", blanks
 * get a placeholder name, duplicates get a numeric suffix, and a CSV column
 * called "id" becomes "csv_id" (each table gets its own "id" primary key).
 *
 * @param list<?string> $headers
 * @return list<string>
 */
function clean_headers(array $headers): array
{
    $seen = [];
    $out = [];
    foreach ($headers as $i => $header) {
        $h = (string) $header;
        if ($i === 0) {
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h; // UTF-8 byte-order mark
        }
        $h = preg_replace('/[^A-Za-z0-9_]/', '_', trim($h)) ?? '';
        if ($h === '' || trim($h, '_') === '') {
            $h = 'column_' . ($i + 1);
        }
        if (strtolower($h) === 'id') {
            $h = 'csv_id';
        }
        $h = substr($h, 0, PG_MAX_IDENT - 4);

        $base = $h;
        $n = 2;
        while (isset($seen[strtolower($h)])) {
            $h = $base . '_' . $n++;
        }
        $seen[strtolower($h)] = true;
        $out[] = $h;
    }
    return $out;
}

/**
 * Pick a Postgres type that holds every value in the column without loss:
 * BIGINT for whole numbers, NUMERIC (exact, any precision) for other
 * numbers, TEXT otherwise. Numbers written with leading zeros (ZIP codes,
 * CUSIPs, account numbers) stay TEXT so the zeros survive.
 *
 * @param iterable<?string> $values
 */
function infer_column_type(iterable $values): string
{
    $any = false;
    $integer = true;
    foreach ($values as $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $any = true;
        if ($integer && !preg_match('/^-?(0|[1-9][0-9]{0,17})$/', $v)) {
            $integer = false;
        }
        if (!$integer && !preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][-+]?[0-9]+)?$/', $v)) {
            return 'TEXT';
        }
    }
    if (!$any) {
        return 'TEXT';
    }
    return $integer ? 'BIGINT' : 'NUMERIC';
}

/**
 * Read a CSV file: header row plus data rows, values trimmed, blanks as null.
 *
 * @return array{headers: list<string>, rows: list<list<?string>>}
 */
function read_csv(string $path): array
{
    $fh = @fopen($path, 'r');
    if ($fh === false) {
        throw new ImportError('Could not read the uploaded file');
    }
    try {
        $header = fgetcsv($fh, 0, ',', '"', '');
        if ($header === false || $header === [null]) {
            throw new ImportError('The CSV file is empty');
        }
        $headers = clean_headers($header);
        $width = count($headers);

        $rows = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($r === [null]) {
                continue; // blank line
            }
            $row = [];
            for ($i = 0; $i < $width; $i++) {
                $v = isset($r[$i]) ? trim((string) $r[$i]) : '';
                $row[] = $v === '' ? null : $v;
            }
            $rows[] = $row;
        }
    } finally {
        fclose($fh);
    }

    if (!$rows) {
        throw new ImportError('The CSV file has no data rows');
    }
    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * Import a CSV into a table, creating the table if needed. All or nothing:
 * any failure rolls back, including the CREATE TABLE.
 *
 * @return array{table: string, created: bool, rows: int, columns: array<string, string>}
 */
function import_csv(PDO $pdo, string $path, string $table): array
{
    $csv = read_csv($path);
    $headers = $csv['headers'];

    $types = [];
    foreach ($headers as $i => $h) {
        $types[$h] = infer_column_type(array_column($csv['rows'], $i));
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT column_name, data_type FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position"
        );
        $stmt->execute([$table]);
        $existing = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $created = !$existing;

        if ($created) {
            $defs = ['id BIGSERIAL PRIMARY KEY'];
            foreach ($types as $col => $type) {
                $defs[] = quote_ident($col) . ' ' . $type;
            }
            $pdo->exec('CREATE TABLE ' . quote_ident($table) . ' (' . implode(', ', $defs) . ')');
        } else {
            $missing = array_diff($headers, array_keys($existing));
            if ($missing) {
                throw new ImportError(sprintf(
                    "Table '%s' already exists and has no column named %s. Pick a new table name, or match its columns: %s",
                    $table,
                    implode(', ', array_map(fn ($c) => "'$c'", $missing)),
                    implode(', ', array_keys($existing))
                ));
            }
            $types = array_intersect_key($existing, array_flip($headers));
        }

        $insert = $pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            quote_ident($table),
            implode(', ', array_map('quote_ident', $headers)),
            implode(', ', array_fill(0, count($headers), '?'))
        ));
        foreach ($csv['rows'] as $n => $row) {
            try {
                $insert->execute($row);
            } catch (PDOException $e) {
                // Line numbers count the header as line 1.
                throw new ImportError(sprintf('Row on line %d could not be inserted: %s', $n + 2, pg_error_summary($e)), 0, $e);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['table' => $table, 'created' => $created, 'rows' => count($csv['rows']), 'columns' => $types];
}
