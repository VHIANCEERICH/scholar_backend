<?php
declare(strict_types=1);

require_once __DIR__ . '/scholar_status_common.php';

require_method('POST');
$userId = (int) request_value('user_id', 0);

if ($userId <= 0) {
    respond_error('Invalid user_id', 422);
}

set_scholar_active_state($conn, $userId, false, 'archive');
respond_success(['message' => 'Scholar archived']);
