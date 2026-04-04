<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit('Method not allowed');
}

$rawPath = trim((string) ($_GET['path'] ?? ''));
if ($rawPath === '') {
    http_response_code(400);
    exit('Missing path');
}

$normalizedPath = str_replace('\\', '/', $rawPath);

// Accept absolute filesystem paths by extracting the uploads segment.
$uploadsPos = stripos($normalizedPath, '/uploads/');
if ($uploadsPos !== false) {
    $normalizedPath = substr($normalizedPath, $uploadsPos + 1);
} else {
    $normalizedPath = preg_replace('#^.*?/scholar_php/#i', '', $normalizedPath);
    $normalizedPath = ltrim((string) $normalizedPath, '/');

    // Final fallback for values like "uploads/file.png" without a leading slash.
    if (!preg_match('#^uploads(?:/|$)#i', $normalizedPath)) {
        $uploadsPrefixPos = stripos($normalizedPath, 'uploads/');
        if ($uploadsPrefixPos !== false) {
            $normalizedPath = substr($normalizedPath, $uploadsPrefixPos);
        }
    }
}

$normalizedPath = preg_replace('#/{2,}#', '/', $normalizedPath);
$normalizedPath = ltrim((string) $normalizedPath, '/');

if (!preg_match('#^uploads(?:/|$)#i', $normalizedPath)) {
    http_response_code(403);
    exit('Invalid path');
}

$uploadsRoot = trim((string) (getenv('UPLOADS_ROOT_DIR') ?: ''));
if ($uploadsRoot === '') {
    $uploadsRoot = __DIR__ . '/uploads';
}

$uploadsRoot = rtrim(str_replace('\\', '/', $uploadsRoot), '/');
$uploadsRootReal = realpath($uploadsRoot);
if ($uploadsRootReal === false || !is_dir($uploadsRootReal)) {
    http_response_code(404);
    exit('File not found');
}

$relativeInsideUploads = preg_replace('#^uploads(?:/|$)#i', '', $normalizedPath);
$relativeInsideUploads = ltrim((string) $relativeInsideUploads, '/');
$requested = $uploadsRootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeInsideUploads);
$filePath = realpath($requested);
$uploadsRootNorm = rtrim(str_replace('\\', '/', $uploadsRootReal), '/');
$filePathNorm = $filePath === false ? '' : str_replace('\\', '/', $filePath);
if ($filePath === false || !is_file($filePath) || strpos($filePathNorm, $uploadsRootNorm . '/') !== 0) {
    http_response_code(404);
    exit('File not found');
}

$mimeType = 'application/octet-stream';
if (function_exists('mime_content_type')) {
    $detected = @mime_content_type($filePath);
    if (is_string($detected) && $detected !== '') {
        $mimeType = $detected;
    }
} else {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    if (isset($mimeMap[$extension])) {
        $mimeType = $mimeMap[$extension];
    }
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($filePath));
header('Cache-Control: public, max-age=86400');
readfile($filePath);