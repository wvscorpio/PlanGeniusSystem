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
          $message = 'Registration successful. You can now log in.';
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
  <link rel="stylesheet" type="text/css" href="css/style.css"> 
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <style>
    :root{ --bg1:#e9f0ff; --bg2:#f6fbff; --accent:#5b3bff; --muted:#efefef; --card:#ffffff; --black:#03010a; }
    html,body{height:100%;margin:0;font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,'Helvetica Neue',Arial;}
    body{ display:flex;align-items:center;justify-content:center; background:linear-gradient(180deg,var(--bg1),var(--bg2)); padding:40px; }
    .wrap{ width:520px;max-width:94%;background:var(--card);border-radius:16px;padding:34px;box-shadow:0 6px 20px rgba(38,61,91,0.08); }
    
    .brand{ 
      text-align:center;
      margin-bottom:20px; 
      display:flex;
      justify-content:center;
    }
    .brand-link{
      display:inline-flex;
      align-items:center;
      gap:12px;
      text-decoration:none;
    }
    .brand-icon{
      width:44px;
      height:44px;
      border-radius:12px;
      background:linear-gradient(135deg, #9333ea, #7c3aed);
      display:flex;
      align-items:center;
      justify-content:center;
      color:#ffffff;
      box-shadow:0 4px 12px rgba(124, 58, 237, 0.25);
    }
    .brand-text{
      text-align:left;
      display:flex;
      flex-direction:column;
    }
    .brand-title{
      font-size:24px;
      font-weight:800;
      color:#2e1065;
      letter-spacing:-0.5px;
      line-height:1.1;
    }
    .brand-title span{
      color:#7c3aed;
    }
    .brand-subtitle{
      font-size:10px;
      font-weight:600;
      color:#9333ea;
      text-transform:uppercase;
      letter-spacing:1px;
      margin-top:1px;
    }

    .tabbar{ display:flex;gap:12px; background:#efefef;padding:8px;border-radius:999px; width:320px;margin:0 auto 18px; }
    .tab{ flex:1;text-align:center;padding:10px;border-radius:999px;font-weight:600;color:#222;cursor:pointer;text-decoration:none }
    .tab.active{ background:white; box-shadow:0 4px 0 rgba(0,0,0,0.06); border:4px solid rgba(0,0,0,0.06); }

    .row{ display:flex;gap:18px }
    .col{ flex:1 }
    label{display:block;font-weight:600;margin:10px 0 8px;color:#1f2933}
    .input{width:100%;padding:16px;border-radius:12px;border:none;background:var(--muted);box-sizing:border-box;font-size:15px}
    select.input{ cursor:pointer; }
    .btn{display:block;width:100%;background:var(--black);color:#fff;padding:14px;border-radius:12px;border:none;font-weight:700;font-size:16px;margin-top:18px;cursor:pointer}

    .toast-container{ position:fixed; top:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:10px; align-items:flex-end }
    .toast{ background:#fff; border-radius:8px; padding:12px 16px; box-shadow:0 6px 18px rgba(20,30,50,0.12); min-width:280px; max-width:380px; color:#0b1720; border-left:6px solid #ccc; display:flex; gap:12px; align-items:flex-start }
    .toast.success{ border-left-color:#16a34a }
    .toast.error{ border-left-color:#dc2626 }
    .toast .close{ margin-left:auto; background:transparent;border:none;color:#667085;font-weight:700;cursor:pointer }
    .toast .content{ white-space:pre-wrap }

    @media (max-width:520px){ .row{ flex-direction:column } .tabbar{ width:100% } }
  </style>
</head>
<body>
  <div id="toast-container" class="toast-container"></div>

  <div class="wrap">
    <!-- Landing Page Matching Logo -->
    <div class="brand">
      <a href="index.php" class="brand-link">
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

    <form method="post" action="register.php">
      <div class="row">
        <div class="col">
          <label for="first">First Name</label>
          <input id="first" name="first" type="text" class="input" value="<?= htmlspecialchars($_POST['first'] ?? '') ?>" required />
        </div>
        <div class="col">
          <label for="last">Last Name</label>
          <input id="last" name="last" type="text" class="input" value="<?= htmlspecialchars($_POST['last'] ?? '') ?>" required />
        </div>
      </div>

      <label for="phone">Telephone</label>
      <input id="phone" name="phone" type="tel" class="input" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required />

      <label for="email">Email</label>
      <input id="email" name="email" type="email" class="input" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required />

      <label for="password">Password</label>
      <input id="password" name="password" type="password" class="input" required />

      <label for="security_question">Security Question</label>
      <select id="security_question" name="security_question" class="input" required>
        <option value="">-- Select a Security Question --</option>
        <option value="What was the name of your first pet?" <?= ($_POST['security_question'] ?? '') === 'What was the name of your first pet?' ? 'selected' : '' ?>>What was the name of your first pet?</option>
        <option value="What is your mother's name?" <?= ($_POST['security_question'] ?? '') === "What is your mother's name?" ? 'selected' : '' ?>>What is your mother's name?</option>
        <option value="What high school did you attend?" <?= ($_POST['security_question'] ?? '') === 'What high school did you attend?' ? 'selected' : '' ?>>What high school did you attend?</option>
        <option value="In what city were you born?" <?= ($_POST['security_question'] ?? '') === 'In what city were you born?' ? 'selected' : '' ?>>In what city were you born?</option>
      </select>

      <label for="security_answer">Security Answer</label>
      <input id="security_answer" name="security_answer" type="text" class="input" placeholder="Your secret answer" value="<?= htmlspecialchars($_POST['security_answer'] ?? '') ?>" required />

      <button type="submit" class="btn">Register</button>
    </form>
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