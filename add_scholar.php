<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

function users_has_google_id_column(mysqli $conn): bool
{
    static $hasColumn = null;
    if ($hasColumn !== null) {
        return $hasColumn;
    }

    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'google_id'");
    $hasColumn = $result instanceof mysqli_result && $result->num_rows > 0;
    return $hasColumn;
}

function ensure_users_google_id_column(mysqli $conn): bool
{
    if (users_has_google_id_column($conn)) {
        return true;
    }

    if ($conn->query("ALTER TABLE users ADD COLUMN google_id VARCHAR(191) NOT NULL DEFAULT '' AFTER email") === true) {
        return true;
    }

    return users_has_google_id_column($conn);
}

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
$plainPassword = (string) ($data['password'] ?? '');
$googleId = trim((string) ($data['google_id'] ?? ''));

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
    $googleColumnReady = $googleId !== '' ? ensure_users_google_id_column($conn) : false;

    if ($userId <= 0) {
        if ($email === '' || $plainPassword === '') {
            respond_error('Email and password are required to create a scholar account.', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond_error('Invalid email address for scholar account.', 422);
        }

        if (strlen($plainPassword) < 6) {
            respond_error('Password must be at least 6 characters.', 422);
        }

        $checkStmt = db_prepare($conn, 'SELECT user_id FROM users WHERE email = ? LIMIT 1');
        $checkStmt->bind_param('s', $email);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()?->fetch_assoc();
        $checkStmt->close();

        if ($exists) {
            respond_error('Email is already in use.', 409);
        }

        if ($username === '') {
            $username = trim($firstName . ' ' . $lastName);
        }

        $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new RuntimeException('Failed to hash password');
        }

        // Store hash in password_hash; keep legacy password column empty.
        $legacyPassword = '';
        if ($googleColumnReady) {
            $userStmt = db_prepare(
                $conn,
                "INSERT INTO users (username, email, google_id, password_hash, password, role, is_active) VALUES (?, ?, ?, ?, ?, 'scholar', 1)"
            );
            $userStmt->bind_param('sssss', $username, $email, $googleId, $passwordHash, $legacyPassword);
        } else {
            $userStmt = db_prepare(
                $conn,
                "INSERT INTO users (username, email, password_hash, password, role, is_active) VALUES (?, ?, ?, ?, 'scholar', 1)"
            );
            $userStmt->bind_param('ssss', $username, $email, $passwordHash, $legacyPassword);
        }
        if (!$userStmt->execute()) {
            throw new RuntimeException('Failed to create scholar user: ' . $userStmt->error);
        }

        $userId = $userStmt->insert_id;
        $createdUser = true;
        $userStmt->close();
    } else {
        $existingUserStmt = db_prepare(
            $conn,
            'SELECT user_id, email, username, role FROM users WHERE user_id = ? LIMIT 1'
        );
        $existingUserStmt->bind_param('i', $userId);
        $existingUserStmt->execute();
        $existingUser = $existingUserStmt->get_result()?->fetch_assoc();
        $existingUserStmt->close();

        if (!$existingUser) {
            respond_error('Linked user account was not found.', 404);
        }

        $existingRole = strtolower(trim((string) ($existingUser['role'] ?? '')));
        if ($existingRole !== 'scholar') {
            respond_error('Linked account is not a scholar account.', 409);
        }

        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                respond_error('Invalid email address for scholar account.', 422);
            }

            $conflictStmt = db_prepare(
                $conn,
                'SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1'
            );
            $conflictStmt->bind_param('si', $email, $userId);
            $conflictStmt->execute();
            $conflict = $conflictStmt->get_result()?->fetch_assoc();
            $conflictStmt->close();

            if ($conflict) {
                respond_error('Email is already in use by another account.', 409);
            }
        } else {
            $email = trim((string) ($existingUser['email'] ?? ''));
        }

        if ($username === '') {
            $username = trim((string) ($existingUser['username'] ?? ''));
            if ($username === '') {
                $username = trim($firstName . ' ' . $lastName);
            }
        }

        if ($plainPassword !== '' && strlen($plainPassword) < 6) {
            respond_error('Password must be at least 6 characters.', 422);
        }

        $passwordHash = '';
        $legacyPassword = '';
        if ($plainPassword !== '') {
            $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                throw new RuntimeException('Failed to hash password');
            }
        }

        if ($googleColumnReady) {
            if ($plainPassword !== '') {
                $updateUserStmt = db_prepare(
                    $conn,
                    'UPDATE users
                     SET username = ?, email = ?, google_id = CASE WHEN ? <> \'\' THEN ? ELSE google_id END, password_hash = ?, password = ?
                     WHERE user_id = ?'
                );
                $updateUserStmt->bind_param(
                    'ssssssi',
                    $username,
                    $email,
                    $googleId,
                    $googleId,
                    $passwordHash,
                    $legacyPassword,
                    $userId
                );
            } else {
                $updateUserStmt = db_prepare(
                    $conn,
                    'UPDATE users
                     SET username = ?, email = ?, google_id = CASE WHEN ? <> \'\' THEN ? ELSE google_id END
                     WHERE user_id = ?'
                );
                $updateUserStmt->bind_param(
                    'ssssi',
                    $username,
                    $email,
                    $googleId,
                    $googleId,
                    $userId
                );
            }
        } else {
            if ($plainPassword !== '') {
                $updateUserStmt = db_prepare(
                    $conn,
                    'UPDATE users SET username = ?, email = ?, password_hash = ?, password = ? WHERE user_id = ?'
                );
                $updateUserStmt->bind_param(
                    'ssssi',
                    $username,
                    $email,
                    $passwordHash,
                    $legacyPassword,
                    $userId
                );
            } else {
                $updateUserStmt = db_prepare(
                    $conn,
                    'UPDATE users SET username = ?, email = ? WHERE user_id = ?'
                );
                $updateUserStmt->bind_param('ssi', $username, $email, $userId);
            }
        }

        if (!$updateUserStmt->execute()) {
            throw new RuntimeException('Failed to update linked scholar user: ' . $updateUserStmt->error);
        }
        $updateUserStmt->close();

        $existingScholarStmt = db_prepare(
            $conn,
            'SELECT scholar_id FROM scholars WHERE user_id = ? LIMIT 1'
        );
        $existingScholarStmt->bind_param('i', $userId);
        $existingScholarStmt->execute();
        $existingScholar = $existingScholarStmt->get_result()?->fetch_assoc();
        $existingScholarStmt->close();

        if ($existingScholar) {
            respond_success([
                'message' => 'Scholar profile already exists for this Google-linked account',
                'user_id' => $userId,
                'scholar_id' => (int) ($existingScholar['scholar_id'] ?? 0),
                'account_created' => false,
                'linked_existing_user' => true,
            ]);
        }
    }

    $scholarStmt = db_prepare(
        $conn,
        "INSERT INTO scholars (user_id, first_name, middle_name, last_name, course, year_level, scholarship_category, assigned_area, scholarship_status, academic_type, sport_type, gift_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''))"
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

    if (db_table_exists($conn, 'notifications')) {
        $notificationMessage = "NEW_SCHOLAR_ACCOUNT:" . $userId . "\n";
        $notificationMessage .= $username . " has created a scholar account.";

        $adminStmt = db_prepare(
            $conn,
            "SELECT user_id FROM users WHERE role = 'admin' AND is_active = 1"
        );
        $adminStmt->execute();
        $admins = $adminStmt->get_result();

        if ($admins instanceof mysqli_result) {
            $notifyStmt = db_prepare(
                $conn,
                'INSERT INTO notifications (user_id, message) VALUES (?, ?)'
            );
            while ($admin = $admins->fetch_assoc()) {
                $adminId = (int) ($admin['user_id'] ?? 0);
                if ($adminId <= 0) {
                    continue;
                }
                $notifyStmt->bind_param('is', $adminId, $notificationMessage);
                $notifyStmt->execute();
            }
            $notifyStmt->close();
        }
        $adminStmt->close();
    }

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
