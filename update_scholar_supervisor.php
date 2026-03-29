<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$data = require_fields(['user_id']);

$userId = (int) ($data['user_id'] ?? 0);
$supervisor = trim((string) ($data['supervisor'] ?? ''));

if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

$stmt = db_prepare(
    $conn,
    'UPDATE scholars SET supervisor = ? WHERE user_id = ?'
);
$stmt->bind_param('si', $supervisor, $userId);
$stmt->execute();

if ($stmt->errno) {
    $message = $stmt->error;
    $stmt->close();
    respond_error('Update failed: ' . $message, 500);
}

if ($stmt->affected_rows === 0) {
    $checkStmt = db_prepare(
        $conn,
        'SELECT supervisor FROM scholars WHERE user_id = ? LIMIT 1'
    );
    $checkStmt->bind_param('i', $userId);
    $checkStmt->execute();
    $row = $checkStmt->get_result()?->fetch_assoc();
    $checkStmt->close();

    if (!$row) {
        $stmt->close();
        respond_error('Scholar not found for this user_id', 404);
    }
}

$stmt->close();

respond_success([
    'message' => 'Supervisor updated successfully',
    'user_id' => $userId,
    'supervisor' => $supervisor,
]);
