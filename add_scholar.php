<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

error_log('add_scholar.php HIT: ' . __FILE__);

require_method('POST');
$data = require_fields([
    'first_name',
    'last_name',
    'course',
    'year_level',
    'scholarship_category',
]);

$firstName = trim((string) $data['first_name']);
$middleName = trim((string) ($data['middle_name'] ?? ''));
$lastName = trim((string) $data['last_name']);
$course = trim((string) $data['course']);
$yearLevel = (int) $data['year_level'];
$category = trim((string) $data['scholarship_category']);
$assignedArea = trim((string) ($data['assigned_area'] ?? ''));
$academicType = trim((string) ($data['academic_type'] ?? ''));
$sportType = trim((string) ($data['sport_type'] ?? ''));
$giftType = trim((string) ($data['gift_type'] ?? ''));
$rawScholarshipStatus = strtolower(trim((string) ($data['scholarship_status'] ?? 'pending')));
$userId = (int) ($data['user_id'] ?? 0);
$email = trim((string) ($data['email'] ?? ''));
$username = trim((string) ($data['username'] ?? ''));
$plainPassword = (string) ($data['password'] ?? '123456');

$allowedCategories = ['student_assistant', 'academic', 'varsity', 'gift_of_education'];
$allowedStatuses = ['terminated', 'approved', 'under_verification', 'pending'];
$statusAliases = [
    '' => 'pending',
    'active' => 'approved',
    'approved' => 'approved',
    'pending' => 'pending',
    'under_verification' => 'under_verification',
    'under verification' => 'under_verification',
    'for_verification' => 'under_verification',
    'for verification' => 'under_verification',
    'probation' => 'under_verification',
    'terminated' => 'terminated',
];
$allowedAcademicTypes = ['A', 'B', 'C'];
$allowedGiftTypes = ['ip_member', 'pwd'];

$scholarshipStatus = $statusAliases[$rawScholarshipStatus] ?? $rawScholarshipStatus;

if (!in_array($category, $allowedCategories, true)) {
    respond_error('Invalid scholarship category', 422);
}

if (!in_array($scholarshipStatus, $allowedStatuses, true)) {
    respond_error('Invalid scholarship status', 422);
}

if ($yearLevel <= 0) {
    respond_error('Invalid year level', 422);
}

if ($category === 'academic') {
    $academicType = strtoupper($academicType);
    if (!in_array($academicType, $allowedAcademicTypes, true)) {
        respond_error('Invalid academic type', 422);
    }
} else {
    $academicType = '';
}

if ($category === 'varsity') {
    if ($sportType === '') {
        respond_error('Invalid sport type', 422);
    }
} else {
    $sportType = '';
}

if ($category === 'gift_of_education') {
    $giftType = strtolower($giftType);
    if ($giftType === '') {
        $giftType = 'ip_member';
    }
    if (!in_array($giftType, $allowedGiftTypes, true)) {
        respond_error('Invalid gift type', 422);
    }
} else {
    $giftType = '';
}

$createdUser = false;
$conn->begin_transaction();

try {
    if ($userId <= 0) {
        if ($email === '') {
            $baseLocal = strtolower(preg_replace('/[^a-z0-9]+/i', '', $firstName . '.' . $lastName));
            $baseLocal = $baseLocal !== '' ? $baseLocal : 'scholar';
            $email = $baseLocal . '@jmc.edu.ph';
            $suffix = 1;

            while (true) {
                $checkStmt = db_prepare($conn, 'SELECT user_id FROM users WHERE email = ? LIMIT 1');
                $checkStmt->bind_param('s', $email);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()?->fetch_assoc();
                $checkStmt->close();

                if (!$exists) {
                    break;
                }

                $email = $baseLocal . $suffix . '@jmc.edu.ph';
                $suffix++;
            }
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email address for scholar account');
        }

        if ($username === '') {
            $username = $firstName . ' ' . $lastName;
        }

        $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $userStmt = db_prepare(
            $conn,
            "INSERT INTO users (username, email, password_hash, password, role, is_active) VALUES (?, ?, ?, ?, 'scholar', 1)"
        );
        $userStmt->bind_param('ssss', $username, $email, $passwordHash, $passwordHash);
        if (!$userStmt->execute()) {
            throw new RuntimeException('Failed to create scholar user: ' . $userStmt->error);
        }

        $userId = $userStmt->insert_id;
        $createdUser = true;
        $userStmt->close();
    }

    $scholarStmt = db_prepare(
        $conn,
        "INSERT INTO scholars (user_id, first_name, middle_name, last_name, course, year_level, scholarship_category, assigned_area, scholarship_status, academic_type, sport_type, gift_type)`n         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''))"
    );
    $scholarStmt->bind_param(
        'issssissssss',
        $userId,
        $firstName,
        $middleName,
        $lastName,
        $course,
        $yearLevel,
        $category,
        $assignedArea,
        $scholarshipStatus,
        $academicType,
        $sportType,
        $giftType
    );

    if (!$scholarStmt->execute()) {
        throw new RuntimeException('Failed to create scholar profile: ' . $scholarStmt->error);
    }

    $scholarId = $scholarStmt->insert_id;
    $scholarStmt->close();
    $conn->commit();

    respond_success([
        'message' => 'Scholar successfully created',
        'user_id' => $userId,
        'scholar_id' => $scholarId,
        'account_created' => $createdUser,
    ], 201);
} catch (Throwable $e) {
    $conn->rollback();
    respond_error($e->getMessage(), 500);
}