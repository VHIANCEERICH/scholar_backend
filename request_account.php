<?php
declare(strict_types=1);

require_once __DIR__ . '/account_request_common.php';

require_method('POST');
ensure_account_requests_table($conn);

$data = request_data();
$role = strtolower(trim((string) ($data['role'] ?? 'scholar')));
$username = account_request_display_name((string) ($data['username'] ?? ''), (string) ($data['email'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$plainPassword = (string) ($data['password'] ?? '');
$scholarshipCategory = account_request_normalize_scholarship_category(
    (string) ($data['scholarship_category'] ?? $data['scholarship_type'] ?? '')
);
$scholarshipTypeLabel = trim((string) ($data['scholarship_type'] ?? $data['scholarship_type_label'] ?? ''));
$googleId = trim((string) ($data['google_id'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond_error('Invalid email format', 422);
}

if (!in_array($role, ['admin', 'scholar', 'supervisor'], true)) {
    respond_error('Invalid role selected', 422);
}

if (strlen($plainPassword) < 8) {
    respond_error('Password must be at least 8 characters.', 422);
}

if ($scholarshipTypeLabel === '') {
    $scholarshipTypeLabel = account_request_role_label($role);
}

$checkStmt = db_prepare($conn, 'SELECT user_id FROM users WHERE email = ? OR username = ? LIMIT 1');
$checkStmt->bind_param('ss', $email, $username);
$checkStmt->execute();
$existingUser = $checkStmt->get_result()?->fetch_assoc();
$checkStmt->close();
if ($existingUser) {
    respond_error('Username or email already exists', 409);
}

$pendingStmt = db_prepare(
    $conn,
    "SELECT request_id FROM account_requests
     WHERE email = ? AND role = ? AND status = 'pending'
     LIMIT 1"
);
$pendingStmt->bind_param('ss', $email, $role);
$pendingStmt->execute();
$existingRequest = $pendingStmt->get_result()?->fetch_assoc();
$pendingStmt->close();
if ($existingRequest) {
    respond_error('An approval request already exists for this account.', 409);
}

$passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
if ($passwordHash === false) {
    respond_error('Failed to hash password', 500);
}

[$firstName, $middleName, $lastName] = account_request_split_name($username);
$course = trim((string) ($data['course'] ?? 'Not specified'));
$yearLevel = (int) ($data['year_level'] ?? 1);
if ($yearLevel <= 0) {
    $yearLevel = 1;
}

$stmt = db_prepare(
    $conn,
    'INSERT INTO account_requests
     (request_kind, existing_user_id, role, username, email, password_hash, scholarship_category, scholarship_type_label, first_name, middle_name, last_name, course, year_level, status, google_id)
     VALUES (\'new_account\', 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?)'
);
$stmt->bind_param(
    'ssssssssssis',
    $role,
    $username,
    $email,
    $passwordHash,
    $scholarshipCategory,
    $scholarshipTypeLabel,
    $firstName,
    $middleName,
    $lastName,
    $course,
    $yearLevel,
    $googleId
);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to submit account request: ' . $error, 500);
}

$requestId = $stmt->insert_id;
$stmt->close();

respond_success([
    'message' => 'Account request submitted for approval',
    'request_id' => $requestId,
    'role' => $role,
    'status' => 'pending',
], 201);
