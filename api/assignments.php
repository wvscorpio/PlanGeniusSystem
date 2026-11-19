<?php
// api/assignments.php
header('Content-Type: application/json');

include_once '../dbConn.php';
include_once 'auth.php';

// 1. Enforce GET Request Method[cite: 1]
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed. Use GET for fetching assignments.']);
    exit;
}

try {
    $student = validateBearerToken($conn);

    // Fetch user-specific assignments
    $stmt = $conn->prepare('SELECT * FROM "Assignment" WHERE "studentID" = :studentID ORDER BY "dueDate" ASC');
    $stmt->execute([':studentID' => $student['studentID']]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'authenticated_as' => $student['email'],
        'count' => count($assignments),
        'data' => $assignments
    ]);
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}