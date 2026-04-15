<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';
require_once __DIR__ . '/backend_env.php';
require_once __DIR__ . '/google_oauth_state.php';

function oauth_env(string $name, string $default = ''): string
{
    return backend_env($name, $default);
}

function oauth_current_base_url(): string
{
    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto !== '') {
        $forwardedProto = trim((string) explode(',', $forwardedProto)[0]);
    }

    $scheme = ($forwardedProto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'))
        ? 'https'
        : 'http';

    $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
    if (str_contains($host, ',')) {
        $host = trim((string) explode(',', $host)[0]);
    }

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim($scriptDir, '/');

    return $scheme . '://' . $host . ($scriptDir !== '' ? $scriptDir : '');
}

function oauth_redirect_uri(): string
{
    $configured = trim((string) oauth_env('GOOGLE_OAUTH_REDIRECT_URI', ''));
    if ($configured !== '') {
        return $configured;
    }

    return oauth_current_base_url() . '/google_oauth_callback.php';
}

function oauth_is_valid_success_url(string $url): bool
{
    $parsed = parse_url($url);
    if (!is_array($parsed)) {
        return false;
    }

    $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
    $host = strtolower((string) ($parsed['host'] ?? ''));

    return in_array($scheme, ['http', 'https'], true) && $host !== '';
}

function oauth_html(string $title, string $message, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . $safeTitle . '</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#111827;color:#f9fafb;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}';
    echo '.card{max-width:720px;width:100%;background:#1f2937;border:1px solid #374151;border-radius:18px;padding:28px;box-shadow:0 18px 40px rgba(0,0,0,.35)}';
    echo 'h1{margin:0 0 12px;font-size:28px}p{line-height:1.6;font-size:16px;color:#d1d5db}.muted{color:#9ca3af;font-size:14px;margin-top:18px}</style>';
    echo '</head><body><div class="card"><h1>' . $safeTitle . '</h1><p>' . $safeMessage . '</p><p class="muted">You can now return to the app.</p></div></body></html>';
    exit;
}

$role = strtolower(trim((string) ($_GET['role'] ?? 'scholar')));
if (!in_array($role, ['admin', 'scholar'], true)) {
    $role = 'scholar';
}

$clientId = oauth_env('GOOGLE_CLIENT_ID', oauth_env('GOOGLE_OAUTH_CLIENT_ID'));
if ($clientId === '') {
    oauth_html('Google Login Not Configured', 'Missing GOOGLE_CLIENT_ID (or GOOGLE_OAUTH_CLIENT_ID) in the backend environment.', 500);
}

$redirectUri = oauth_redirect_uri();

$successUrl = trim((string) ($_GET['success_url'] ?? ''));
if (!oauth_is_valid_success_url($successUrl)) {
    $successUrl = trim((string) oauth_env('GOOGLE_OAUTH_SUCCESS_URL', ''));
    if (!oauth_is_valid_success_url($successUrl)) {
        $successUrl = 'https://scholar-frontend-yqnn.onrender.com';
    }
}

$state = oauth_state_encode([
    'role' => $role,
    'ts' => time(),
    'nonce' => bin2hex(random_bytes(16)),
    'success_url' => $successUrl,
]);

$params = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
];

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
header('Location: ' . $authUrl, true, 302);
exit;
