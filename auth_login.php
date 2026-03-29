<?php
<<<<<<< HEAD
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$data = require_fields(['email', 'password']);

$email = trim((string) $data['email']);
$password = (string) $data['password'];

$stmt = db_prepare(
    $conn,
    'SELECT user_id, username, email, password_hash, password, role, is_active FROM users WHERE email = ? LIMIT 1'
);
$stmt->bind_param('s', $email);
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
if (($user['role'] ?? '') === 'scholar' && db_table_exists($conn, 'scholars')) {
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
        $extra = [
            'scholar_id' => (int) ($profile['scholar_id'] ?? 0),
            'scholarship_category' => $profile['scholarship_category'] ?? '',
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

=======

// ================================
// CORS CONFIGURATION (SECURE)
// ================================

$trusted_domains = [
    "https://yourdomain.com",
    "http://localhost:3000",
    "http://localhost:5000"
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $trusted_domains)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle browser preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json");

// ================================
// DATABASE CONNECTION
// ================================
include 'connection.php';

// ================================
// READ INPUT FROM FLUTTER
// ================================

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$email = $_POST['email'] ?? ($data['email'] ?? '');
$password = $_POST['password'] ?? ($data['password'] ?? '');

// ================================
// VALIDATION
// ================================

if (empty($email) || empty($password)) {
    echo json_encode([
        "status" => "error",
        "message" => "Please provide email and password"
    ]);
    exit();
}

// ================================
// FIND USER
// ================================

$stmt = $conn->prepare("SELECT id, email, password, role FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {

    // Verify hashed password
    if (password_verify($password, $user['password'])) {

        $response = [
            "status" => "success",
            "user" => [
                "id" => $user['id'],
                "email" => $user['email'],
                "role" => $user['role'],
                "usr_fullname" => explode('@', $user['email'])[0]
            ]
        ];

        echo json_encode($response);

    } else {

        echo json_encode([
            "status" => "error",
            "message" => "Invalid password"
        ]);

    }

} else {

    echo json_encode([
        "status" => "error",
        "message" => "User not found"
    ]);

}

$stmt->close();
$conn->close();

?>
>>>>>>> 3265e6e429034427c9039481f105b3c2a1aed208
