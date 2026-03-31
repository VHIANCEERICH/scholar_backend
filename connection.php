<?php

declare(strict_types=1);

$host = '127.0.0.1';              // matches docker-compose MySQL service name
$username = 'root';           // your MySQL username
$password = '';  // your MySQL password
$dbname = 'scholar_sys';      // your database name

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed: ' . $conn->connect_error,
    ]);
    exit;
}

$conn->set_charset('utf8mb4');
