<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$data = require_fields(['notification_id']);

$notificationId = (int) $data['notification_id'];
$isRead = (int) ($data['is_read'] ?? 1);

$stmt = db_prepare($conn, 'UPDATE notifications SET is_read = ? WHERE notification_id = ?');
$stmt->bind_param('ii', $isRead, $notificationId);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to update notification: ' . $error, 500);
}
$stmt->close();

respond_success(['message' => 'Notification updated']);