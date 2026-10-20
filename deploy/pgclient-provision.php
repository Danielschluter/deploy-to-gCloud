<?php
// Run by vm-setup.sh inside the php container (as www-data) to write PGClient's config,
// so the public setup wizard is never needed. Sets the admin login from ADMIN_USER /
// ADMIN_PASSWORD and, on first run, adds the bundled Postgres as the default connection.
// Connections added later in the UI are kept.
require '/var/www/html/includes/config.php';

$user = getenv('ADMIN_USER');
$pass = getenv('ADMIN_PASSWORD');
if (!$user || !$pass) {
    fwrite(STDERR, "ADMIN_USER and ADMIN_PASSWORD must be set\n");
    exit(1);
}

$cfg = pgclient_load_config();
$cfg['version'] = $cfg['version'] ?? 1;

$auth = $cfg['auth'] ?? [];
if (($auth['username'] ?? '') !== $user || !password_verify($pass, $auth['password_hash'] ?? '')) {
    $cfg['auth'] = ['username' => $user, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT)];
}

if (empty($cfg['connections'])) {
    $conn = pgclient_env_connection();
    $conn['name'] = 'Local PostgreSQL';
    $conn['is_default'] = true;
    $cfg['connections'] = [pgclient_normalize_connection($conn)];
}

if (!pgclient_save_config($cfg)) {
    fwrite(STDERR, 'Could not write ' . PGCLIENT_CONFIG_FILE . "\n");
    exit(1);
}
echo 'PGClient config written to ' . PGCLIENT_CONFIG_FILE . "\n";
