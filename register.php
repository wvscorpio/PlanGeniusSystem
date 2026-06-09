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

    if ($first === '') $errors[] = 'First name is required.';
    if ($last === '') $errors[] = 'Last name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($phone === '') $errors[] = 'Telephone is required.';

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare(
                'INSERT INTO "Student" ("firstName", "lastName", "email", "password", "phoneNo") VALUES (:first, :last, :email, :password, :phone)'
            );
            $stmt->execute([
                ':first' => $first,
                ':last' => $last,
                ':email' => $email,
                ':password' => password_hash($password, PASSWORD_BCRYPT),
                ':phone' => $phone,
            ]);
            $message = 'Registration successful. You can now log in.';
        } catch (PDOException $e) {
            $errors[] = 'Registration failed: ' . $e->getMessage();
        }
    }
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
  <style>
    :root{ --bg1:#e9f0ff; --bg2:#f6fbff; --accent:#5b3bff; --muted:#efefef; --card:#ffffff; --black:#03010a; }
    html,body{height:100%;margin:0;font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,'Helvetica Neue',Arial;}
    body{ display:flex;align-items:center;justify-content:center; background:linear-gradient(180deg,var(--bg1),var(--bg2)); padding:40px; }
    .wrap{ width:520px;max-width:94%;background:var(--card);border-radius:16px;padding:34px;box-shadow:0 6px 20px rgba(38,61,91,0.08); }
    .brand{ text-align:center;margin-bottom:8px; }
    .brand h1{ margin:0;font-size:36px;color:var(--accent);font-weight:700; }
    .brand p{ margin:4px 0 20px;color:#7b7f87;font-weight:500 }

    .tabbar{ display:flex;gap:12px; background:#efefef;padding:8px;border-radius:999px; width:320px;margin:0 auto 18px; }
    .tab{ flex:1;text-align:center;padding:10px;border-radius:999px;font-weight:600;color:#222;cursor:pointer }
    .tab.active{ background:white; box-shadow:0 4px 0 rgba(0,0,0,0.06); border:4px solid rgba(0,0,0,0.06); }

    .row{ display:flex;gap:18px }
    .col{ flex:1 }
    label{display:block;font-weight:600;margin:10px 0 8px;color:#1f2933}
    .input{width:100%;padding:16px;border-radius:12px;border:none;background:var(--muted);box-sizing:border-box;font-size:15px}
    .btn{display:block;width:100%;background:var(--black);color:#fff;padding:14px;border-radius:12px;border:none;font-weight:700;font-size:16px;margin-top:18px;cursor:pointer}

    .messages{margin-bottom:12px}
    .error{ background:#ffecec;border:1px solid #f5c2c2;padding:10px;border-radius:8px;color:#6a1b1b;margin-bottom:8px }
    .success{ background:#eef7ff;border:1px solid #c6e0ff;padding:10px;border-radius:8px;color:#0b3a66;margin-bottom:8px }

    @media (max-width:520px){ .row{ flex-direction:column } .tabbar{ width:100% } }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="brand">
      <h1>PlanGenius</h1>
      <p>Intelligent Planner for Students</p>
    </div>

    <div class="tabbar">
        <a href="login.php" class="tab">Login</a>
        <a href="register.php" class="tab active">Register</a>
    </div>

    <div class="messages">
      <?php if (!empty($errors)): ?>
        <?php foreach($errors as $e): ?>
          <div class="error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($message): ?>
        <div class="success"><?= $message ?></div>
      <?php endif; ?>
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

      <button type="submit" class="btn">Register</button>
    </form>

  </div>
</body>
</html>