<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

if (!db_table_exists($conn, 'replies')) {
    respond_error('Replies module is unavailable because the replies table does not exist', 500);
}

require_method('POST');
$replyId = (int) request_value('reply_id', 0);

if ($replyId <= 0) {
    respond_error('Invalid reply_id', 422);
}

$stmt = db_prepare($conn, 'DELETE FROM replies WHERE reply_id = ?');
$stmt->bind_param('i', $replyId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to delete reply: ' . $error, 500);
}

$deleted = $stmt->affected_rows;
$stmt->close();

if ($deleted === 0) {
    respond_error('Reply not found', 404);
}

respond_success(['message' => 'Reply deleted']);
