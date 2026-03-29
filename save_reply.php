<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

if (!db_table_exists($conn, 'replies')) {
    respond_error('Replies module is unavailable because the replies table does not exist', 500);
}

require_method('POST');
$data = require_fields(['notification_id', 'message']);

$notificationId = (int) $data['notification_id'];
$message = trim((string) $data['message']);
$userId = (int) ($data['user_id'] ?? 0);
$visibility = trim((string) ($data['visibility'] ?? 'admin'));

if ($notificationId <= 0) {
    respond_error('Invalid notification_id', 422);
}

if ($message === '') {
    respond_error('Reply message is required', 422);
}

$visibility = strtolower($visibility);
if ($visibility === 'all scholars') {
    $visibility = 'all';
}
if ($visibility !== 'all') {
    $visibility = 'admin';
}

if ($userId > 0) {
    $stmt = db_prepare($conn, 'INSERT INTO replies (notification_id, user_id, message, visibility) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('iiss', $notificationId, $userId, $message, $visibility);
} else {
    $stmt = db_prepare($conn, 'INSERT INTO replies (notification_id, message, visibility) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $notificationId, $message, $visibility);
}

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to save reply: ' . $error, 500);
}

$replyId = $stmt->insert_id;
$stmt->close();

respond_success([
    'message' => 'Reply saved successfully',
    'reply_id' => $replyId,
], 201);