<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

// 1. Get and validate the User ID
$userId = (int) ($_GET['user_id'] ?? request_value('user_id', 0));
if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

// 2. Fetch the latest application details for this scholar
$appStmt = db_prepare(
    $conn,
    'SELECT a.application_id, a.status, a.remarks
     FROM applications a
     INNER JOIN scholars s ON s.scholar_id = a.scholar_id
     WHERE s.user_id = ?
     ORDER BY a.application_id DESC
     LIMIT 1'
);
$appStmt->bind_param('i', $userId);
$appStmt->execute();
$application = $appStmt->get_result()?->fetch_assoc();
$appStmt->close();

// Handle cases where no application exists
if (!$application) {
    respond_success([
        'assigned_work' => 0,
        'rendered_hours' => 0,
        'remaining_hours' => 400,
        'required_hours' => 400,
        'duty_status' => 'No Application',
        'submissions_count' => 0,
        'activity_reports' => 0,
        'status' => 'No Application',
        'submissions' => [],
    ]);
    exit;
}

$applicationId = (int) $application['application_id'];

// 3. Fetch submissions for the dashboard list
$subsStmt = db_prepare(
    $conn,
    'SELECT submission_id, requirement_id, file_path, status, upload_date, remarks, reviewer_comment
     FROM submissions
     WHERE application_id = ?
     ORDER BY upload_date DESC'
);
$subsStmt->bind_param('i', $applicationId);
$subsStmt->execute();
$submissions = fetch_all_assoc($subsStmt);
$subsStmt->close();

// Format submission data for the Flutter UI
foreach ($submissions as &$submission) {
    $submission['submission_id'] = (int) $submission['submission_id'];
    $submission['image_url'] = make_public_file_url((string) $submission['file_path']);
    $submission['doc_name'] = basename((string) ($submission['file_path'] ?? 'Document'));
}
unset($submission);

// 4. Fetch Duty Hours (Prioritize 'duty_totals' table updated by Admin)
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
}

// 5. Final Response
respond_success([
    'application_id' => $applicationId,
    'rendered_hours' => $renderedHours,
    'remaining_hours' => $remainingHours,
    'required_hours' => $requiredHours,
    'duty_status' => $application['status'] ?? 'Pending',
    'status' => $application['status'] ?? 'Pending',
    'submissions_count' => count($submissions),
    'submissions' => $submissions,
    'application_remarks' => $application['remarks'] ?? '',
]);
