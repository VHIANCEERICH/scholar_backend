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

if ($targetUserId > 0) {
    $recipientStmt = db_prepare(
        $conn,
        "SELECT user_id FROM users WHERE user_id = ? AND role = 'scholar' AND is_active = 1"
    );
    $recipientStmt->bind_param('i', $targetUserId);
    $recipientStmt->execute();
    $recipientResult = $recipientStmt->get_result();
} else {
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

    if ($categoryFilter !== '') {
        $categoryAliases = [$categoryFilter];
        if ($categoryFilter === 'academic') {
            $categoryAliases[] = 'academic_scholar';
        } elseif ($categoryFilter === 'varsity') {
            $categoryAliases[] = 'varsity_scholar';
        } elseif ($categoryFilter === 'student_assistant') {
            $categoryAliases[] = 'student assistant';
        }

        $placeholders = implode(',', array_fill(0, count($categoryAliases), '?'));
        $types = str_repeat('s', count($categoryAliases));
        $recipientStmt = db_prepare(
            $conn,
            "SELECT u.user_id
             FROM users u
             INNER JOIN scholars s ON s.user_id = u.user_id
             WHERE u.role = 'scholar'
               AND u.is_active = 1
               AND LOWER(TRIM(COALESCE(s.scholarship_category, ''))) IN ($placeholders)"
        );
        $recipientStmt->bind_param($types, ...$categoryAliases);
        $recipientStmt->execute();
        $recipientResult = $recipientStmt->get_result();
    } else {
        $recipientResult = $conn->query("SELECT user_id FROM users WHERE role = 'scholar' AND is_active = 1");
    }
}

if (!$recipientResult) {
    respond_error('Failed to load recipients: ' . $conn->error, 500);
}

if ($recipientResult->num_rows === 0) {
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

    $notificationStmt = db_prepare($conn, 'INSERT INTO notifications (user_id, message) VALUES (?, ?)');
    $inserted = 0;

    while ($recipient = $recipientResult->fetch_assoc()) {
        $userId = (int) $recipient['user_id'];
        $notificationStmt->bind_param('is', $userId, $announcementBody);
        if (!$notificationStmt->execute()) {
            throw new RuntimeException('Failed to send notification: ' . $notificationStmt->error);
        }
        $inserted++;
    }

    $notificationStmt->close();
    if (isset($recipientStmt)) {
        $recipientStmt->close();
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
