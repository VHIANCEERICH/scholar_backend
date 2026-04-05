<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

function ensure_chat_messages_table(mysqli $conn): void
{
    if (db_table_exists($conn, 'chat_messages')) {
        return;
    }

    $sql = "
        CREATE TABLE chat_messages (
            chat_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_pair_time (sender_id, receiver_id, created_at),
            INDEX idx_receiver_read (receiver_id, is_read, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($sql)) {
        respond_error('Failed to create chat_messages table: ' . $conn->error, 500);
    }
}

function fetch_user_summary(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = db_prepare(
        $conn,
        "
        SELECT
            u.user_id,
            u.role,
            u.is_active,
            COALESCE(NULLIF(CONCAT_WS(' ', sc.first_name, sc.last_name), ''), u.username, u.email, CONCAT('User #', u.user_id)) AS display_name,
            COALESCE(sc.scholarship_category, '') AS scholarship_category
        FROM users u
        LEFT JOIN scholars sc ON sc.user_id = u.user_id
        WHERE u.user_id = ?
        LIMIT 1
        "
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'user_id' => (int) ($row['user_id'] ?? 0),
        'role' => strtolower((string) ($row['role'] ?? '')),
        'is_active' => (int) ($row['is_active'] ?? 0),
        'display_name' => (string) ($row['display_name'] ?? ''),
        'scholarship_category' => normalize_scholarship_category((string) ($row['scholarship_category'] ?? '')),
    ];
}

function validate_chat_pair(mysqli $conn, int $senderId, int $receiverId): array
{
    if ($senderId <= 0 || $receiverId <= 0 || $senderId === $receiverId) {
        respond_error('Invalid sender_id/receiver_id', 422);
    }

    $sender = fetch_user_summary($conn, $senderId);
    $receiver = fetch_user_summary($conn, $receiverId);

    if (!$sender || !$receiver) {
        respond_error('Chat user not found', 404);
    }
    if ($sender['is_active'] !== 1 || $receiver['is_active'] !== 1) {
        respond_error('One of the users is inactive', 403);
    }

    $senderRole = $sender['role'];
    $receiverRole = $receiver['role'];

    $senderIsAdmin = in_array($senderRole, ['admin', 'staff'], true);
    $receiverIsAdmin = in_array($receiverRole, ['admin', 'staff'], true);
    $senderIsScholar = $senderRole === 'scholar';
    $receiverIsScholar = $receiverRole === 'scholar';

    if (!(($senderIsAdmin && $receiverIsScholar) || ($senderIsScholar && $receiverIsAdmin))) {
        respond_error('Only admin/staff <-> scholar chat is allowed', 403);
    }

    return [$sender, $receiver];
}
