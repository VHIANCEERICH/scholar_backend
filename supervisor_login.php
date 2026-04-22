<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';
require_once __DIR__ . '/supervisor_auth_common.php';

require_method('POST');
$data = require_fields(['email', 'password']);

$email = trim((string) ($data['email'] ?? ''));
$password = (string) ($data['password'] ?? '');

$stmt = db_prepare(
    $conn,
    'SELECT user_id, username, email, password_hash, password, role, is_active
     FROM users
     WHERE email = ?
     LIMIT 1'
);
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()?->fetch_assoc();
$stmt->close();

if (!$user) {
    respond_error('Supervisor account not found', 404);
}

if (strtolower(trim((string) ($user['role'] ?? ''))) !== 'supervisor') {
    respond_error('This account does not have supervisor access', 403);
}

if ((int) ($user['is_active'] ?? 0) !== 1) {
    respond_error('Supervisor account is inactive', 403);
}

$hashPassword = trim((string) ($user['password_hash'] ?? ''));
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

$token = supervisor_issue_token($conn, (int) ($user['user_id'] ?? 0));

respond_success([
    'message' => 'Supervisor login successful',
    'token' => $token,
    'user_id' => (int) ($user['user_id'] ?? 0),
    'username' => trim((string) ($user['username'] ?? '')),
    'email' => trim((string) ($user['email'] ?? '')),
    'role' => 'supervisor',
]);
