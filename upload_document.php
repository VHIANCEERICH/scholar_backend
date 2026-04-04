<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_common.php';

require_method('POST');

$startedAt = microtime(true);

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

function upload_json_error(string $message, int $statusCode = 400): void
{
    respond_error($message, $statusCode);
}

function read_docx_text(string $filename): string
{
    if (!class_exists('ZipArchive')) {
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($filename) !== true) {
        return '';
    }

    $index = $zip->locateName('word/document.xml');
    if ($index === false) {
        $zip->close();
        return '';
    }

    $xml = $zip->getFromIndex($index);
    $zip->close();
    return trim(preg_replace('/\s+/', ' ', strip_tags((string) $xml)));
}

function read_pdf_text(string $filename): array
{
    $parserClass = 'Smalot\\PdfParser\\Parser';
    if (!class_exists($parserClass)) {
        return [false, '', 'PDF parser is not installed on the server.'];
    }

    try {
        $parser = new $parserClass();
        $pdf = $parser->parseFile($filename);
        return [true, trim($pdf->getText()), ''];
    } catch (Throwable $e) {
        return [false, '', 'PDF parsing failed.'];
    }
}

function run_tesseract(string $filename): array
{
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $candidates = $isWindows
        ? [
            getenv('TESSERACT_PATH') ?: '',
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
            'tesseract',
        ]
        : [
            getenv('TESSERACT_PATH') ?: '',
            '/usr/bin/tesseract',
            '/usr/local/bin/tesseract',
            '/opt/homebrew/bin/tesseract',
            'tesseract',
        ];

    $binary = null;
    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        if ($candidate === 'tesseract') {
            $output = [];
            $status = 1;
            exec($isWindows ? 'where tesseract 2>NUL' : 'command -v tesseract 2>/dev/null', $output, $status);
            if ($status === 0 && !empty($output[0])) {
                $binary = trim($output[0]);
                break;
            }
        } elseif (file_exists($candidate)) {
            $binary = $candidate;
            break;
        }
    }

    if ($binary === null) {
        return [false, '', 'Tesseract not found on the server.'];
    }

    $binaryArg = $isWindows ? '"' . str_replace('"', '\\"', $binary) . '"' : escapeshellarg($binary);
    $fileArg = $isWindows ? '"' . str_replace('"', '\\"', $filename) . '"' : escapeshellarg($filename);

    // Use a document-like OCR mode by default for grade sheets.
    $ocrCommand = $binaryArg . ' ' . $fileArg . ' stdout -l eng --psm 6 2>&1';
    if (!$isWindows) {
        // Prevent long-running OCR from hanging the upload request.
        $ocrCommand = 'timeout 45s ' . $ocrCommand;
    }

    $output = [];
    $status = 1;
    exec($ocrCommand, $output, $status);

    $rawLines = array_map(static fn($line) => trim((string) $line), $output);
    $rawText = trim(implode(' ', array_filter($rawLines, static fn($line) => $line !== '')));

    // Tesseract often prints warnings to stderr (merged here). Strip known diagnostics
    // so they don't erase valid extracted content.
    $cleanLines = [];
    foreach ($rawLines as $line) {
        if ($line === '') {
            continue;
        }

        if (preg_match('/^Warning/i', $line)) {
            continue;
        }
        if (preg_match('/^Estimating resolution/i', $line)) {
            continue;
        }
        if (preg_match('/^Detected\s+\d+\s+diacritics/i', $line)) {
            continue;
        }
        if (preg_match('/^Tesseract Open Source OCR Engine/i', $line)) {
            continue;
        }

        $cleanLines[] = $line;
    }

    $cleanText = trim(implode(' ', $cleanLines));
    if ($cleanText !== '') {
        return [true, $cleanText, ''];
    }

    if ($status !== 0) {
        return [false, '', 'OCR failed: ' . ($rawText !== '' ? $rawText : 'Unknown OCR error')];
    }

    return [true, $rawText, ''];
}
$userId = (int) ($_POST['user_id'] ?? request_value('user_id', 0));
$applicationId = (int) ($_POST['application_id'] ?? request_value('application_id', 0));
$requirementIdRaw = $_POST['requirement_id'] ?? request_value('requirement_id', null);
$requirementId = ($requirementIdRaw === null || $requirementIdRaw === '') ? null : (int) $requirementIdRaw;
if ($requirementId !== null && $requirementId <= 0) {
    $requirementId = null;
}
$documentType = trim((string) ($_POST['document_type'] ?? request_value('document_type', 'Report of Grades')));
$userRemarks = trim((string) ($_POST['remarks'] ?? request_value('remarks', '')));
$clientExtractedText = trim((string) ($_POST['extracted_text'] ?? request_value('extracted_text', '')));
$clientAverageRaw = $_POST['computed_average'] ?? request_value('computed_average', null);
$clientAverage = ($clientAverageRaw === null || $clientAverageRaw === '') ? null : (float) $clientAverageRaw;
$clientSystemStatus = strtolower(trim((string) ($_POST['system_status'] ?? request_value('system_status', ''))));
$analysisSource = trim((string) ($_POST['analysis_source'] ?? request_value('analysis_source', '')));
$analysisNotes = [];

function resolve_requirement(mysqli $conn, ?int $requirementId, string $documentType): array
{
    if ($requirementId !== null && $requirementId <= 0) {
        $requirementId = null;
    }

    if ($requirementId !== null && $requirementId > 0) {
        $stmt = db_prepare($conn, 'SELECT requirement_id, requirement_name FROM requirements WHERE requirement_id = ? LIMIT 1');
        $stmt->bind_param('i', $requirementId);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        if ($row) {
            return [(int) $row['requirement_id'], (string) $row['requirement_name']];
        }
    }

    $cleanType = strtolower(trim($documentType));
    if ($cleanType === '') {
        return [$requirementId, $documentType];
    }

    $stmt = db_prepare(
        $conn,
        'SELECT requirement_id, requirement_name FROM requirements WHERE LOWER(requirement_name) = ? LIMIT 1'
    );
    $stmt->bind_param('s', $cleanType);
    $stmt->execute();
    $row = $stmt->get_result()?->fetch_assoc();
    $stmt->close();
    if ($row) {
        return [(int) $row['requirement_id'], (string) $row['requirement_name']];
    }

    $pattern = null;
    if (str_contains($cleanType, 'renewal')) {
        $pattern = '%renewal%';
    } elseif (str_contains($cleanType, 'report') && str_contains($cleanType, 'grade')) {
        $pattern = '%report%grade%';
    } elseif (str_contains($cleanType, 'grade')) {
        $pattern = '%grade%';
    }

    if ($pattern !== null) {
        $stmt = db_prepare(
            $conn,
            'SELECT requirement_id, requirement_name FROM requirements WHERE LOWER(requirement_name) LIKE ? LIMIT 1'
        );
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        if ($row) {
            return [(int) $row['requirement_id'], (string) $row['requirement_name']];
        }
    }

    return [$requirementId, $documentType];
}

$fileField = isset($_FILES['document_image']) ? 'document_image' : (isset($_FILES['document']) ? 'document' : null);

[$requirementId, $documentType] = resolve_requirement($conn, $requirementId, $documentType);
if ($requirementId !== null && $requirementId <= 0) {
    $requirementId = null;
}

if ($requirementId !== null && $requirementId > 0) {
    $chk = db_prepare($conn, 'SELECT 1 FROM requirements WHERE requirement_id = ? LIMIT 1');
    $chk->bind_param('i', $requirementId);
    $chk->execute();
    $exists = $chk->get_result()?->fetch_assoc();
    $chk->close();
    if (!$exists) {
        $requirementId = null;
    }
}

if ($userId <= 0 || $fileField === null) {
    upload_json_error('Missing user_id or file', 422);
}

if (!isset($_FILES[$fileField]['tmp_name']) || $_FILES[$fileField]['error'] !== UPLOAD_ERR_OK) {
    upload_json_error('Invalid upload', 422);
}

if ($applicationId <= 0) {
    // Look up scholar_id for this user.
    $schStmt = db_prepare($conn, 'SELECT scholar_id FROM scholars WHERE user_id = ? LIMIT 1');
    $schStmt->bind_param('i', $userId);
    $schStmt->execute();
    $schRow = $schStmt->get_result()?->fetch_assoc();
    $schStmt->close();

    $scholarId = (int) ($schRow['scholar_id'] ?? 0);
    if ($scholarId <= 0) {
        upload_json_error('Scholar record not found for this user', 404);
    }

    // Try to reuse the latest application if it exists.
    $appStmt = db_prepare(
        $conn,
        'SELECT application_id
         FROM applications
         WHERE scholar_id = ?
         ORDER BY application_id DESC
         LIMIT 1'
    );
    $appStmt->bind_param('i', $scholarId);
    $appStmt->execute();
    $application = $appStmt->get_result()?->fetch_assoc();
    $appStmt->close();
    $applicationId = (int) ($application['application_id'] ?? 0);

    // Auto-create an application if none exists.
    if ($applicationId <= 0) {
        $createStmt = db_prepare(
            $conn,
            'INSERT INTO applications (scholar_id, status, remarks) VALUES (?, ?, ?)'
        );
        $pending = 'pending';
        $autoRemark = 'Auto-created from upload';
        $createStmt->bind_param('iss', $scholarId, $pending, $autoRemark);
        if (!$createStmt->execute()) {
            $err = $createStmt->error;
            $createStmt->close();
            upload_json_error('Unable to create application for upload: ' . $err, 500);
        }
        $applicationId = $createStmt->insert_id;
        $createStmt->close();
    }
}

$uploadsRoot = trim((string) (getenv('UPLOADS_ROOT_DIR') ?: ''));
if ($uploadsRoot === '') {
    $uploadsRoot = __DIR__ . '/uploads';
}

$uploadDir = rtrim(str_replace('\\', '/', $uploadsRoot), '/') . '/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
    upload_json_error('Failed to create upload directory', 500);
}

$originalName = (string) ($_FILES[$fileField]['name'] ?? 'file');
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'docx'];

if (!in_array($extension, $allowedExtensions, true)) {
    upload_json_error('Unsupported file type. Allowed: jpg, jpeg, png, pdf, docx', 422);
}

try {
    $suffix = bin2hex(random_bytes(4));
} catch (Throwable $e) {
    $suffix = substr(uniqid('', true), -8);
}

$storedFilename = time() . '_' . $userId . '_' . $suffix . '.' . $extension;
$absolutePath = $uploadDir . $storedFilename;
$relativePath = 'uploads/' . $storedFilename;

if (!move_uploaded_file($_FILES[$fileField]['tmp_name'], $absolutePath)) {
    upload_json_error('Could not move uploaded file', 500);
}

$extractedText = '';
$usedClientAnalysis = false;

if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
    [$ok, $text, $message] = run_tesseract($absolutePath);
    if ($ok) {
        $extractedText = $text;
    } else {
        $analysisNotes[] = $message;
    }
} elseif ($extension === 'pdf') {
    [$ok, $text, $message] = read_pdf_text($absolutePath);
    if ($ok) {
        $extractedText = $text;
    } else {
        $analysisNotes[] = $message;
    }
} elseif ($extension === 'docx') {
    $extractedText = read_docx_text($absolutePath);
}

if ($extractedText === '' && $clientExtractedText !== '') {
    $extractedText = $clientExtractedText;
    $usedClientAnalysis = true;
    $analysisNotes[] = 'Used client-side extracted text.';
}

$cleanText = trim(preg_replace('/\s+/', ' ', $extractedText));
preg_match_all('/\b(?:100|[7-9][0-9](?:\.[0-9]{1,2})?)\b/', $cleanText, $matches);
$grades = array_map('floatval', $matches[0] ?? []);
$gradeCount = count($grades);

if ($gradeCount >= 3) {
    $average = array_sum($grades) / $gradeCount;
    $status = $average >= 85 ? 'approved' : 'pending';
} else {
    $average = 0.0;
    $status = 'pending';

    if ($clientAverage !== null && $clientAverage > 0) {
        $average = $clientAverage;
        $status = in_array($clientSystemStatus, ['approved', 'pending'], true)
            ? $clientSystemStatus
            : ($average >= 85 ? 'approved' : 'pending');
        $usedClientAnalysis = true;
        $analysisNotes[] = 'Used client-side average fallback.';
    } else {
        $analysisNotes[] = 'Insufficient grade data detected in file.';
    }
}

if ($cleanText === '') {
    $analysisNotes[] = 'No text extracted from file.';
}

if ($gradeCount >= 3) {
    $analysisSummary = 'Detected ' . $gradeCount . ' grades. Average: ' . round($average, 2) . '. Status: ' . ucfirst($status) . '.';
} elseif ($usedClientAnalysis && $average > 0) {
    $analysisSummary = 'Client-side analysis used. Average: ' . round($average, 2) . '. Status: ' . ucfirst($status) . '.';
} else {
    $analysisSummary = 'Analysis unavailable for this file.';
}

$analysisFailed = ($average <= 0 || $cleanText === '') && !$usedClientAnalysis;
$averageToStore = $average > 0 ? $average : null;

if ($requirementId === null) {
    if ($averageToStore === null) {
        $stmt = db_prepare(
            $conn,
            'INSERT INTO submissions (application_id, requirement_id, file_path, status, remarks, computed_average)
             VALUES (?, NULL, ?, ?, ?, NULL)'
        );
        $stmt->bind_param('isss', $applicationId, $relativePath, $status, $userRemarks);
    } else {
        $stmt = db_prepare(
            $conn,
            'INSERT INTO submissions (application_id, requirement_id, file_path, status, remarks, computed_average)
             VALUES (?, NULL, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssd', $applicationId, $relativePath, $status, $userRemarks, $averageToStore);
    }
} else {
    if ($averageToStore === null) {
        $stmt = db_prepare(
            $conn,
            'INSERT INTO submissions (application_id, requirement_id, file_path, status, remarks, computed_average)
             VALUES (?, ?, ?, ?, ?, NULL)'
        );
        $stmt->bind_param('iisss', $applicationId, $requirementId, $relativePath, $status, $userRemarks);
    } else {
        $stmt = db_prepare(
            $conn,
            'INSERT INTO submissions (application_id, requirement_id, file_path, status, remarks, computed_average)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iisssd', $applicationId, $requirementId, $relativePath, $status, $userRemarks, $averageToStore);
    }
}

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    upload_json_error('Failed to save submission: ' . $error, 500);
}

$submissionId = $stmt->insert_id;
$stmt->close();

$processingSeconds = microtime(true) - $startedAt;
$preview = function_exists('mb_substr')
    ? mb_substr($cleanText, 0, 200) . (mb_strlen($cleanText) > 200 ? '...' : '')
    : substr($cleanText, 0, 200) . (strlen($cleanText) > 200 ? '...' : '');

respond_success([
    'message' => 'Document uploaded successfully',
    'submission_id' => $submissionId,
    'application_id' => $applicationId,
    'requirement_id' => $requirementId,
    'file_path' => $relativePath,
    'file_url' => make_public_file_url($relativePath),
    'document_type' => $documentType,
    'average' => round($average, 2),
    'grade_count' => $gradeCount,
    'system_status' => $status,
    'remarks' => $analysisSummary,
    'user_remarks' => $userRemarks,
    'analysis_notes' => implode(' | ', $analysisNotes),
    'analysis_failed' => $analysisFailed,
    'analysis_source' => $usedClientAnalysis ? ($analysisSource !== '' ? $analysisSource : 'client') : 'server',
    'processing_seconds' => round($processingSeconds, 3),
    'extracted_text' => $preview,
], 201);



