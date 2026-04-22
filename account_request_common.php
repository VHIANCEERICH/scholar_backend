<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

function ensure_account_requests_table(mysqli $conn): void
{
    if (!db_table_exists($conn, 'account_requests')) {
        $sql = "
            CREATE TABLE account_requests (
                request_id INT AUTO_INCREMENT PRIMARY KEY,
                request_kind VARCHAR(40) NOT NULL DEFAULT 'new_account',
                existing_user_id INT NOT NULL DEFAULT 0,
                role VARCHAR(20) NOT NULL,
                username VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                scholarship_category VARCHAR(64) NOT NULL DEFAULT '',
                scholarship_type_label VARCHAR(120) NOT NULL DEFAULT '',
                first_name VARCHAR(120) NOT NULL DEFAULT '',
                middle_name VARCHAR(120) NOT NULL DEFAULT '',
                last_name VARCHAR(120) NOT NULL DEFAULT '',
                course VARCHAR(120) NOT NULL DEFAULT '',
                year_level INT NOT NULL DEFAULT 1,
                status VARCHAR(24) NOT NULL DEFAULT 'pending',
                google_id VARCHAR(120) NOT NULL DEFAULT '',
                requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL DEFAULT NULL,
                reviewed_by INT NOT NULL DEFAULT 0,
                review_note VARCHAR(255) NOT NULL DEFAULT '',
                INDEX idx_status_requested (status, requested_at),
                INDEX idx_email (email),
                INDEX idx_role (role),
                INDEX idx_request_kind_status (request_kind, status),
                INDEX idx_existing_user (existing_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if (!$conn->query($sql)) {
            respond_error('Failed to create account_requests table: ' . $conn->error, 500);
        }
        return;
    }

    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM account_requests');
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $name = strtolower((string) ($row['Field'] ?? ''));
            if ($name !== '') {
                $columns[$name] = true;
            }
        }
        $result->close();
    }

    if (!isset($columns['request_kind'])) {
        if (!$conn->query("ALTER TABLE account_requests ADD COLUMN request_kind VARCHAR(40) NOT NULL DEFAULT 'new_account' AFTER request_id")) {
            respond_error('Failed to add request_kind column: ' . $conn->error, 500);
        }
    }

    if (!isset($columns['existing_user_id'])) {
        if (!$conn->query("ALTER TABLE account_requests ADD COLUMN existing_user_id INT NOT NULL DEFAULT 0 AFTER request_kind")) {
            respond_error('Failed to add existing_user_id column: ' . $conn->error, 500);
        }
    }
}

function account_request_role_label(string $role): string
{
    $normalized = strtolower(trim($role));
    if ($normalized === 'admin') {
        return 'Admin';
    }
    if ($normalized === 'supervisor') {
        return 'Supervisor';
    }
    return 'Scholar';
}

function account_request_normalize_scholarship_category(string $value): string
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return 'student_assistant';
    }

    if (str_contains($normalized, 'student') && str_contains($normalized, 'assistant')) {
        return 'student_assistant';
    }
    if (str_contains($normalized, 'varsity')) {
        return 'varsity';
    }
    if (str_contains($normalized, 'academic')) {
        return 'academic';
    }
    if (str_contains($normalized, 'gift')) {
        return 'gift_of_education';
    }

    return in_array($normalized, ['student_assistant', 'varsity', 'academic', 'gift_of_education'], true)
        ? $normalized
        : 'student_assistant';
}

function account_request_display_name(string $name, string $email): string
{
    $name = trim($name);
    if ($name !== '') {
        return $name;
    }

    return trim($email) !== '' ? trim($email) : 'Google User';
}

function account_request_split_name(string $name): array
{
    $clean = preg_replace('/\s+/', ' ', trim($name));
    if (!is_string($clean) || $clean === '') {
        return ['Google', '', 'User'];
    }

    $parts = explode(' ', $clean);
    if (count($parts) === 1) {
        return [$parts[0], '', 'User'];
    }

    if (count($parts) === 2) {
        return [$parts[0], '', $parts[1]];
    }

    return [$parts[0], implode(' ', array_slice($parts, 1, -1)), $parts[count($parts) - 1]];
}

function account_request_pending_count(mysqli $conn): int
{
    ensure_account_requests_table($conn);
    $stmt = db_prepare($conn, "SELECT COUNT(*) AS total FROM account_requests WHERE status = 'pending'");
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0);
}

function account_request_kind_label(string $requestKind, string $role): string
{
    $requestKind = strtolower(trim($requestKind));
    if ($requestKind === 'admin_google_access') {
        return 'Admin Google Access';
    }

    return account_request_role_label($role);
}
