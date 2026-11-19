<?php
// api/auth.php
header('Content-Type: application/json');
include_once '../dbConn.php';

function validateBearerToken(PDO $conn): array {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/D', trim($authHeader), $matches)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized. Missing or malformed Bearer token.']);
        exit;
    }

    $tokenHash = hash('sha256', $matches[1]);
    $stmt = $conn->prepare('
        SELECT "studentID", "firstName", "lastName", "email"
        FROM "Student"
        WHERE "api_token" = :tokenHash
          AND "api_token_expires_at" > CURRENT_TIMESTAMP
        LIMIT 1
    ');
    $stmt->execute([':tokenHash' => $tokenHash]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired Bearer token.']);
        exit;
    }

    return $student;
}