<?php
declare(strict_types=1);

// 1. CORS Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/backend_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
    exit;
}

// Default to 400 as you requested
define('DEFAULT_REQUIRED_HOURS', 400);

// Get data from POST
$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$renderedHours = isset($_POST['rendered_hours']) ? (int)$_POST['rendered_hours'] : null;
$requiredHours = isset($_POST['required_hours']) ? (int)$_POST['required_hours'] : DEFAULT_REQUIRED_HOURS;

if ($userId <= 0 || $renderedHours === null) {
    echo json_encode(["status" => "error", "message" => "Invalid user_id or rendered_hours"]);
    exit;
}

// 2. Ensure table structure is correct (using required_hours instead of remaining)
if (!db_table_exists($conn, 'duty_totals')) {
    $createSql = "CREATE TABLE IF NOT EXISTS duty_totals (
        user_id INT PRIMARY KEY,
        rendered_hours INT NOT NULL DEFAULT 0,
        required_hours INT NOT NULL DEFAULT 400,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $conn->query($createSql);
}

// 3. Update or Insert the record (Score format: rendered and required)
$sql = "INSERT INTO duty_totals (user_id, rendered_hours, required_hours) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        rendered_hours = VALUES(rendered_hours),
        required_hours = VALUES(required_hours),
        updated_at = CURRENT_TIMESTAMP";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('iii', $userId, $renderedHours, $requiredHours);
    if ($stmt->execute()) {
        echo json_encode([
            "status" => "success",
            "user_id" => $userId,
            "rendered_hours" => $renderedHours,
            "required_hours" => $requiredHours
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => $stmt->error]);
    }
    $stmt->close();
} else {
    echo json_encode(["status" => "error", "message" => $conn->error]);
}
