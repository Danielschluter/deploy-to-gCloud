<?php
function get_db() {
    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '5432';
    $db = getenv('POSTGRES_DB') ?: 'appdb';
    $user = getenv('POSTGRES_USER') ?: 'user';
    $pass = getenv('POSTGRES_PASSWORD') ?: 'password';
    $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return $pdo;
    } catch (PDOException $e) {
        echo "DB connection failed: " . htmlspecialchars($e->getMessage());
        exit(1);
    }
}
