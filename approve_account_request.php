<?php
declare(strict_types=1);

require_once __DIR__ . '/account_request_common.php';

function approve_users_has_google_id_column(mysqli $conn): bool
{
    static $hasColumn = null;
    if ($hasColumn !== null) {
        return $hasColumn;
    }

    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'google_id'");
    $hasColumn = $result instanceof mysqli_result && $result->num_rows > 0;
    return $hasColumn;
}

function approve_ensure_users_google_id_column(mysqli $conn): bool
{
    if (approve_users_has_google_id_column($conn)) {
        return true;
    }

    if ($conn->query("ALTER TABLE users ADD COLUMN google_id VARCHAR(191) NOT NULL DEFAULT '' AFTER email") === true) {
        return true;
    }

    return approve_users_has_google_id_column($conn);
}

require_method('POST');
ensure_account_requests_table($conn);

$data = request_data();
$requestId = (int) ($data['request_id'] ?? 0);

if ($requestId <= 0) {
    respond_error('Invalid request_id', 422);
}

$stmt = db_prepare(
    $conn,
    'SELECT request_id, role, username, email, password_hash, scholarship_category, scholarship_type_label, first_name, middle_name, last_name, course, year_level, status, google_id
     FROM account_requests
     WHERE request_id = ?
     LIMIT 1'
);
$stmt->bind_param('i', $requestId);
$stmt->execute();
$request = $stmt->get_result()?->fetch_assoc();
$stmt->close();

if (!$request) {
    respond_error('Account request not found', 404);
}

if (strtolower((string) ($request['status'] ?? '')) !== 'pending') {
    respond_error('This request has already been processed.', 409);
}

$role = strtolower(trim((string) ($request['role'] ?? 'scholar')));
$username = trim((string) ($request['username'] ?? ''));
$email = trim((string) ($request['email'] ?? ''));
$passwordHash = trim((string) ($request['password_hash'] ?? ''));
$scholarshipCategory = trim((string) ($request['scholarship_category'] ?? ''));
$firstName = trim((string) ($request['first_name'] ?? ''));
$middleName = trim((string) ($request['middle_name'] ?? ''));
$lastName = trim((string) ($request['last_name'] ?? ''));
$course = trim((string) ($request['course'] ?? ''));
$yearLevel = (int) ($request['year_level'] ?? 1);
$googleId = trim((string) ($request['google_id'] ?? ''));

if ($passwordHash === '') {
    respond_error('Missing password hash for request', 500);
}

$conn->begin_transaction();

try {
    $googleColumnReady = $googleId !== '' ? approve_ensure_users_google_id_column($conn) : false;

    $existingStmt = db_prepare($conn, 'SELECT user_id FROM users WHERE email = ? LIMIT 1');
    $existingStmt->bind_param('s', $email);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()?->fetch_assoc();
    $existingStmt->close();

    if ($existing) {
        throw new RuntimeException('A user with this email already exists.');
    }

    $legacyPassword = $passwordHash;
    if ($googleColumnReady) {
        $userStmt = db_prepare(
            $conn,
            'INSERT INTO users (username, email, google_id, password_hash, password, role, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $userStmt->bind_param('ssssss', $username, $email, $googleId, $passwordHash, $legacyPassword, $role);
    } else {
        $userStmt = db_prepare(
            $conn,
            'INSERT INTO users (username, email, password_hash, password, role, is_active) VALUES (?, ?, ?, ?, ?, 1)'
        );
        $userStmt->bind_param('sssss', $username, $email, $passwordHash, $legacyPassword, $role);
    }
    if (!$userStmt->execute()) {
        throw new RuntimeException('Failed to create user: ' . $userStmt->error);
    }

    $userId = (int) $userStmt->insert_id;
    $userStmt->close();

    $scholarId = 0;
    if ($role === 'scholar') {
        if ($firstName === '' && $lastName === '') {
            [$firstName, $middleName, $lastName] = account_request_split_name($username);
        }

        if ($course === '') {
            $course = 'Not specified';
        }
        if ($yearLevel <= 0) {
            $yearLevel = 1;
        }
        if ($scholarshipCategory === '') {
            $scholarshipCategory = 'student_assistant';
        }

        $scholarStmt = db_prepare(
            $conn,
            "INSERT INTO scholars (user_id, first_name, middle_name, last_name, course, year_level, scholarship_category, assigned_area, scholarship_status, academic_type, sport_type, gift_type)
             VALUES (?, ?, ?, ?, ?, ?, ?, '', 'approved', NULL, NULL, NULL)"
        );
        $scholarStmt->bind_param(
            'issssis',
            $userId,
            $firstName,
            $middleName,
            $lastName,
            $course,
            $yearLevel,
            $scholarshipCategory
        );

        if (!$scholarStmt->execute()) {
            throw new RuntimeException('Failed to create scholar profile: ' . $scholarStmt->error);
        }

        $scholarId = (int) $scholarStmt->insert_id;
        $scholarStmt->close();
    }

    $reviewStmt = db_prepare(
        $conn,
        'UPDATE account_requests SET status = \'approved\', reviewed_at = NOW(), reviewed_by = 0, review_note = \'\' WHERE request_id = ?'
    );
    $reviewStmt->bind_param('i', $requestId);
    if (!$reviewStmt->execute()) {
        throw new RuntimeException('Failed to update request: ' . $reviewStmt->error);
    }
    $reviewStmt->close();

    $conn->commit();

    respond_success([
        'message' => 'Account request approved',
        'request_id' => $requestId,
        'user_id' => $userId,
        'scholar_id' => $scholarId,
        'role' => $role,
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    respond_error($e->getMessage(), 500);
}
