<?php
declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/backend_common.php';

$scholarCounts = [];
$countResult = $conn->query("SELECT scholarship_category, COUNT(*) AS total FROM scholars GROUP BY scholarship_category");
if ($countResult) {
    while ($row = $countResult->fetch_assoc()) {
        $category = trim((string) ($row['scholarship_category'] ?? 'Uncategorized'));
        $scholarCounts[] = [
            'category' => $category !== '' ? $category : 'Uncategorized',
            'total' => (int) ($row['total'] ?? 0),
        ];
    }
}

$gradeDue = '';
$renewalDue = '';
if (db_table_exists($conn, 'requirements')) {
    $reqResult = $conn->query("SELECT requirement_name, due_date FROM requirements");
    if ($reqResult) {
        while ($row = $reqResult->fetch_assoc()) {
            $name = strtolower((string) ($row['requirement_name'] ?? ''));
            $due = (string) ($row['due_date'] ?? '');
            if ($due === '') continue;
            if (strpos($name, 'grade') !== false && $gradeDue === '') {
                $gradeDue = $due;
            }
            if (strpos($name, 'renewal') !== false && $renewalDue === '') {
                $renewalDue = $due;
            }
        }
    }
}

$submissionRows = [];
$gradeMatcher = "(sub2.requirement_id = 0 OR LOWER(COALESCE(r2.requirement_name, '')) LIKE '%report%grade%' OR LOWER(COALESCE(r2.requirement_name, '')) LIKE '%grade%')";
$renewalMatcher = "(sub2.requirement_id = 1 OR LOWER(COALESCE(r2.requirement_name, '')) LIKE '%renewal%')";
$sql = "
    SELECT
        u.user_id,
        CONCAT_WS(' ', s.first_name, s.last_name) AS scholar_name,
        CONCAT_WS(' - ', s.course, s.year_level) AS course_year,
        MAX(sub.upload_date) AS latest_submission,
        (SELECT sub2.status
         FROM submissions sub2
         INNER JOIN applications a2 ON a2.application_id = sub2.application_id
         LEFT JOIN requirements r2 ON r2.requirement_id = sub2.requirement_id
         WHERE a2.scholar_id = s.scholar_id AND {$gradeMatcher}
         ORDER BY sub2.upload_date DESC
         LIMIT 1) AS grade_status_raw,
        (SELECT sub2.upload_date
         FROM submissions sub2
         INNER JOIN applications a2 ON a2.application_id = sub2.application_id
         LEFT JOIN requirements r2 ON r2.requirement_id = sub2.requirement_id
         WHERE a2.scholar_id = s.scholar_id AND {$gradeMatcher}
         ORDER BY sub2.upload_date DESC
         LIMIT 1) AS grade_upload_date,
        (SELECT sub2.status
         FROM submissions sub2
         INNER JOIN applications a2 ON a2.application_id = sub2.application_id
         LEFT JOIN requirements r2 ON r2.requirement_id = sub2.requirement_id
         WHERE a2.scholar_id = s.scholar_id AND {$renewalMatcher}
         ORDER BY sub2.upload_date DESC
         LIMIT 1) AS renewal_status_raw,
        (SELECT sub2.upload_date
         FROM submissions sub2
         INNER JOIN applications a2 ON a2.application_id = sub2.application_id
         LEFT JOIN requirements r2 ON r2.requirement_id = sub2.requirement_id
         WHERE a2.scholar_id = s.scholar_id AND {$renewalMatcher}
         ORDER BY sub2.upload_date DESC
         LIMIT 1) AS renewal_upload_date,
        s.assigned_area,
        s.academic_type,
        s.sport_type,
        s.gift_type,
        s.grant_coverage,
        s.retention_gwa,
        s.scholarship_status,
        s.scholarship_category,
        s.supervisor,
        dt.rendered_hours,
        dt.required_hours
    FROM scholars s
    INNER JOIN users u ON u.user_id = s.user_id
    LEFT JOIN applications a ON a.scholar_id = s.scholar_id
    LEFT JOIN submissions sub ON sub.application_id = a.application_id
    LEFT JOIN duty_totals dt ON dt.user_id = u.user_id
    GROUP BY
        u.user_id,
        s.scholar_id,
        s.first_name,
        s.last_name,
        s.course,
        s.year_level,
        s.assigned_area,
        s.academic_type,
        s.sport_type,
        s.gift_type,
        s.grant_coverage,
        s.retention_gwa,
        s.scholarship_status,
        s.scholarship_category,
        s.supervisor,
        dt.rendered_hours,
        dt.required_hours
    ORDER BY scholar_name ASC
    LIMIT 50
";

$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $gradeStatusRaw = strtolower((string) ($row['grade_status_raw'] ?? ''));
        $renewalStatusRaw = strtolower((string) ($row['renewal_status_raw'] ?? ''));
        $gradeUpload = (string) ($row['grade_upload_date'] ?? '');
        $renewalUpload = (string) ($row['renewal_upload_date'] ?? '');

        $gradeStatus = $gradeStatusRaw === '' ? 'Missing' : ($gradeStatusRaw === 'approved' ? 'Passed' : ($gradeStatusRaw === 'rejected' ? 'Rejected' : 'Pending'));
        $renewalStatus = $renewalStatusRaw === '' ? 'Missing' : ($renewalStatusRaw === 'approved' ? 'Passed' : ($renewalStatusRaw === 'rejected' ? 'Rejected' : 'Pending'));

        $gradeOnTime = $gradeStatus === 'Passed' && ($gradeDue === '' || ($gradeUpload !== '' && strtotime($gradeUpload) <= strtotime($gradeDue)));
        $renewalOnTime = $renewalStatus === 'Passed' && ($renewalDue === '' || ($renewalUpload !== '' && strtotime($renewalUpload) <= strtotime($renewalDue)));

        $remarks = ($gradeOnTime && $renewalOnTime) ? 'Complete' : 'Not Complete';

        $dueDate = $gradeDue;
        if ($renewalDue !== '' && ($dueDate === '' || strtotime($renewalDue) < strtotime($dueDate))) {
            $dueDate = $renewalDue;
        }

        $rendered = (int) ($row['rendered_hours'] ?? 0);
        $required = (int) ($row['required_hours'] ?? 400);
        $dutyHoursDisplay = $rendered . '/' . $required;

        $submissionRows[] = [
            'user_id' => (int) ($row['user_id'] ?? 0),
            'name' => trim((string) ($row['scholar_name'] ?? '')),
            'course_year' => trim((string) ($row['course_year'] ?? '')),
            'latest_submission' => (string) ($row['latest_submission'] ?? ''),
            'due_date' => $dueDate,
            'grade_status' => $gradeStatus,
            'renewal_status' => $renewalStatus,
            'remarks' => $remarks,
            'assigned_area' => (string) ($row['assigned_area'] ?? ''),
            'academic_type' => (string) ($row['academic_type'] ?? ''),
            'sport_type' => (string) ($row['sport_type'] ?? ''),
            'gift_type' => (string) ($row['gift_type'] ?? ''),
            'grant_coverage' => (string) ($row['grant_coverage'] ?? ''),
            'retention_gwa' => (string) ($row['retention_gwa'] ?? ''),
            'scholarship_status' => (string) ($row['scholarship_status'] ?? ''),
            'category' => (string) ($row['scholarship_category'] ?? ''),
            'supervisor' => (string) ($row['supervisor'] ?? '—'),
            'duty_hours' => $dutyHoursDisplay,
        ];
    }
}

respond_success([
    'category_counts' => $scholarCounts,
    'grade_due_date' => $gradeDue,
    'renewal_due_date' => $renewalDue,
    'scholars' => $submissionRows,
]);
