<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (pgclient_config_exists()) {
    if (empty($_SESSION['pgclient_authed'])) {
        header('Location: login.php');
        exit;
    }
    if (empty($_GET['force'])) {
        header('Location: index.php');
        exit;
    }
}

$env_db = pgclient_env_connection();

$pgc_user = getenv('PGC_ADMIN_USER');
$pgc_pass = getenv('PGC_ADMIN_PASS');
if (!pgclient_config_exists() && $pgc_user && $pgc_pass && strlen($pgc_pass) >= 8) {
    $conn = pgclient_normalize_connection([
        'name'       => getenv('PGC_CONN_NAME') ?: 'PortfolioSight PostgreSQL',
        'host'       => $env_db['host'] ?? '127.0.0.1',
        'port'       => $env_db['port'] ?? 5432,
        'database'   => $env_db['database'] ?? 'admin_portfolio',
        'username'   => $env_db['username'] ?? 'admin_main',
        'password'   => $env_db['password'] ?? '',
        'ssl'        => $env_db['ssl'] ?? false,
        'is_default' => true,
    ]);

    $test = pgclient_test_connection($conn);
    if ($test['success']) {
        $cfg = [
            'version' => 1,
            'auth' => [
                'username' => $pgc_user,
                'password_hash' => password_hash($pgc_pass, PASSWORD_DEFAULT),
            ],
            'connections' => [$conn],
        ];
        if (pgclient_save_config($cfg)) {
            session_regenerate_id(true);
            $_SESSION['pgclient_authed']  = true;
            $_SESSION['pgclient_user']    = $pgc_user;
            $_SESSION['pgclient_conn_id'] = $conn['id'];
            header('Location: index.php');
            exit;
        }
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    $conn = pgclient_normalize_connection([
        'name'       => trim($_POST['conn_name'] ?? 'PortfolioSight PostgreSQL'),
        'host'       => trim($_POST['host'] ?? '127.0.0.1'),
        'port'       => (int)($_POST['port'] ?? 5432),
        'database'   => trim($_POST['database'] ?? 'admin_portfolio'),
        'username'   => trim($_POST['db_user'] ?? 'admin_main'),
        'password'   => $_POST['db_pass'] ?? '',
        'ssl'        => !empty($_POST['ssl']),
        'is_default' => true,
    ]);

    if (strlen($username) < 3) $errors[] = 'Admin username must be at least 3 characters.';
    if (strlen($password) < 8) $errors[] = 'Admin password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Admin passwords do not match.';
    if ($conn['host'] === '') $errors[] = 'Database host is required.';
    if ($conn['database'] === '') $errors[] = 'Database name is required.';
    if ($conn['username'] === '') $errors[] = 'Database username is required.';

    if (!$errors) {
        $test = pgclient_test_connection($conn);
        if (!$test['success']) {
            $errors[] = 'Database connection failed: ' . $test['error'];
        } else {
            $cfg = [
                'version' => 1,
                'auth' => [
                    'username' => $username,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ],
                'connections' => [$conn],
            ];

            if (pgclient_save_config($cfg)) {
                session_regenerate_id(true);
                $_SESSION['pgclient_authed']  = true;
                $_SESSION['pgclient_user']    = $username;
                $_SESSION['pgclient_conn_id'] = $conn['id'];
                header('Location: index.php');
                exit;
            }

            $errors[] = 'Could not write config file: ' . PGCLIENT_CONFIG_FILE . '. Make sure PHP can write to the domain private folder.';
        }
    }
}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function posted_or_env(string $post, string $envKey, string $default = ''): string {
    global $env_db;
    return (string)($_POST[$post] ?? $env_db[$envKey] ?? $default);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PGClient — Setup</title>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}:root{--bg:#16181d;--bg2:#1c1f26;--panel:#22252e;--panel2:#2a2d38;--border:#2a2d38;--text:#c9cdd9;--text2:#8b90a8;--text3:#555a72;--accent:#4da6ff;--accent2:#3d8fe0;--accent-bg:rgba(77,166,255,.12);--green:#4ec994;--green-bg:rgba(78,201,148,.12);--red:#f07878;--red-bg:rgba(240,120,120,.12)}html,body{height:100%;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;font-size:13px}body{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}.setup-wrap{width:100%;max-width:580px}.setup-logo{text-align:center;margin-bottom:28px}.logo-mark{width:52px;height:52px;border-radius:12px;background:linear-gradient(135deg,#336699,#4da6ff);display:inline-flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;color:#fff;margin-bottom:12px}.logo-title{font-size:22px;font-weight:700}.logo-sub{font-size:12px;color:var(--text3);margin-top:4px}.setup-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;overflow:hidden}.setup-card-hdr{padding:18px 24px;border-bottom:1px solid var(--border);background:var(--bg2)}.setup-card-hdr h2{font-size:15px;font-weight:600}.setup-card-hdr p{font-size:11.5px;color:var(--text2);margin-top:4px}.setup-body{padding:24px}.section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text3);margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid var(--border)}.form-group{margin-bottom:16px}.form-lbl{font-size:10.5px;color:var(--text3);margin-bottom:5px;display:block;font-weight:600;text-transform:uppercase;letter-spacing:.4px}.form-inp{background:var(--bg);border:1px solid var(--border);color:var(--text);padding:8px 11px;border-radius:6px;font-size:12.5px;font-family:inherit;width:100%;transition:border-color .15s}.form-inp:focus{outline:none;border-color:var(--accent)}.form-hint{font-size:11px;color:var(--text3);margin-top:4px}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}.grid3{display:grid;grid-template-columns:2fr 1fr;gap:12px}.errors{background:var(--red-bg);border:1px solid rgba(240,120,120,.3);border-radius:6px;padding:12px 14px;margin-bottom:18px}.errors li{color:var(--red);font-size:12px;margin-left:14px;margin-bottom:2px}.info{background:var(--accent-bg);border:1px solid rgba(77,166,255,.25);border-radius:6px;padding:10px 13px;margin-bottom:16px;font-size:11.5px;color:var(--accent);line-height:1.45}.divider{height:1px;background:var(--border);margin:20px 0}.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:6px;border:none;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;width:100%;justify-content:center}.btn-primary{background:var(--accent);color:#fff}.btn-primary:hover{background:var(--accent2)}.btn-secondary{background:var(--panel2);color:var(--text2);flex:0 0 auto;width:auto;padding:9px 16px}.cb-wrap{display:flex;align-items:center;gap:8px;padding:8px 0}.cb-wrap input[type=checkbox]{width:15px;height:15px;accent-color:var(--accent);cursor:pointer}.cb-wrap label{font-size:12.5px;color:var(--text2);cursor:pointer}.test-result{margin-top:10px;font-size:12px;padding:8px 12px;border-radius:5px;display:none}.test-result.ok{background:var(--green-bg);color:var(--green)}.test-result.fail{background:var(--red-bg);color:var(--red)}code{color:var(--text);background:var(--bg);border:1px solid var(--border);border-radius:4px;padding:1px 4px}@media(max-width:620px){.grid2,.grid3{grid-template-columns:1fr}.setup-wrap{max-width:100%}}
</style>
</head>
<body>
<div class="setup-wrap">
    <div class="setup-logo">
        <div class="logo-mark">PG</div>
        <div class="logo-title">PGClient Setup</div>
        <div class="logo-sub">panel.portfoliosight.io / HestiaCP PostgreSQL</div>
    </div>
    <div class="setup-card">
        <div class="setup-card-hdr">
            <h2>Initial Setup</h2>
            <p>Create the PGClient admin login and connect to your HestiaCP PostgreSQL database.</p>
        </div>
        <div class="setup-body">
            <?php if (!empty($errors)): ?>
            <ul class="errors"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>

            <div class="info">
                Config will be saved to <code><?= e(PGCLIENT_CONFIG_FILE) ?></code>, outside <code>public_html</code> when deployed on HestiaCP.
            </div>

            <form method="POST" id="setup-form">
                <div class="section-label">PGClient Admin Login</div>
                <div class="grid2">
                    <div class="form-group"><label class="form-lbl">Username</label><input class="form-inp" type="text" name="username" value="<?= e($_POST['username'] ?? 'admin') ?>" autocomplete="username" required></div>
                    <div></div>
                </div>
                <div class="grid2">
                    <div class="form-group"><label class="form-lbl">Password</label><input class="form-inp" type="password" name="password" placeholder="Min 8 characters" autocomplete="new-password" required></div>
                    <div class="form-group"><label class="form-lbl">Confirm Password</label><input class="form-inp" type="password" name="confirm" placeholder="Repeat password" autocomplete="new-password" required></div>
                </div>

                <div class="divider"></div>
                <div class="section-label">HestiaCP PostgreSQL Connection</div>
                <div class="form-group"><label class="form-lbl">Connection Name</label><input class="form-inp" type="text" name="conn_name" value="<?= e($_POST['conn_name'] ?? 'PortfolioSight PostgreSQL') ?>"></div>
                <div class="grid3">
                    <div class="form-group"><label class="form-lbl">Host</label><input class="form-inp" type="text" name="host" value="<?= e(posted_or_env('host','host','127.0.0.1')) ?>" required><div class="form-hint">Use <code>127.0.0.1</code> for PostgreSQL on the same VPS.</div></div>
                    <div class="form-group"><label class="form-lbl">Port</label><input class="form-inp" type="number" name="port" value="<?= e(posted_or_env('port','port','5432')) ?>"></div>
                </div>
                <div class="form-group"><label class="form-lbl">Database Name</label><input class="form-inp" type="text" name="database" value="<?= e(posted_or_env('database','database','admin_portfolio')) ?>" required></div>
                <div class="grid2">
                    <div class="form-group"><label class="form-lbl">DB Username</label><input class="form-inp" type="text" name="db_user" value="<?= e(posted_or_env('db_user','username','admin_main')) ?>" autocomplete="off" required></div>
                    <div class="form-group"><label class="form-lbl">DB Password</label><input class="form-inp" type="password" name="db_pass" value="<?= e($_POST['db_pass'] ?? $env_db['password'] ?? '') ?>" autocomplete="off"></div>
                </div>
                <div class="cb-wrap"><input type="checkbox" id="ssl" name="ssl" value="1" <?= !empty($_POST['ssl']) || !empty($env_db['ssl']) ? 'checked' : '' ?>><label for="ssl">Require SSL connection</label></div>
                <div id="test-result" class="test-result"></div>
                <div class="divider"></div>
                <div style="display:flex;gap:10px"><button type="button" class="btn btn-secondary" onclick="testConn(event)">Test Connection</button><button type="submit" class="btn btn-primary">Save & Launch PGClient</button></div>
            </form>
        </div>
    </div>
</div>
<script>
async function testConn(evt) {
    const form = document.getElementById('setup-form');
    const fd = new FormData(form);
    const res = document.getElementById('test-result');
    const btn = evt.currentTarget;
    res.style.display = 'none';
    btn.textContent = 'Testing…';
    btn.disabled = true;
    try {
        const r = await fetch('api.php?action=test_connection', { method: 'POST', body: fd, credentials: 'same-origin' });
        const d = await r.json();
        res.style.display = 'block';
        if (d.success) {
            res.className = 'test-result ok';
            res.textContent = 'Connected: ' + (d.database || '') + ' as ' + (d.user || '') + ' — ' + (d.version || '');
        } else {
            res.className = 'test-result fail';
            res.textContent = d.error || 'Connection failed';
        }
    } catch(e) {
        res.style.display = 'block';
        res.className = 'test-result fail';
        res.textContent = e.message;
    }
    btn.textContent = 'Test Connection';
    btn.disabled = false;
}
</script>
</body>
</html>
