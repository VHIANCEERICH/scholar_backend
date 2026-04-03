<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if (ob_get_level() === 0) {
    ob_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
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
    if ($path === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    $normalizedPath = str_replace('\\', '/', $path);
    $normalizedPath = preg_replace('#^.*?/scholar_php/#i', '', $normalizedPath);
    $normalizedPath = ltrim((string) $normalizedPath, '/');

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . '/scholar_php/' . $normalizedPath;
}


