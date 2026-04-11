<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

function notifications_has_archive_columns(mysqli $conn): bool
{
    try {
        $result = $conn->query("SHOW COLUMNS FROM notifications LIKE 'archived'");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    } catch (Throwable $_) {
        return false;
    }
}

function update_notification_read_state(mysqli $conn, int $notificationId, bool $isRead): void
{
    $readState = $isRead ? 1 : 0;
    $stmt = db_prepare($conn, 'UPDATE notifications SET is_read = ? WHERE notification_id = ?');
    $stmt->bind_param('ii', $readState, $notificationId);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        respond_error('Failed to update notification: ' . $error, 500);
    }

    $stmt->close();
}

function archive_notification_by_id(mysqli $conn, int $notificationId, int $userId = 0): void
{
    if (!notifications_has_archive_columns($conn)) {
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
}
