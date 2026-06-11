<?php
include 'dbConn.php';
session_start();
$message = '';

// 1. If the student is already logged in, redirect them directly to the dashboard
if (isset($_SESSION['studentID'])) {
    header('Location: dashboard.php');
    exit;
}

// 2. Handle the Login Form Submission
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
                
                header('Location: dashboard.php');
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
  <link rel="stylesheet" type="text/css" href="css/style.css"> 
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
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

    .tabbar{ 
      display:flex;gap:12px; background:#efefef;padding:8px;border-radius:999px; width:320px;margin:0 auto 18px; 
    }
    .tab{ 
      flex:1;text-align:center;padding:10px;border-radius:999px;font-weight:600;color:#222;cursor:pointer 
    }
    .tab.active{ 
      background:white; box-shadow:0 4px 0 rgba(0,0,0,0.06); border:4px solid rgba(0,0,0,0.06); 
    }

    label{
      display:block;font-weight:600;margin:10px 0 8px;color:#1f2933
    }
    .input{
      width:100%;padding:18px;border-radius:12px;border:none;background:var(--muted);box-sizing:border-box;font-size:15px
    }
    .btn{
      display:block;width:100%;background:var(--black);color:#fff;padding:14px;border-radius:12px;border:none;
      font-weight:700;font-size:16px;margin-top:18px;cursor:pointer
    }

    .alert{ 
      background:#fff6e6;border:1px solid #ffe0b3;padding:10px;border-radius:8px;color:#6a4b00;margin-bottom:12px 
    }
    .footer{ 
      text-align:center;margin-top:12px;color:#9aa0a6;font-size:13px 
    }

    .toast-container{ position:fixed; top:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:10px; align-items:flex-end }
    .toast{ background:#fff; border-radius:8px; padding:12px 16px; box-shadow:0 6px 18px rgba(20,30,50,0.12); min-width:280px; max-width:380px; color:#0b1720; border-left:6px solid #ccc; display:flex; gap:12px; align-items:flex-start }
    .toast.success{ border-left-color:#16a34a }
    .toast.error{ border-left-color:#dc2626 }
    .toast .close{ margin-left:auto; background:transparent;border:none;color:#667085;font-weight:700;cursor:pointer }
    .toast .content{ white-space:pre-wrap }

    @media (max-width:520px){ .tabbar{ width:100%; } }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="brand">
      <h1>PlanGenius</h1>
      <p>Intelligent Planner for Students</p>
    </div>

    <div id="toast-container" class="toast-container" aria-live="polite"></div>
  <div class="tabbar">
        <a href="login.php" class="tab active">Login</a>
        <a href="register.php" class="tab">Register</a>
    </div>

    <form method="post" action="login.php">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" class="input" placeholder="" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required />

      <label for="password">Password</label>
      <input id="password" name="password" type="password" class="input" placeholder="" required />

      <button type="submit" class="btn">Login</button>
    </form>

    <div class="footer">Forgot password? Contact your administrator.</div>
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
