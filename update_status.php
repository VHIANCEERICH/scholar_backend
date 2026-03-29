<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
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

$stmt = db_prepare(
    $conn,
    'UPDATE submissions SET status = ?, reviewer_comment = ? WHERE submission_id = ?'
);
$stmt->bind_param('ssi', $status, $reviewerComment, $submissionId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to update verification: ' . $error, 500);
}

$updated = $stmt->affected_rows;
$stmt->close();

if ($updated === 0) {
    respond_error('Submission not found or unchanged', 404);
}

respond_success(['message' => 'Verification updated']);
