<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$userId = (int) request_value('user_id', 0);

if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

if (!isset($_FILES['image'])) {
    respond_error('No image uploaded', 422);
}

$uploadDir = __DIR__ . '/uploads/profile';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    respond_error('Upload failed with error code ' . $file['error'], 422);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$ext = $ext !== '' ? strtolower($ext) : 'jpg';
$filename = sprintf('profile_%d_%d.%s', $userId, time(), $ext);
$destPath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    respond_error('Failed to move uploaded file', 500);
}

$relativePath = 'uploads/profile/' . $filename;
$stmt = db_prepare($conn, 'UPDATE scholars SET profile_image = ? WHERE user_id = ?');
$stmt->bind_param('si', $relativePath, $userId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to save profile image: ' . $error, 500);
}
$stmt->close();

respond_success([
    'profile_image' => $relativePath,
    'profile_image_url' => make_public_file_url($relativePath),
]);