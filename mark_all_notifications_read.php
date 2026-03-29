<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$userId = (int) request_value('user_id', 0);
$isRead = (int) request_value('is_read', 1);

if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

$stmt = db_prepare($conn, 'UPDATE notifications SET is_read = ? WHERE user_id = ?');
$stmt->bind_param('ii', $isRead, $userId);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to update notifications: ' . $error, 500);
}
$stmt->close();

respond_success(['message' => 'Notifications updated']);