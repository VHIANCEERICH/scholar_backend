<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');
$data = require_fields(['user_id']);

$userId = (int) ($data['user_id'] ?? 0);
$headCoach = trim((string) ($data['head_coach'] ?? ''));
$trainingSchedule = trim((string) ($data['training_schedule'] ?? ''));
$gameSchedule = trim((string) ($data['game_schedule'] ?? ''));

if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

$stmt = db_prepare(
    $conn,
    'UPDATE scholars
     SET head_coach = ?, training_schedule = ?, game_schedule = ?
     WHERE user_id = ?'
);
$stmt->bind_param('sssi', $headCoach, $trainingSchedule, $gameSchedule, $userId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    respond_error('Failed to update varsity details: ' . $error, 500);
}

$stmt->close();

respond_success([
    'message' => 'Varsity details updated successfully',
    'user_id' => $userId,
    'head_coach' => $headCoach,
    'training_schedule' => $trainingSchedule,
    'game_schedule' => $gameSchedule,
]);
