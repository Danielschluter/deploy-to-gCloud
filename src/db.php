<?php
require_once __DIR__ . '/includes/config.php';

function get_db(): PDO {
    if (session_status() === PHP_SESSION_NONE) {
        require_once __DIR__ . '/includes/auth.php';
    }

    $conn = null;
    if (pgclient_config_exists()) {
        $conn = pgclient_get_active_connection();
    }

    if (!$conn) {
        $conn = pgclient_env_connection();
    }

    try {
        return pgclient_make_pdo($conn);
    } catch (Throwable $e) {
        throw new RuntimeException('DB connection failed: ' . $e->getMessage(), 0, $e);
    }
}
