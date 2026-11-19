<?php
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');
include 'dbConn.php';

$studentID = $_SESSION['studentID'] ?? null;
if (!$studentID) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Validate file upload
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['pdf'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file provided']);
    exit;
}

$file = $_FILES['pdf'];
$maxFileSize = 10 * 1024 * 1024; // 10MB limit

// Validate file format and size
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'File upload failed: ' . $file['error']]);
    exit;
}

if ($file['size'] > $maxFileSize) {
    echo json_encode(['success' => false, 'error' => 'File size exceeds 10MB limit']);
    exit;
}

// Check MIME type
$mimeType = mime_content_type($file['tmp_name']);
if ($mimeType !== 'application/pdf') {
    echo json_encode(['success' => false, 'error' => 'Only PDF files are allowed.']);
    exit;
}

// Create uploads directory
$uploadsDir = __DIR__ . '/uploads/assignments';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

// --- AUTOMATIC ORPHAN FILE CLEANUP ---
// Delete any PDF in the uploads directory that is not linked to an assignment in the database
try {
    $dbPdfsStmt = $conn->query('SELECT "pdfFile" FROM "Assignment" WHERE "pdfFile" IS NOT NULL AND "pdfFile" != \'\'');
    $activePdfs = $dbPdfsStmt->fetchAll(PDO::FETCH_COLUMN);
    $activePdfsSet = array_flip($activePdfs);

    $diskFiles = glob($uploadsDir . '/*.pdf');
    if ($diskFiles) {
        foreach ($diskFiles as $diskFile) {
            $baseName = basename($diskFile);
            if (!isset($activePdfsSet[$baseName])) {
                @unlink($diskFile); // Delete orphaned file
            }
        }
    }
} catch (Exception $e) {
    // Continue if cleanup fails
}

// --- DUPLICATE PDF FILE HASH VALIDATION ---
$pdfHash = hash_file('sha256', $file['tmp_name']);

try {
    $hashCheck = $conn->prepare('
        SELECT 1 FROM "Assignment" a
        JOIN "Course" c ON a."courseID" = c."courseID"
        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE s."studentID" = :studentID AND a."pdfHash" = :pdfHash
        LIMIT 1
    ');
    $hashCheck->execute([
        ':studentID' => $studentID,
        ':pdfHash' => $pdfHash
    ]);
    
    if ($hashCheck->fetch()) {
        echo json_encode(['success' => false, 'error' => 'This assessment document has already been uploaded.']);
        exit;
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error during file validation.']);
    exit;
}

// Generate unique filename
$filename = 'assignment_' . $studentID . '_' . time() . '_' . uniqid() . '.pdf';
$filePath = $uploadsDir . '/' . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit;
}

// Run Python extraction
$pythonPath = 'python'; // or 'python3' depending on system
$command = escapeshellcmd($pythonPath) . " " . escapeshellarg(__DIR__ . '/processor/exam_processor.py') . " --extract-exam " . escapeshellarg($filePath);
$output = shell_exec($command . ' 2>&1');

if ($output === null) {
    unlink($filePath);
    echo json_encode([
        'success' => false,
        'error' => 'PDF extraction service unavailable. Please enter assignment details manually.',
        'filePath' => $filename
    ]);
    exit;
}

$extractedData = json_decode($output, true);

if (!$extractedData || !isset($extractedData['success'])) {
    unlink($filePath);
    echo json_encode([
        'success' => false,
        'error' => $extractedData['error'] ?? 'Failed to extract PDF content.',
        'raw_output' => $output // Optional debug info
    ]);
    exit;
}

if (!$extractedData['success']) {
    unlink($filePath);
    echo json_encode([
        'success' => false,
        'error' => $extractedData['error']
    ]);
    exit;
}

// Return success with extracted data and filename
echo json_encode([
    'success' => true,
    'extractedData' => $extractedData['data'] ?? $extractedData['exams'] ?? $extractedData,
    'filePath' => $filename
]);