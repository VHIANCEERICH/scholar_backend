<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

$userId = (int) ($_GET['user_id'] ?? request_value('user_id', 0));
if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

$stmt = db_prepare(
    $conn,
    "
    SELECT
        s.scholar_id,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.course,
        s.year_level,
        s.scholarship_category,
        s.assigned_area,
        s.academic_type,
        s.academic_benefit,
        s.academic_gwa_requirement,
        s.monthly_stipend,
        s.sport_type,
        s.head_coach,
        s.training_schedule,
        s.game_schedule,
        s.gift_type,
        s.grant_coverage,
        s.retention_gwa,
        s.profile_image,
        s.supervisor,
        s.scholarship_status,
        s.gpa,
        u.email,
        u.username
    FROM scholars s
    INNER JOIN users u ON u.user_id = s.user_id
    WHERE s.user_id = ?
    LIMIT 1
    "
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$row = $stmt->get_result()?->fetch_assoc();
$stmt->close();

if (!$row) {
    respond_error('Scholar profile not found', 404);
}

$fullName = trim(implode(' ', array_filter([
    trim((string) ($row['first_name'] ?? '')),
    trim((string) ($row['middle_name'] ?? '')),
    trim((string) ($row['last_name'] ?? '')),
])));

$category = trim((string) ($row['scholarship_category'] ?? 'Student Assistant'));
$role = $category !== '' ? $category : 'Scholar';

$semesterOptions = [
    'AY 2025-2026 2nd Semester',
    'AY 2025-2026 1st Semester',
    'Summer 2026',
];

$renderedHours = 0;
$requiredHours = 400;
$remainingHours = 400;
if (db_table_exists($conn, 'duty_totals')) {
    $hoursStmt = db_prepare(
        $conn,
        'SELECT rendered_hours, required_hours FROM duty_totals WHERE user_id = ? LIMIT 1'
    );
    $hoursStmt->bind_param('i', $userId);
    $hoursStmt->execute();
    $hoursRow = $hoursStmt->get_result()?->fetch_assoc();
    $hoursStmt->close();
    if ($hoursRow) {
        $renderedHours = (int) ($hoursRow['rendered_hours'] ?? 0);
        $requiredHours = (int) ($hoursRow['required_hours'] ?? 400);
        $remainingHours = max(0, $requiredHours - $renderedHours);
    }
} elseif (db_table_exists($conn, 'duty_logs')) {
    $hoursStmt = db_prepare(
        $conn,
        'SELECT COALESCE(SUM(hours), 0) AS rendered_hours FROM duty_logs WHERE user_id = ?'
    );
    $hoursStmt->bind_param('i', $userId);
    $hoursStmt->execute();
    $hoursRow = $hoursStmt->get_result()?->fetch_assoc();
    $hoursStmt->close();
    $renderedHours = (int) ($hoursRow['rendered_hours'] ?? 0);
}

$supervisor = trim((string) ($row['supervisor'] ?? ''));
if ($supervisor === '') {
    $supervisor = 'Scholarship Office';
}

$giftType = (string) ($row['gift_type'] ?? '');
if (strtolower($category) === 'gift_of_education' && trim($giftType) === '') {
    $giftType = 'ip_member';
}

$detailRows = [];
switch (strtolower($category)) {
    case 'academic scholar':
    case 'academic':
        $detailRows[] = [
            'Scholarship Type' => (string) ($row['academic_type'] ?? 'Academic Scholar'),
            'Benefit' => (string) ($row['academic_benefit'] ?? 'Tuition Assistance'),
            'GWA Req.' => trim((string) ($row['academic_gwa_requirement'] ?? '')) !== ''
                ? (string) $row['academic_gwa_requirement']
                : (((float) ($row['gpa'] ?? 0)) > 0
                    ? number_format((float) $row['gpa'], 2)
                    : 'N/A'),
            'Monthly Stipend' => 'PHP ' . number_format((float) ($row['monthly_stipend'] ?? 3000), 2),
        ];
        break;

    case 'varsity scholar':
    case 'varsity':
        $detailRows[] = [
            'Sport' => (string) ($row['sport_type'] ?? 'Varsity Team'),
            'Head Coach' => (string) ($row['head_coach'] ?? 'Athletics Office'),
            'Training Schedule' => (string) ($row['training_schedule'] ?? 'See athletics coordinator'),
            'Game Schedule' => (string) ($row['game_schedule'] ?? 'No game schedule yet'),
        ];
        break;

    case 'gift_of_education':
    case 'gift of education':
        $retentionGwa = trim((string) ($row['retention_gwa'] ?? ''));
        if ($retentionGwa === '') {
            $gpa = (float) ($row['gpa'] ?? 0);
            $retentionGwa = $gpa > 0 ? number_format($gpa, 2) : '80%';
        }
        $detailRows[] = [
            'Scholarship Type' => (string) ($giftType !== '' ? $giftType : 'ip_member'),
            'Grant Coverage' => (string) ($row['grant_coverage'] ?? '100% Free'),
            'Retention GWA' => $retentionGwa,
            'Renewal Status' => (string) ($row['scholarship_status'] ?? ''),
        ];
        break;

    default:
        $detailRows[] = [
            'Assign Area' => (string) ($row['assigned_area'] ?? 'Unassigned'),
            'Duty Hours' => (string) $renderedHours,
            'Supervisor' => $supervisor,
            'Required Hours' => (string) $requiredHours,
            'Remaining Hours' => (string) $remainingHours,
        ];
        break;
}

respond_success([
    'profile' => [
        'user_id' => $userId,
        'scholar_id' => (int) ($row['scholar_id'] ?? 0),
        'name' => $fullName !== '' ? $fullName : ((string) ($row['username'] ?? ('Scholar #' . $userId))),
        'course' => (string) ($row['course'] ?? ''),
        'first_name' => (string) ($row['first_name'] ?? ''),
        'middle_name' => (string) ($row['middle_name'] ?? ''),
        'last_name' => (string) ($row['last_name'] ?? ''),
        'year_level' => (string) ($row['year_level'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'role' => $role,
        'scholarship_category' => $category,
        'assigned_area' => (string) ($row['assigned_area'] ?? ''),
        'academic_type' => (string) ($row['academic_type'] ?? ''),
        'academic_benefit' => (string) ($row['academic_benefit'] ?? ''),
        'academic_gwa_requirement' => (string) ($row['academic_gwa_requirement'] ?? ''),
        'monthly_stipend' => (float) ($row['monthly_stipend'] ?? 3000),
        'gpa' => (float) ($row['gpa'] ?? 0),
        'sport_type' => (string) ($row['sport_type'] ?? ''),
        'head_coach' => (string) ($row['head_coach'] ?? ''),
        'training_schedule' => (string) ($row['training_schedule'] ?? ''),
        'game_schedule' => (string) ($row['game_schedule'] ?? ''),
        'gift_type' => $giftType,
        'grant_coverage' => (string) ($row['grant_coverage'] ?? ''),
        'retention_gwa' => (string) ($row['retention_gwa'] ?? ''),
        'scholarship_status' => (string) ($row['scholarship_status'] ?? ''),
        'profile_image' => (string) ($row['profile_image'] ?? ''),
        'profile_image_url' => make_public_file_url((string) ($row['profile_image'] ?? '')),
        // Backward-compatible alias used by some screens.
        'status' => (string) ($row['scholarship_status'] ?? ''),
        'supervisor' => $supervisor,
        'rendered_hours' => $renderedHours,
        'remaining_hours' => $remainingHours,
        'required_hours' => $requiredHours,
    ],
    'semesters' => $semesterOptions,
    'detail_rows' => $detailRows,
]);