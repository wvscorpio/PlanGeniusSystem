<?php
$current_page = 'profile';

$profile_file = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'profile.json';
$profile = [
    'first_name' => 'John',
    'last_name' => 'Doe',
    'email' => 'john.doe@example.com',
    'telephone' => '1234567890',
];

if (file_exists($profile_file)) {
    $raw = file_get_contents($profile_file);
    $profile = json_decode($raw, true) ?: $profile;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_profile'])) {
        $profile['first_name'] = trim($_POST['first_name'] ?? $profile['first_name']);
        $profile['last_name'] = trim($_POST['last_name'] ?? $profile['last_name']);
        $profile['email'] = trim($_POST['email'] ?? $profile['email']);
        $profile['telephone'] = trim($_POST['telephone'] ?? $profile['telephone']);
        file_put_contents($profile_file, json_encode($profile, JSON_PRETTY_PRINT));
        $message = 'Profile updated successfully.';
    }
    if (isset($_POST['delete_account'])) {
        unlink($profile_file);
        $profile = [
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'telephone' => '',
        ];
        $message = 'Account deleted. Profile data reset.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PlanGenius — Profile</title>
  <link rel="stylesheet" href="css/style.css">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <style>
    .content-wrap { padding: 28px; }
    .page-title { font-size: 32px; margin: 0; }
    .page-subtitle { color: #7b7f87; margin: 8px 0 20px; }
    .card { background: #fff; border: 1px solid #eef2f6; border-radius: 22px; padding: 32px; max-width: 880px; }
    .card h2 { margin: 0 0 8px; }
    .card p { color: #7b7f87; margin: 0 0 28px; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; }
    .field { margin-bottom: 20px; }
    .field label { display: block; font-weight: 700; margin-bottom: 8px; }
    .field input { width: 100%; padding: 14px 16px; border: 1px solid #d1d5db; border-radius: 14px; font-size: 15px; }
    .buttons { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 16px; }
    .btn { padding: 14px 28px; border-radius: 14px; border: none; cursor: pointer; font-weight: 700; }
    .btn-primary { background: #060412; color: #fff; }
    .btn-danger { background: #dc3545; color: #fff; }
    .message { margin-bottom: 20px; padding: 16px 18px; border-radius: 14px; background: #ecfdf5; color: #166534; border: 1px solid #d1fae5; }
    @media (max-width: 800px) { .row { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <?php include 'includes/sidebar.php'; ?>
  <main class="main-content">
    <?php include 'includes/header.php'; ?>

    <div class="content-wrap">
      <div class="page-header">
        <h1 class="page-title">Profile Management</h1>
        <p class="page-subtitle">View, update, or delete your profile information</p>
      </div>

      <div class="card">
        <h2>Personal Information</h2>
        <p>Update your personal details here.</p>
        <?php if ($message): ?><div class="message"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <form method="post">
          <div class="row">
            <div class="field">
              <label for="first_name">First Name</label>
              <input id="first_name" name="first_name" type="text" value="<?php echo htmlspecialchars($profile['first_name']); ?>" required>
            </div>
            <div class="field">
              <label for="last_name">Last Name</label>
              <input id="last_name" name="last_name" type="text" value="<?php echo htmlspecialchars($profile['last_name']); ?>" required>
            </div>
          </div>

          <div class="field">
            <label for="email">Email Address</label>
            <input id="email" name="email" type="email" value="<?php echo htmlspecialchars($profile['email']); ?>" required>
          </div>

          <div class="field">
            <label for="telephone">Telephone Number</label>
            <input id="telephone" name="telephone" type="tel" value="<?php echo htmlspecialchars($profile['telephone']); ?>" required>
          </div>

          <div class="buttons">
            <button type="submit" name="save_profile" class="btn btn-primary">Save Changes</button>
            <button type="submit" name="delete_account" class="btn btn-danger" onclick="return confirm('Delete your profile data? This cannot be undone.')">Delete Account</button>
          </div>
        </form>
      </div>
    </div>

    <button class="help-btn"><i class="ph ph-question"></i></button>
  </main>
</body>
</html>