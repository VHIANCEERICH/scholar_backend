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

function oauth_success_url_from_state(array $stateData): string
{
    $successUrl = trim((string) ($stateData['success_url'] ?? ''));
    if ($successUrl === '') {
        $successUrl = oauth_env('GOOGLE_OAUTH_SUCCESS_URL', '');
    }
    if ($successUrl === '') {
        $successUrl = 'https://scholar-frontend-yqnn.onrender.com';
    }

    return oauth_is_valid_success_url($successUrl) ? $successUrl : '';
}

function oauth_frontend_url(): string
{
    $url = trim((string) oauth_env('GOOGLE_OAUTH_SUCCESS_URL', ''));
    if ($url === '') {
        $url = 'https://scholar-frontend-yqnn.onrender.com';
    }

    return oauth_is_valid_success_url($url) ? $url : 'https://scholar-frontend-yqnn.onrender.com';
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

function oauth_http_post(string $url, array $fields): array
{
    $payload = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Token request failed: ' . $error);
        }

        return [$status, (string) $body];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 30,
        ],
    ]);

    $body = file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('Token request failed using stream context.');
    }

    $status = 200;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $headerLine, $matches)) {
                $status = (int) $matches[1];
                break;
            }
        }
    }

    return [$status, (string) $body];
}

function oauth_http_get(string $url, array $headers = []): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Userinfo request failed: ' . $error);
        }

        return [$status, (string) $body];
    }

    $headerText = '';
    foreach ($headers as $header) {
        $headerText .= $header . "\r\n";
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => $headerText,
            'timeout' => 30,
        ],
    ]);

    $body = file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('Userinfo request failed using stream context.');
    }

    $status = 200;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $headerLine, $matches)) {
                $status = (int) $matches[1];
                break;
            }
        }
    }

    return [$status, (string) $body];
}

function oauth_render_error(string $message, int $status = 400): void
{
    oauth_html('Google Login Failed', $message, $status);
}

function oauth_redirect_to_app(string $successUrl, array $params, int $status = 302): bool
{
    if (!oauth_is_valid_success_url($successUrl)) {
        return false;
    }

    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $separator = str_contains($successUrl, '?') ? '&' : '?';
    header('Location: ' . $successUrl . $separator . $query, true, $status);
    exit;
}

$incomingState = (string) ($_GET['state'] ?? '');
$incomingCode = (string) ($_GET['code'] ?? '');
$incomingError = (string) ($_GET['error'] ?? '');
$stateData = oauth_state_decode($incomingState);

if ($incomingError !== '') {
    oauth_render_error('Google returned an error: ' . $incomingError, 400);
}

if ($incomingCode === '') {
    oauth_render_error('Missing authorization code from Google.', 400);
}

if ($stateData === null) {
    oauth_render_error('Invalid OAuth state. Please try again.', 403);
}

$role = strtolower(trim((string) ($stateData['role'] ?? ($_GET['role'] ?? 'scholar'))));
if (!in_array($role, ['admin', 'scholar'], true)) {
    $role = 'scholar';
}

$successUrl = oauth_success_url_from_state($stateData);
$frontendUrl = oauth_frontend_url();

$clientId = oauth_env('GOOGLE_CLIENT_ID', oauth_env('GOOGLE_OAUTH_CLIENT_ID'));
$clientSecret = oauth_env('GOOGLE_CLIENT_SECRET', oauth_env('GOOGLE_OAUTH_CLIENT_SECRET'));
$redirectUri = oauth_redirect_uri();

if ($clientId === '' || $clientSecret === '') {
    oauth_render_error('Missing Google client credentials in the backend environment.', 500);
}

try {
    [$tokenStatus, $tokenBody] = oauth_http_post('https://oauth2.googleapis.com/token', [
        'code' => $incomingCode,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ]);
} catch (Throwable $e) {
    oauth_render_error('Failed to exchange the authorization code: ' . $e->getMessage(), 500);
}

$tokenData = json_decode($tokenBody, true);
if ($tokenStatus < 200 || $tokenStatus >= 300 || !is_array($tokenData) || empty($tokenData['access_token'])) {
    $detail = is_array($tokenData) ? json_encode($tokenData) : $tokenBody;
    oauth_render_error('Google token exchange failed: ' . $detail, 500);
}

try {
    [$profileStatus, $profileBody] = oauth_http_get(
        'https://openidconnect.googleapis.com/v1/userinfo',
        ['Authorization: Bearer ' . $tokenData['access_token']]
    );
} catch (Throwable $e) {
    oauth_render_error('Failed to load Google profile: ' . $e->getMessage(), 500);
}

$profileData = json_decode($profileBody, true);
if ($profileStatus < 200 || $profileStatus >= 300 || !is_array($profileData)) {
    oauth_render_error('Google profile lookup failed.', 500);
}

$email = trim((string) ($profileData['email'] ?? ''));
$name = trim((string) ($profileData['name'] ?? $profileData['given_name'] ?? ''));
$googleId = trim((string) ($profileData['sub'] ?? ''));

if ($email === '') {
    oauth_render_error('Google did not return an email address for this account.', 500);
}

$userStmt = db_prepare(
    $conn,
    'SELECT user_id, username, email, role, is_active FROM users WHERE email = ? LIMIT 1'
);
$userStmt->bind_param('s', $email);
$userStmt->execute();
$user = $userStmt->get_result()?->fetch_assoc();
$userStmt->close();

if (!$user) {
    $pendingParams = [
        'status' => 'pending_account',
        'email' => $email,
        'name' => $name !== '' ? $name : $email,
        'role' => $role,
        'google_id' => $googleId,
    ];

    if (oauth_redirect_to_app($frontendUrl, $pendingParams, 302)) {
        return;
    }

    oauth_html(
        'Google Login Pending',
        'No local account exists for ' . $email . '. Please return to the app and complete account creation.',
        200
    );
}

if (isset($user['is_active']) && (int) $user['is_active'] !== 1) {
    oauth_render_error('This account is inactive.', 403);
}

$localRole = strtolower(trim((string) ($user['role'] ?? '')));
if ($localRole !== $role) {
    oauth_render_error(
        'You signed in as a ' . $role . ' account, but this email is registered as ' . ($localRole !== '' ? $localRole : 'unknown') . '.',
        403
    );
}

$displayName = trim((string) ($user['username'] ?? ''));
if ($displayName === '') {
    $displayName = $name !== '' ? $name : $email;
}

$extra = [];
if ($localRole === 'scholar') {
    $profileStmt = db_prepare(
        $conn,
        'SELECT scholar_id, scholarship_category, academic_type, sport_type, gift_type, first_name, last_name FROM scholars WHERE user_id = ? LIMIT 1'
    );
    $profileStmt->bind_param('i', $user['user_id']);
    $profileStmt->execute();
    $scholar = $profileStmt->get_result()?->fetch_assoc();
    $profileStmt->close();

    if (!$scholar) {
        $pendingParams = [
            'status' => 'pending_account',
            'email' => $email,
            'name' => $displayName,
            'role' => $role,
            'google_id' => $googleId,
            'user_id' => (int) $user['user_id'],
            'scholarship_category' => '',
        ];

        if (oauth_redirect_to_app($frontendUrl, $pendingParams, 302)) {
            return;
        }

        oauth_html(
            'Google Login Pending',
            'Scholar profile not found for this account. Please return to the app and complete account creation.',
            200
        );
    }

    $displayName = trim(implode(' ', array_filter([
        trim((string) ($scholar['first_name'] ?? '')),
        trim((string) ($scholar['last_name'] ?? '')),
    ])));
    if ($displayName === '') {
        $displayName = $name !== '' ? $name : $email;
    }

    $extra = [
        'scholar_id' => (int) ($scholar['scholar_id'] ?? 0),
        'scholarship_category' => (string) ($scholar['scholarship_category'] ?? ''),
        'academic_type' => (string) ($scholar['academic_type'] ?? ''),
        'sport_type' => (string) ($scholar['sport_type'] ?? ''),
        'gift_type' => (string) ($scholar['gift_type'] ?? ''),
    ];
}

$successParams = array_merge([
    'status' => 'success',
    'user_id' => (int) $user['user_id'],
    'email' => $email,
    'name' => $displayName,
    'role' => $localRole,
], $extra);

if (!oauth_redirect_to_app($successUrl, $successParams, 302)) {
    if ($localRole === 'scholar') {
        oauth_redirect_to_app($frontendUrl, array_merge($successParams, ['status' => 'success']), 302);
    }

    $message = 'Signed in successfully as ' . $displayName . ' (' . $email . ').';
    if (!empty($extra['scholarship_category'])) {
        $message .= ' Scholar category: ' . $extra['scholarship_category'] . '.';
    }

    oauth_html('Google Login Successful', $message, 200);
}
