<?php
declare(strict_types=1);

require_once __DIR__ . '/account_request_common.php';

require_method('POST');
ensure_account_requests_table($conn);

$data = request_data();
$requestId = (int) ($data['request_id'] ?? 0);
$reviewNote = trim((string) ($data['review_note'] ?? ''));

if ($requestId <= 0) {
    respond_error('Invalid request_id', 422);
}

$stmt = db_prepare(
    $conn,
    'SELECT request_id, status FROM account_requests WHERE request_id = ? LIMIT 1'
);
$stmt->bind_param('i', $requestId);
$stmt->execute();
$request = $stmt->get_result()?->fetch_assoc();
$stmt->close();

if (!$request) {
    respond_error('Account request not found', 404);
}

if (strtolower((string) ($request['status'] ?? '')) !== 'pending') {
    respond_error('This request has already been processed.', 409);
}

$updateStmt = db_prepare(
    $conn,
    'UPDATE account_requests SET status = \'declined\', reviewed_at = NOW(), reviewed_by = 0, review_note = ? WHERE request_id = ?'
);
$updateStmt->bind_param('si', $reviewNote, $requestId);

if (!$updateStmt->execute()) {
    $error = $updateStmt->error;
    $updateStmt->close();
    respond_error('Failed to decline request: ' . $error, 500);
}

$updateStmt->close();

respond_success([
    'message' => 'Account request declined',
    'request_id' => $requestId,
]);
