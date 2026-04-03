<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$userId = (int) request_value('user_id', 0);

if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

// Soft-delete: mark inactive so the account cannot log in anymore.
$stmt = db_prepare($conn, 'UPDATE users SET is_active = 0 WHERE user_id = ?');
$stmt->bind_param('i', $userId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to archive scholar: ' . $error, 500);
}

$updated = $stmt->affected_rows;
$stmt->close();

if ($updated === 0) {
    respond_error('Scholar not found', 404);
}

respond_success(['message' => 'Scholar archived']);