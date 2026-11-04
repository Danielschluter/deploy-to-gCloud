<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

/**
 * Shared PDO connection to PostgreSQL.
 *
 * Settings (environment variables):
 *   DB_SOCKET    Unix socket directory, e.g. /cloudsql/PROJECT:REGION:INSTANCE on Cloud Run.
 *                When set, DB_HOST and DB_PORT are ignored.
 *   DB_HOST      Hostname (default "db", the docker-compose service).
 *   DB_PORT      Port (default 5432).
 *   DB_NAME      Database (falls back to POSTGRES_DB, then "appdb").
 *   DB_USER      User (falls back to POSTGRES_USER).
 *   DB_PASSWORD  Password (falls back to POSTGRES_PASSWORD). Required.
 *   DB_SSLMODE   Optional libpq sslmode, e.g. "require" for a public-IP database.
 */
function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $name = env('DB_NAME') ?? env('POSTGRES_DB') ?? 'appdb';
    $user = env('DB_USER') ?? env('POSTGRES_USER');
    $pass = env('DB_PASSWORD') ?? env('POSTGRES_PASSWORD');
    $socket = env('DB_SOCKET');

    $dsn = $socket !== null
        ? "pgsql:host={$socket};dbname={$name}"
        : sprintf('pgsql:host=%s;port=%s;dbname=%s', env('DB_HOST') ?? 'db', env('DB_PORT') ?? '5432', $name);
    if (($sslmode = env('DB_SSLMODE')) !== null) {
        $dsn .= ";sslmode={$sslmode}";
    }

    try {
        if ($user === null || $pass === null) {
            throw new PDOException('DB_USER and DB_PASSWORD must be set');
        }
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        // Details go to the log, never to the visitor.
        error_log('Database connection failed: ' . $e->getMessage());
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Retry-After: 30');
        echo "The database is unavailable. Please try again shortly.\n";
        exit;
    }
}

/** Double-quote a Postgres identifier (table or column name). */
function quote_ident(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

/** @return list<string> tables in the public schema */
function list_tables(PDO $pdo): array
{
    return $pdo->query(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
         ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN);
}

/** The useful part of a Postgres error ("invalid input syntax for type bigint: ..."). */
function pg_error_summary(PDOException $e): string
{
    $msg = $e->errorInfo[2] ?? $e->getMessage();
    return trim(preg_replace('/^ERROR:\s*/', '', strtok((string) $msg, "\n") ?: (string) $msg) ?? '');
}
