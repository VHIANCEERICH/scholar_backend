<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$data = require_fields(['user_id', 'first_name', 'last_name', 'course', 'year_level']);

$userId = (int) $data['user_id'];
$firstName = trim((string) $data['first_name']);
$middleName = trim((string) ($data['middle_name'] ?? ''));
$lastName = trim((string) $data['last_name']);
$course = trim((string) $data['course']);
$yearLevel = (int) $data['year_level'];
$assignedArea = trim((string) ($data['assigned_area'] ?? ''));
$academicType = trim((string) ($data['academic_type'] ?? ''));
$academicBenefit = trim((string) ($data['academic_benefit'] ?? ''));
$academicGwaRequirement = trim((string) ($data['academic_gwa_requirement'] ?? ''));
$gpa = isset($data['gpa']) && $data['gpa'] !== '' ? (float) $data['gpa'] : 0.0;
$monthlyStipend = isset($data['monthly_stipend']) && $data['monthly_stipend'] !== ''
    ? (float) $data['monthly_stipend']
    : 3000.0;
$sportType = trim((string) ($data['sport_type'] ?? ''));
$headCoach = trim((string) ($data['head_coach'] ?? ''));
$trainingSchedule = trim((string) ($data['training_schedule'] ?? ''));
$gameSchedule = trim((string) ($data['game_schedule'] ?? ''));
$giftType = trim((string) ($data['gift_type'] ?? ''));

$allowedAcademicTypes = ['A', 'B', 'C'];
$allowedGiftTypes = ['ip_member', 'pwd'];

if ($userId <= 0 || $yearLevel <= 0) {
    respond_error('Invalid user_id or year_level', 422);
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

if ($gpa < 0 || $gpa > 5) {
    respond_error('Invalid GWA requirement', 422);
}

$stmt = db_prepare(
    $conn,
    'UPDATE scholars SET first_name = ?, middle_name = ?, last_name = ?, course = ?, year_level = ?, gpa = ?, assigned_area = ?, academic_type = ?, academic_benefit = ?, academic_gwa_requirement = ?, monthly_stipend = ?, sport_type = ?, head_coach = ?, training_schedule = ?, game_schedule = ?, gift_type = ? WHERE user_id = ?'
);
$stmt->bind_param(
    'ssssidssssdsssssi',
    $firstName,
    $middleName,
    $lastName,
    $course,
    $yearLevel,
    $gpa,
    $assignedArea,
    $academicType,
    $academicBenefit,
    $academicGwaRequirement,
    $monthlyStipend,
    $sportType,
    $headCoach,
    $trainingSchedule,
    $gameSchedule,
    $giftType,
    $userId
);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to update profile: ' . $error, 500);
}

$stmt->close();
respond_success(['message' => 'Profile updated successfully']);
