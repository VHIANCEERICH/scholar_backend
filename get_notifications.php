<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

$userId = (int) ($_GET['user_id'] ?? request_value('user_id', 0));
$sql = 'SELECT notification_id, user_id, message, is_read, created_at FROM notifications';

if ($userId > 0) {
    $sql .= ' WHERE user_id = ? ORDER BY created_at DESC';
    $stmt = db_prepare($conn, $sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $notifications = fetch_all_assoc($stmt);
    $stmt->close();
} else {
    $sql .= ' ORDER BY created_at DESC';
    $result = $conn->query($sql);
    if (!$result) {
        respond_error('Failed to retrieve notifications: ' . $conn->error, 500);
    }
    $notifications = $result->fetch_all(MYSQLI_ASSOC);
}

foreach ($notifications as &$notification) {
    $notification['notification_id'] = (int) $notification['notification_id'];
    $notification['user_id'] = (int) $notification['user_id'];
    $notification['is_read'] = (int) $notification['is_read'];
}
unset($notification);

respond_success(['data' => $notifications]);
