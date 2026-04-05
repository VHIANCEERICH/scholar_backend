<?php
declare(strict_types=1);

require_once __DIR__ . '/chat_common.php';

require_method('POST');
no_store_cache();
ensure_chat_messages_table($conn);

$data = require_fields(['user_id', 'peer_id']);
$userId = (int) ($data['user_id'] ?? 0);
$peerId = (int) ($data['peer_id'] ?? 0);

validate_chat_pair($conn, $userId, $peerId);

$stmt = db_prepare(
    $conn,
    'UPDATE chat_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0'
);
$stmt->bind_param('ii', $peerId, $userId);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to mark chat as read: ' . $error, 500);
}
$affected = $stmt->affected_rows;
$stmt->close();

respond_success([
    'message' => 'Chat messages marked as read',
    'updated' => (int) $affected,
]);
