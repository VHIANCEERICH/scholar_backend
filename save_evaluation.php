<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';
require_once __DIR__ . '/supervisor_auth_common.php';

function ensure_evaluations_table(mysqli $conn): void
{
    if (!db_table_exists($conn, 'evaluations')) {
        $sql = "
            CREATE TABLE evaluations (
                evaluation_id INT AUTO_INCREMENT PRIMARY KEY,
                scholar_id INT NOT NULL,
                program_type VARCHAR(30) NOT NULL,
                supervisor_user_id INT NOT NULL DEFAULT 0,
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
                INDEX idx_scholar_created (scholar_id, created_at),
                INDEX idx_supervisor_created (supervisor_user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if (!$conn->query($sql)) {
            respond_error('Failed to create evaluations table: ' . $conn->error, 500);
        }
        return;
    }

    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM evaluations');
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $field = strtolower((string) ($row['Field'] ?? ''));
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
        $result->close();
    }

    if (!isset($columns['supervisor_user_id'])) {
        if (!$conn->query("ALTER TABLE evaluations ADD COLUMN supervisor_user_id INT NOT NULL DEFAULT 0 AFTER program_type")) {
            respond_error('Failed to add supervisor_user_id column: ' . $conn->error, 500);
        }
    }
}

require_method('POST');
ensure_evaluations_table($conn);
$supervisor = require_supervisor_auth($conn);

$data = require_fields([
    'scholar_id',
    'program_type',
    'ratings_json',
    'total_score',
    'average_score',
]);

$scholarId = (int) ($data['scholar_id'] ?? 0);
$programType = strtolower(trim((string) ($data['program_type'] ?? '')));
$courseYear = trim((string) ($data['course_year'] ?? ''));
$assignedArea = trim((string) ($data['assigned_area'] ?? ''));
$supervisorName = trim((string) ($supervisor['username'] ?? ''));
$supervisorUserId = (int) ($supervisor['user_id'] ?? 0);
$monthLabel = trim((string) ($data['month_label'] ?? ''));
$ratingsJson = (string) ($data['ratings_json'] ?? '');
$totalScore = (int) ($data['total_score'] ?? 0);
$averageScore = (float) ($data['average_score'] ?? 0);
$recommendation = trim((string) ($data['recommendation'] ?? ''));

$allowedPrograms = ['student_assistant', 'varsity'];

if ($scholarId <= 0) {
    respond_error('Invalid scholar_id', 422);
}

if (!in_array($programType, $allowedPrograms, true)) {
    respond_error('Invalid program_type', 422);
}

// Validate ratings_json is JSON.
$decoded = json_decode($ratingsJson, true);
if (!is_array($decoded)) {
    respond_error('Invalid ratings_json', 422);
}

$stmt = db_prepare(
    $conn,
    'INSERT INTO evaluations (scholar_id, program_type, supervisor_user_id, course_year, assigned_area, supervisor_name, month_label, ratings_json, total_score, average_score, recommendation)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$stmt->bind_param(
    'isisssssids',
    $scholarId,
    $programType,
    $supervisorUserId,
    $courseYear,
    $assignedArea,
    $supervisorName,
    $monthLabel,
    $ratingsJson,
    $totalScore,
    $averageScore,
    $recommendation
);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to save evaluation: ' . $error, 500);
}

$evaluationId = $stmt->insert_id;
$stmt->close();

respond_success([
    'message' => 'Evaluation saved successfully',
    'evaluation_id' => $evaluationId,
]);
