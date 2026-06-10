<?php
session_start();
header('Content-Type: application/json');

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
    // Additional PDF check using file signature
    $handle = fopen($file['tmp_name'], 'r');
    $header = fread($handle, 4);
    fclose($handle);
    
    if ($header !== '%PDF') {
        echo json_encode(['success' => false, 'error' => 'Invalid PDF file format']);
        exit;
    }
}

// Create uploads directory
$uploadsDir = __DIR__ . '/uploads/assignments';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
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
$command = escapeshellcmd("$pythonPath data_processor.py --extract-pdf " . escapeshellarg($filePath));
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
        'error' => $extractedData['error'] ?? 'Failed to extract PDF content',
        'filePath' => $filename
    ]);
    exit;
}

// Return successfully extracted data with file path
echo json_encode([
    'success' => true,
    'filePath' => $filename,
    'extractedData' => $extractedData['data'] ?? null
]);
exit;
?>
