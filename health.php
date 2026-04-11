<?php
declare(strict_types=1);

require_once __DIR__ . '/cors_utils.php';

apply_cors_headers(['GET', 'OPTIONS']);
header('Content-Type: application/json; charset=utf-8');

handle_preflight();

echo json_encode([
    'status' => 'ok',
    'time' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
