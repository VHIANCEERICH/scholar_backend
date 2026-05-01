<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$data = require_fields(['user_id']);

$userId = (int) ($data['user_id'] ?? 0);
$academicType = trim((string) ($data['academic_type'] ?? ''));
$academicBenefit = trim((string) ($data['academic_benefit'] ?? ''));
$academicGwaRequirement = trim((string) ($data['academic_gwa_requirement'] ?? ''));
$monthlyStipendRaw = trim((string) ($data['monthly_stipend'] ?? ''));
$monthlyStipend = $monthlyStipendRaw !== '' ? (float) $monthlyStipendRaw : 3000.0;

if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

if ($academicType !== '') {
    $academicType = normalize_academic_type_for_storage($conn, $academicType);
}

$stmt = db_prepare(
    $conn,
    'UPDATE scholars
     SET academic_type = ?,
         academic_benefit = ?,
         academic_gwa_requirement = ?,
         monthly_stipend = ?
     WHERE user_id = ?'
);

$stmt->bind_param('sssdi', $academicType, $academicBenefit, $academicGwaRequirement, $monthlyStipend, $userId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to update academic details: ' . $error, 500);
}

$stmt->close();
respond_success([
    'message' => 'Academic details updated successfully',
    'user_id' => $userId,
    'academic_type' => $academicType,
    'academic_benefit' => $academicBenefit,
    'academic_gwa_requirement' => $academicGwaRequirement,
    'monthly_stipend' => $monthlyStipend,
]);
