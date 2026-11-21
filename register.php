<?php
include 'dbConn.php';
session_start();
$errors = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = trim($_POST['first'] ?? '');
    $last = trim($_POST['last'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $securityQuestion = trim($_POST['security_question'] ?? '');
    $securityAnswer = trim($_POST['security_answer'] ?? '');

    if ($first === '') $errors[] = 'First name is required.';
    if ($last === '') $errors[] = 'Last name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($phone === '') $errors[] = 'Telephone is required.';
    if ($securityQuestion === '') $errors[] = 'Please select a security question.';
    if ($securityAnswer === '') $errors[] = 'Please provide a security answer.';

    if (empty($errors)) {
      try {
        $check = $conn->prepare('SELECT "password" FROM "Student" WHERE "email" = :email');
        $check->execute([':email' => $email]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
          if (password_verify($password, $existing['password'])) {
            $errors[] = 'An account with this email and password already exists.';
          } else {
            $errors[] = 'Email is already registered. Please log in or reset your password.';
          }
          $_POST = [];
          $first = $last = $email = $phone = $password = $securityQuestion = $securityAnswer = '';
        } else {
          $hashedAnswer = password_hash(strtolower($securityAnswer), PASSWORD_DEFAULT);

          $stmt = $conn->prepare(
            'INSERT INTO "Student" ("firstName", "lastName", "email", "password", "phoneNo", "security_question", "security_answer") 
             VALUES (:first, :last, :email, :password, :phone, :security_question, :security_answer)'
          );
          $stmt->execute([
            ':first' => $first,
            ':last' => $last,
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_BCRYPT),
            ':phone' => $phone,
            ':security_question' => $securityQuestion,
            ':security_answer' => $hashedAnswer,
          ]);
          $_SESSION['success_message'] = 'Registration successful! You can now log in.';
          header('Location: login.php');
          exit;
        }
      } catch (PDOException $e) {
        $errors[] = 'Registration failed: ' . $e->getMessage();
      }
    }
}

if (!empty($errors)) {
    $message = implode(' ', $errors);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PlanGenius — Register</title>
  <link rel="stylesheet" href="css/auth.css">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="auth-page">
  <div id="toast-container" class="toast-container" aria-live="polite"></div>

  <div class="wrap wrap-wide">
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
      <a href="login.php" class="tab">Login</a>
      <a href="register.php" class="tab active">Register</a>
    </div>

    <form method="post" action="register.php" onsubmit="handleAuthSubmit(this, 'Creating account...')">
      <div class="row">
        <div class="col field-group">
          <label for="first">First Name</label>
          <input id="first" name="first" type="text" class="input" placeholder="e.g., John" autocomplete="given-name" value="<?= htmlspecialchars($_POST['first'] ?? '') ?>" required />
        </div>
        <div class="col field-group">
          <label for="last">Last Name</label>
          <input id="last" name="last" type="text" class="input" placeholder="e.g., Doe" autocomplete="family-name" value="<?= htmlspecialchars($_POST['last'] ?? '') ?>" required />
        </div>
      </div>

      <div class="field-group">
        <label for="phone">Telephone</label>
        <input id="phone" name="phone" type="tel" class="input" placeholder="e.g., 0123456789" autocomplete="tel" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required />
      </div>

      <div class="field-group">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" class="input" placeholder="you@example.com" autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required />
      </div>

      <div class="field-group">
        <label for="password">Password</label>
        <div class="password-wrap">
          <input id="password" name="password" type="password" class="input" placeholder="Minimum 8 characters" autocomplete="new-password" oninput="updatePasswordStrength(this.value)" required />
          <button type="button" class="toggle-pw-btn" aria-label="Show password" onclick="togglePasswordVisibility('password', this)">
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
        <label for="security_question">Security Question</label>
        <select id="security_question" name="security_question" class="input" required>
          <option value="">-- Select a Security Question --</option>
          <option value="What was the name of your first pet?" <?= ($_POST['security_question'] ?? '') === 'What was the name of your first pet?' ? 'selected' : '' ?>>What was the name of your first pet?</option>
          <option value="What is your mother's name?" <?= ($_POST['security_question'] ?? '') === "What is your mother's name?" ? 'selected' : '' ?>>What is your mother's name?</option>
          <option value="What high school did you attend?" <?= ($_POST['security_question'] ?? '') === 'What high school did you attend?' ? 'selected' : '' ?>>What high school did you attend?</option>
          <option value="In what city were you born?" <?= ($_POST['security_question'] ?? '') === 'In what city were you born?' ? 'selected' : '' ?>>In what city were you born?</option>
        </select>
      </div>

      <div class="field-group">
        <label for="security_answer">Security Answer</label>
        <input id="security_answer" name="security_answer" type="text" class="input" placeholder="Your secret answer" value="<?= htmlspecialchars($_POST['security_answer'] ?? '') ?>" required />
      </div>

      <button type="submit" class="btn">Register</button>
    </form>
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