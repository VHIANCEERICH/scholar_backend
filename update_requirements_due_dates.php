<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

if (!db_table_exists($conn, 'requirements')) {
    respond_error('Requirements table not found', 500);
}

require_method('POST');
$data = require_fields(['grade_due_date', 'renewal_due_date']);

$gradeDue = trim((string) $data['grade_due_date']);
$renewalDue = trim((string) $data['renewal_due_date']);

if ($gradeDue === '' || $renewalDue === '') {
    respond_error('Both grade_due_date and renewal_due_date are required', 422);
}

$updateByName = function(string $keyword, string $dueDate) use ($conn) {
    $stmt = db_prepare(
        $conn,
        'UPDATE requirements SET due_date = ? WHERE LOWER(requirement_name) LIKE ?'
    );
    $like = '%' . strtolower($keyword) . '%';
    $stmt->bind_param('ss', $dueDate, $like);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return [$ok, $affected];
};

[$okGrades, $affectedGrades] = $updateByName('grade', $gradeDue);
[$okRenewal, $affectedRenewal] = $updateByName('renewal', $renewalDue);

if (!$okGrades || !$okRenewal) {
    respond_error('Failed to update due dates', 500);
}

respond_success([
    'message' => 'Due dates updated',
    'grade_due_date' => $gradeDue,
    'renewal_due_date' => $renewalDue,
    'updated_grades' => $affectedGrades,
    'updated_renewal' => $affectedRenewal,
]);
