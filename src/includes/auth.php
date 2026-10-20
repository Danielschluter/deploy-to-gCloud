<?php
require_once __DIR__ . '/config.php';

function pgclient_start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_name('pgclient_sess');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

pgclient_start_session();

$_pgclient_self = basename($_SERVER['PHP_SELF'] ?? '');
$_pgclient_is_api = ($_pgclient_self === 'api.php');

if (!pgclient_config_exists()) {
    if ($_pgclient_self !== 'setup.php') {
        if ($_pgclient_is_api) {
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'setup_required', 'redirect' => 'setup.php']);
            exit;
        }
        header('Location: setup.php');
        exit;
    }
    return;
}

if (empty($_SESSION['pgclient_authed'])) {
    if ($_pgclient_self !== 'login.php' && $_pgclient_self !== 'setup.php') {
        if ($_pgclient_is_api) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'auth_required', 'redirect' => 'login.php']);
            exit;
        }
        $next = pgclient_safe_redirect($_SERVER['REQUEST_URI'] ?? 'index.php');
        header('Location: login.php?next=' . urlencode($next));
        exit;
    }
}
