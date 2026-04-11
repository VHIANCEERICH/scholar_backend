<?php
declare(strict_types=1);
require_once __DIR__ . '/cors_utils.php';

// Render's Docker setup uses `php -S` (no Apache), so `.htaccess` rules are ignored.
// This router adds correct headers for `/uploads/*` and then falls back to the
// built-in server for everything else.

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = is_string($uri) ? $uri : '/';
$path = urldecode($uri);

if (str_starts_with($path, '/uploads/')) {
    $relative = ltrim($path, '/');
    $candidate = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $baseDir = realpath(__DIR__);
    $filePath = realpath($candidate);

    apply_cors_headers(['GET', 'OPTIONS']);
    handle_preflight();

    if ($baseDir !== false && $filePath !== false && str_starts_with($filePath, $baseDir) && is_file($filePath)) {
        $mimeType = 'application/octet-stream';
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($filePath);
            if (is_string($detected) && $detected !== '') {
                $mimeType = $detected;
            }
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . (string) filesize($filePath));
        header('Cache-Control: public, max-age=86400');
        readfile($filePath);
        exit;
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File not found';
    exit;
}

return false;
