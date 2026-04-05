<?php
declare(strict_types=1);

require_once __DIR__ . '/chat_common.php';

require_method('GET');
no_store_cache();
ensure_chat_messages_table($conn);

$userId = (int) ($_GET['user_id'] ?? request_value('user_id', 0));
$peerId = (int) ($_GET['peer_id'] ?? request_value('peer_id', 0));
$limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 200;
$markRead = (int) ($_GET['mark_read'] ?? 1);

[$user, $peer] = validate_chat_pair($conn, $userId, $peerId);

$stmt = db_prepare(
    $conn,
    "
    SELECT chat_id, sender_id, receiver_id, message, created_at, is_read
    FROM chat_messages
    WHERE (sender_id = ? AND receiver_id = ?)
       OR (sender_id = ? AND receiver_id = ?)
    ORDER BY created_at ASC
    LIMIT ?
    "
);
$stmt->bind_param('iiiii', $userId, $peerId, $peerId, $userId, $limit);
$stmt->execute();
$rows = fetch_all_assoc($stmt);
$stmt->close();

if ($markRead === 1) {
    $readStmt = db_prepare(
        $conn,
        'UPDATE chat_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0'
    );
    $readStmt->bind_param('ii', $peerId, $userId);
    $readStmt->execute();
    $readStmt->close();
}

$data = [];
foreach ($rows as $row) {
    $data[] = [
        'chat_id' => (int) ($row['chat_id'] ?? 0),
        'sender_id' => (int) ($row['sender_id'] ?? 0),
        'receiver_id' => (int) ($row['receiver_id'] ?? 0),
        'message' => (string) ($row['message'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'is_read' => (int) ($row['is_read'] ?? 0),
        'direction' => ((int) ($row['sender_id'] ?? 0) === $userId) ? 'outgoing' : 'incoming',
    ];
}

respond_success([
    'peer' => $peer,
    'messages' => $data,
]);
