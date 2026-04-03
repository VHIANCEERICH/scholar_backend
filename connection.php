<?php

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: '3306');
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$dbname = getenv('DB_NAME') ?: 'scholar_sys';

$mysqli = mysqli_init();
if ($mysqli === false) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database initialization failed',
    ]);
    exit;
}

// Fail fast when DB host/credentials are wrong to avoid long upstream timeouts (502).
$mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
$connected = @$mysqli->real_connect($host, $username, $password, $dbname, $port);

if (!$connected) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed: ' . $mysqli->connect_error,
    ]);
    exit;
}

$conn = $mysqli;
$conn->set_charset('utf8mb4');