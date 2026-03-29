<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$data = require_fields(['username', 'email', 'password', 'role']);

$username = trim((string) $data['username']);
$email = trim((string) $data['email']);
$plainPassword = (string) $data['password'];
$role = strtolower(trim((string) $data['role']));
$allowedRoles = ['admin', 'staff', 'scholar'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond_error('Invalid email format', 422);
}

if (!in_array($role, $allowedRoles, true)) {
    respond_error('Invalid role selected', 422);
}

$checkStmt = db_prepare($conn, 'SELECT user_id FROM users WHERE email = ? OR username = ? LIMIT 1');
$checkStmt->bind_param('ss', $email, $username);
$checkStmt->execute();
$existingUser = $checkStmt->get_result()?->fetch_assoc();
$checkStmt->close();

if ($existingUser) {
    respond_error('Username or email already exists', 409);
}

$passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
$stmt = db_prepare(
    $conn,
    'INSERT INTO users (username, email, password_hash, password, role, is_active) VALUES (?, ?, ?, ?, ?, 1)'
);
$stmt->bind_param('sssss', $username, $email, $passwordHash, $passwordHash, $role);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to create user: ' . $error, 500);
}

$userId = $stmt->insert_id;
$stmt->close();

respond_success([
    'message' => 'User created successfully',
    'user_id' => $userId,
], 201);
