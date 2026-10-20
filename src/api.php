<?php
$action = $_REQUEST['action'] ?? '';

// test_connection can run before auth/setup (used by setup wizard)
if ($action === 'test_connection') {
    header('Content-Type: application/json');
    require_once 'includes/config.php';
    // Once setup is done, only logged-in users may probe connections.
    if (pgclient_config_exists()) {
        require_once 'includes/auth.php';
    }
    $conn = [
        'host'     => trim($_POST['host']     ?? '127.0.0.1'),
        'port'     => (int)($_POST['port']     ?? 5432),
        'database' => trim($_POST['database']  ?? 'admin_portfolio'),
        'username' => trim($_POST['db_user'] ?? $_POST['username'] ?? 'postgres'),
        'password' => $_POST['db_pass'] ?? $_POST['password'] ?? '',
        'ssl'      => !empty($_POST['ssl']) && $_POST['ssl'] !== '0',
    ];
    echo json_encode(pgclient_test_connection($conn));
    exit;
}

if ($action !== 'export') {
    header('Content-Type: application/json');
}

require 'db.php';
require 'includes/auth.php';

$pdo = null;
try {
    $pdo = get_db();
    setup_internal_tables($pdo);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB: ' . $e->getMessage()]);
    exit;
}

function setup_internal_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS _sqlclient_query_history (
            id SERIAL PRIMARY KEY,
            sql_text TEXT NOT NULL,
            execution_time FLOAT,
            row_count INTEGER,
            status VARCHAR(10) DEFAULT 'success',
            error_message TEXT,
            executed_at TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS _sqlclient_saved_queries (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            sql_text TEXT NOT NULL,
            description TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        );
    ");
}

function log_query(PDO $pdo, string $sql, float $time, int $rows, string $status, ?string $error): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO _sqlclient_query_history (sql_text, execution_time, row_count, status, error_message)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$sql, $time, $rows, $status, $error]);
        $pdo->exec("
            DELETE FROM _sqlclient_query_history
            WHERE id NOT IN (SELECT id FROM _sqlclient_query_history ORDER BY id DESC LIMIT 200)
        ");
    } catch (Exception $e) {}
}

function split_sql_statements(string $sql): array {
    $statements = [];
    $current = '';
    $in_string = false;
    $string_char = '';
    $in_comment = false;
    $in_line_comment = false;
    $i = 0;
    $len = strlen($sql);

    while ($i < $len) {
        $char = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($in_line_comment) {
            if ($char === "\n") $in_line_comment = false;
            $current .= $char;
        } elseif ($in_comment) {
            if ($char === '*' && $next === '/') {
                $current .= '*/';
                $i += 2;
                $in_comment = false;
                continue;
            }
            $current .= $char;
        } elseif ($in_string) {
            if ($char === $string_char && $next !== $string_char) {
                $in_string = false;
            } elseif ($char === $string_char && $next === $string_char) {
                $current .= $char;
                $i++;
            }
            $current .= $char;
        } else {
            if ($char === '-' && $next === '-') {
                $in_line_comment = true;
                $current .= $char;
            } elseif ($char === '/' && $next === '*') {
                $in_comment = true;
                $current .= $char;
            } elseif ($char === '\'' || $char === '"') {
                $in_string = true;
                $string_char = $char;
                $current .= $char;
            } elseif ($char === ';') {
                $trimmed = trim($current);
                if (!empty($trimmed)) $statements[] = $trimmed;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $i++;
    }

    $trimmed = trim($current);
    if (!empty($trimmed)) $statements[] = $trimmed;

    return $statements;
}

switch ($action) {
    case 'execute':               handle_execute($pdo); break;
    case 'schema_tree':           handle_schema_tree($pdo); break;
    case 'table_structure':       handle_table_structure($pdo); break;
    case 'table_data':            handle_table_data($pdo); break;
    case 'save_query':            handle_save_query($pdo); break;
    case 'get_saved_queries':     handle_get_saved_queries($pdo); break;
    case 'delete_saved_query':    handle_delete_saved_query($pdo); break;
    case 'query_history':         handle_query_history($pdo); break;
    case 'clear_history':         handle_clear_history($pdo); break;
    case 'export':                handle_export($pdo); break;
    case 'db_stats':              handle_db_stats($pdo); break;
    case 'autocomplete':          handle_autocomplete($pdo); break;
    case 'update_cell':           handle_update_cell($pdo); break;
    case 'insert_row':            handle_insert_row($pdo); break;
    case 'delete_row_pk':         handle_delete_row_pk($pdo); break;
    case 'add_connection':        handle_add_connection(); break;
    case 'update_connection':     handle_update_connection(); break;
    case 'delete_connection':     handle_delete_connection(); break;
    case 'test_connection_by_id': handle_test_connection_by_id(); break;
    case 'set_active_connection': handle_set_active_connection(); break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
}

function handle_execute(PDO $pdo): void {
    $sql = trim($_POST['sql'] ?? '');
    if (empty($sql)) {
        echo json_encode(['success' => false, 'error' => 'No SQL provided']);
        return;
    }

    $statements = split_sql_statements($sql);
    if (empty($statements)) {
        echo json_encode(['success' => false, 'error' => 'No valid SQL statements found']);
        return;
    }

    $start = microtime(true);
    $all_results = [];

    foreach ($statements as $stmt) {
        try {
            $pdoStmt = $pdo->query($stmt);
            $col_count = $pdoStmt->columnCount();
            $columns = [];
            $rows = [];
            $has_data = false;

            if ($col_count > 0) {
                $has_data = true;
                for ($i = 0; $i < $col_count; $i++) {
                    $meta = $pdoStmt->getColumnMeta($i);
                    $columns[] = [
                        'name' => $meta['name'],
                        'type' => strtolower($meta['native_type'] ?? 'text')
                    ];
                }
                $rows = $pdoStmt->fetchAll(PDO::FETCH_NUM);
            }

            $verb = strtoupper(explode(' ', trim($stmt))[0]);
            $all_results[] = [
                'statement'    => strlen($stmt) > 200 ? substr($stmt, 0, 200) . '…' : $stmt,
                'columns'      => $columns,
                'rows'         => $rows,
                'rowCount'     => $has_data ? count($rows) : $pdoStmt->rowCount(),
                'hasData'      => $has_data,
                'type'         => $verb
            ];
        } catch (PDOException $e) {
            $time = round((microtime(true) - $start) * 1000, 2);
            log_query($pdo, $sql, $time, 0, 'error', $e->getMessage());
            echo json_encode([
                'success'          => false,
                'error'            => $e->getMessage(),
                'time'             => $time,
                'failedStatement'  => $stmt
            ]);
            return;
        }
    }

    $time = round((microtime(true) - $start) * 1000, 2);
    $total_rows = array_sum(array_column($all_results, 'rowCount'));
    log_query($pdo, $sql, $time, $total_rows, 'success', null);

    echo json_encode([
        'success'        => true,
        'results'        => $all_results,
        'time'           => $time,
        'statementCount' => count($all_results)
    ]);
}

function handle_schema_tree(PDO $pdo): void {
    try {
        $schemas_stmt = $pdo->query("
            SELECT schema_name
            FROM information_schema.schemata
            WHERE schema_name NOT IN ('pg_catalog','information_schema','pg_toast')
            ORDER BY CASE WHEN schema_name='public' THEN 0 ELSE 1 END, schema_name
        ");
        $schema_names = $schemas_stmt->fetchAll(PDO::FETCH_COLUMN);

        $schemas = [];
        foreach ($schema_names as $schema_name) {
            $q = $pdo->quote($schema_name);
            $tables_stmt = $pdo->query("
                SELECT t.table_name, t.table_type,
                       COALESCE(c.reltuples::bigint, 0) as row_estimate
                FROM information_schema.tables t
                LEFT JOIN pg_class c ON c.relname = t.table_name
                LEFT JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = t.table_schema
                WHERE t.table_schema = $q
                ORDER BY t.table_type, t.table_name
            ");

            $tables = [];
            $views = [];
            while ($row = $tables_stmt->fetch(PDO::FETCH_ASSOC)) {
                $item = ['name' => $row['table_name'], 'rowCount' => max(0, (int)$row['row_estimate'])];
                if ($row['table_type'] === 'VIEW') $views[] = $item;
                else $tables[] = $item;
            }

            $schemas[] = ['name' => $schema_name, 'tables' => $tables, 'views' => $views];
        }

        echo json_encode(['success' => true, 'schemas' => $schemas]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_table_structure(PDO $pdo): void {
    $table  = $_GET['table']  ?? $_POST['table']  ?? '';
    $schema = $_GET['schema'] ?? $_POST['schema'] ?? 'public';

    if (empty($table)) { echo json_encode(['success' => false, 'error' => 'No table specified']); return; }

    try {
        $col_stmt = $pdo->prepare("
            SELECT c.column_name, c.data_type, c.character_maximum_length,
                   c.numeric_precision, c.numeric_scale, c.is_nullable,
                   c.column_default, c.ordinal_position, c.udt_name,
                   CASE WHEN pk.column_name IS NOT NULL THEN true ELSE false END as is_primary
            FROM information_schema.columns c
            LEFT JOIN (
                SELECT ku.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage ku
                     ON tc.constraint_name = ku.constraint_name AND tc.table_schema = ku.table_schema
                WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_name = ? AND tc.table_schema = ?
            ) pk ON c.column_name = pk.column_name
            WHERE c.table_name = ? AND c.table_schema = ?
            ORDER BY c.ordinal_position
        ");
        $col_stmt->execute([$table, $schema, $table, $schema]);
        $columns = $col_stmt->fetchAll(PDO::FETCH_ASSOC);

        $idx_stmt = $pdo->prepare("
            SELECT i.relname as index_name, ix.indisunique as is_unique, ix.indisprimary as is_primary,
                   array_agg(a.attname ORDER BY k.i) as columns
            FROM pg_class t
            JOIN pg_index ix ON t.oid = ix.indrelid
            JOIN pg_class i ON i.oid = ix.indexrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            CROSS JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS k(attnum, i)
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum
            WHERE t.relname = ? AND n.nspname = ?
            GROUP BY i.relname, ix.indisunique, ix.indisprimary
            ORDER BY ix.indisprimary DESC, i.relname
        ");
        $idx_stmt->execute([$table, $schema]);
        $indexes = array_map(function ($idx) {
            $cols = explode(',', trim($idx['columns'], '{}'));
            return [
                'name'      => $idx['index_name'],
                'isUnique'  => $idx['is_unique'] === 't',
                'isPrimary' => $idx['is_primary'] === 't',
                'columns'   => $cols
            ];
        }, $idx_stmt->fetchAll(PDO::FETCH_ASSOC));

        $fk_stmt = $pdo->prepare("
            SELECT tc.constraint_name, kcu.column_name,
                   ccu.table_name as foreign_table, ccu.column_name as foreign_column,
                   rc.update_rule, rc.delete_rule
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                 ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
                 ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
            JOIN information_schema.referential_constraints rc
                 ON rc.constraint_name = tc.constraint_name AND rc.constraint_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = ? AND tc.table_schema = ?
            ORDER BY tc.constraint_name
        ");
        $fk_stmt->execute([$table, $schema]);
        $foreign_keys = $fk_stmt->fetchAll(PDO::FETCH_ASSOC);

        $count_stmt = $pdo->prepare("
            SELECT reltuples::bigint FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relname = ? AND n.nspname = ?
        ");
        $count_stmt->execute([$table, $schema]);
        $row_count = (int)max(0, (int)$count_stmt->fetchColumn());

        $ddl = generate_ddl($table, $schema, $columns);

        echo json_encode([
            'success'     => true,
            'table'       => $table,
            'schema'      => $schema,
            'columns'     => $columns,
            'indexes'     => $indexes,
            'foreignKeys' => $foreign_keys,
            'rowCount'    => $row_count,
            'ddl'         => $ddl
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function generate_ddl(string $table, string $schema, array $columns): string {
    $prefix = $schema !== 'public' ? "\"$schema\"." : '';
    $lines = [];
    $pk_cols = [];

    foreach ($columns as $col) {
        $type = strtoupper($col['udt_name'] ?? $col['data_type']);
        if ($col['character_maximum_length']) $type .= "({$col['character_maximum_length']})";
        elseif ($col['numeric_precision'] && $col['data_type'] === 'numeric')
            $type .= "({$col['numeric_precision']}," . ($col['numeric_scale'] ?? 0) . ')';

        $line = "    \"{$col['column_name']}\" $type";
        if ($col['is_nullable'] === 'NO') $line .= ' NOT NULL';
        if ($col['column_default'] !== null) $line .= ' DEFAULT ' . $col['column_default'];

        if ($col['is_primary'] === true || $col['is_primary'] === 't') {
            $pk_cols[] = '"' . $col['column_name'] . '"';
        }
        $lines[] = $line;
    }

    if (!empty($pk_cols)) $lines[] = '    PRIMARY KEY (' . implode(', ', $pk_cols) . ')';

    return "CREATE TABLE {$prefix}\"{$table}\" (\n" . implode(",\n", $lines) . "\n);";
}

function handle_table_data(PDO $pdo): void {
    $table    = $_GET['table']    ?? '';
    $schema   = $_GET['schema']   ?? 'public';
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = min(500, max(10, (int)($_GET['per_page'] ?? 50)));
    $sort_col = $_GET['sort_col'] ?? '';
    $sort_dir = strtoupper($_GET['sort_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
    $search   = trim($_GET['search'] ?? '');

    if (empty($table)) { echo json_encode(['success' => false, 'error' => 'No table specified']); return; }

    try {
        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_name=? AND table_schema=?");
        $check->execute([$table, $schema]);
        if (!$check->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'Table not found']); return; }

        $col_stmt = $pdo->prepare("
            SELECT column_name, data_type FROM information_schema.columns
            WHERE table_name=? AND table_schema=? ORDER BY ordinal_position
        ");
        $col_stmt->execute([$table, $schema]);
        $columns = $col_stmt->fetchAll(PDO::FETCH_ASSOC);

        $qt = "\"$schema\".\"$table\"";

        $where = '';
        if (!empty($search) && !empty($columns)) {
            $conditions = array_map(
                fn($c) => 'CAST("' . $c['column_name'] . '" AS TEXT) ILIKE ' . $pdo->quote('%' . $search . '%'),
                $columns
            );
            $where = 'WHERE ' . implode(' OR ', $conditions);
        }

        $total = (int)$pdo->query("SELECT COUNT(*) FROM $qt $where")->fetchColumn();

        $order = '';
        if (!empty($sort_col)) {
            $valid = array_column($columns, 'column_name');
            if (in_array($sort_col, $valid)) $order = "ORDER BY \"$sort_col\" $sort_dir";
        }
        if (empty($order)) {
            $has_id = in_array('id', array_column($columns, 'column_name'));
            $first = $columns[0]['column_name'] ?? null;
            $order = 'ORDER BY ' . ($has_id ? '"id"' : ($first ? "\"$first\"" : '1'));
        }

        $offset = ($page - 1) * $per_page;
        $rows = $pdo->query("SELECT * FROM $qt $where $order LIMIT $per_page OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'    => true,
            'columns'    => $columns,
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $per_page,
            'totalPages' => (int)ceil($total / $per_page)
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_save_query(PDO $pdo): void {
    $name = trim($_POST['name'] ?? '');
    $sql  = trim($_POST['sql']  ?? '');
    $desc = trim($_POST['description'] ?? '');
    $id   = (int)($_POST['id'] ?? 0);

    if (empty($name) || empty($sql)) { echo json_encode(['success' => false, 'error' => 'Name and SQL required']); return; }

    try {
        if ($id > 0) {
            $s = $pdo->prepare("UPDATE _sqlclient_saved_queries SET name=?,sql_text=?,description=?,updated_at=NOW() WHERE id=?");
            $s->execute([$name, $sql, $desc, $id]);
            echo json_encode(['success' => true, 'id' => $id]);
        } else {
            $s = $pdo->prepare("INSERT INTO _sqlclient_saved_queries (name,sql_text,description) VALUES (?,?,?) RETURNING id");
            $s->execute([$name, $sql, $desc]);
            echo json_encode(['success' => true, 'id' => $s->fetchColumn()]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_get_saved_queries(PDO $pdo): void {
    try {
        $s = $pdo->query("SELECT id,name,sql_text,description,created_at,updated_at FROM _sqlclient_saved_queries ORDER BY updated_at DESC");
        echo json_encode(['success' => true, 'queries' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_delete_saved_query(PDO $pdo): void {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid ID']); return; }
    try {
        $pdo->prepare("DELETE FROM _sqlclient_saved_queries WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_query_history(PDO $pdo): void {
    $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
    try {
        $s = $pdo->prepare("
            SELECT id,sql_text,execution_time,row_count,status,error_message,executed_at
            FROM _sqlclient_query_history
            WHERE sql_text NOT LIKE '%_sqlclient_%'
            ORDER BY id DESC LIMIT ?
        ");
        $s->execute([$limit]);
        echo json_encode(['success' => true, 'history' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_clear_history(PDO $pdo): void {
    try {
        $pdo->exec("TRUNCATE TABLE _sqlclient_query_history RESTART IDENTITY");
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_export(PDO $pdo): void {
    $sql    = trim($_POST['sql'] ?? '');
    $format = strtolower($_POST['format'] ?? 'csv');

    if (empty($sql)) { http_response_code(400); echo 'No SQL'; return; }

    try {
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cols = empty($rows) ? [] : array_keys($rows[0]);
        $fname = 'export_' . date('Ymd_His') . '.' . $format;

        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            echo json_encode($rows, JSON_PRETTY_PRINT);
        } else {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            $out = fopen('php://output', 'w');
            if (!empty($cols)) fputcsv($out, $cols);
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo 'Error: ' . $e->getMessage();
    }
}

function handle_db_stats(PDO $pdo): void {
    try {
        $version     = $pdo->query("SELECT version()")->fetchColumn();
        $ver_short   = preg_match('/PostgreSQL ([\d.]+)/', $version, $m) ? 'PostgreSQL ' . $m[1] : $version;

        [$dbname, $size] = $pdo->query(
            "SELECT current_database(), pg_size_pretty(pg_database_size(current_database()))"
        )->fetch(PDO::FETCH_NUM);

        $table_count = (int)$pdo->query("
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema='public' AND table_type='BASE TABLE'
        ")->fetchColumn();

        $view_count = (int)$pdo->query("
            SELECT COUNT(*) FROM information_schema.views WHERE table_schema='public'
        ")->fetchColumn();

        $large_tables = $pdo->query("
            SELECT relname as table_name,
                   pg_size_pretty(pg_total_relation_size(c.oid)) as total_size,
                   pg_total_relation_size(c.oid) as size_bytes,
                   reltuples::bigint as row_estimate
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname='public' AND c.relkind='r' AND c.relname NOT LIKE '_sqlclient_%'
            ORDER BY pg_total_relation_size(c.oid) DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        $active_conns = (int)$pdo->query(
            "SELECT count(*) FROM pg_stat_activity WHERE state='active'"
        )->fetchColumn();

        $start_time = $pdo->query("SELECT pg_postmaster_start_time()")->fetchColumn();

        $history_count = (int)$pdo->query("SELECT COUNT(*) FROM _sqlclient_query_history")->fetchColumn();

        echo json_encode([
            'success'           => true,
            'version'           => $ver_short,
            'versionFull'       => $version,
            'database'          => $dbname,
            'size'              => $size,
            'tableCount'        => $table_count,
            'viewCount'         => $view_count,
            'activeConnections' => $active_conns,
            'startTime'         => $start_time,
            'historyCount'      => $history_count,
            'largeTables'       => $large_tables
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_autocomplete(PDO $pdo): void {
    try {
        $tables  = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        $columns = $pdo->query("SELECT DISTINCT column_name FROM information_schema.columns WHERE table_schema='public' ORDER BY column_name")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'tables' => $tables, 'columns' => $columns]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'tables' => [], 'columns' => []]);
    }
}

function handle_update_cell(PDO $pdo): void {
    try {
        $table    = $_POST['table']    ?? '';
        $pk_json  = $_POST['pk_json']  ?? '';   // JSON map of ALL pk cols → values
        $col      = $_POST['col']      ?? '';
        $val      = $_POST['val']      ?? '';
        $set_null = ($_POST['set_null'] ?? '') === '1';

        if ($table === '' || $pk_json === '' || $col === '') {
            echo json_encode(['success' => false, 'error' => 'Missing required parameters']); return;
        }

        $pk_map = json_decode($pk_json, true);
        if (!is_array($pk_map) || empty($pk_map)) {
            echo json_encode(['success' => false, 'error' => 'Invalid pk_json']); return;
        }

        // Validate table exists in public schema, not an internal table
        $chk = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name=? AND table_name NOT LIKE '_sqlclient_%'");
        $chk->execute([$table]);
        if (!$chk->fetchColumn()) {
            echo json_encode(['success' => false, 'error' => 'Invalid table']); return;
        }

        // Validate all column names (target col + all PK cols) exist in the table
        $cols_stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=?");
        $cols_stmt->execute([$table]);
        $valid_cols = $cols_stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array($col, $valid_cols, true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid target column']); return;
        }
        foreach (array_keys($pk_map) as $pkc) {
            if (!in_array($pkc, $valid_cols, true)) {
                echo json_encode(['success' => false, 'error' => 'Invalid PK column: ' . htmlspecialchars($pkc)]); return;
            }
        }

        // Build WHERE clause spanning all PK columns (handles composite PKs)
        $where_parts  = [];
        $bind_vals    = [];
        $new_val = $set_null ? null : $val;
        $bind_vals[] = $new_val;                  // first bind: the new value for SET

        foreach ($pk_map as $pkc => $pkv) {
            if ($pkv === null) {
                $where_parts[] = "\"$pkc\" IS NULL";
            } else {
                $where_parts[] = "\"$pkc\" = ?";
                $bind_vals[]   = $pkv;
            }
        }

        $where = implode(' AND ', $where_parts);
        $stmt  = $pdo->prepare("UPDATE \"$table\" SET \"$col\" = ? WHERE $where");
        $stmt->execute($bind_vals);

        echo json_encode(['success' => true, 'rows_affected' => $stmt->rowCount()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_insert_row(PDO $pdo): void {
    try {
        $table  = $_POST['table']  ?? '';
        $schema = $_POST['schema'] ?? 'public';

        if (empty($table)) { echo json_encode(['success' => false, 'error' => 'No table specified']); return; }

        // Validate table
        $chk = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name=?");
        $chk->execute([$schema, $table]);
        if (!$chk->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'Table not found']); return; }

        // Get columns
        $col_stmt = $pdo->prepare("
            SELECT column_name, data_type, column_default, is_nullable
            FROM information_schema.columns
            WHERE table_schema=? AND table_name=?
            ORDER BY ordinal_position
        ");
        $col_stmt->execute([$schema, $table]);
        $all_cols = $col_stmt->fetchAll(PDO::FETCH_ASSOC);
        $valid_cols = array_column($all_cols, 'column_name');

        $cols_to_insert = [];
        $vals_to_insert = [];

        $row_data = json_decode($_POST['row_data'] ?? '{}', true);
        if (!is_array($row_data)) { echo json_encode(['success' => false, 'error' => 'Invalid row_data']); return; }

        foreach ($row_data as $col => $val) {
            if (!in_array($col, $valid_cols, true)) continue;
            $cols_to_insert[] = "\"$col\"";
            $vals_to_insert[] = $val === '__NULL__' ? null : $val;
        }

        if (empty($cols_to_insert)) { echo json_encode(['success' => false, 'error' => 'No columns provided']); return; }

        $placeholders = implode(',', array_fill(0, count($vals_to_insert), '?'));
        $sql = "INSERT INTO \"$schema\".\"$table\" (" . implode(',', $cols_to_insert) . ") VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals_to_insert);

        echo json_encode(['success' => true, 'rows_affected' => $stmt->rowCount()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_delete_row_pk(PDO $pdo): void {
    try {
        $table   = $_POST['table']   ?? '';
        $schema  = $_POST['schema']  ?? 'public';
        $pk_json = $_POST['pk_json'] ?? '';

        if (empty($table) || empty($pk_json)) { echo json_encode(['success' => false, 'error' => 'Missing parameters']); return; }

        $pk_map = json_decode($pk_json, true);
        if (!is_array($pk_map) || empty($pk_map)) { echo json_encode(['success' => false, 'error' => 'Invalid pk_json']); return; }

        // Validate table
        $chk = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name=? AND table_name NOT LIKE '_sqlclient_%'");
        $chk->execute([$schema, $table]);
        if (!$chk->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'Invalid table']); return; }

        // Validate columns
        $cols_stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=?");
        $cols_stmt->execute([$schema, $table]);
        $valid_cols = $cols_stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_keys($pk_map) as $pkc) {
            if (!in_array($pkc, $valid_cols, true)) { echo json_encode(['success' => false, 'error' => 'Invalid PK column: ' . htmlspecialchars($pkc)]); return; }
        }

        $where_parts = [];
        $bind_vals   = [];
        foreach ($pk_map as $pkc => $pkv) {
            if ($pkv === null) { $where_parts[] = "\"$pkc\" IS NULL"; }
            else { $where_parts[] = "\"$pkc\" = ?"; $bind_vals[] = $pkv; }
        }

        $stmt = $pdo->prepare("DELETE FROM \"$schema\".\"$table\" WHERE " . implode(' AND ', $where_parts));
        $stmt->execute($bind_vals);
        echo json_encode(['success' => true, 'rows_affected' => $stmt->rowCount()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_add_connection(): void {
    try {
        $cfg = pgclient_load_config();
        $conn = [
            'id'         => pgclient_generate_id(),
            'name'       => trim($_POST['name'] ?? 'New Connection'),
            'host'       => trim($_POST['host'] ?? 'localhost'),
            'port'       => (int)($_POST['port'] ?? 5432),
            'database'   => trim($_POST['database'] ?? 'postgres'),
            'username'   => trim($_POST['username'] ?? 'postgres'),
            'password'   => $_POST['password'] ?? '',
            'ssl'        => !empty($_POST['ssl']) && $_POST['ssl'] !== '0',
            'is_default' => !empty($_POST['is_default']) && $_POST['is_default'] !== '0',
            'created_at' => date('c'),
        ];
        if ($conn['is_default']) {
            foreach ($cfg['connections'] as &$c) { $c['is_default'] = false; }
            unset($c);
        }
        $cfg['connections'][] = $conn;
        if (pgclient_save_config($cfg)) {
            echo json_encode(['success' => true, 'id' => $conn['id']]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save config']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_update_connection(): void {
    try {
        $id = trim($_POST['id'] ?? '');
        if (empty($id)) { echo json_encode(['success' => false, 'error' => 'No ID provided']); return; }

        $cfg = pgclient_load_config();
        $found = false;
        $is_default = !empty($_POST['is_default']) && $_POST['is_default'] !== '0';

        if ($is_default) {
            foreach ($cfg['connections'] as &$c) { $c['is_default'] = false; }
            unset($c);
        }

        foreach ($cfg['connections'] as &$conn) {
            if ($conn['id'] !== $id) continue;
            $found = true;
            $conn['name']       = trim($_POST['name']     ?? $conn['name']);
            $conn['host']       = trim($_POST['host']     ?? $conn['host']);
            $conn['port']       = (int)($_POST['port']    ?? $conn['port']);
            $conn['database']   = trim($_POST['database'] ?? $conn['database']);
            $conn['username']   = trim($_POST['username'] ?? $conn['username']);
            if (!empty($_POST['password'])) $conn['password'] = $_POST['password'];
            $conn['ssl']        = !empty($_POST['ssl']) && $_POST['ssl'] !== '0';
            $conn['is_default'] = $is_default;
            $conn['updated_at'] = date('c');
        }
        unset($conn);

        if (!$found) { echo json_encode(['success' => false, 'error' => 'Connection not found']); return; }
        if (pgclient_save_config($cfg)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save config']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_delete_connection(): void {
    try {
        $id = trim($_POST['id'] ?? '');
        if (empty($id)) { echo json_encode(['success' => false, 'error' => 'No ID provided']); return; }

        $cfg = pgclient_load_config();
        $cfg['connections'] = array_values(array_filter($cfg['connections'], fn($c) => $c['id'] !== $id));

        if (isset($_SESSION['pgclient_conn_id']) && $_SESSION['pgclient_conn_id'] === $id) {
            unset($_SESSION['pgclient_conn_id']);
        }

        if (pgclient_save_config($cfg)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save config']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_test_connection_by_id(): void {
    try {
        $id = trim($_POST['id'] ?? '');
        $conn = pgclient_get_connection($id);
        if (!$conn) { echo json_encode(['success' => false, 'error' => 'Connection not found']); return; }
        echo json_encode(pgclient_test_connection($conn));
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function handle_set_active_connection(): void {
    try {
        $id = trim($_POST['id'] ?? '');
        $conn = pgclient_get_connection($id);
        if (!$conn) { echo json_encode(['success' => false, 'error' => 'Connection not found']); return; }
        $_SESSION['pgclient_conn_id'] = $id;
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
