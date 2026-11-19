<?php
require_once 'dbConn.php';
session_start();

$studentID = $_SESSION['studentID'] ?? null;
if ($studentID !== null) {
    try {
        $stmt = $conn->prepare('UPDATE "Student" SET "api_token" = NULL, "api_token_expires_at" = NULL WHERE "studentID" = :studentID');
        $stmt->execute([':studentID' => $studentID]);
    } catch (PDOException $e) {
        // Continue clearing the browser session even if token revocation fails.
    }
}

// Clear all session data
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();
header('Location: login.php');
exit;
