<?php
declare(strict_types=1);

require_once __DIR__ . '/notification_common.php';

require_method('POST');
$data = require_fields(['notification_id', 'status']);

$notificationId = (int) $data['notification_id'];
$status = strtolower(trim((string) $data['status']));
$userId = (int) ($data['user_id'] ?? 0);

if ($notificationId <= 0) {
    respond_error('Invalid notification_id', 422);
}

if ($status === 'read' || $status === 'unread') {
    update_notification_read_state($conn, $notificationId, $status === 'read');
    respond_success(['message' => 'Notification updated']);
}

if ($status === 'archived') {
    archive_notification_by_id($conn, $notificationId, $userId);
    respond_success(['message' => 'Notification archived']);
}

respond_error('Invalid status', 422, ['allowed' => ['read', 'unread', 'archived']]);
