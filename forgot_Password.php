<?php
include 'dbConn.php';
session_start();

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
  <title>Forgot Password — PlanGenius</title>
  <link rel="stylesheet" href="css/auth.css">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="auth-page">
  <div id="toast-container" class="toast-container" aria-live="polite"></div>

  <div class="wrap">
    <div class="brand">
      <a href="index.php" class="brand-link" title="Back to Home" aria-label="PlanGenius — Back to Home">
        <div class="brand-icon">
          <i class="ph ph-books" style="font-size:22px;"></i>
        </div>
        <div class="brand-text">
          <span class="brand-title">Plan<span>Genius</span></span>
          <span class="brand-subtitle">Academic Planner</span>
        </div>
      </a>
    </div>

    <p class="step-subtitle">
      <?= $step === 1 ? 'Enter your registered email to verify your identity' : 'Answer your security question to reset your password' ?>
    </p>

    <?php if ($step === 1): ?>
      <form action="" method="POST" onsubmit="handleAuthSubmit(this, 'Verifying...')">
        <input type="hidden" name="action" value="verify_email" />

        <div class="field-group">
          <label for="email">Registered Email</label>
          <input id="email" name="email" type="email" class="input" placeholder="you@example.com" autocomplete="email" value="<?= htmlspecialchars($email) ?>" required autofocus />
        </div>

        <button type="submit" class="btn">Continue</button>
      </form>
    <?php else: ?>
      <form action="" method="POST" onsubmit="handleAuthSubmit(this, 'Resetting password...')">
        <input type="hidden" name="action" value="reset_password" />
        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>" />
        <input type="hidden" name="security_question" value="<?= htmlspecialchars($securityQuestion) ?>" />

        <div class="field-group">
          <label for="email_display">Registered Email</label>
          <input id="email_display" type="email" class="input input-readonly" value="<?= htmlspecialchars($email) ?>" readonly />
        </div>

        <div class="field-group">
          <label for="security_question_display">Security Question</label>
          <input id="security_question_display" type="text" class="input input-readonly" value="<?= htmlspecialchars($securityQuestion) ?>" readonly />
        </div>

        <div class="field-group">
          <label for="security_answer">Security Answer</label>
          <input id="security_answer" name="security_answer" type="text" class="input" placeholder="Enter your secret answer" required autofocus />
        </div>

        <div class="field-group">
          <label for="new_password">New Password</label>
          <div class="password-wrap">
            <input id="new_password" name="new_password" type="password" class="input" placeholder="Minimum 8 characters" autocomplete="new-password" oninput="updatePasswordStrength(this.value); checkMatch();" required />
            <button type="button" class="toggle-pw-btn" aria-label="Show password" onclick="togglePasswordVisibility('new_password', this)">
              <i class="ph ph-eye"></i>
            </button>
          </div>
          <div class="pw-meter-wrap">
            <div class="pw-bars">
              <div class="pw-bar" id="pw-bar-1"></div>
              <div class="pw-bar" id="pw-bar-2"></div>
              <div class="pw-bar" id="pw-bar-3"></div>
              <div class="pw-bar" id="pw-bar-4"></div>
            </div>
            <div class="pw-hint">
              <span id="pw-length-hint">At least 8 characters</span>
              <span id="pw-strength-label" style="font-weight:600;"></span>
            </div>
          </div>
        </div>

        <div class="field-group">
          <label for="confirm_password">Confirm New Password</label>
          <div class="password-wrap">
            <input id="confirm_password" name="confirm_password" type="password" class="input" placeholder="Re-enter new password" autocomplete="new-password" oninput="checkMatch()" required />
            <button type="button" class="toggle-pw-btn" aria-label="Show password" onclick="togglePasswordVisibility('confirm_password', this)">
              <i class="ph ph-eye"></i>
            </button>
          </div>
          <div class="pw-hint" style="margin-top:6px;">
            <span id="pw-match-hint"></span>
          </div>
        </div>

        <button type="submit" class="btn">Reset Password</button>
      </form>
    <?php endif; ?>

    <div class="footer">
      <a href="login.php"><i class="ph ph-arrow-left"></i> Back to Login</a>
    </div>
  </div>

  <script>
    const serverMessage = <?php echo json_encode($message); ?>;

    function togglePasswordVisibility(inputId, btn) {
      const input = document.getElementById(inputId);
      const icon = btn.querySelector('i');
      if (!input) return;
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
      if (icon) icon.className = isHidden ? 'ph ph-eye-slash' : 'ph ph-eye';
    }

    function updatePasswordStrength(pw) {
      let score = 0;
      if (pw.length >= 8) score++;
      if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
      if (/\d/.test(pw)) score++;
      if (/[^A-Za-z0-9]/.test(pw)) score++;

      const colors = ['#ef4444', '#f59e0b', '#3b82f6', '#10b981'];
      const labels = ['Weak', 'Fair', 'Good', 'Strong'];

      for (let i = 1; i <= 4; i++) {
        const bar = document.getElementById('pw-bar-' + i);
        if (bar) bar.style.backgroundColor = (pw.length > 0 && i <= score) ? colors[score - 1] : '#e2e8f0';
      }

      const lenHint = document.getElementById('pw-length-hint');
      const labelEl = document.getElementById('pw-strength-label');
      if (lenHint) {
        lenHint.textContent = pw.length >= 8 ? '✓ 8+ characters met' : `At least 8 characters (${pw.length}/8)`;
        lenHint.style.color = pw.length >= 8 ? '#059669' : '#64748b';
      }
      if (labelEl) {
        labelEl.textContent = pw.length > 0 ? labels[Math.max(0, score - 1)] : '';
        labelEl.style.color = pw.length > 0 ? colors[Math.max(0, score - 1)] : '#64748b';
      }
    }

    function checkMatch() {
      const pw = document.getElementById('new_password')?.value || '';
      const confirm = document.getElementById('confirm_password')?.value || '';
      const hint = document.getElementById('pw-match-hint');
      if (!hint) return;
      if (!confirm) {
        hint.textContent = '';
      } else if (pw === confirm) {
        hint.textContent = '✓ Passwords match';
        hint.style.color = '#059669';
      } else {
        hint.textContent = 'Passwords do not match';
        hint.style.color = '#dc2626';
      }
    }

    function handleAuthSubmit(form, loadingText) {
      const btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.innerText = loadingText;
        btn.disabled = true;
      }
    }

    function createToast(type, text) {
      const container = document.getElementById('toast-container');
      if (!container) return;
      const t = document.createElement('div');
      t.className = 'toast ' + (type === 'success' ? 'success' : 'error');
      const content = document.createElement('div');
      content.className = 'content';
      content.textContent = text;
      const close = document.createElement('button');
      close.className = 'close';
      close.innerText = '×';
      close.onclick = () => t.remove();
      t.appendChild(content);
      t.appendChild(close);
      container.appendChild(t);
      setTimeout(() => t.remove(), 5000);
    }

    document.addEventListener('DOMContentLoaded', () => {
      if (serverMessage) createToast('error', serverMessage);
    });
  </script>
</body>
</html>