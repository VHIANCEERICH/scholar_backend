<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$data = require_fields(['notification_id', 'status']);

$notificationId = (int) $data['notification_id'];
$status = strtolower(trim((string) $data['status']));
$userId = (int) ($data['user_id'] ?? 0);

if ($notificationId <= 0) {
    respond_error('Invalid notification_id', 422);
}

if ($status === 'read' || $status === 'unread') {
    $isRead = $status === 'read' ? 1 : 0;
    $stmt = db_prepare($conn, 'UPDATE notifications SET is_read = ? WHERE notification_id = ?');
    $stmt->bind_param('ii', $isRead, $notificationId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        respond_error('Failed to update notification: ' . $error, 500);
    }
    $stmt->close();
    respond_success(['message' => 'Notification updated']);
}

if ($status === 'archived') {
    $hasArchivedColumn = false;
    try {
        $result = $conn->query("SHOW COLUMNS FROM notifications LIKE 'archived'");
        $hasArchivedColumn = $result instanceof mysqli_result && $result->num_rows > 0;
    } catch (Throwable $_) {
        $hasArchivedColumn = false;
    }

    if (!$hasArchivedColumn) {
        respond_error(
            'Archive not available: missing notifications.archived column. Run the migration SQL to add archived/archived_at.',
            501
        );
    }

    if ($userId > 0) {
        $stmt = db_prepare(
            $conn,
            'UPDATE notifications SET archived = 1, archived_at = NOW() WHERE notification_id = ? AND user_id = ?'
        );
        $stmt->bind_param('ii', $notificationId, $userId);
    } else {
        $stmt = db_prepare(
            $conn,
            'UPDATE notifications SET archived = 1, archived_at = NOW() WHERE notification_id = ?'
        );
        $stmt->bind_param('i', $notificationId);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        respond_error('Failed to archive notification: ' . $error, 500);
    }

    $updated = $stmt->affected_rows;
    $stmt->close();

    if ($updated === 0) {
        respond_error('Notification not found or already archived', 404);
    }

    respond_success(['message' => 'Notification archived']);
}

respond_error('Invalid status', 422, ['allowed' => ['read', 'unread', 'archived']]);
