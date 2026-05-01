<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';
require_once __DIR__ . '/supervisor_auth_common.php';

require_method('GET');
require_supervisor_auth($conn);

$stmt = db_prepare(
    $conn,
    "SELECT
        s.scholar_id,
        COALESCE(u.user_id, s.user_id) AS user_id,
        CONCAT_WS(' ', s.first_name, s.last_name) AS scholar_name,
        s.first_name,
        s.last_name,
        s.course,
        s.year_level,
        s.assigned_area,
        s.supervisor,
        s.scholarship_category,
        s.scholarship_status
     FROM scholars s
     LEFT JOIN users u ON u.user_id = s.user_id
     WHERE COALESCE(u.is_active, 1) = 1
     ORDER BY s.last_name ASC, s.first_name ASC"
);
$stmt->execute();
$result = $stmt->get_result();
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

foreach ($rows as &$row) {
    $row['scholar_id'] = (int) ($row['scholar_id'] ?? 0);
    $row['user_id'] = (int) ($row['user_id'] ?? 0);
    $row['year_level'] = (int) ($row['year_level'] ?? 0);
    $row['course_year'] = trim((string) ($row['course'] ?? '')) !== ''
        ? trim((string) ($row['course'] ?? '')) . ' - Year ' . (int) ($row['year_level'] ?? 0)
        : 'Year ' . (int) ($row['year_level'] ?? 0);
    $row['assigned_area'] = trim((string) ($row['assigned_area'] ?? ''));
    $row['supervisor'] = trim((string) ($row['supervisor'] ?? ''));
}
unset($row);

respond_success(['data' => $rows]);
