<?php
// Set JSON header
header('Content-Type: application/json');

include '../dbConn.php';

// Allow only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Read raw JSON input stream
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$email = isset($data['email']) ? trim($data['email']) : '';
$password = $data['password'] ?? '';

// Validate inputs
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid email address.']);
    exit;
}

if (empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter your password.']);
    exit;
}

try {
    // Query database for student details
    $stmt = $conn->prepare('SELECT "studentID", "firstName", "lastName", "password" FROM "Student" WHERE "email" = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verify hashed password
    if ($user && password_verify($password, $user['password'])) {
        $token = bin2hex(random_bytes(32));
        
        // Set expiry timestamp 7 days from now
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

        // Store only a one-way hash; the raw token is returned once to the API client.
        $updateStmt = $conn->prepare('
            UPDATE "Student" 
            SET "api_token" = :token, 
                "api_token_expires_at" = :expires 
            WHERE "studentID" = :id
        ');
        $updateStmt->execute([
            ':token' => hash('sha256', $token),
            ':expires' => $expiresAt,
            ':id' => $user['studentID']
        ]);

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'token' => $token,
            'expires_at' => $expiresAt
        ]);
        exit;
    }

    http_response_code(401);
    echo json_encode(['error' => 'Email or password is incorrect.']);
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Login error: ' . $e->getMessage()]);
    exit;
}