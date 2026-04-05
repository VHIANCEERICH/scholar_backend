<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

function ensure_announcement_comments_table(mysqli $conn): void
{
    if (db_table_exists($conn, 'announcement_comments')) {
        return;
    }

    $sql = "
        CREATE TABLE announcement_comments (
            comment_id INT AUTO_INCREMENT PRIMARY KEY,
            announcement_id INT NOT NULL,
            user_id INT NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_announcement_created (announcement_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($sql)) {
        respond_error('Failed to create announcement_comments table: ' . $conn->error, 500);
    }
}

require_method('POST');
ensure_announcement_comments_table($conn);

$data = require_fields(['announcement_id', 'user_id', 'message']);
$announcementId = (int) ($data['announcement_id'] ?? 0);
$userId = (int) ($data['user_id'] ?? 0);
$message = trim((string) ($data['message'] ?? ''));

if ($announcementId <= 0) {
    respond_error('Invalid announcement_id', 422);
}

if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

if ($message === '') {
    respond_error('Comment message is required', 422);
}

if (!db_table_exists($conn, 'announcements')) {
    respond_error('Announcements table does not exist', 500);
}

$checkStmt = db_prepare($conn, 'SELECT announcement_id FROM announcements WHERE announcement_id = ? LIMIT 1');
$checkStmt->bind_param('i', $announcementId);
$checkStmt->execute();
$exists = $checkStmt->get_result()?->fetch_assoc();
$checkStmt->close();
if (!$exists) {
    respond_error('Announcement not found', 404);
}

$stmt = db_prepare(
    $conn,
    'INSERT INTO announcement_comments (announcement_id, user_id, message) VALUES (?, ?, ?)'
);
$stmt->bind_param('iis', $announcementId, $userId, $message);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to save comment: ' . $error, 500);
}

$commentId = (int) $stmt->insert_id;
$stmt->close();

// Mirror scholar comments into replies so Admin Notifications can display them.
$mirroredReplyId = 0;
if (db_table_exists($conn, 'replies') && db_table_exists($conn, 'notifications')) {
    $notificationId = 0;
    $announcementMarker = 'ANNOUNCEMENT_ID:' . $announcementId . '%';

    $nStmt = db_prepare(
        $conn,
        'SELECT notification_id
         FROM notifications
         WHERE user_id = ?
           AND message LIKE ?
         ORDER BY notification_id DESC
         LIMIT 1'
    );
    $nStmt->bind_param('is', $userId, $announcementMarker);
    $nStmt->execute();
    $nRow = $nStmt->get_result()?->fetch_assoc();
    $nStmt->close();
    $notificationId = (int) ($nRow['notification_id'] ?? 0);

    // Fallback: create a notification record for this scholar if not found,
    // then attach the reply to keep thread/admin-notification wiring intact.
    if ($notificationId <= 0) {
        $aStmt = db_prepare(
            $conn,
            'SELECT title, message FROM announcements WHERE announcement_id = ? LIMIT 1'
        );
        $aStmt->bind_param('i', $announcementId);
        $aStmt->execute();
        $aRow = $aStmt->get_result()?->fetch_assoc();
        $aStmt->close();

        $announcementTitle = trim((string) ($aRow['title'] ?? ''));
        $announcementBody = trim((string) ($aRow['message'] ?? ''));
        $payload = 'ANNOUNCEMENT_ID:' . $announcementId . "\n" .
            ($announcementTitle !== ''
                ? ($announcementTitle . ($announcementBody !== '' ? "\n" . $announcementBody : ''))
                : $announcementBody);

        $insertNotification = db_prepare(
            $conn,
            'INSERT INTO notifications (user_id, message) VALUES (?, ?)'
        );
        $insertNotification->bind_param('is', $userId, $payload);
        if ($insertNotification->execute()) {
            $notificationId = (int) $insertNotification->insert_id;
        }
        $insertNotification->close();
    }

    if ($notificationId > 0) {
        $visibility = 'admin';
        $rStmt = db_prepare(
            $conn,
            'INSERT INTO replies (notification_id, user_id, message, visibility) VALUES (?, ?, ?, ?)'
        );
        $rStmt->bind_param('iiss', $notificationId, $userId, $message, $visibility);
        if ($rStmt->execute()) {
            $mirroredReplyId = (int) $rStmt->insert_id;
        }
        $rStmt->close();
    }
}

respond_success([
    'message' => 'Comment saved successfully',
    'comment_id' => $commentId,
    'mirrored_reply_id' => $mirroredReplyId,
]);
