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

function resolve_announcement_category_aliases(string $target): array
{
    $targetLower = strtolower($target);
    if (str_contains($targetLower, 'student assistant')) {
        return ['student_assistant', 'student assistant'];
    }
    if (str_contains($targetLower, 'academic')) {
        return ['academic', 'academic_scholar'];
    }
    if (str_contains($targetLower, 'varsity')) {
        return ['varsity', 'varsity_scholar'];
    }
    if (str_contains($targetLower, 'gift')) {
        return ['gift_of_education'];
    }

    return [];
}

$categoryAliases = $targetUserId > 0 ? [] : resolve_announcement_category_aliases($target);

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

    if ($targetUserId > 0) {
        $notificationStmt = db_prepare(
            $conn,
            "INSERT INTO notifications (user_id, message)
             SELECT user_id, ?
             FROM users
             WHERE user_id = ?
               AND role = 'scholar'
               AND is_active = 1"
        );
        $notificationStmt->bind_param('si', $announcementBody, $targetUserId);
    } elseif ($categoryAliases !== []) {
        $placeholders = implode(',', array_fill(0, count($categoryAliases), '?'));
        $types = 's' . str_repeat('s', count($categoryAliases));
        $notificationStmt = db_prepare(
            $conn,
            "INSERT INTO notifications (user_id, message)
             SELECT u.user_id, ?
             FROM users u
             INNER JOIN scholars s ON s.user_id = u.user_id
             WHERE u.role = 'scholar'
               AND u.is_active = 1
               AND LOWER(TRIM(COALESCE(s.scholarship_category, ''))) IN ($placeholders)"
        );
        $notificationStmt->bind_param($types, $announcementBody, ...$categoryAliases);
    } else {
        $notificationStmt = db_prepare(
            $conn,
            "INSERT INTO notifications (user_id, message)
             SELECT user_id, ?
             FROM users
             WHERE role = 'scholar'
               AND is_active = 1"
        );
        $notificationStmt->bind_param('s', $announcementBody);
    }

    if (!$notificationStmt->execute()) {
        throw new RuntimeException('Failed to send notification: ' . $notificationStmt->error);
    }
    $inserted = $notificationStmt->affected_rows;
    $notificationStmt->close();

    if ($inserted === 0) {
        throw new RuntimeException('No scholar users found');
    }

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
