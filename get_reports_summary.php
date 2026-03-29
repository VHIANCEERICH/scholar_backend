<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

$totalScholars = (int) ($conn->query('SELECT COUNT(*) AS total FROM scholars')->fetch_assoc()['total'] ?? 0);
$approved = (int) ($conn->query("SELECT COUNT(*) AS total FROM submissions WHERE status = 'approved'")->fetch_assoc()['total'] ?? 0);
$pending = (int) ($conn->query("SELECT COUNT(*) AS total FROM submissions WHERE status = 'pending'")->fetch_assoc()['total'] ?? 0);
$rejected = (int) ($conn->query("SELECT COUNT(*) AS total FROM submissions WHERE status = 'rejected'")->fetch_assoc()['total'] ?? 0);

$byType = [];
$typeResult = $conn->query("SELECT scholarship_category, COUNT(*) AS total FROM scholars GROUP BY scholarship_category ORDER BY total DESC");
if ($typeResult) {
    while ($row = $typeResult->fetch_assoc()) {
        $byType[] = [
            'label' => (string) ($row['scholarship_category'] ?? 'Uncategorized'),
            'value' => (int) ($row['total'] ?? 0),
        ];
    }
}

respond_success([
    'summary' => [
        'total_scholars' => $totalScholars,
        'approved' => $approved,
        'pending' => $pending,
        'rejected' => $rejected,
    ],
    'by_type' => $byType,
    'status_distribution' => [
        ['label' => 'Approved', 'value' => $approved],
        ['label' => 'Pending', 'value' => $pending],
        ['label' => 'Rejected', 'value' => $rejected],
    ],
]);