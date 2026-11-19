<?php
include 'dbConn.php';
session_start();
$message = '';
$successMessage = '';

// Check for success message from password reset
if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Helper function to determine landing page based on existing academic structure
function getLandingPage($conn, $studentID) {
    try {
        $check = $conn->prepare('SELECT 1 FROM "SemesterPlan" WHERE "studentID" = :studentID LIMIT 1');
        $check->execute([':studentID' => $studentID]);
        return $check->fetch() ? 'dashboard.php' : 'academic_structure.php';
    } catch (PDOException $e) {
        return 'academic_structure.php';
    }
}

// 1. If the student is already logged in, redirect based on academic structure
if (isset($_SESSION['studentID'])) {
    $destination = getLandingPage($conn, $_SESSION['studentID']);
    header('Location: ' . $destination);
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
                
                // Redirect first-timers without semesters to Academic Structure, others to Dashboard
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
  <link rel="stylesheet" type="text/css" href="css/style.css"> 
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
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
    @media (max-width:520px){ .tabbar{ width:100%; } }
  </style>
</head>
<body>
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

    <div class="footer">Forgot password? <a href="forgot_password.php" style="color: #3b82f6; text-decoration: none; font-weight: 500;">Reset here</a></div>
    
    <div class="notification-toast" id="notificationToast">
      <i class="ph-fill ph-check-circle"></i> <span id="notificationMessage">Login successfully!</span>
    </div>
  </div>

<script>
    const serverMessage = <?php echo json_encode($message); ?>;
    const successMessage = <?php echo json_encode($successMessage); ?>;

    function showNotification(message, type = 'success') {
      const toast = document.getElementById('notificationToast');
      document.getElementById('notificationMessage').innerText = message; 
      
      const cls = type === 'error' ? 'error' : 'success';
      toast.classList.remove('success', 'error');
      toast.classList.add(cls, 'show');
      
      setTimeout(() => {
          toast.classList.remove('show');
          toast.classList.remove(cls);
      }, 3000);
    }

    function escapeHtml(str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }
    document.addEventListener('DOMContentLoaded', () => {
      if (serverMessage) {
        showNotification(escapeHtml(serverMessage), 'error');
      }
      if (successMessage) {                      
        showNotification(escapeHtml(successMessage), 'success');  
      }
    });
  </script>
</body>
</html>