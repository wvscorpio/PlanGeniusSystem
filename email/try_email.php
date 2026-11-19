<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/dbConn.php';
require_once __DIR__ . '/EmailService.php';

session_start();

$studentID = $_SESSION['studentID'] ?? null;
if (!$studentID) {
    http_response_code(401);
    exit('Please log in before sending a test email.');
}

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $conn->prepare('SELECT email, "firstName", "lastName" FROM "Student" WHERE "studentID" = :studentID LIMIT 1');
        $stmt->execute([':studentID' => $studentID]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student || !filter_var($student['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The logged-in student does not have a valid email address.');
        }

        $name = trim(($student['firstName'] ?? '') . ' ' . ($student['lastName'] ?? ''));
        $success = (new EmailService())->sendTest($student['email'], $name);
        $message = $success
            ? 'Test email sent to Mailtrap for ' . $student['email'] . '.'
            : 'The test email failed. Check storage/logs/email.log.';
    } catch (Throwable $exception) {
        $message = 'Test email failed: ' . $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PlanGenius Email Test</title>
</head>
<body>
  <h1>PlanGenius Email Test</h1>
  <?php if ($message !== ''): ?><p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
  <form method="post">
    <button type="submit">Send test email to my account</button>
  </form>
</body>
</html>
