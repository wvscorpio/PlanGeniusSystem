<?php
include 'dbConn.php';
session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['studentID'])) {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$step = 1;
$email = '';
$securityQuestion = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'verify_email') {
        $email = trim($_POST['email'] ?? '');

        if ($email === '') {
            $message = 'Please enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
        } else {
            try {
                $stmt = $conn->prepare('SELECT "security_question" FROM "Student" WHERE "email" = :email LIMIT 1');
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && !empty($user['security_question'])) {
                    $securityQuestion = $user['security_question'];
                    $step = 2;
                } else {
                    $message = 'No account found associated with that email address or security question not configured.';
                }
            } catch (PDOException $e) {
                $message = 'Error verifying account. Please try again.';
            }
        }
    } elseif ($action === 'reset_password') {
        $email = trim($_POST['email'] ?? '');
        $securityQuestion = trim($_POST['security_question'] ?? '');
        $securityAnswer = trim($_POST['security_answer'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($email === '' || $securityAnswer === '' || $newPassword === '' || $confirmPassword === '') {
            $message = 'All fields are required.';
            $step = 2;
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $step = 2;
        } elseif (strlen($newPassword) < 8) {
            $message = 'Password must be at least 8 characters long.';
            $step = 2;
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'Passwords do not match.';
            $step = 2;
        } else {
            try {
                $stmt = $conn->prepare('SELECT "studentID", "security_answer" FROM "Student" WHERE "email" = :email LIMIT 1');
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                $normalizedAnswer = strtolower($securityAnswer);

                if ($user && !empty($user['security_answer']) && password_verify($normalizedAnswer, $user['security_answer'])) {
                    // Security answer matches -> Hash the new password and update the database
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $updateStmt = $conn->prepare('UPDATE "Student" SET "password" = :password, "api_token" = NULL, "api_token_expires_at" = NULL WHERE "email" = :email');
                    $updateStmt->execute([
                        ':password' => $hashedPassword,
                        ':email' => $email
                    ]);

                    $_SESSION['success_message'] = 'Your password has been successfully reset. Please log in.';
                    header('Location: login.php');
                    exit;
                } else {
                    // Generic error message without revealing correct details
                    $message = 'Verification failed. Incorrect security answer.';
                    $step = 2;
                }
            } catch (PDOException $e) {
                $message = 'Error resetting password. Please try again.';
                $step = 2;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password - PlanGenius</title>
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <style>
    :root{
      --bg1:#e9f0ff;
      --bg2:#f6fbff;
      --accent:#5b3bff;
      --muted:#efefef;
      --card:#ffffff;
      --black:#03010a;
    }
    html,body{
      height:100%;margin:0;font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,'Helvetica Neue',Arial;
    }
    body{
      display:flex;align-items:center;justify-content:center;
      background:linear-gradient(180deg,var(--bg1),var(--bg2));
      padding:40px;
    }
    .wrap{
      width:520px;max-width:94%;background:var(--card);border-radius:16px;padding:34px;box-shadow:0 6px 20px rgba(38,61,91,0.08);
    }
    .brand{ 
      text-align:center;margin-bottom:8px; 
    }
    .brand h1{ 
      margin:0;font-size:36px;color:var(--accent);font-weight:700; 
    }
    .brand p{ 
      margin:4px 0 20px;color:#7b7f87;font-weight:500 
    }

    label{
      display:block;font-weight:600;margin:10px 0 8px;color:#1f2933
    }
    .input{
      width:100%;padding:18px;border-radius:12px;border:none;background:var(--muted);box-sizing:border-box;font-size:15px
    }
    .input-readonly{
      background:#e5e7eb;color:#4b5563;cursor:not-allowed;
    }
    .btn{
      display:block;width:100%;background:var(--black);color:#fff;padding:14px;border-radius:12px;border:none;
      font-weight:700;font-size:16px;margin-top:18px;cursor:pointer
    }

    .footer{ 
      text-align:center;margin-top:16px;color:#9aa0a6;font-size:13px 
    }
    .footer a {
      color: var(--accent);
      text-decoration: none;
      font-weight: 600;
    }

    .toast-container{ position:fixed; top:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:10px; align-items:flex-end }
    .toast{ background:#fff; border-radius:8px; padding:12px 16px; box-shadow:0 6px 18px rgba(20,30,50,0.12); min-width:280px; max-width:380px; color:#0b1720; border-left:6px solid #ccc; display:flex; gap:12px; align-items:flex-start }
    .toast.success{ border-left-color:#16a34a }
    .toast.error{ border-left-color:#dc2626 }
    .toast .close{ margin-left:auto; background:transparent;border:none;color:#667085;font-weight:700;cursor:pointer }
    .toast .content{ white-space:pre-wrap }
  </style>
</head>
<body>

  <div id="toast-container" class="toast-container"></div>

  <div class="wrap"> 
    
    <div class="brand">
      <h1>PlanGenius</h1>
      <p><?= $step === 1 ? 'Enter your email to verify identity' : 'Answer your security question to reset password' ?></p>
    </div>
    
    <?php if ($step === 1): ?>
      <form action="forgot_password.php" method="POST">
        <input type="hidden" name="action" value="verify_email" />
        
        <label for="email">Registered Email</label>
        <input id="email" name="email" type="email" class="input" placeholder="yourname@example.com" value="<?= htmlspecialchars($email) ?>" required />
        
        <button type="submit" class="btn">Continue</button>
      </form>
    <?php else: ?>
      <form action="forgot_password.php" method="POST">
        <input type="hidden" name="action" value="reset_password" />
        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>" />
        <input type="hidden" name="security_question" value="<?= htmlspecialchars($securityQuestion) ?>" />

        <label for="email_display">Registered Email</label>
        <input id="email_display" type="email" class="input input-readonly" value="<?= htmlspecialchars($email) ?>" readonly />

        <label for="security_question_display">Security Question</label>
        <input id="security_question_display" type="text" class="input input-readonly" value="<?= htmlspecialchars($securityQuestion) ?>" readonly />

        <label for="security_answer">Security Answer</label>
        <input id="security_answer" name="security_answer" type="text" class="input" placeholder="Enter your secret answer" required autofocus />

        <label for="new_password">New Password</label>
        <input id="new_password" name="new_password" type="password" class="input" placeholder="••••••••" required />

        <label for="confirm_password">Confirm New Password</label>
        <input id="confirm_password" name="confirm_password" type="password" class="input" placeholder="••••••••" required />
        
        <button type="submit" class="btn">Reset Password</button>
      </form>
    <?php endif; ?>

    <div class="footer">Remembered your password? <a href="login.php">Back to Login</a></div>
  </div>

  <script>
    const serverMessage = <?php echo json_encode($message); ?>;

    function createToast(type, html) {
      const container = document.getElementById('toast-container');
      if (!container) return;
      const t = document.createElement('div');
      t.className = 'toast ' + (type === 'success' ? 'success' : 'error');
      const content = document.createElement('div');
      content.className = 'content';
      content.innerHTML = html;
      const close = document.createElement('button');
      close.className = 'close';
      close.innerText = '×';
      close.onclick = () => { t.remove(); };
      t.appendChild(content);
      t.appendChild(close);
      container.appendChild(t);
      setTimeout(() => { t.remove(); }, 5000);
    }

    document.addEventListener('DOMContentLoaded', () => {
      if (serverMessage) {
        createToast('error', escapeHtml(serverMessage));
      }
    });

    function escapeHtml(str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }
  </script>
</body>
</html>