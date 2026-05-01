<?php
declare(strict_types=1);

require_once __DIR__ . '/account_request_common.php';

require_method('GET');
ensure_account_requests_table($conn);

$status = strtolower(trim((string) ($_GET['status'] ?? 'pending')));
if (!in_array($status, ['pending', 'approved', 'declined', 'all'], true)) {
    $status = 'pending';
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 120;
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
$limit = max(1, min(300, $limit));
$offset = max(0, $offset);

$where = '';
$params = [];
$types = '';
if ($status !== 'all') {
    $where = 'WHERE status = ?';
    $params[] = $status;
    $types .= 's';
}

$sql = "
    SELECT
        request_id,
        request_kind,
        existing_user_id,
        role,
        username,
        email,
        scholarship_category,
        scholarship_type_label,
        course,
        year_level,
        status,
        google_id,
        requested_at,
        reviewed_at,
        reviewed_by,
        review_note
    FROM account_requests
    {$where}
    ORDER BY requested_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = db_prepare($conn, $sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = fetch_all_assoc($stmt);
$stmt->close();

foreach ($rows as &$row) {
    $row['request_id'] = (int) ($row['request_id'] ?? 0);
    $row['existing_user_id'] = (int) ($row['existing_user_id'] ?? 0);
    $row['year_level'] = (int) ($row['year_level'] ?? 0);
    $row['reviewed_by'] = (int) ($row['reviewed_by'] ?? 0);
}
unset($row);

respond_success([
    'data' => $rows,
    'pending_count' => account_request_pending_count($conn),
]);
