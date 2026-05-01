<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

function ensure_supervisor_sessions_table(mysqli $conn): void
{
    if (db_table_exists($conn, 'supervisor_sessions')) {
        return;
    }

    $sql = "
        CREATE TABLE supervisor_sessions (
            session_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            last_used_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uniq_token_hash (token_hash),
            KEY idx_user_id (user_id),
            KEY idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($sql)) {
        respond_error('Failed to create supervisor_sessions table: ' . $conn->error, 500);
    }
}

function supervisor_issue_token(mysqli $conn, int $userId, int $ttlHours = 12): string
{
    ensure_supervisor_sessions_table($conn);

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $stmt = db_prepare(
        $conn,
        'INSERT INTO supervisor_sessions (user_id, token_hash, expires_at, last_used_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), NOW())'
    );
    $stmt->bind_param('isi', $userId, $tokenHash, $ttlHours);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        respond_error('Failed to create supervisor session: ' . $error, 500);
    }
    $stmt->close();

    return $token;
}

function supervisor_read_bearer_token(): string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '') {
        return '';
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) !== 1) {
        return '';
    }

    return trim((string) ($matches[1] ?? ''));
}

function require_supervisor_auth(mysqli $conn): array
{
    ensure_supervisor_sessions_table($conn);

    $token = supervisor_read_bearer_token();
    if ($token === '') {
        respond_error('Supervisor authentication is required', 401);
    }

    $tokenHash = hash('sha256', $token);
    $stmt = db_prepare(
        $conn,
        "SELECT
            u.user_id,
            u.username,
            u.email,
            u.role,
            u.is_active
         FROM supervisor_sessions ss
         INNER JOIN users u ON u.user_id = ss.user_id
         WHERE ss.token_hash = ?
           AND ss.expires_at > NOW()
         LIMIT 1"
    );
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $user = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    if (!$user) {
        respond_error('Supervisor session is invalid or expired', 401);
    }

    if (strtolower(trim((string) ($user['role'] ?? ''))) !== 'supervisor') {
        respond_error('Supervisor access only', 403);
    }

    if ((int) ($user['is_active'] ?? 0) !== 1) {
        respond_error('Supervisor account is inactive', 403);
    }

    $updateStmt = db_prepare(
        $conn,
        'UPDATE supervisor_sessions SET last_used_at = NOW() WHERE token_hash = ?'
    );
    $updateStmt->bind_param('s', $tokenHash);
    $updateStmt->execute();
    $updateStmt->close();

    return [
        'user_id' => (int) ($user['user_id'] ?? 0),
        'username' => trim((string) ($user['username'] ?? '')),
        'email' => trim((string) ($user['email'] ?? '')),
        'role' => 'supervisor',
        'token' => $token,
    ];
}
