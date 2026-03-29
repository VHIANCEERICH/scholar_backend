<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

$userId = (int) ($_GET['user_id'] ?? request_value('user_id', 0));
if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

$sql = "
    SELECT
        s.scholar_id,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.course,
        s.year_level,
        s.gpa,
        s.scholarship_category,
        s.assigned_area,
        s.academic_type,
        s.sport_type,
        s.gift_type,
        s.scholarship_status,
        a.application_id,
        a.status AS application_status,
        a.remarks AS application_remarks
    FROM scholars s
    LEFT JOIN applications a ON a.scholar_id = s.scholar_id
    WHERE s.user_id = ?
    ORDER BY a.application_id DESC
    LIMIT 1
";

$stmt = db_prepare($conn, $sql);
$stmt->bind_param('i', $userId);
$stmt->execute();
$profile = $stmt->get_result()?->fetch_assoc();
$stmt->close();

if (!$profile) {
    respond_error('Scholar profile not found', 404);
}

$applicationId = (int) ($profile['application_id'] ?? 0);
$category = trim((string) ($profile['scholarship_category'] ?? 'Student Assistant'));
$fullName = trim(implode(' ', array_filter([
    trim((string) ($profile['first_name'] ?? '')),
    trim((string) ($profile['middle_name'] ?? '')),
    trim((string) ($profile['last_name'] ?? '')),
])));
$displayName = $fullName !== '' ? $fullName : ('Scholar #' . $userId);
$submissions = [];

if ($applicationId > 0) {
    $subsStmt = db_prepare(
        $conn,
        "
        SELECT
            s.submission_id,
            COALESCE(r.requirement_name, CONCAT('Requirement #', COALESCE(s.requirement_id, 0))) AS type,
            s.status,
            s.upload_date,
            s.file_path,
            s.reviewer_comment
        FROM submissions s
        LEFT JOIN requirements r ON r.requirement_id = s.requirement_id
        WHERE s.application_id = ?
        ORDER BY s.upload_date DESC
        LIMIT 10
        "
    );
    $subsStmt->bind_param('i', $applicationId);
    $subsStmt->execute();
    $submissions = fetch_all_assoc($subsStmt);
    $subsStmt->close();
}

foreach ($submissions as &$submission) {
    $submission['submission_id'] = (int) ($submission['submission_id'] ?? 0);
    $submission['name'] = basename((string) ($submission['file_path'] ?? 'Document'));
    $submission['type'] = (string) ($submission['type'] ?? 'Document');
    $submission['status'] = ucfirst((string) ($submission['status'] ?? 'Pending'));
}
unset($submission);

$renderedHours = 0;
$remainingHours = 100;
if (db_table_exists($conn, 'duty_totals')) {
    $hoursStmt = db_prepare(
        $conn,
        'SELECT rendered_hours, remaining_hours FROM duty_totals WHERE user_id = ? LIMIT 1'
    );
    $hoursStmt->bind_param('i', $userId);
    $hoursStmt->execute();
    $hoursRow = $hoursStmt->get_result()?->fetch_assoc();
    $hoursStmt->close();
    if ($hoursRow) {
        $renderedHours = (int) ($hoursRow['rendered_hours'] ?? 0);
        $remainingHours = (int) ($hoursRow['remaining_hours'] ?? 100);
    }
} elseif (db_table_exists($conn, 'duty_logs')) {
    $hoursStmt = db_prepare($conn, 'SELECT COALESCE(SUM(hours), 0) AS rendered_hours FROM duty_logs WHERE user_id = ?');
    $hoursStmt->bind_param('i', $userId);
    $hoursStmt->execute();
    $hoursRow = $hoursStmt->get_result()?->fetch_assoc();
    $hoursStmt->close();
    $renderedHours = (int) ($hoursRow['rendered_hours'] ?? 0);
    $remainingHours = max(0, 100 - $renderedHours);
}
$gpa = (float) ($profile['gpa'] ?? 0);
$yearLevel = (string) ($profile['year_level'] ?? '0');
$scholarshipStatus = trim((string) ($profile['scholarship_status'] ?? ''));
$applicationStatus = trim((string) ($profile['application_status'] ?? ''));
$rawStatus = $scholarshipStatus !== '' ? $scholarshipStatus : $applicationStatus;
$statusLabel = $rawStatus !== '' ? ucfirst($rawStatus) : 'Pending';

$stats = [
    'display_name' => $displayName,
    'category' => $category,
    'course' => (string) ($profile['course'] ?? ''),
    'status' => $statusLabel,
    'type' => $category,
    'gwa' => $gpa > 0 ? number_format($gpa, 2) : '0.00',
    'gpa' => $gpa > 0 ? number_format($gpa, 2) : '0.00',
    'units' => $yearLevel !== '0' ? $yearLevel : '0',
    'rendered_hours' => (string) $renderedHours,
    'remaining_hours' => (string) $remainingHours,
    'assigned_area' => (string) ($profile['assigned_area'] ?? '-'),
    'academic_type' => (string) ($profile['academic_type'] ?? '-'),
    'sport_type' => (string) ($profile['sport_type'] ?? '-'),
    'gift_type' => (string) ($profile['gift_type'] ?? '-'),
    'remarks' => (string) ($profile['application_remarks'] ?? ''),
];

respond_success([
    'stats' => $stats,
    'submissions' => $submissions,
]);
