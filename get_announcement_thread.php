<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

if (!db_table_exists($conn, 'announcements')) {
    respond_error('Announcements module is unavailable because the announcements table does not exist', 500);
}

if (!db_table_exists($conn, 'announcement_comments')) {
    // comments are optional; return announcement with empty comments.
}

$announcementId = (int) ($_GET['announcement_id'] ?? request_value('announcement_id', 0));
if ($announcementId <= 0) {
    respond_error('Invalid announcement_id', 422);
}

$stmt = db_prepare(
    $conn,
    'SELECT announcement_id, title, message, target, target_user_id, created_at FROM announcements WHERE announcement_id = ? LIMIT 1'
);
$stmt->bind_param('i', $announcementId);
$stmt->execute();
$announcement = $stmt->get_result()?->fetch_assoc();
$stmt->close();

if (!$announcement) {
    respond_error('Announcement not found', 404);
}

$comments = [];
if (db_table_exists($conn, 'announcement_comments')) {
    $cStmt = db_prepare(
        $conn,
        "
        SELECT
            c.comment_id,
            c.announcement_id,
            c.user_id,
            c.message,
            c.created_at,
            u.username,
            u.role
        FROM announcement_comments c
        LEFT JOIN users u ON u.user_id = c.user_id
        WHERE c.announcement_id = ?
        ORDER BY c.created_at ASC
        "
    );
    $cStmt->bind_param('i', $announcementId);
    $cStmt->execute();
    $comments = fetch_all_assoc($cStmt);
    $cStmt->close();
}

respond_success([
    'announcement' => $announcement,
    'comments' => $comments,
]);