<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

function ensure_announcements_table(mysqli $conn): void
{
    if (db_table_exists($conn, 'announcements')) {
        return;
    }

    $sql = "
        CREATE TABLE announcements (
            announcement_id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL DEFAULT '',
            message LONGTEXT NOT NULL,
            target VARCHAR(120) NOT NULL DEFAULT 'All Scholars',
            target_user_id INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($sql)) {
        respond_error('Failed to create announcements table: ' . $conn->error, 500);
    }
}

function build_recipient_scope(mysqli $conn, int $targetUserId, string $target): array
{
    if ($targetUserId > 0) {
        return [
            'join' => '',
            'where' => "u.user_id = ? AND u.role = 'scholar' AND u.is_active = 1",
            'types' => 'i',
            'params' => [$targetUserId],
        ];
    }

    $categoryFilter = '';
    $targetLower = strtolower($target);
    if (str_contains($targetLower, 'student assistant')) {
        $categoryFilter = 'student_assistant';
    } elseif (str_contains($targetLower, 'academic')) {
        $categoryFilter = 'academic';
    } elseif (str_contains($targetLower, 'varsity')) {
        $categoryFilter = 'varsity';
    } elseif (str_contains($targetLower, 'gift')) {
        $categoryFilter = 'gift_of_education';
    }

    if ($categoryFilter === '') {
        return [
            'join' => '',
            'where' => "u.role = 'scholar' AND u.is_active = 1",
            'types' => '',
            'params' => [],
        ];
    }

    $categoryAliases = [$categoryFilter];
    if ($categoryFilter === 'academic') {
        $categoryAliases[] = 'academic_scholar';
    } elseif ($categoryFilter === 'varsity') {
        $categoryAliases[] = 'varsity_scholar';
    } elseif ($categoryFilter === 'student_assistant') {
        $categoryAliases[] = 'student assistant';
    }

    $placeholders = implode(',', array_fill(0, count($categoryAliases), '?'));

    return [
        'join' => 'INNER JOIN scholars s ON s.user_id = u.user_id',
        'where' => "u.role = 'scholar'
            AND u.is_active = 1
            AND LOWER(TRIM(COALESCE(s.scholarship_category, ''))) IN ($placeholders)",
        'types' => str_repeat('s', count($categoryAliases)),
        'params' => $categoryAliases,
    ];
}

function bind_dynamic_params(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $bindArgs = [$types];
    foreach ($params as $index => $value) {
        $bindArgs[] = &$params[$index];
    }

    $stmt->bind_param(...$bindArgs);
}

require_method('POST');
ensure_announcements_table($conn);
$data = request_data();

$title = trim((string) ($data['title'] ?? $data['notification_title'] ?? ''));
$message = trim((string) ($data['message'] ?? $data['content'] ?? ''));
$target = trim((string) ($data['target'] ?? 'All Scholars'));
$targetUserId = (int) ($data['target_user_id'] ?? 0);

if ($message === '') {
    respond_error('Announcement message is required', 422);
}
$recipientScope = build_recipient_scope($conn, $targetUserId, $target);
$recipientCountSql = sprintf(
    'SELECT COUNT(*) AS total FROM users u %s WHERE %s',
    $recipientScope['join'],
    $recipientScope['where']
);
$recipientCountStmt = db_prepare($conn, $recipientCountSql);
bind_dynamic_params($recipientCountStmt, $recipientScope['types'], $recipientScope['params']);
$recipientCountStmt->execute();
$recipientCount = (int) (($recipientCountStmt->get_result()?->fetch_assoc()['total'] ?? 0));
$recipientCountStmt->close();

if ($recipientCount <= 0) {
    respond_error('No scholar users found', 404);
}

$conn->begin_transaction();

try {
    $announcementStmt = db_prepare(
        $conn,
        'INSERT INTO announcements (title, message, target, target_user_id) VALUES (?, ?, ?, ?)'
    );
    $announcementStmt->bind_param('sssi', $title, $message, $target, $targetUserId);
    if (!$announcementStmt->execute()) {
        throw new RuntimeException('Failed to save announcement: ' . $announcementStmt->error);
    }
    $announcementId = (int) $announcementStmt->insert_id;
    $announcementStmt->close();

    $announcementBody = "ANNOUNCEMENT_ID:" . $announcementId . "\n";
    $announcementBody .= $title !== '' ? ($title . "\n" . $message) : $message;

    $notificationInsertSql = sprintf(
        'INSERT INTO notifications (user_id, message)
         SELECT u.user_id, ?
         FROM users u %s
         WHERE %s',
        $recipientScope['join'],
        $recipientScope['where']
    );
    $notificationStmt = db_prepare($conn, $notificationInsertSql);
    $types = 's' . $recipientScope['types'];
    $params = array_merge([$announcementBody], $recipientScope['params']);
    bind_dynamic_params($notificationStmt, $types, $params);
    if (!$notificationStmt->execute()) {
        throw new RuntimeException('Failed to send notification: ' . $notificationStmt->error);
    }
    $inserted = $notificationStmt->affected_rows;
    $notificationStmt->close();

    $conn->commit();

    respond_success(
        [
            'message' => 'Announcement saved successfully',
            'announcement_id' => $announcementId,
            'inserted' => $inserted,
        ],
        201
    );
} catch (Throwable $e) {
    $conn->rollback();
    respond_error($e->getMessage(), 500);
}
