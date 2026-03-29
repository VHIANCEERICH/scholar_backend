<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

$hasDutyTotals = db_table_exists($conn, 'duty_totals');

$sql = "
    SELECT
        s.scholar_id,
        COALESCE(u.user_id, s.user_id) AS user_id,
        u.username,
        u.email,
        u.role,
        u.is_active,
        s.first_name,
        s.middle_name,
        s.last_name,
        s.course,
        s.year_level,
        s.gpa,
        s.scholarship_category,
        s.assigned_area,
        s.academic_type,
        s.sport_type,
        s.gift_type,
        s.scholarship_status,
        s.profile_image,
        s.created_at" .
        ($hasDutyTotals ? ",
        dt.rendered_hours,
        dt.remaining_hours" : "") . "
    FROM scholars s
    LEFT JOIN users u ON u.user_id = s.user_id
    " . ($hasDutyTotals ? "LEFT JOIN duty_totals dt ON dt.user_id = u.user_id" : "") . "
    ORDER BY s.scholar_id DESC
";

$result = $conn->query($sql);
if (!$result) {
    respond_error('Failed to retrieve scholars: ' . $conn->error, 500);
}

$scholars = [];
while ($row = $result->fetch_assoc()) {
    $category = strtolower(trim((string) ($row['scholarship_category'] ?? '')));
    if ($category === 'gift_of_education') {
        $giftType = strtolower(trim((string) ($row['gift_type'] ?? '')));
        if ($giftType === '') {
            $row['gift_type'] = 'ip_member';
        }
    }
    $row['user_id'] = (int) $row['user_id'];
    $row['scholar_id'] = (int) $row['scholar_id'];
    $row['year_level'] = (int) $row['year_level'];
    $row['is_active'] = isset($row['is_active']) ? (int) $row['is_active'] : 0;
    $row['profile_image_url'] = make_public_file_url((string) ($row['profile_image'] ?? ''));
    if ($hasDutyTotals) {
        $row['rendered_hours'] = (int) ($row['rendered_hours'] ?? 0);
        $row['remaining_hours'] = (int) ($row['remaining_hours'] ?? 100);
    }
    $scholars[] = $row;
}

respond_success(['data' => $scholars]);
