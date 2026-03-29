<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

function ensure_evaluations_table(mysqli $conn): void
{
    if (db_table_exists($conn, 'evaluations')) {
        return;
    }

    $sql = "
        CREATE TABLE evaluations (
            evaluation_id INT AUTO_INCREMENT PRIMARY KEY,
            scholar_id INT NOT NULL,
            program_type VARCHAR(30) NOT NULL,
            course_year VARCHAR(80) NOT NULL DEFAULT '',
            assigned_area VARCHAR(150) NOT NULL DEFAULT '',
            supervisor_name VARCHAR(150) NOT NULL DEFAULT '',
            month_label VARCHAR(60) NOT NULL DEFAULT '',
            ratings_json LONGTEXT NOT NULL,
            total_score INT NOT NULL DEFAULT 0,
            average_score DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            recommendation TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_program_created (program_type, created_at),
            INDEX idx_scholar_created (scholar_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$conn->query($sql)) {
        respond_error('Failed to create evaluations table: ' . $conn->error, 500);
    }
}

ensure_evaluations_table($conn);

$programType = strtolower(trim((string) ($_GET['program_type'] ?? '')));
$limit = (int) ($_GET['limit'] ?? 100);
if ($limit <= 0) {
    $limit = 100;
}
$limit = min(200, $limit);

$params = [];
$types = '';
$where = '';

if ($programType !== '') {
    $allowedPrograms = ['student_assistant', 'varsity'];
    if (!in_array($programType, $allowedPrograms, true)) {
        respond_error('Invalid program_type', 422);
    }
    $where = 'WHERE e.program_type = ?';
    $types .= 's';
    $params[] = $programType;
}

$sql = "
    SELECT
        e.evaluation_id,
        e.scholar_id,
        e.program_type,
        COALESCE(NULLIF(e.course_year, ''), CONCAT_WS(' - ', s.course, s.year_level)) AS course_year,
        e.assigned_area,
        e.supervisor_name,
        e.month_label,
        e.total_score,
        e.average_score,
        e.recommendation,
        e.created_at,
        CONCAT_WS(' ', s.first_name, s.last_name) AS scholar_name,
        s.scholarship_category
    FROM evaluations e
    LEFT JOIN scholars s ON s.scholar_id = e.scholar_id
    {$where}
    ORDER BY e.created_at DESC
    LIMIT {$limit}
";

if ($where !== '') {
    $stmt = db_prepare($conn, $sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
} else {
    $result = $conn->query($sql);
    if (!$result) {
        respond_error('Failed to retrieve evaluations: ' . $conn->error, 500);
    }
    $rows = $result->fetch_all(MYSQLI_ASSOC);
}

respond_success(['data' => $rows]);