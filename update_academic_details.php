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

$sql = 'UPDATE scholars
     SET academic_type = ?,
         academic_benefit = ?,
         academic_gwa_requirement = ?,
         monthly_stipend = ?
     WHERE user_id = ?';

$runUpdate = static function (string $typeValue) use (
    $conn,
    $sql,
    $academicBenefit,
    $academicGwaRequirement,
    $monthlyStipend,
    $userId
): array {
    $stmt = db_prepare($conn, $sql);
    $stmt->bind_param('sssdi', $typeValue, $academicBenefit, $academicGwaRequirement, $monthlyStipend, $userId);
    $ok = $stmt->execute();
    $result = [
        'ok' => $ok,
        'error' => $stmt->error,
    ];
    $stmt->close();
    return $result;
};

$result = $runUpdate($academicType);

if (
    !$result['ok']
    && $academicType !== ''
    && str_contains(strtolower((string) $result['error']), 'academic_type')
    && str_contains(strtolower((string) $result['error']), 'data truncated')
) {
    $alternateAcademicType = alternate_academic_type_storage($academicType);
    if ($alternateAcademicType !== $academicType) {
        $academicType = $alternateAcademicType;
        $result = $runUpdate($academicType);
    }
}

if (!$result['ok']) {
    respond_error('Failed to update academic details: ' . $result['error'], 500);
}

respond_success([
    'message' => 'Academic details updated successfully',
    'user_id' => $userId,
    'academic_type' => $academicType,
    'academic_benefit' => $academicBenefit,
    'academic_gwa_requirement' => $academicGwaRequirement,
    'monthly_stipend' => $monthlyStipend,
]);
