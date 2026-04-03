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
$normalizedPath = preg_replace('#^.*?/scholar_php/#i', '', $normalizedPath);
$normalizedPath = ltrim((string) $normalizedPath, '/');

if (!preg_match('#^uploads(?:/|$)#i', $normalizedPath)) {
    http_response_code(403);
    exit('Invalid path');
}

$baseDir = realpath(__DIR__);
$filePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath));
if ($baseDir === false || $filePath === false || strpos($filePath, $baseDir) !== 0 || !is_file($filePath)) {
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