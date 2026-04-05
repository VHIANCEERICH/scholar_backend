<?php
declare(strict_types=1);

require_once __DIR__ . '/chat_common.php';

require_method('GET');
no_store_cache();
ensure_chat_messages_table($conn);

$userId = (int) ($_GET['user_id'] ?? request_value('user_id', 0));
$limit = isset($_GET['limit']) ? max(1, min(300, (int) $_GET['limit'])) : 80;

if ($userId <= 0) {
    respond_error('user_id is required', 422);
}

$user = fetch_user_summary($conn, $userId);
if (!$user || $user['is_active'] !== 1) {
    respond_error('User not found or inactive', 404);
}

$role = $user['role'];
if (!in_array($role, ['admin', 'staff', 'scholar'], true)) {
    respond_error('Unauthorized role', 403);
}

$sql = "
    SELECT
        CASE
            WHEN sender_id = ? THEN receiver_id
            ELSE sender_id
        END AS peer_id,
        MAX(created_at) AS last_message_at,
        SUBSTRING_INDEX(
            GROUP_CONCAT(message ORDER BY created_at DESC SEPARATOR '\\n'),
            '\\n',
            1
        ) AS last_message,
        SUM(CASE WHEN receiver_id = ? AND is_read = 0 THEN 1 ELSE 0 END) AS unread_count
    FROM chat_messages
    WHERE sender_id = ? OR receiver_id = ?
    GROUP BY peer_id
    ORDER BY last_message_at DESC
    LIMIT ?
";

$stmt = db_prepare($conn, $sql);
$stmt->bind_param('iiiii', $userId, $userId, $userId, $userId, $limit);
$stmt->execute();
$rows = fetch_all_assoc($stmt);
$stmt->close();

$data = [];
foreach ($rows as $row) {
    $peerId = (int) ($row['peer_id'] ?? 0);
    if ($peerId <= 0) {
        continue;
    }

    $peer = fetch_user_summary($conn, $peerId);
    if (!$peer || $peer['is_active'] !== 1) {
        continue;
    }

    $peerIsAdmin = in_array($peer['role'], ['admin', 'staff'], true);
    $peerIsScholar = $peer['role'] === 'scholar';

    // enforce admin/staff <-> scholar only
    $valid = ($peerIsAdmin && $role === 'scholar') || ($peerIsScholar && in_array($role, ['admin', 'staff'], true));
    if (!$valid) {
        continue;
    }

    $data[] = [
        'peer_id' => $peerId,
        'peer_name' => $peer['display_name'],
        'peer_role' => $peer['role'],
        'peer_category' => $peer['scholarship_category'],
        'last_message' => (string) ($row['last_message'] ?? ''),
        'last_message_at' => (string) ($row['last_message_at'] ?? ''),
        'unread_count' => (int) ($row['unread_count'] ?? 0),
    ];
}

usort($data, static function (array $a, array $b): int {
    $ta = strtotime((string) ($a['last_message_at'] ?? '')) ?: 0;
    $tb = strtotime((string) ($b['last_message_at'] ?? '')) ?: 0;
    return $tb <=> $ta;
});

respond_success(['data' => $data]);
