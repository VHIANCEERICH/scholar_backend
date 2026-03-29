<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

if (!db_table_exists($conn, 'replies')) {
    respond_error('Replies module is unavailable because the replies table does not exist', 500);
}

$notificationId = (int) ($_GET['notification_id'] ?? request_value('notification_id', 0));

$sql = "
    SELECT
        r.reply_id,
        r.notification_id,
        r.user_id,
        r.message AS reply_message,
        r.visibility,
        r.created_at AS reply_created_at,
        n.message AS notification_message,
        n.created_at AS notification_created_at,
        u.username,
        u.email
    FROM replies r
    LEFT JOIN notifications n ON n.notification_id = r.notification_id
    LEFT JOIN users u ON u.user_id = r.user_id
";

if ($notificationId > 0) {
    $sql .= ' WHERE r.notification_id = ? ORDER BY r.created_at DESC';
    $stmt = db_prepare($conn, $sql);
    $stmt->bind_param('i', $notificationId);
    $stmt->execute();
    $replies = fetch_all_assoc($stmt);
    $stmt->close();
} else {
    $sql .= ' ORDER BY r.created_at DESC';
    $result = $conn->query($sql);
    if (!$result) {
        respond_error('Failed to retrieve replies: ' . $conn->error, 500);
    }
    $replies = $result->fetch_all(MYSQLI_ASSOC);
}

respond_success(['data' => $replies]);