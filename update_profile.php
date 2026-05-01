<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$data = require_fields(['user_id']);

$userId = (int) ($data['user_id'] ?? 0);
if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

$currentStmt = db_prepare(
    $conn,
    'SELECT first_name, middle_name, last_name, course, year_level
     FROM scholars
     WHERE user_id = ?
     LIMIT 1'
);
$currentStmt->bind_param('i', $userId);
$currentStmt->execute();
$current = $currentStmt->get_result()?->fetch_assoc();
$currentStmt->close();

if (!$current) {
    respond_error('Scholar profile not found for this user_id', 404, [
        'user_id' => $userId,
    ]);
}

$firstName = trim((string) ($data['first_name'] ?? ''));
if ($firstName === '') {
    $firstName = trim((string) ($current['first_name'] ?? ''));
}

$middleName = trim((string) ($data['middle_name'] ?? ''));
if (!array_key_exists('middle_name', $data)) {
    $middleName = trim((string) ($current['middle_name'] ?? ''));
}

$lastName = trim((string) ($data['last_name'] ?? ''));
if ($lastName === '') {
    $lastName = trim((string) ($current['last_name'] ?? ''));
}

$course = trim((string) ($data['course'] ?? ''));
if ($course === '') {
    $course = trim((string) ($current['course'] ?? ''));
}

$yearLevelRaw = trim((string) ($data['year_level'] ?? ''));
$yearLevel = $yearLevelRaw !== ''
    ? (int) $yearLevelRaw
    : (int) ($current['year_level'] ?? 0);

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

$allowedGiftTypes = ['ip_member', 'pwd'];
$allowedStatuses = [
    'active',
    'probation',
    'terminated',
    'approved',
    'under_verification',
    'pending',
];

if ($yearLevel <= 0) {
    respond_error('Invalid year_level', 422);
}

if ($academicType !== '') {
    $academicType = normalize_academic_type_for_storage($conn, $academicType);
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
];

if (array_key_exists('academic_type', $data)) {
    $assignments[] = ['academic_type = ?', 's', $academicType];
}
if (array_key_exists('sport_type', $data)) {
    $assignments[] = ['sport_type = ?', 's', $sportType];
}
if (array_key_exists('gift_type', $data)) {
    $assignments[] = ['gift_type = ?', 's', $giftType];
}
if ($hasAcademicBenefit && array_key_exists('academic_benefit', $data)) {
    $assignments[] = ['academic_benefit = ?', 's', $academicBenefit];
}
if ($hasAcademicGwaRequirement && array_key_exists('academic_gwa_requirement', $data)) {
    $assignments[] = ['academic_gwa_requirement = ?', 's', $academicGwaRequirement];
}
if ($hasMonthlyStipend && array_key_exists('monthly_stipend', $data)) {
    $assignments[] = ['monthly_stipend = ?', 'd', $monthlyStipend];
}
if ($hasHeadCoach && array_key_exists('head_coach', $data)) {
    $assignments[] = ['head_coach = ?', 's', $headCoach];
}
if ($hasTrainingSchedule && array_key_exists('training_schedule', $data)) {
    $assignments[] = ['training_schedule = ?', 's', $trainingSchedule];
}
if ($hasGameSchedule && array_key_exists('game_schedule', $data)) {
    $assignments[] = ['game_schedule = ?', 's', $gameSchedule];
}
if ($hasGrantCoverage && array_key_exists('grant_coverage', $data)) {
    $assignments[] = ['grant_coverage = ?', 's', $grantCoverage];
}
if ($hasRetentionGwa && (array_key_exists('retention_gwa', $data) || array_key_exists('gpa', $data))) {
    $assignments[] = ['retention_gwa = ?', 's', $retentionGwa];
}
if ($hasScholarshipStatus && array_key_exists('scholarship_status', $data)) {
    $assignments[] = ['scholarship_status = ?', 's', $scholarshipStatus];
}

$sql = 'UPDATE scholars SET ' . implode(",\n         ", array_column($assignments, 0)) . ' WHERE user_id = ?';
$types = implode('', array_column($assignments, 1)) . 'i';
$values = array_map(static fn (array $item) => $item[2], $assignments);
$values[] = $userId;

$runUpdate = static function (array $statementValues) use ($conn, $sql, $types): array {
    $stmt = db_prepare($conn, $sql);
    $stmt->bind_param($types, ...$statementValues);
    $ok = $stmt->execute();
    $result = [
        'ok' => $ok,
        'error' => $stmt->error,
        'affected_rows' => $stmt->affected_rows,
    ];
    $stmt->close();
    return $result;
};

$result = $runUpdate($values);

if (
    !$result['ok']
    && $academicType !== ''
    && array_key_exists('academic_type', $data)
    && str_contains(strtolower((string) $result['error']), 'academic_type')
    && str_contains(strtolower((string) $result['error']), 'data truncated')
) {
    $alternateAcademicType = alternate_academic_type_storage($academicType);
    if ($alternateAcademicType !== $academicType) {
        foreach ($assignments as $index => $assignment) {
            if ($assignment[0] === 'academic_type = ?') {
                $assignments[$index][2] = $alternateAcademicType;
                break;
            }
        }
        $values = array_map(static fn (array $item) => $item[2], $assignments);
        $values[] = $userId;
        $result = $runUpdate($values);
    }
}

if (!$result['ok']) {
    respond_error('Failed to update profile: ' . $result['error'], 500, [
        'sql' => $sql,
    ]);
}

$affectedRows = (int) $result['affected_rows'];

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
