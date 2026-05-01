<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$data = request_data();
$loginId = trim((string) ($data['email'] ?? $data['username'] ?? $data['login'] ?? ''));
$password = (string) ($data['password'] ?? '');

if ($loginId === '' || trim($password) === '') {
    respond_error('Missing required fields', 422, ['fields' => ['email', 'password']]);
}

$requestedScholarshipCategory = trim((string) ($data['scholarship_category'] ?? $data['scholarship_type'] ?? ''));

$stmt = db_prepare(
    $conn,
    'SELECT user_id, username, email, password_hash, password, role, is_active
     FROM users
     WHERE email = ? OR username = ?
     LIMIT 1'
);
$stmt->bind_param('ss', $loginId, $loginId);
$stmt->execute();
$user = $stmt->get_result()?->fetch_assoc();
$stmt->close();

if (!$user) {
    respond_error('User not found', 404);
}

if (isset($user['is_active']) && (int) $user['is_active'] !== 1) {
    respond_error('User account is inactive', 403);
}

$hashPassword = (string) ($user['password_hash'] ?? '');
$legacyPassword = (string) ($user['password'] ?? '');

$isValid = false;
if ($hashPassword !== '') {
    $isValid = password_verify($password, $hashPassword);
}
if (!$isValid && $legacyPassword !== '') {
    $isValid = ($password === $legacyPassword)
        || password_verify($password, $legacyPassword)
        || md5($password) === $legacyPassword
        || sha1($password) === $legacyPassword;
}

if (!$isValid) {
    respond_error('Invalid password', 401);
}

$extra = [];
if (($user['role'] ?? '') === 'scholar') {
    $profileStmt = db_prepare(
        $conn,
        'SELECT scholar_id, scholarship_category, academic_type, sport_type, gift_type, first_name, last_name
         FROM scholars WHERE user_id = ? LIMIT 1'
    );
    $profileStmt->bind_param('i', $user['user_id']);
    $profileStmt->execute();
    $profile = $profileStmt->get_result()?->fetch_assoc();
    $profileStmt->close();

    if ($profile) {
        $profileCategory = trim((string) ($profile['scholarship_category'] ?? ''));
        if ($profileCategory === '' && $requestedScholarshipCategory !== '') {
            // Return the caller's requested category immediately without turning login into a write request.
            $profileCategory = $requestedScholarshipCategory;
        }

        $extra = [
            'scholar_id' => (int) ($profile['scholar_id'] ?? 0),
            'scholarship_category' => $profileCategory,
            'academic_type' => $profile['academic_type'] ?? '',
            'sport_type' => $profile['sport_type'] ?? '',
            'gift_type' => $profile['gift_type'] ?? '',
            'name' => trim(implode(' ', array_filter([
                trim((string) ($profile['first_name'] ?? '')),
                trim((string) ($profile['last_name'] ?? '')),
            ]))),
        ];
    }
}

respond_success(array_merge([
    'user_id' => (int) $user['user_id'],
    'username' => $user['username'],
    'email' => $user['email'],
    'role' => $user['role'],
], $extra));
