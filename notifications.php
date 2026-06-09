<?php
$current_page = 'notifications';

// Load notifications file path and settings
$notif_file = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'notifications.json';
$settings_file = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'notification_settings.json';

$notifications = [];
if (file_exists($notif_file)) {
    $nraw = file_get_contents($notif_file);
    $notifications = json_decode($nraw, true) ?: [];
}

$settings = [
    'email_reminders' => true,
    'assignment_reminder_days' => 2,
    'exam_reminder_days' => 7,
];
if (file_exists($settings_file)) {
    $sraw = file_get_contents($settings_file);
    $settings = json_decode($sraw, true) ?: $settings;
}

// Save notification settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $settings['email_reminders'] = isset($_POST['email_reminders']) && $_POST['email_reminders'] === '1';
    $settings['assignment_reminder_days'] = max(0, intval($_POST['assignment_reminder_days'] ?? 2));
    $settings['exam_reminder_days'] = max(0, intval($_POST['exam_reminder_days'] ?? 7));
    file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
    header('Location: notifications.php');
    exit;
}

// Mark notification as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read_id'])) {
    $id = intval($_POST['mark_read_id']);
    foreach ($notifications as &$notification) {
        if ($notification['id'] === $id) {
            $notification['read'] = true;
        }
    }
    unset($notification);
    file_put_contents($notif_file, json_encode($notifications, JSON_PRETTY_PRINT));
    header('Location: notifications.php');
    exit;
}

usort($notifications, function ($a, $b) {
    return strcmp($b['date'] ?? '', $a['date'] ?? '');
});

$total = count($notifications);
$unread = count(array_filter($notifications, function ($notification) {
    return empty($notification['read']);
}));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PlanGenius - Notifications</title>
  <link rel="stylesheet" href="css/style.css">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <style>
    .content-wrap { padding: 28px; }
    .page-title { font-size: 28px; margin: 0; }
    .page-subtitle { color: #7b7f87; margin: 6px 0 18px; }
    .stat-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; margin-bottom: 18px; }
    .stat-card { background: #fff; border: 1px solid #eef2f6; border-radius: 18px; padding: 24px; position: relative; min-height: 130px; }
    .stat-card .label { color: #7b7f87; margin-bottom: 12px; display: block; }
    .stat-card .value { font-size: 34px; font-weight: 700; line-height: 1; }
    .stat-card .icon { position: absolute; top: 24px; right: 24px; opacity: .16; font-size: 38px; }

    .main-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 18px; }
    .panel { background: #fff; border: 1px solid #eef2f6; border-radius: 18px; padding: 24px; }
    .panel h3 { margin: 0 0 12px; }
    .panel p { color: #7b7f87; margin: 0 0 20px; }
    .notification-item { border-top: 1px solid #f3f5f7; padding: 18px 0; }
    .notification-item:first-child { border-top: none; }
    .notification-item.unread { background: #f7fbff; }
    .notification-item .title { font-weight: 700; margin-bottom: 8px; }
    .notification-item .message { color: #475569; margin-bottom: 12px; }
    .notification-item .meta { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
    .notification-item .meta .date { color: #7b7f87; font-size: 13px; }
    .notification-item .meta button { border: none; border-radius: 999px; padding: 10px 16px; background: #0f172a; color: #fff; cursor: pointer; font-weight: 700; }
    .notification-item .meta button[disabled] { opacity: 0.5; cursor: default; background: #94a3b8; }

    .settings-panel { display: grid; gap: 18px; }
    .toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 18px; background: #f8fafc; border-radius: 16px; }
    .toggle-row label { font-weight: 700; }
    .switch { position: relative; display: inline-flex; width: 46px; height: 28px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: #d1d5db; border-radius: 999px; transition: .2s; }
    .slider::before { position: absolute; content: ""; height: 22px; width: 22px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: .2s; }
    .switch input:checked + .slider { background: #22c55e; }
    .switch input:checked + .slider::before { transform: translateX(18px); }

    .settings-field { display: grid; gap: 10px; }
    .settings-field label { font-weight: 700; }
    .settings-field input { width: 100%; max-width: 120px; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 12px; }
    .settings-field small { color: #7b7f87; }
    .settings-footer { display: flex; justify-content: flex-end; }
    .settings-footer button { padding: 12px 24px; border: none; border-radius: 12px; background: #0f172a; color: #fff; font-weight: 700; cursor: pointer; }

    .empty-state { text-align: center; color: #7b7f87; padding: 48px 0; }
    @media (max-width: 960px) { .main-grid { grid-template-columns: 1fr; } .stat-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <?php include 'includes/sidebar.php'; ?>
  <main class="main-content">
    <?php include 'includes/header.php'; ?>

    <div class="content-wrap">
      <div class="page-header">
        <h1 class="page-title">Notification Management</h1>
        <p class="page-subtitle">Automated reminders for assignments, exams, and study sessions</p>
      </div>

      <div class="stat-grid">
        <div class="stat-card">
          <span class="label">Total Notifications</span>
          <div class="value"><?php echo $total; ?></div>
          <i class="ph ph-bell icon"></i>
        </div>

        <div class="stat-card">
          <span class="label">Unread</span>
          <div class="value" style="color:#dc2626"><?php echo $unread; ?></div>
          <i class="ph ph-envelope icon"></i>
        </div>

        <div class="stat-card">
          <span class="label">Email Reminders</span>
          <div class="value"><?php echo $settings['email_reminders'] ? 'Active' : 'Disabled'; ?></div>
          <i class="ph ph-gear-six icon"></i>
        </div>
      </div>

      <div class="main-grid">
        <div class="panel">
          <h3>Recent Notifications</h3>
          <p class="small">Latest reminders and alerts from your planner</p>
          <?php if (empty($notifications)): ?>
            <div class="empty-state">No new notifications.</div>
          <?php else: ?>
            <?php foreach ($notifications as $notification): ?>
              <div class="notification-item <?php echo empty($notification['read']) ? 'unread' : ''; ?>">
                <div class="title"><?php echo htmlspecialchars($notification['title']); ?></div>
                <div class="message"><?php echo htmlspecialchars($notification['message']); ?></div>
                <div class="meta">
                  <div class="date"><?php echo htmlspecialchars($notification['date']); ?></div>
                  <form method="post" style="margin:0">
                    <input type="hidden" name="mark_read_id" value="<?php echo intval($notification['id']); ?>">
                    <button type="submit" <?php echo empty($notification['read']) ? '' : 'disabled'; ?>><?php echo empty($notification['read']) ? 'Mark read' : 'Read'; ?></button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div class="panel">
          <h3>Email Notification Settings</h3>
          <p class="small">Configure how you want to receive reminder alerts</p>
          <form method="post">
            <div class="toggle-row">
              <span>Email reminders</span>
              <label class="switch">
                <input type="checkbox" name="email_reminders" value="1" <?php echo $settings['email_reminders'] ? 'checked' : ''; ?>>
                <span class="slider"></span>
              </label>
            </div>

            <div class="settings-field">
              <label for="assignment_reminder_days">Assignment Reminders</label>
              <input id="assignment_reminder_days" type="number" name="assignment_reminder_days" min="0" value="<?php echo intval($settings['assignment_reminder_days']); ?>">
              <small>Get notified <?php echo intval($settings['assignment_reminder_days']); ?> days before assignment deadlines.</small>
            </div>

            <div class="settings-field">
              <label for="exam_reminder_days">Exam Reminders</label>
              <input id="exam_reminder_days" type="number" name="exam_reminder_days" min="0" value="<?php echo intval($settings['exam_reminder_days']); ?>">
              <small>Get notified <?php echo intval($settings['exam_reminder_days']); ?> days before exams.</small>
            </div>

            <div class="settings-footer">
              <button type="submit" name="save_settings">Save Settings</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <button class="help-btn"><i class="ph ph-question"></i></button>
  </main>
</body>
</html>
