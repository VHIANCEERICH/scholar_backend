<?php
declare(strict_types=1);

require_once __DIR__ . '/chat_common.php';

require_method('POST');
no_store_cache();
ensure_chat_messages_table($conn);

$data = require_fields(['sender_id', 'receiver_id', 'message']);
$senderId = (int) ($data['sender_id'] ?? 0);
$receiverId = (int) ($data['receiver_id'] ?? 0);
$message = trim((string) ($data['message'] ?? ''));

if ($message === '') {
    respond_error('Message is required', 422);
}
if (mb_strlen($message) > 4000) {
    respond_error('Message is too long', 422);
}

[$sender, $receiver] = validate_chat_pair($conn, $senderId, $receiverId);

$conn->begin_transaction();

try {
    $stmt = db_prepare(
        $conn,
        'INSERT INTO chat_messages (sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, 0)'
    );
    $stmt->bind_param('iis', $senderId, $receiverId, $message);
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to send chat message: ' . $stmt->error);
    }
    $chatId = (int) $stmt->insert_id;
    $stmt->close();

    $preview = mb_substr($message, 0, 180);
    $notifPayload = '[TYPE:chat][CHAT_ID:' . $chatId . '][CHAT_FROM:' . $senderId . '][CHAT_TO:' . $receiverId . "]\n"
        . $sender['display_name'] . ': ' . $preview;

    $nStmt = db_prepare($conn, 'INSERT INTO notifications (user_id, message) VALUES (?, ?)');
    $nStmt->bind_param('is', $receiverId, $notifPayload);
    if (!$nStmt->execute()) {
        throw new RuntimeException('Failed to queue chat notification: ' . $nStmt->error);
    }
    $nStmt->close();

    $conn->commit();

    respond_success([
        'message' => 'Chat message sent',
        'chat_id' => $chatId,
        'sender' => $sender,
        'receiver' => $receiver,
    ], 201);
} catch (Throwable $e) {
    $conn->rollback();
    respond_error($e->getMessage(), 500);
}
