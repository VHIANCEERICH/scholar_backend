<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('GET');

$userId = (int) ($_GET['user_id'] ?? request_value('user_id', 0));
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 80;
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
$includeArchived = (int) ($_GET['include_archived'] ?? request_value('include_archived', 0));

$limit = max(1, min(200, $limit));
$offset = max(0, $offset);

header('Cache-Control: public, max-age=10');

$hasArchivedColumn = false;
try {
    $result = $conn->query("SHOW COLUMNS FROM notifications LIKE 'archived'");
    $hasArchivedColumn = $result instanceof mysqli_result && $result->num_rows > 0;
} catch (Throwable $_) {
    $hasArchivedColumn = false;
}

$select = 'notification_id, user_id, message, is_read, created_at';
if ($hasArchivedColumn) {
    $select .= ', archived, archived_at';
}

$whereArchived = ($hasArchivedColumn && $includeArchived !== 1) ? ' AND archived = 0' : '';

if ($userId > 0) {
    $sql = "SELECT {$select} FROM notifications WHERE user_id = ?{$whereArchived} ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $stmt = db_prepare($conn, $sql);
    $stmt->bind_param('iii', $userId, $limit, $offset);
    $stmt->execute();
    $notifications = fetch_all_assoc($stmt);
    $stmt->close();
} else {
    $sql = "SELECT {$select} FROM notifications WHERE 1=1{$whereArchived} ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $stmt = db_prepare($conn, $sql);
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $notifications = fetch_all_assoc($stmt);
    $stmt->close();
}

foreach ($notifications as &$notification) {
    $notification['notification_id'] = (int) $notification['notification_id'];
    $notification['user_id'] = (int) $notification['user_id'];
    $notification['is_read'] = (int) $notification['is_read'];
    if ($hasArchivedColumn) {
        $notification['archived'] = (int) ($notification['archived'] ?? 0);
    }
}
unset($notification);

respond_success(['data' => $notifications]);
