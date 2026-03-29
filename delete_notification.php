<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$notificationId = (int) request_value('notification_id', 0);

if ($notificationId <= 0) {
    respond_error('Invalid notification_id', 422);
}

$stmt = db_prepare($conn, 'DELETE FROM notifications WHERE notification_id = ?');
$stmt->bind_param('i', $notificationId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to delete notification: ' . $error, 500);
}

$deleted = $stmt->affected_rows;
$stmt->close();

if ($deleted === 0) {
    respond_error('Notification not found', 404);
}

respond_success(['message' => 'Notification deleted']);
