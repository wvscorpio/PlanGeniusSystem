<?php
include 'dbConn.php';
session_start();
$message = '';
$successMessage = '';

if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

function getLandingPage($conn, $studentID) {
    try {
        $check = $conn->prepare('SELECT 1 FROM "SemesterPlan" WHERE "studentID" = :studentID LIMIT 1');
        $check->execute([':studentID' => $studentID]);
        return $check->fetch() ? 'dashboard.php' : 'academic_structure.php';
    } catch (PDOException $e) {
        return 'academic_structure.php';
    }
}

if (isset($_SESSION['studentID'])) {
    $destination = getLandingPage($conn, $_SESSION['studentID']);
    header('Location: ' . $destination);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = $_POST['password'] ?? '';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } elseif ($password === '') {
        $message = 'Please enter your password.';
    } else {
        try {
            $stmt = $conn->prepare('SELECT "studentID", "firstName", "lastName", "password" FROM "Student" WHERE "email" = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['studentID'] = $user['studentID'];
                $_SESSION['firstName'] = $user['firstName'];
                $_SESSION['lastName'] = $user['lastName'];
                $_SESSION['email'] = $email;

                $destination = getLandingPage($conn, $user['studentID']);
                header('Location: ' . $destination);
                exit;
            }

            $message = 'Email or password is incorrect.';
        } catch (PDOException $e) {
            $message = 'Login error: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PlanGenius — Login</title>
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

    <div class="tabbar">
      <a href="login.php" class="tab active">Login</a>
      <a href="register.php" class="tab">Register</a>
    </div>

    <form method="post" action="login.php" onsubmit="handleAuthSubmit(this, 'Signing in...')">
      <div class="field-group">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" class="input" placeholder="you@example.com" autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required />
      </div>

      <div class="field-group">
        <label for="password">Password</label>
        <div class="password-wrap">
          <input id="password" name="password" type="password" class="input" placeholder="Enter your password" autocomplete="current-password" required />
          <button type="button" class="toggle-pw-btn" aria-label="Show password" onclick="togglePasswordVisibility('password', this)">
            <i class="ph ph-eye"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn">Login</button>
    </form>

    <div class="footer">
      <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
    </div>
  </div>

  <script>
    const serverMessage = <?php echo json_encode($message); ?>;
    const successMessage = <?php echo json_encode($successMessage); ?>;

    function togglePasswordVisibility(inputId, btn) {
      const input = document.getElementById(inputId);
      const icon = btn.querySelector('i');
      if (!input) return;
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
      if (icon) icon.className = isHidden ? 'ph ph-eye-slash' : 'ph ph-eye';
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
      setTimeout(() => t.remove(), 4500);
    }

    document.addEventListener('DOMContentLoaded', () => {
      if (serverMessage) createToast('error', serverMessage);
      if (successMessage) createToast('success', successMessage);
    });
  </script>
</body>
</html>