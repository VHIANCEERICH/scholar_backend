<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

function set_scholar_active_state(mysqli $conn, int $userId, bool $isActive, string $errorVerb): void
{
    $state = $isActive ? 1 : 0;
    $stmt = db_prepare($conn, 'UPDATE users SET is_active = ? WHERE user_id = ?');
    $stmt->bind_param('ii', $state, $userId);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        respond_error('Failed to ' . $errorVerb . ' scholar: ' . $error, 500);
    }

    $updated = $stmt->affected_rows;
    $stmt->close();

    if ($updated === 0) {
        respond_error('Scholar not found', 404);
    }
}
