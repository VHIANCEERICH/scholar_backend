<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

function ensure_evaluations_table(mysqli $conn): void
{
    if (!db_table_exists($conn, 'evaluations')) {
        respond_error('Evaluations module is unavailable because the evaluations table does not exist', 500);
    }

    $result = $conn->query("SHOW COLUMNS FROM evaluations LIKE 'supervisor_user_id'");
    $hasColumn = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->close();
    }
    if (!$hasColumn) {
        if (!$conn->query("ALTER TABLE evaluations ADD COLUMN supervisor_user_id INT NOT NULL DEFAULT 0 AFTER program_type")) {
            respond_error('Failed to add supervisor_user_id column: ' . $conn->error, 500);
        }
    }
}

ensure_evaluations_table($conn);

$evaluationId = (int) ($_GET['evaluation_id'] ?? request_value('evaluation_id', 0));
if ($evaluationId <= 0) {
    respond_error('Invalid evaluation_id', 422);
}

$stmt = db_prepare(
    $conn,
    "
    SELECT
        e.evaluation_id,
        e.scholar_id,
        e.program_type,
        e.supervisor_user_id,
        e.course_year,
        e.assigned_area,
        e.supervisor_name,
        e.month_label,
        e.ratings_json,
        e.total_score,
        e.average_score,
        e.recommendation,
        e.created_at,
        CONCAT_WS(' ', s.first_name, s.last_name) AS scholar_name,
        s.scholarship_category
    FROM evaluations e
    LEFT JOIN scholars s ON s.scholar_id = e.scholar_id
    WHERE e.evaluation_id = ?
    LIMIT 1
    "
);
$stmt->bind_param('i', $evaluationId);
$stmt->execute();
$row = $stmt->get_result()?->fetch_assoc();
$stmt->close();

if (!$row) {
    respond_error('Evaluation not found', 404);
}

respond_success(['evaluation' => $row]);
