<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$data = require_fields(['user_id', 'first_name', 'last_name', 'course', 'year_level']);

$userId = (int) ($data['user_id'] ?? 0);
$firstName = trim((string) ($data['first_name'] ?? ''));
$middleName = trim((string) ($data['middle_name'] ?? ''));
$lastName = trim((string) ($data['last_name'] ?? ''));
$course = trim((string) ($data['course'] ?? ''));
$yearLevel = (int) ($data['year_level'] ?? 0);

$assignedArea = trim((string) ($data['assigned_area'] ?? ''));
$academicType = trim((string) ($data['academic_type'] ?? ''));
$academicBenefit = trim((string) ($data['academic_benefit'] ?? ''));
$academicGwaRequirement = trim((string) ($data['academic_gwa_requirement'] ?? ''));
$monthlyStipendRaw = trim((string) ($data['monthly_stipend'] ?? ''));
$monthlyStipend = $monthlyStipendRaw !== '' ? (float) $monthlyStipendRaw : null;

$sportType = trim((string) ($data['sport_type'] ?? ''));
$headCoach = trim((string) ($data['head_coach'] ?? ''));
$trainingSchedule = trim((string) ($data['training_schedule'] ?? ''));
$gameSchedule = trim((string) ($data['game_schedule'] ?? ''));

$giftType = trim((string) ($data['gift_type'] ?? ''));
$grantCoverage = trim((string) ($data['grant_coverage'] ?? ''));
$retentionGwa = trim((string) ($data['retention_gwa'] ?? ($data['gpa'] ?? '')));
$scholarshipStatusRaw = trim((string) ($data['scholarship_status'] ?? ''));

$allowedAcademicTypes = ['A', 'B', 'C'];
$allowedGiftTypes = ['ip_member', 'pwd'];
$allowedStatuses = [
    'active',
    'probation',
    'terminated',
    'approved',
    'under_verification',
    'pending',
];

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

$scholarshipStatus = '';
if ($scholarshipStatusRaw !== '') {
    $scholarshipStatus = strtolower($scholarshipStatusRaw);
    $scholarshipStatus = str_replace([' ', '-'], '_', $scholarshipStatus);
    $scholarshipStatus = preg_replace('/_+/', '_', (string) $scholarshipStatus);

    if (str_contains($scholarshipStatus, 'verify')) {
        $scholarshipStatus = 'under_verification';
    }

    if (!in_array($scholarshipStatus, $allowedStatuses, true)) {
        respond_error('Invalid scholarship status', 422, ['allowed' => $allowedStatuses]);
    }
}

$hasScholarshipStatus = db_column_exists($conn, 'scholars', 'scholarship_status');
$hasGrantCoverage = db_column_exists($conn, 'scholars', 'grant_coverage');
$hasRetentionGwa = db_column_exists($conn, 'scholars', 'retention_gwa');
$hasMonthlyStipend = db_column_exists($conn, 'scholars', 'monthly_stipend');
$hasAcademicBenefit = db_column_exists($conn, 'scholars', 'academic_benefit');
$hasAcademicGwaRequirement = db_column_exists($conn, 'scholars', 'academic_gwa_requirement');
$hasHeadCoach = db_column_exists($conn, 'scholars', 'head_coach');
$hasTrainingSchedule = db_column_exists($conn, 'scholars', 'training_schedule');
$hasGameSchedule = db_column_exists($conn, 'scholars', 'game_schedule');

$assignments = [
    ['first_name = ?', 's', $firstName],
    ['middle_name = ?', 's', $middleName],
    ['last_name = ?', 's', $lastName],
    ['course = ?', 's', $course],
    ['year_level = ?', 'i', $yearLevel],
    ['assigned_area = ?', 's', $assignedArea],
    ['academic_type = ?', 's', $academicType],
    ['sport_type = ?', 's', $sportType],
    ['gift_type = ?', 's', $giftType],
];

if ($hasAcademicBenefit) {
    $assignments[] = ['academic_benefit = ?', 's', $academicBenefit];
}
if ($hasAcademicGwaRequirement) {
    $assignments[] = ['academic_gwa_requirement = ?', 's', $academicGwaRequirement];
}
if ($hasMonthlyStipend) {
    $assignments[] = ['monthly_stipend = ?', 'd', $monthlyStipend];
}
if ($hasHeadCoach) {
    $assignments[] = ['head_coach = ?', 's', $headCoach];
}
if ($hasTrainingSchedule) {
    $assignments[] = ['training_schedule = ?', 's', $trainingSchedule];
}
if ($hasGameSchedule) {
    $assignments[] = ['game_schedule = ?', 's', $gameSchedule];
}
if ($hasGrantCoverage) {
    $assignments[] = ['grant_coverage = ?', 's', $grantCoverage];
}
if ($hasRetentionGwa) {
    $assignments[] = ['retention_gwa = ?', 's', $retentionGwa];
}
if ($hasScholarshipStatus) {
    $assignments[] = ['scholarship_status = ?', 's', $scholarshipStatus];
}

$sql = 'UPDATE scholars SET ' . implode(",\n         ", array_column($assignments, 0)) . ' WHERE user_id = ?';
$types = implode('', array_column($assignments, 1)) . 'i';
$values = array_map(static fn (array $item) => $item[2], $assignments);
$values[] = $userId;

$stmt = db_prepare($conn, $sql);
$stmt->bind_param($types, ...$values);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to update profile: ' . $error, 500, [
        'sql' => $sql,
    ]);
}

$affectedRows = $stmt->affected_rows;
$stmt->close();

if ($affectedRows === 0) {
    $checkStmt = db_prepare($conn, 'SELECT scholar_id FROM scholars WHERE user_id = ? LIMIT 1');
    $checkStmt->bind_param('i', $userId);
    $checkStmt->execute();
    $existingRow = $checkStmt->get_result()?->fetch_assoc();
    $checkStmt->close();

    if (!$existingRow) {
        respond_error('Scholar profile not found for this user_id', 404, [
            'user_id' => $userId,
        ]);
    }
}

if ($scholarshipStatus !== '' && $hasScholarshipStatus) {
    $checkStmt = db_prepare($conn, 'SELECT scholarship_status FROM scholars WHERE user_id = ? LIMIT 1');
    $checkStmt->bind_param('i', $userId);
    $checkStmt->execute();
    $checkRow = $checkStmt->get_result()?->fetch_assoc();
    $checkStmt->close();

    $storedStatus = (string) ($checkRow['scholarship_status'] ?? '');
    if ($storedStatus !== $scholarshipStatus) {
        respond_error(
            'Scholarship status was not saved. Update the database enum to include approved, under_verification, and pending.',
            500,
            [
                'requested' => $scholarshipStatus,
                'stored' => $storedStatus,
                'sql_hint' => "ALTER TABLE scholars MODIFY scholarship_status ENUM('active','probation','terminated','approved','under_verification','pending') NOT NULL DEFAULT 'active';",
            ]
        );
    }
}

respond_success([
    'message' => 'Profile updated successfully',
    'user_id' => $userId,
]);
