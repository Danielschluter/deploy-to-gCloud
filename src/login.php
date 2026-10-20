<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (!pgclient_config_exists()) {
    header('Location: setup.php');
    exit;
}

if (!empty($_SESSION['pgclient_authed'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$next  = pgclient_safe_redirect($_GET['next'] ?? 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg      = pgclient_load_config();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stored_user = $cfg['auth']['username'] ?? '';
    $stored_hash = $cfg['auth']['password_hash'] ?? '';

    if ($stored_user !== '' && $stored_hash !== '' && hash_equals($stored_user, $username) && password_verify($password, $stored_hash)) {
        session_regenerate_id(true);
        $_SESSION['pgclient_authed'] = true;
        $_SESSION['pgclient_user']   = $username;
        $default = pgclient_get_default_connection();
        if ($default) $_SESSION['pgclient_conn_id'] = $default['id'];
        header('Location: ' . pgclient_safe_redirect($_POST['next'] ?? 'index.php'));
        exit;
    }

    $error = 'Invalid username or password.';
    sleep(1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PGClient — Login</title>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#16181d;--bg2:#1c1f26;--panel:#22252e;--border:#2a2d38;--text:#c9cdd9;--text2:#8b90a8;--text3:#555a72;--accent:#4da6ff;--accent2:#3d8fe0;--red:#f07878;--red-bg:rgba(240,120,120,.12)}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;font-size:13px}
body{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}.login-wrap{width:340px}.login-logo{text-align:center;margin-bottom:32px}.logo-mark{width:52px;height:52px;border-radius:12px;background:linear-gradient(135deg,#336699,#4da6ff);display:inline-flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;color:#fff;margin-bottom:12px}.logo-title{font-size:22px;font-weight:700}.logo-sub{font-size:12px;color:var(--text3);margin-top:4px}.login-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:28px}.form-group{margin-bottom:16px}.form-lbl{font-size:10.5px;color:var(--text3);margin-bottom:5px;display:block;font-weight:600;text-transform:uppercase;letter-spacing:.4px}.form-inp{background:var(--bg);border:1px solid var(--border);color:var(--text);padding:9px 12px;border-radius:6px;font-size:13px;font-family:inherit;width:100%;transition:border-color .15s}.form-inp:focus{outline:none;border-color:var(--accent)}.btn{display:flex;align-items:center;justify-content:center;gap:6px;padding:10px 18px;border-radius:6px;border:none;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;width:100%}.btn-primary{background:var(--accent);color:#fff}.btn-primary:hover{background:var(--accent2)}.error-box{background:var(--red-bg);border:1px solid rgba(240,120,120,.3);border-radius:6px;padding:10px 14px;margin-bottom:16px;color:var(--red);font-size:12px}
</style>
</head>
<body>
<div class="login-wrap">
    <div class="login-logo">
        <div class="logo-mark">PG</div>
        <div class="logo-title">PGClient</div>
        <div class="logo-sub">panel.portfoliosight.io</div>
    </div>
    <div class="login-card">
        <?php if ($error): ?><div class="error-box">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST" autocomplete="on">
            <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
            <div class="form-group">
                <label class="form-lbl" for="username">Username</label>
                <input class="form-inp" type="text" id="username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autocomplete="username" autofocus required>
            </div>
            <div class="form-group">
                <label class="form-lbl" for="password">Password</label>
                <input class="form-inp" type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary">Sign In →</button>
        </form>
    </div>
</div>
</body>
</html>
