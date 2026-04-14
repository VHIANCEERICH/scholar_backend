<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/backend_common.php';
require_once __DIR__ . '/backend_env.php';

function oauth_env(string $name, string $default = ''): string
{
    return backend_env($name, $default);
}

function oauth_current_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim($scriptDir, '/');

    return $scheme . '://' . $host . ($scriptDir !== '' ? $scriptDir : '');
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

$redirectUri = oauth_env('GOOGLE_OAUTH_REDIRECT_URI');
if ($redirectUri === '') {
    $redirectUri = oauth_current_base_url() . '/google_oauth_callback.php';
}

$_SESSION['google_oauth_role'] = $role;
$_SESSION['google_oauth_state'] = bin2hex(random_bytes(16));

$params = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $_SESSION['google_oauth_state'],
    'access_type' => 'offline',
    'prompt' => 'select_account consent',
    'include_granted_scopes' => 'true',
];

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
header('Location: ' . $authUrl, true, 302);
exit;