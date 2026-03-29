<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('GET');

if (!db_table_exists($conn, 'announcements')) {
    respond_error('Announcements table not found', 404);
}

$limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 20;

$sql = "
    SELECT
        announcement_id,
        title,
        message,
        created_at
    FROM announcements
    ORDER BY created_at DESC
    LIMIT ?
";

$stmt = db_prepare($conn, $sql);
$stmt->bind_param('i', $limit);
$stmt->execute();
$rows = fetch_all_assoc($stmt);
$stmt->close();

foreach ($rows as &$row) {
    $row['announcement_id'] = (int) ($row['announcement_id'] ?? 0);
}
unset($row);

respond_success(['data' => $rows]);
