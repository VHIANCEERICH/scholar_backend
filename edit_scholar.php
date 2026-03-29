<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';
require_method('POST');
$data = require_fields(fields: ['scholar_id', 'course', 'year_level']);

$scholarId = (int) $data['scholar_id'];
$course = trim((string) $data['course']);
$yearLevel = (int) $data['year_level'];
$assignedArea = trim((string) request_value('assigned_area', ''));
$academicType = trim((string) ($data['academic_type'] ?? ''));
$sportType = trim((string) ($data['sport_type'] ?? ''));
$giftType = trim((string) ($data['gift_type'] ?? ''));

$allowedAcademicTypes = ['A', 'B', 'C'];
$allowedGiftTypes = ['ip_member', 'pwd'];

if ($scholarId <= 0 || $yearLevel <= 0) {
    respond_error('Invalid scholar ID or year level', 422);
}

if ($academicType !== '') {
    $academicType = strtoupper($academicType);
    if (!in_array($academicType, $allowedAcademicTypes, true)) {
        respond_error('Invalid academic type', 422);
    }
}

if ($giftType !== '') {
    $giftType = strtolower($giftType);
    if (!in_array($giftType, $allowedGiftTypes, true)) {
        respond_error('Invalid gift type', 422);
    }
}

$academicType = $academicType === '' ? null : $academicType;
$sportType = $sportType === '' ? null : $sportType;
$giftType = $giftType === '' ? null : $giftType;

$stmt = db_prepare(
    $conn,
    'UPDATE scholars SET course = ?, year_level = ?, assigned_area = ?, academic_type = ?, sport_type = ?, gift_type = ? WHERE scholar_id = ?'
);
$stmt->bind_param('sissssi', $course, $yearLevel, $assignedArea, $academicType, $sportType, $giftType, $scholarId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to update scholar: ' . $error, 500);
}

$stmt->close();
respond_success(['message' => 'Scholar updated successfully']);
