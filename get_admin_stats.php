<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

$totalScholars = (int) ($conn->query('SELECT COUNT(*) AS count FROM scholars')->fetch_assoc()['count'] ?? 0);
$pendingDocuments = (int) ($conn->query("SELECT COUNT(*) AS count FROM submissions WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0);
$approvedDocuments = (int) ($conn->query("SELECT COUNT(*) AS count FROM submissions WHERE status = 'approved'")->fetch_assoc()['count'] ?? 0);
$totalApplications = (int) ($conn->query('SELECT COUNT(*) AS count FROM applications')->fetch_assoc()['count'] ?? 0);
$activeScholars = (int) ($conn->query("SELECT COUNT(*) AS count FROM scholars WHERE scholarship_status = 'active'")->fetch_assoc()['count'] ?? 0);

$gradeReports = (int) ($conn->query(
    "SELECT COUNT(*) AS count
     FROM submissions s
     LEFT JOIN requirements r ON r.requirement_id = s.requirement_id
     WHERE (s.requirement_id = 0)
        OR (LOWER(r.requirement_name) LIKE '%report%' AND LOWER(r.requirement_name) LIKE '%grade%')"
)->fetch_assoc()['count'] ?? 0);

$renewalLetters = (int) ($conn->query(
    "SELECT COUNT(*) AS count
     FROM submissions s
     LEFT JOIN requirements r ON r.requirement_id = s.requirement_id
     WHERE (s.requirement_id = 1)
        OR (LOWER(r.requirement_name) LIKE '%renewal%')"
)->fetch_assoc()['count'] ?? 0);

$recentSql = "
    SELECT
        s.submission_id,
        s.status,
        s.upload_date,
        s.requirement_id,
        r.requirement_name,
        CONCAT_WS(' ', sc.first_name, sc.middle_name, sc.last_name) AS scholar_name
    FROM submissions s
    LEFT JOIN applications a ON a.application_id = s.application_id
    LEFT JOIN scholars sc ON sc.scholar_id = a.scholar_id
    LEFT JOIN requirements r ON r.requirement_id = s.requirement_id
    ORDER BY s.upload_date DESC
    LIMIT 10
";

$recentResult = $conn->query($recentSql);
$recentSubmissions = [];
if ($recentResult) {
    while ($row = $recentResult->fetch_assoc()) {
        $recentSubmissions[] = [
            'submission_id' => (int) $row['submission_id'],
            'name' => trim((string) ($row['scholar_name'] ?? '')) ?: 'Unknown Scholar',
            'type' => $row['requirement_name'] ?: ('Requirement #' . (int) $row['requirement_id']),
            'status' => ucfirst((string) $row['status']),
            'upload_date' => $row['upload_date'],
        ];
    }
}

respond_success([
    'total_scholars' => $totalScholars,
    'pending_documents' => $pendingDocuments,
    'pending_renewals' => $pendingDocuments,
    'approved_documents' => $approvedDocuments,
    'total_applications' => $totalApplications,
    'grade_reports' => $gradeReports,
    'renewal_letters' => $renewalLetters,
    'active_scholars' => $activeScholars,
    'recent_submissions' => $recentSubmissions,
]);
