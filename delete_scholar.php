<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$userId = (int) request_value('user_id', 0);

if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

$stmt = db_prepare($conn, 'DELETE FROM users WHERE user_id = ?');
$stmt->bind_param('i', $userId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to delete scholar: ' . $error, 500);
}

$deleted = $stmt->affected_rows;
$stmt->close();

if ($deleted === 0) {
    respond_error('Scholar not found', 404);
}

respond_success(['message' => 'Scholar deleted']);
