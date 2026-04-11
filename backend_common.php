<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/cors_utils.php';
require_once __DIR__ . '/path_utils.php';

apply_cors_headers(['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']);
header('Content-Type: application/json; charset=utf-8');

if (ob_get_level() === 0) {
    ob_start();
}

handle_preflight();

require_once __DIR__ . '/connection.php';

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    if (!headers_sent()) {
        apply_cors_headers(['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']);
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }

    if (ob_get_level() > 0) {
        ob_clean();
    }

    echo json_encode([
        'status' => 'error',
        'message' => 'Fatal server error',
        'detail' => $error['message'] ?? 'Unknown error',
        'file' => basename((string) ($error['file'] ?? '')),
        'line' => $error['line'] ?? 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

function respond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    if (ob_get_level() > 0) {
        ob_clean();
    }

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        http_response_code(500);
        $json = json_encode(
            [
                'status' => 'error',
                'message' => 'Failed to encode JSON response',
                'error' => json_last_error_msg(),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    echo $json;
    exit;
}

function respond_success(array $payload = [], int $statusCode = 200): void
{
    respond(array_merge(['status' => 'success'], $payload), $statusCode);
}

function respond_error(string $message, int $statusCode = 400, array $extra = []): void
{
    respond(array_merge(['status' => 'error', 'message' => $message], $extra), $statusCode);
}

function require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        respond_error('Method not allowed', 405);
    }
}

function request_data(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }

    $data = $_POST;
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = array_merge($data, $json);
        }
    }

    return $data;
}

function request_value(string $key, $default = null)
{
    $data = request_data();
    return $data[$key] ?? $default;
}

function require_fields(array $fields): array
{
    $data = request_data();
    $missing = [];

    foreach ($fields as $field) {
        $value = $data[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $missing[] = $field;
        }
    }

    if ($missing !== []) {
        respond_error('Missing required fields', 422, ['fields' => $missing]);
    }

    return $data;
}

function db_prepare(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        respond_error('Database prepare failed: ' . $conn->error, 500);
    }

    return $stmt;
}

function db_table_exists(mysqli $conn, string $table): bool
{
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function fetch_all_assoc(mysqli_stmt $stmt): array
{
    $result = $stmt->get_result();
    if (!$result) {
        return [];
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

function make_public_file_url(string $path): string
{
    if (trim($path) === '') {
        return '';
    }

    $normalizedPath = normalize_upload_path($path);

    if ($normalizedPath === '') {
        return '';
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto !== '') {
        $protoParts = explode(',', $forwardedProto);
        $forwardedProto = trim((string) ($protoParts[0] ?? ''));
        if ($forwardedProto === 'https') {
            $isHttps = true;
        }
    }

    $scheme = $isHttps ? 'https' : 'http';

    $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
    if (str_contains($host, ',')) {
        $host = trim((string) explode(',', $host)[0]);
    }
    if ($host === '') {
        $host = 'localhost';
    }

    // Support both root deployment and /scholar_php deployment paths.
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($basePath === '.' || $basePath === '/') {
        $basePath = '';
    }

    $forwardedPrefix = trim((string) ($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? ''));
    if ($forwardedPrefix !== '') {
        $forwardedPrefix = '/' . trim($forwardedPrefix, '/');
        if ($forwardedPrefix === '/') {
            $forwardedPrefix = '';
        }
        if ($forwardedPrefix !== '' && $forwardedPrefix !== $basePath) {
            $basePath = $forwardedPrefix;
        }
    }

    $servePath = ($basePath !== '' ? $basePath : '') . '/serve_file.php?path=' . rawurlencode($normalizedPath);

    // Prefer explicit public base URL in reverse-proxy deployments.
    $publicBaseUrl = trim((string) (getenv('PUBLIC_BASE_URL') ?: getenv('APP_PUBLIC_BASE_URL') ?: ''));
    if ($publicBaseUrl !== '') {
        return rtrim($publicBaseUrl, '/') . $servePath;
    }

    // If host is unresolved/internal, return a relative URL so frontend resolves
    // against its configured API base origin.
    $isLocalHost = in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)
        || str_starts_with(strtolower($host), 'localhost:')
        || str_starts_with(strtolower($host), '127.0.0.1:')
        || str_starts_with(strtolower($host), '[::1]:');

    if ($isLocalHost) {
        return $servePath;
    }

    return $scheme . '://' . $host . $servePath;
}
