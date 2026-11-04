<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

/**
 * Gate for the admin pages (CSV import, table browser), which can create,
 * change and drop tables.
 *
 * Uses HTTP Basic auth against ADMIN_USER (default "admin") and either
 * ADMIN_PASSWORD_HASH (from password_hash(), preferred) or ADMIN_PASSWORD.
 * With neither set, the pages are open when APP_ENV=local and closed
 * otherwise, so a production deploy is never accidentally left open.
 *
 * Basic auth sends the password with every request, so only use it over
 * HTTPS (Cloud Run URLs always are).
 */
function require_admin(): void
{
    $expectedUser = env('ADMIN_USER') ?? 'admin';
    $hash = env('ADMIN_PASSWORD_HASH');
    $plain = env('ADMIN_PASSWORD');

    if ($hash === null && $plain === null) {
        if (app_env() === 'local') {
            return;
        }
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Admin pages are disabled: set ADMIN_PASSWORD_HASH or ADMIN_PASSWORD to enable them.\n";
        exit;
    }

    [$user, $pass] = basic_auth_credentials();
    $ok = $user !== null
        && hash_equals($expectedUser, $user)
        && ($hash !== null ? password_verify((string) $pass, $hash) : hash_equals($plain, (string) $pass));

    if (!$ok) {
        if ($user !== null) {
            error_log('Admin login failed for user "' . substr($user, 0, 64) . '" from ' . client_ip());
            usleep(random_int(200_000, 500_000)); // slow down guessing
        }
        header('WWW-Authenticate: Basic realm="Admin", charset="UTF-8"');
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Authentication required.\n";
        exit;
    }
}

/** @return array{?string, ?string} */
function basic_auth_credentials(): array
{
    if (isset($_SERVER['PHP_AUTH_USER'])) {
        return [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ?? ''];
    }
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($header, 'Basic ') === 0) {
        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            return explode(':', $decoded, 2);
        }
    }
    return [null, null];
}

function client_ip(): string
{
    // Cloud Run puts the client first in X-Forwarded-For.
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    return $fwd !== '' ? trim(explode(',', $fwd)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '?');
}

function is_https(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on'
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/**
 * CSRF token for forms (double-submit cookie: works across Cloud Run
 * instances without shared session storage). Call before any output.
 */
function csrf_token(): string
{
    static $token = null;
    if ($token !== null) {
        return $token;
    }
    $cookie = $_COOKIE['csrf'] ?? '';
    if (is_string($cookie) && preg_match('/^[a-f0-9]{64}$/', $cookie)) {
        return $token = $cookie;
    }
    $token = bin2hex(random_bytes(32));
    setcookie('csrf', $token, [
        'path' => '/',
        'httponly' => true,
        'secure' => is_https(),
        'samesite' => 'Strict',
    ]);
    $_COOKIE['csrf'] = $token;
    return $token;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Reject state-changing requests that don't carry this site's CSRF token. */
function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    $cookie = $_COOKIE['csrf'] ?? '';

    // Browsers send Origin on cross-site POSTs; when present it must be us.
    $sameOrigin = true;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin !== null) {
        $o = parse_url($origin) ?: [];
        $originHost = ($o['host'] ?? '') . (isset($o['port']) ? ':' . $o['port'] : '');
        $sameOrigin = $originHost !== '' && strcasecmp($originHost, $_SERVER['HTTP_HOST'] ?? '') === 0;
    }

    if (!is_string($sent) || !is_string($cookie) || $cookie === '' || !hash_equals($cookie, $sent) || !$sameOrigin) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "This form has expired or did not come from this site. Go back, reload the page and try again.\n";
        exit;
    }
}

/** Security headers for HTML pages (nginx adds them too; this covers php -S and other servers). */
function send_page_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
}
