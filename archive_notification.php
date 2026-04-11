<?php
declare(strict_types=1);

require_once __DIR__ . '/notification_common.php';

require_method('POST');
$data = require_fields(['notification_id']);

$notificationId = (int) $data['notification_id'];
$userId = (int) ($data['user_id'] ?? 0);

if ($notificationId <= 0) {
    respond_error('Invalid notification_id', 422);
}

archive_notification_by_id($conn, $notificationId, $userId);
respond_success(['message' => 'Notification archived']);
