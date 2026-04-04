<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$userId = (int) request_value('user_id', 0);

if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

function delete_if_table_has_user_id(mysqli $conn, string $table, int $userId): void
{
    if (!db_table_exists($conn, $table)) {
        return;
    }

    $colResult = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'user_id'");
    if (!($colResult instanceof mysqli_result) || $colResult->num_rows === 0) {
        return;
    }

    $stmt = db_prepare($conn, "DELETE FROM `{$table}` WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

try {
    $conn->begin_transaction();

    $scholarId = 0;
    if (db_table_exists($conn, 'scholars')) {
        $stmt = db_prepare($conn, 'SELECT scholar_id FROM scholars WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        $scholarId = (int) ($row['scholar_id'] ?? 0);
    }

    // Delete direct user dependents first to avoid FK failures (e.g., duty_totals).
    delete_if_table_has_user_id($conn, 'duty_totals', $userId);
    delete_if_table_has_user_id($conn, 'duty_logs', $userId);
    delete_if_table_has_user_id($conn, 'notifications', $userId);
    delete_if_table_has_user_id($conn, 'announcement_reads', $userId);
    delete_if_table_has_user_id($conn, 'archived_scholars', $userId);

    // Delete submission/application chain if scholar relation exists.
    if ($scholarId > 0 && db_table_exists($conn, 'applications')) {
        if (db_table_exists($conn, 'submissions')) {
            $stmt = db_prepare(
                $conn,
                'DELETE s FROM submissions s INNER JOIN applications a ON a.application_id = s.application_id WHERE a.scholar_id = ?'
            );
            $stmt->bind_param('i', $scholarId);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = db_prepare($conn, 'DELETE FROM applications WHERE scholar_id = ?');
        $stmt->bind_param('i', $scholarId);
        $stmt->execute();
        $stmt->close();
    }

    if ($scholarId > 0 && db_table_exists($conn, 'scholars')) {
        $stmt = db_prepare($conn, 'DELETE FROM scholars WHERE scholar_id = ?');
        $stmt->bind_param('i', $scholarId);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = db_prepare($conn, 'DELETE FROM users WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

    if ($deleted === 0) {
        $conn->rollback();
        respond_error('Scholar not found', 404);
    }

    $conn->commit();
    respond_success(['message' => 'Scholar deleted']);
} catch (Throwable $e) {
    if ($conn->errno !== 0) {
        $conn->rollback();
    }
    respond_error('Failed to delete scholar: ' . $e->getMessage(), 500);
}