<?php
/**
 * PGClient configuration helpers.
 *
 * HestiaCP-friendly layout:
 * - App files normally live in: /home/<user>/web/panel.portfoliosight.io/public_html
 * - Runtime config is stored outside public_html by default:
 *   /home/<user>/web/panel.portfoliosight.io/private/pgclient.json
 *
 * You can override paths/credentials with environment variables when desired.
 */

if (!defined('PGCLIENT_APP_ROOT')) {
    // Works whether the app is deployed as public_html/*.php or public_html/src/*.php.
    define('PGCLIENT_APP_ROOT', dirname(__DIR__));
}

function pgclient_is_hestia_public_html(string $path): bool {
    return (bool)preg_match('#/web/[^/]+/public_html(?:/|$)#', $path);
}

function pgclient_base_dir(): string {
    $override = getenv('PGCLIENT_BASE_DIR');
    if ($override) return rtrim($override, '/');

    $dir = __DIR__;

    // If deployed directly in Hestia public_html/includes, base is the domain folder.
    if (pgclient_is_hestia_public_html($dir)) {
        $parts = explode('/public_html', $dir, 2);
        return $parts[0];
    }

    // If deployed as project/src/includes, base is the project root.
    return dirname(__DIR__, 2);
}

function pgclient_config_dir(): string {
    $override = getenv('PGCLIENT_CONFIG_DIR');
    if ($override) return rtrim($override, '/');
    return pgclient_base_dir() . '/private';
}

function pgclient_config_file(): string {
    $override = getenv('PGCLIENT_CONFIG_FILE');
    if ($override) return $override;
    return pgclient_config_dir() . '/pgclient.json';
}

if (!defined('PGCLIENT_CONFIG_DIR')) {
    define('PGCLIENT_CONFIG_DIR', pgclient_config_dir());
}
if (!defined('PGCLIENT_CONFIG_FILE')) {
    define('PGCLIENT_CONFIG_FILE', pgclient_config_file());
}

function pgclient_config_exists(): bool {
    return is_file(PGCLIENT_CONFIG_FILE) && is_readable(PGCLIENT_CONFIG_FILE);
}

function pgclient_load_config(): array {
    if (!pgclient_config_exists()) return [];
    $raw = file_get_contents(PGCLIENT_CONFIG_FILE);
    $cfg = json_decode($raw, true);
    return is_array($cfg) ? $cfg : [];
}

function pgclient_save_config(array $cfg): bool {
    if (!is_dir(PGCLIENT_CONFIG_DIR)) {
        if (!mkdir(PGCLIENT_CONFIG_DIR, 0750, true) && !is_dir(PGCLIENT_CONFIG_DIR)) {
            return false;
        }
    }

    $cfg['_meta'] = [
        'updated_at' => date('c'),
        'config_file' => PGCLIENT_CONFIG_FILE,
    ];

    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $tmp = PGCLIENT_CONFIG_FILE . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0640);

    return rename($tmp, PGCLIENT_CONFIG_FILE);
}

function pgclient_get_connections(): array {
    $cfg = pgclient_load_config();
    return is_array($cfg['connections'] ?? null) ? $cfg['connections'] : [];
}

function pgclient_get_connection(string $id): ?array {
    foreach (pgclient_get_connections() as $conn) {
        if (($conn['id'] ?? '') === $id) return $conn;
    }
    return null;
}

function pgclient_get_default_connection(): ?array {
    $conns = pgclient_get_connections();
    foreach ($conns as $conn) {
        if (!empty($conn['is_default'])) return $conn;
    }
    return $conns[0] ?? null;
}

function pgclient_get_active_connection(): ?array {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $id = $_SESSION['pgclient_conn_id'] ?? null;
        if ($id) {
            $conn = pgclient_get_connection($id);
            if ($conn) return $conn;
        }
    }
    return pgclient_get_default_connection();
}

function pgclient_bool_env(string $name, bool $default = false): bool {
    $val = getenv($name);
    if ($val === false || $val === '') return $default;
    return in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on', 'require'], true);
}

function pgclient_env_connection(): array {
    $conn = [];
    $db_url = getenv('DATABASE_URL') ?: getenv('POSTGRES_URL') ?: '';

    if ($db_url) {
        $p = parse_url($db_url);
        if ($p !== false) {
            $conn = [
                'host'     => $p['host'] ?? '127.0.0.1',
                'port'     => $p['port'] ?? 5432,
                'database' => ltrim($p['path'] ?? '/postgres', '/'),
                'username' => urldecode($p['user'] ?? 'postgres'),
                'password' => urldecode($p['pass'] ?? ''),
            ];
        }
    }

    $conn['host']     = getenv('PGHOST') ?: getenv('DB_HOST') ?: getenv('PGCLIENT_DB_HOST') ?: ($conn['host'] ?? '127.0.0.1');
    $conn['port']     = (int)(getenv('PGPORT') ?: getenv('DB_PORT') ?: getenv('PGCLIENT_DB_PORT') ?: ($conn['port'] ?? 5432));
    $conn['database'] = getenv('PGDATABASE') ?: getenv('POSTGRES_DB') ?: getenv('PGCLIENT_DB_NAME') ?: ($conn['database'] ?? 'admin_portfolio');
    $conn['username'] = getenv('PGUSER') ?: getenv('POSTGRES_USER') ?: getenv('PGCLIENT_DB_USER') ?: ($conn['username'] ?? 'admin_main');
    $conn['password'] = getenv('PGPASSWORD') ?: getenv('POSTGRES_PASSWORD') ?: getenv('PGCLIENT_DB_PASS') ?: ($conn['password'] ?? '');
    $conn['ssl']      = pgclient_bool_env('PGCLIENT_DB_SSL', pgclient_bool_env('PGSSLMODE', false));

    return $conn;
}

function pgclient_normalize_connection(array $conn): array {
    return [
        'id'         => $conn['id'] ?? pgclient_generate_id(),
        'name'       => trim((string)($conn['name'] ?? 'PortfolioSight PostgreSQL')) ?: 'PortfolioSight PostgreSQL',
        'host'       => trim((string)($conn['host'] ?? '127.0.0.1')) ?: '127.0.0.1',
        'port'       => (int)($conn['port'] ?? 5432) ?: 5432,
        'database'   => trim((string)($conn['database'] ?? 'admin_portfolio')) ?: 'admin_portfolio',
        'username'   => trim((string)($conn['username'] ?? 'admin_main')) ?: 'admin_main',
        'password'   => (string)($conn['password'] ?? ''),
        'ssl'        => !empty($conn['ssl']),
        'is_default' => array_key_exists('is_default', $conn) ? (bool)$conn['is_default'] : true,
        'created_at' => $conn['created_at'] ?? date('c'),
    ];
}

function pgclient_make_pdo(array $conn): PDO {
    $conn = pgclient_normalize_connection($conn);

    $dsnParts = [
        'host=' . $conn['host'],
        'port=' . $conn['port'],
        'dbname=' . $conn['database'],
    ];
    if ($conn['ssl']) {
        $dsnParts[] = 'sslmode=require';
    }

    return new PDO('pgsql:' . implode(';', $dsnParts), $conn['username'], $conn['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function pgclient_test_connection(array $conn): array {
    try {
        $pdo = pgclient_make_pdo($conn);
        $row = $pdo->query("SELECT current_database() AS db, current_user AS usr, version() AS version")->fetch();
        preg_match('/PostgreSQL ([\d.]+)/', $row['version'] ?? '', $m);
        return [
            'success' => true,
            'version' => 'PostgreSQL ' . ($m[1] ?? '?'),
            'database' => $row['db'] ?? null,
            'user' => $row['usr'] ?? null,
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function pgclient_generate_id(): string {
    return 'conn_' . bin2hex(random_bytes(6));
}

function pgclient_safe_redirect(string $target, string $fallback = 'index.php'): string {
    $target = trim($target);
    if ($target === '') return $fallback;
    if (preg_match('#^https?://#i', $target) || str_starts_with($target, '//')) return $fallback;
    if (str_contains($target, "\n") || str_contains($target, "\r")) return $fallback;
    return $target;
}
