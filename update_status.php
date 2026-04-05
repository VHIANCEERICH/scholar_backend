<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$data = require_fields(['status']);

$submissionId = (int) ($data['submission_id'] ?? $data['id'] ?? 0);
$status = strtolower(trim((string) $data['status']));
$reviewerComment = trim((string) ($data['reviewer_comment'] ?? $data['remarks'] ?? ''));
$allowedStatuses = ['pending', 'approved', 'rejected'];

if ($submissionId <= 0) {
    respond_error('Invalid submission_id', 422);
}

if (!in_array($status, $allowedStatuses, true)) {
    respond_error('Invalid status', 422);
}

try {
    $conn->begin_transaction();

    $updateSubmission = db_prepare(
        $conn,
        'UPDATE submissions SET status = ?, reviewer_comment = ? WHERE submission_id = ?'
    );
    $updateSubmission->bind_param('ssi', $status, $reviewerComment, $submissionId);

    if (!$updateSubmission->execute()) {
        $error = $updateSubmission->error;
        $updateSubmission->close();
        throw new RuntimeException('Failed to update verification: ' . $error);
    }

    $updated = $updateSubmission->affected_rows;
    $updateSubmission->close();

    if ($updated === 0) {
        $conn->rollback();
        respond_error('Submission not found or unchanged', 404);
    }

    // Keep parent application status in sync for downstream modules.
    $applicationLookup = db_prepare(
        $conn,
        'SELECT application_id FROM submissions WHERE submission_id = ? LIMIT 1'
    );
    $applicationLookup->bind_param('i', $submissionId);
    $applicationLookup->execute();
    $appRow = $applicationLookup->get_result()?->fetch_assoc();
    $applicationLookup->close();

    $applicationId = (int) ($appRow['application_id'] ?? 0);
    if ($applicationId > 0 && db_table_exists($conn, 'applications')) {
        $appStatus = $status;
        $updateApplication = db_prepare(
            $conn,
            'UPDATE applications SET status = ? WHERE application_id = ?'
        );
        $updateApplication->bind_param('si', $appStatus, $applicationId);
        $updateApplication->execute();
        $updateApplication->close();
    }

    $conn->commit();
    respond_success(['message' => 'Verification updated']);
} catch (Throwable $e) {
    $conn->rollback();
    respond_error('Failed to update verification: ' . $e->getMessage(), 500);
}
