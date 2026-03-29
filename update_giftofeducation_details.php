<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$data = require_fields(['user_id', 'scholarship_status']);

$userId = (int) ($data['user_id'] ?? 0);
$giftTypeRaw = trim((string) ($data['gift_type'] ?? ''));
$grantCoverage = trim((string) ($data['grant_coverage'] ?? ''));
$retentionGwa = trim((string) ($data['retention_gwa'] ?? ($data['gpa'] ?? '')));
$statusRaw = trim((string) ($data['scholarship_status'] ?? ''));

$allowedGiftTypes = ['ip_member', 'pwd'];
$allowedStatuses = [
    'active',
    'probation',
    'terminated',
    'approved',
    'under_verification',
    'pending',
];

if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

$giftType = '';
if ($giftTypeRaw !== '') {
    $giftTypeRaw = strtolower($giftTypeRaw);
    if (!in_array($giftTypeRaw, $allowedGiftTypes, true)) {
        respond_error('Invalid gift type', 422);
    }
    $giftType = $giftTypeRaw;
}

$status = strtolower($statusRaw);
$status = str_replace([' ', '-'], '_', $status);
$status = preg_replace('/_+/', '_', (string) $status);

// Accept some UI-friendly variants.
if (str_contains($status, 'verify')) {
    $status = 'under_verification';
}

if (!in_array($status, $allowedStatuses, true)) {
    respond_error('Invalid renewal status', 422, ['allowed' => $allowedStatuses]);
}

$stmt = db_prepare(
    $conn,
    'UPDATE scholars
     SET gift_type = ?,
         grant_coverage = ?,
         retention_gwa = ?,
         scholarship_status = ?
     WHERE user_id = ?'
);

$stmt->bind_param('ssssi', $giftType, $grantCoverage, $retentionGwa, $status, $userId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to update Gift of Education details: ' . $error, 500);
}
$stmt->close();

// MySQL ENUM stores invalid values as '' (empty string) without failing.
// Detect that case so the UI doesn't show a false success.
$checkStmt = db_prepare($conn, 'SELECT scholarship_status FROM scholars WHERE user_id = ? LIMIT 1');
$checkStmt->bind_param('i', $userId);
$checkStmt->execute();
$checkRow = $checkStmt->get_result()?->fetch_assoc();
$checkStmt->close();

$dbStatus = (string) ($checkRow['scholarship_status'] ?? '');
if ($dbStatus !== $status) {
    respond_error(
        'Renewal Status was not saved. Update your database column enum to include approved, under_verification, and pending.',
        500,
        [
            'requested' => $status,
            'stored' => $dbStatus,
            'sql_hint' => "ALTER TABLE scholars MODIFY scholarship_status ENUM('active','probation','terminated','approved','under_verification','pending') NOT NULL DEFAULT 'active';",
        ]
    );
}

respond_success([
    'message' => 'Gift of Education details updated successfully',
    'user_id' => $userId,
    'gift_type' => $giftType,
    'grant_coverage' => $grantCoverage,
    'retention_gwa' => $retentionGwa,
    'scholarship_status' => $status,
]);