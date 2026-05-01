<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('GET');

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
$limit = max(1, min(500, $limit));
$offset = max(0, $offset);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function extract_doc_type_marker(string $remarks): string
{
    if (preg_match('/\[doc_type:([^\]]+)\]/i', $remarks, $m) === 1) {
        return trim((string) ($m[1] ?? ''));
    }
    return '';
}

function strip_doc_type_marker(string $remarks): string
{
    return trim((string) preg_replace('/\[doc_type:[^\]]+\]\s*/i', '', $remarks));
}

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
    WHERE LOWER(TRIM(COALESCE(s.status, ''))) IN ('pending', 'approved', 'rejected')
    ORDER BY CASE LOWER(TRIM(COALESCE(s.status, '')))
        WHEN 'pending' THEN 0
        WHEN 'approved' THEN 1
        WHEN 'rejected' THEN 2
        ELSE 3
    END, s.upload_date DESC
    LIMIT ? OFFSET ?
";

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/connection.php';
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond_error('Database connection is not available', 500);
}

$stmt = db_prepare($conn, $sql);
$stmt->bind_param('ii', $limit, $offset);
$stmt->execute();
$rows = fetch_all_assoc($stmt);
$stmt->close();

$items = [];
foreach ($rows as $row) {
    $requirementId = isset($row['requirement_id']) && $row['requirement_id'] !== null
        ? (int) $row['requirement_id']
        : null;

    $rawRemarks = (string) ($row['remarks'] ?? '');
    $markerDocType = extract_doc_type_marker($rawRemarks);

    $documentType = '';
    if ($markerDocType !== '') {
        $documentType = $markerDocType;
    } elseif (!empty($row['requirement_name'])) {
        $documentType = (string) $row['requirement_name'];
    } elseif ($requirementId !== null) {
        $documentType = 'Requirement #' . $requirementId;
    } else {
        $documentType = 'Renewal Letter';
    }

    $normalizedType = strtolower(trim((string) $documentType));
    $isReportOfGrades = $requirementId === 0
        || $normalizedType === 'requirement #0'
        || (str_contains($normalizedType, 'report') && str_contains($normalizedType, 'grade'));

    $computedAverage = $row['computed_average'];
    if (($computedAverage === null || $computedAverage === '') && $rawRemarks !== '') {
        if (preg_match('/\baverage\s*:\s*([0-9]+(?:\.[0-9]+)?)/i', $rawRemarks, $m) === 1) {
            $computedAverage = (float) $m[1];
        }
    }

    $uploadDateRaw = trim((string) ($row['upload_date'] ?? ''));
    $submittedAt = '';
    if ($uploadDateRaw !== '') {
        try {
            $submittedAt = (new DateTimeImmutable(str_replace('T', ' ', $uploadDateRaw)))
                ->format(DateTimeInterface::ATOM);
        } catch (Throwable $e) {
            $submittedAt = $uploadDateRaw;
        }
    }

    $items[] = [
        'submission_id' => (int) $row['submission_id'],
        'id' => (int) $row['submission_id'],
        'application_id' => isset($row['application_id']) ? (int) $row['application_id'] : null,
        'scholar_id' => isset($row['scholar_id']) ? (int) $row['scholar_id'] : null,
        'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
        'document_type' => $documentType,
        'requirement_id' => $requirementId,
        'file_path' => $row['file_path'],
        'image_url' => make_public_file_url((string) $row['file_path']),
        'status' => $row['status'],
        'admin_status' => ucfirst((string) $row['status']),
        'upload_date' => $row['upload_date'],
        'submitted_at' => $submittedAt,
        'remarks' => strip_doc_type_marker($rawRemarks),
        'reviewer_comment' => $row['reviewer_comment'],
        'computed_average' => $isReportOfGrades ? $computedAverage : null,
        'average' => $isReportOfGrades ? $computedAverage : null,
        'scholar_name' => trim(implode(' ', array_filter([
            $row['first_name'] ?? '',
            $row['middle_name'] ?? '',
            $row['last_name'] ?? '',
        ]))),
    ];
}

respond_success(['data' => $items]);
#testingS
