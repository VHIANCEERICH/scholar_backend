<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

$sql = "
    SELECT
        s.submission_id,
        s.application_id,
        s.requirement_id,
        s.file_path,
        s.status,
        s.upload_date,
        s.reviewer_comment,
        s.remarks,
        s.computed_average,
        a.scholar_id,
        a.status AS application_status,
        sc.user_id,
        sc.first_name,
        sc.middle_name,
        sc.last_name,
        r.requirement_name
    FROM submissions s
    LEFT JOIN applications a ON a.application_id = s.application_id
    LEFT JOIN scholars sc ON sc.scholar_id = a.scholar_id
    LEFT JOIN requirements r ON r.requirement_id = s.requirement_id
    WHERE s.status = 'pending'
    ORDER BY s.upload_date DESC
";

$result = $conn->query($sql);
if (!$result) {
    respond_error('Failed to retrieve pending verifications: ' . $conn->error, 500);
}

$items = [];
while ($row = $result->fetch_assoc()) {
    $documentType = $row['requirement_name'] ?: ('Requirement #' . (int) $row['requirement_id']);
    $normalizedType = strtolower($documentType);
    $showAverage = str_contains($normalizedType, 'report') && str_contains($normalizedType, 'grade');

    $items[] = [
        'submission_id' => (int) $row['submission_id'],
        'id' => (int) $row['submission_id'],
        'application_id' => isset($row['application_id']) ? (int) $row['application_id'] : null,
        'scholar_id' => isset($row['scholar_id']) ? (int) $row['scholar_id'] : null,
        'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
        'document_type' => $documentType,
        'requirement_id' => isset($row['requirement_id']) ? (int) $row['requirement_id'] : null,
        'file_path' => $row['file_path'],
        'image_url' => make_public_file_url((string) $row['file_path']),
        'status' => $row['status'],
        'admin_status' => ucfirst((string) $row['status']),
        'upload_date' => $row['upload_date'],
        'remarks' => $row['remarks'],
        'reviewer_comment' => $row['reviewer_comment'],
        'computed_average' => $showAverage ? $row['computed_average'] : null,
        'average' => $showAverage ? $row['computed_average'] : null,
        'scholar_name' => trim(implode(' ', array_filter([
            $row['first_name'] ?? '',
            $row['middle_name'] ?? '',
            $row['last_name'] ?? '',
        ]))),
    ];
}

respond_success(['data' => $items]);
