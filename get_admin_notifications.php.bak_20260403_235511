<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

if (!db_table_exists($conn, 'replies')) {
    respond_success(['data' => []]);
}

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
        u.email,
        s.profile_image
    FROM replies r
    LEFT JOIN notifications n ON n.notification_id = r.notification_id
    LEFT JOIN users u ON u.user_id = r.user_id
    LEFT JOIN scholars s ON s.user_id = r.user_id
    ORDER BY r.created_at DESC
";

$result = $conn->query($sql);
if (!$result) {
    respond_error('Failed to retrieve admin notifications: ' . $conn->error, 500);
}

$items = [];
while ($row = $result->fetch_assoc()) {
    $row['reply_id'] = (int) $row['reply_id'];
    $row['notification_id'] = (int) ($row['notification_id'] ?? 0);
    $row['user_id'] = isset($row['user_id']) ? (int) $row['user_id'] : 0;
    $row['profile_image_url'] = make_public_file_url((string) ($row['profile_image'] ?? ''));
    $items[] = $row;
}

respond_success(['data' => $items]);