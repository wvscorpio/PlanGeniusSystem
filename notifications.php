<?php
$current_page = 'notifications';
include 'dbConn.php';
session_start();

$studentID = $_SESSION['studentID'] ?? null;
if (!$studentID) {
    header('Location: login.php');
    exit;
}

// -----------------------------------------------------------------------------
// 0. Handle API JSON Requests (Async JS Fetch Handlers)
// -----------------------------------------------------------------------------
$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);

if ($jsonData && isset($jsonData['action'])) {
    header('Content-Type: application/json');
    $action = $jsonData['action'];

    if ($action === 'markAsRead' || $action === 'markAsUnread') {
        $id = intval($jsonData['notificationID'] ?? 0);
        $status = ($action === 'markAsRead') ? 'Read' : 'Unread';
        $u = $conn->prepare('UPDATE "Notification" SET "notificationStatus" = :status WHERE "notificationID" = :id AND "studentID" = :studentID');
        $success = $u->execute([':status' => $status, ':id' => $id, ':studentID' => $studentID]);
        echo json_encode(['success' => (bool)$success]);
        exit;
    }

    if ($action === 'savePreferences') {
        $p = $jsonData['preferences'] ?? [];
        $stmt = $conn->prepare('
            INSERT INTO "NotificationSetting" (
                "studentID", "emailReminders", "assignmentReminders", "examReminders", 
                "studySessionReminders", "assignmentSubmissionAlert", "overdueReminders"
            ) VALUES (
                :studentID, :emailReminders, :assignmentReminders, :examReminders, 
                :studySessionReminders, :assignmentSubmissionAlert, :overdueReminders
            )
            ON CONFLICT ("studentID") DO UPDATE SET
                "emailReminders" = EXCLUDED."emailReminders",
                "assignmentReminders" = EXCLUDED."assignmentReminders",
                "examReminders" = EXCLUDED."examReminders",
                "studySessionReminders" = EXCLUDED."studySessionReminders",
                "assignmentSubmissionAlert" = EXCLUDED."assignmentSubmissionAlert",
                "overdueReminders" = EXCLUDED."overdueReminders"
        ');

        $success = $stmt->execute([
            ':studentID'                 => $studentID,
            ':emailReminders'            => !empty($p['email_reminders']) ? 'true' : 'false',
            ':assignmentReminders'       => !empty($p['assignment_reminders']) ? 'true' : 'false',
            ':examReminders'             => !empty($p['exam_reminders']) ? 'true' : 'false',
            ':studySessionReminders'     => !empty($p['study_session_reminders']) ? 'true' : 'false',
            ':assignmentSubmissionAlert' => !empty($p['assignment_submission_alert']) ? 'true' : 'false',
            ':overdueReminders'          => !empty($p['overdue_reminders']) ? 'true' : 'false',
        ]);

        echo json_encode(['success' => (bool)$success]);
        exit;
    }
}

// -----------------------------------------------------------------------------
// 1. Fetch User Preferences
// -----------------------------------------------------------------------------
$defaults = [
    'email_reminders'              => true,
    'assignment_reminders'         => true,
    'exam_reminders'               => true,
    'study_session_reminders'      => true,
    'assignment_submission_alert'  => true,
    'overdue_reminders'            => true,
];

$settings = $defaults;

$stmt = $conn->prepare('SELECT * FROM "NotificationSetting" WHERE "studentID" = :studentID LIMIT 1');
$stmt->execute([':studentID' => $studentID]);
$dbSettings = $stmt->fetch(PDO::FETCH_ASSOC);

if ($dbSettings) {
    $settings = [
        'email_reminders'              => filter_var($dbSettings['emailReminders'], FILTER_VALIDATE_BOOLEAN),
        'assignment_reminders'         => filter_var($dbSettings['assignmentReminders'], FILTER_VALIDATE_BOOLEAN),
        'exam_reminders'               => filter_var($dbSettings['examReminders'], FILTER_VALIDATE_BOOLEAN),
        'study_session_reminders'      => filter_var($dbSettings['studySessionReminders'], FILTER_VALIDATE_BOOLEAN),
        'assignment_submission_alert'  => filter_var($dbSettings['assignmentSubmissionAlert'], FILTER_VALIDATE_BOOLEAN),
        'overdue_reminders'            => filter_var($dbSettings['overdueReminders'], FILTER_VALIDATE_BOOLEAN),
    ];
}

// -----------------------------------------------------------------------------
// 2. Standard Form POST Fallbacks
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $stmt = $conn->prepare('
        INSERT INTO "NotificationSetting" (
            "studentID", "emailReminders", "assignmentReminders", "examReminders", 
            "studySessionReminders", "assignmentSubmissionAlert", "overdueReminders"
        ) VALUES (
            :studentID, :emailReminders, :assignmentReminders, :examReminders, 
            :studySessionReminders, :assignmentSubmissionAlert, :overdueReminders
        )
        ON CONFLICT ("studentID") DO UPDATE SET
            "emailReminders" = EXCLUDED."emailReminders",
            "assignmentReminders" = EXCLUDED."assignmentReminders",
            "examReminders" = EXCLUDED."examReminders",
            "studySessionReminders" = EXCLUDED."studySessionReminders",
            "assignmentSubmissionAlert" = EXCLUDED."assignmentSubmissionAlert",
            "overdueReminders" = EXCLUDED."overdueReminders"
    ');

    $stmt->execute([
        ':studentID'                 => $studentID,
        ':emailReminders'            => isset($_POST['email_reminders']) ? 'true' : 'false',
        ':assignmentReminders'       => isset($_POST['assignment_reminders']) ? 'true' : 'false',
        ':examReminders'             => isset($_POST['exam_reminders']) ? 'true' : 'false',
        ':studySessionReminders'     => isset($_POST['study_session_reminders']) ? 'true' : 'false',
        ':assignmentSubmissionAlert' => isset($_POST['assignment_submission_alert']) ? 'true' : 'false',
        ':overdueReminders'          => isset($_POST['overdue_reminders']) ? 'true' : 'false',
    ]);

    header('Location: notifications.php?saved=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read_id'])) {
    $id = intval($_POST['mark_read_id']);
    $u = $conn->prepare('UPDATE "Notification" SET "notificationStatus" = \'Read\' WHERE "notificationID" = :id AND "studentID" = :studentID');
    $u->execute([':id' => $id, ':studentID' => $studentID]);
    header('Location: notifications.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_unread_id'])) {
    $id = intval($_POST['mark_unread_id']);
    $u = $conn->prepare('UPDATE "Notification" SET "notificationStatus" = \'Unread\' WHERE "notificationID" = :id AND "studentID" = :studentID');
    $u->execute([':id' => $id, ':studentID' => $studentID]);
    header('Location: notifications.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_notif_id'])) {
    $id = intval($_POST['delete_notif_id']);
    $u = $conn->prepare('DELETE FROM "Notification" WHERE "notificationID" = :id AND "studentID" = :studentID');
    $u->execute([':id' => $id, ':studentID' => $studentID]);
    header('Location: notifications.php?deleted=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $u = $conn->prepare('UPDATE "Notification" SET "notificationStatus" = \'Read\' WHERE "studentID" = :studentID AND "notificationStatus" = \'Unread\'');
    $u->execute([':studentID' => $studentID]);
    header('Location: notifications.php');
    exit;
}

// -----------------------------------------------------------------------------
// 3. Fetch Live Database Notifications (Active Semester Only)
// -----------------------------------------------------------------------------
$notifications = [];
try {
    $stmt = $conn->prepare('
        SELECT n."notificationID", n."notificationMessage", n."sentDate", n."notificationStatus", n."referenceType" 
        FROM "Notification" n
        LEFT JOIN "Assignment" a ON LOWER(TRIM(n."referenceType")) = \'assignment\' AND CAST(n."referenceID" AS INTEGER) = a."assignmentID"
        LEFT JOIN "Exam" e ON LOWER(TRIM(n."referenceType")) = \'exam\' AND CAST(n."referenceID" AS INTEGER) = e."examID"
        LEFT JOIN "Schedule" sch ON LOWER(TRIM(n."referenceType")) IN (\'schedule\', \'studysession\') AND CAST(n."referenceID" AS INTEGER) = sch."scheduleID"
        LEFT JOIN "Course" c ON c."courseID" = COALESCE(a."courseID", e."courseID", sch."courseID")
        LEFT JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE n."studentID" = :studentID
            AND s."startDate" <= CURRENT_DATE 
            AND s."endDate" >= CURRENT_DATE
        ORDER BY n."sentDate" DESC LIMIT 50
    ');
    $stmt->execute([':studentID' => $studentID]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tz = new DateTimeZone('Asia/Kuala_Lumpur');
    $now = new DateTime('now', $tz);

    foreach ($rows as $r) {
        $msg = $r['notificationMessage'];
        $refType = strtolower($r['referenceType'] ?? '');

        $sentDate = new DateTime($r['sentDate'], new DateTimeZone('UTC'));
        $sentDate->setTimezone($tz);

        $diffDays = (int)$now->diff($sentDate)->format('%r%a');

        $courseTag = 'GENERAL';
        if (preg_match('/\[(.*?)\]/', $msg, $matches)) {
            $courseTag = $matches[1];
        }

        $cleanMsg = trim(preg_replace('/\[.*?\]\s*/', '', $msg));

        $color = '#3b82f6';
        $category = 'assignment';
        $icon = 'ph-file-text';

        if (stripos($msg, 'OVERDUE') !== false) {
            $color = '#ef4444'; // Red for Overdue
            $category = 'overdue';
            $icon = 'ph-warning-octagon';
        } elseif (stripos($msg, 'TODAY') !== false) {
            $color = '#ef4444'; // Red for Due Today
            $category = 'assignment';
            $icon = 'ph-clock';
        } elseif (
            $refType === 'studysession' || 
            stripos($msg, 'Study') !== false || 
            stripos($msg, 'Session') !== false
        ) {
            $color = '#8b5cf6';
            $category = 'study_session';
            $icon = 'ph-book-open';
        } elseif (
            $refType === 'exam' || 
            stripos($msg, 'Exam') !== false || 
            stripos($msg, 'Quiz') !== false || 
            stripos($msg, 'Test') !== false || 
            stripos($msg, 'Midterm') !== false || 
            stripos($msg, 'Final') !== false
        ) {
            $category = 'exam';
            $icon = 'ph-exam';
            if (abs($diffDays) <= 1) $color = '#ef4444';
            elseif (abs($diffDays) <= 2) $color = '#f97316';
            elseif (abs($diffDays) <= 3) $color = '#eab308';
            else $color = '#3b82f6';
        } else {
            if (abs($diffDays) <= 1) $color = '#ef4444';
            elseif (abs($diffDays) <= 2) $color = '#f97316';
            elseif (abs($diffDays) <= 3) $color = '#eab308';
        }

        $notifications[] = [
            'id'       => $r['notificationID'],
            'course'   => $courseTag,
            'title'    => ucfirst($category) . ' Alert',
            'message'  => $cleanMsg,
            'sentDate' => $sentDate->format('j M Y, g:i A'),
            'read'     => (strtolower($r['notificationStatus']) === 'read'),
            'color'    => $color,
            'category' => $category,
            'icon'     => $icon
        ];
    }
} catch (Exception $e) {}

$total = count($notifications);
$unread = count(array_filter($notifications, fn($n) => !$n['read']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Notification Management - PlanGenius</title>
  <link rel="stylesheet" href="css/style.css">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body>
  <?php include 'includes/sidebar.php'; ?>
  <main class="main-content">
    <?php include 'includes/header.php'; ?>

    <div class="content-wrap">
      <div class="page-header">
        <h1 class="page-title">Notification Management</h1>
        <p class="page-subtitle">Automated schedule triggers, countdown alerts, and email preferences</p>
      </div>

      <div class="stat-grid">
        <div class="stat-card">
          <span class="label">Total Alerts</span>
          <div class="value"><?php echo $total; ?></div>
          <i class="ph ph-bell icon"></i>
        </div>
        <div class="stat-card">
          <span class="label">Unread Messages</span>
          <div class="value" style="color:#dc2626;"><?php echo $unread; ?></div>
          <i class="ph ph-envelope-simple icon"></i>
        </div>
        <div class="stat-card">
          <span class="label">Email Delivery</span>
          <div class="value" style="color:<?php echo $settings['email_reminders'] ? '#16a34a' : '#64748b'; ?>;">
            <?php echo $settings['email_reminders'] ? 'Active' : 'Disabled'; ?>
          </div>
          <i class="ph ph-paper-plane-tilt icon"></i>
        </div>
      </div>

      <div class="main-grid">
        <div class="panel">
          <div class="panel-header">
            <h3 style="margin:0; font-size:16px;">Notification Feed</h3>
            <?php if ($unread > 0): ?>
              <form method="post" style="margin:0;">
                <button type="submit" name="mark_all_read" class="btn-mark">Mark All as Read</button>
              </form>
            <?php endif; ?>
          </div>

          <div class="filter-pills">
            <button class="filter-btn active" onclick="filterNotifs('all', event)">All</button>
            <button class="filter-btn" onclick="filterNotifs('unread', event)">Unread</button>
            <button class="filter-btn" onclick="filterNotifs('assignment', event)">Assignments</button>
            <button class="filter-btn" onclick="filterNotifs('exam', event)">Exams</button>
            <button class="filter-btn" onclick="filterNotifs('study_session', event)">Study Sessions</button>
            <button class="filter-btn" onclick="filterNotifs('overdue', event)">Overdue</button>
          </div>

          <div id="notifList">
            <?php if (empty($notifications)): ?>
              <div style="text-align:center; padding:40px; color:#94a3b8;">No notifications available.</div>
            <?php else: ?>
              <?php foreach ($notifications as $n): ?>
                <div class="notif-card <?php echo !$n['read'] ? 'unread' : ''; ?>" 
                     data-category="<?php echo $n['category']; ?>" 
                     data-read="<?php echo $n['read'] ? 'true' : 'false'; ?>"
                     style="--urgency-color: <?php echo $n['color']; ?>;">
                  <div class="notif-icon" style="background: <?php echo $n['color']; ?>15; color: <?php echo $n['color']; ?>;">
                    <i class="ph <?php echo $n['icon']; ?>"></i>
                  </div>
                  <div class="notif-content">
                    <div class="notif-top">
                      <span class="course-badge"><?php echo htmlspecialchars($n['course']); ?></span>
                      <span style="font-size:11px; color:#94a3b8;"><?php echo $n['sentDate']; ?></span>
                    </div>
                    <div class="notif-desc"><?php echo htmlspecialchars($n['message']); ?></div>
                    <div class="notif-meta">
                      <span style="color:<?php echo $n['color']; ?>; font-weight:700;">● Priority Alert</span>
                      <div style="display:flex; gap:8px; align-items:center;">
                        <button type="button" class="btn-mark" onclick="toggleReadStatus(<?php echo $n['id']; ?>, <?php echo $n['read'] ? 'true' : 'false'; ?>)">
                          <?php echo $n['read'] ? 'Mark as Unread' : 'Mark as Read'; ?>
                        </button>
                        <button type="button" class="btn-delete" onclick="openDeleteModal(<?php echo $n['id']; ?>)">Delete</button>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="panel">
          <h3 style="margin-top:0; font-size:16px; margin-bottom:16px;">Notification Settings</h3>
          <form id="settingsForm" method="post" onsubmit="handleSavePreferences(event)">
            <div class="toggle-row">
              <span>Email Alerts</span>
              <input type="checkbox" id="email_reminders" name="email_reminders" value="1" <?php echo $settings['email_reminders'] ? 'checked' : ''; ?>>
            </div>

            <div class="toggle-row">
              <div>
                <div class="toggle-label">Assignment Reminders</div>
                <div class="timing-info">3, 2, and 1 day(s) before the due date</div>
              </div>
              <input type="checkbox" id="assignment_reminders" name="assignment_reminders" value="1" <?php echo $settings['assignment_reminders'] ? 'checked' : ''; ?>>
            </div>

            <div class="toggle-row">
              <div>
                <div class="toggle-label">Exam Reminders</div>
                <div class="timing-info">6, 3, 2, and 1 day(s) before the exam</div>
              </div>
              <input type="checkbox" id="exam_reminders" name="exam_reminders" value="1" <?php echo $settings['exam_reminders'] ? 'checked' : ''; ?>>
            </div>

            <div class="toggle-row">
              <div>
                <div class="toggle-label">Study Session Alerts</div>
                <div class="timing-info">30 minutes before the scheduled session</div>
              </div>
              <input type="checkbox" id="study_session_reminders" name="study_session_reminders" value="1" <?php echo $settings['study_session_reminders'] ? 'checked' : ''; ?>>
            </div>

            <div class="toggle-row">
              <div>
                <div class="toggle-label">Assignment Submission Alerts</div>
                <div class="timing-info">60 minutes before the deadline</div>
              </div>
              <input type="checkbox" id="assignment_submission_alert" name="assignment_submission_alert" value="1" <?php echo $settings['assignment_submission_alert'] ? 'checked' : ''; ?>>
            </div>

            <div class="toggle-row" style="margin-bottom:8px;">
              <span>Overdue Notices</span>
              <input type="checkbox" id="overdue_reminders" name="overdue_reminders" value="1" <?php echo $settings['overdue_reminders'] ? 'checked' : ''; ?>>
            </div>

            <button type="submit" name="save_settings" class="save-btn">Save Preferences</button>
          </form>
        </div>
      </div>
    </div>
  </main>

  <!-- Delete Confirmation Modal -->
  <div id="deleteModalOverlay" class="modal-overlay">
    <div id="deleteModal" class="modal">
      <div class="modal-header">
        <h3 class="modal-title">Delete Notification</h3>
        <button type="button" class="close-modal-btn" onclick="closeDeleteModal()">&times;</button>
      </div>
      <div class="modal-body">
        <p style="margin-bottom: 20px; color: var(--text-muted); font-size: 14px;">
          Are you sure you want to delete this notification? This action cannot be undone.
        </p>
        <form id="deleteForm" method="post" style="margin:0;">
          <input type="hidden" name="delete_notif_id" id="deleteNotifId" value="">
          <div style="display: flex; justify-content: flex-end; gap: 12px;">
            <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
            <button type="submit" class="btn btn-danger">Delete</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="notification-toast" id="notificationToast">
    <i class="ph-fill ph-check-circle"></i>
    <span id="notificationMessage">Notification updated</span>
  </div>

  <script>
    function filterNotifs(type, e) {
      document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
      if (e && e.target) {
        e.target.classList.add('active');
      }

      const cards = document.querySelectorAll('.notif-card');
      cards.forEach(card => {
        const cat = card.getAttribute('data-category');
        const read = card.getAttribute('data-read');

        if (type === 'all') {
          card.style.display = 'flex';
        } else if (type === 'unread') {
          card.style.display = (read === 'false') ? 'flex' : 'none';
        } else {
          card.style.display = (cat === type) ? 'flex' : 'none';
        }
      });
    }

    function openDeleteModal(id) {
      document.getElementById('deleteNotifId').value = id;
      document.getElementById('deleteModalOverlay').classList.add('active');
      document.getElementById('deleteModal').classList.add('active');
    }

    function closeDeleteModal() {
      document.getElementById('deleteModalOverlay').classList.remove('active');
      document.getElementById('deleteModal').classList.remove('active');
      document.getElementById('deleteNotifId').value = '';
    }

    document.addEventListener('DOMContentLoaded', () => {
      const url = new URL(window.location.href);
      if (url.searchParams.has('deleted')) {
        showNotification('Notification deleted successfully.', 'success');
        url.searchParams.delete('deleted');
        window.history.replaceState({}, document.title, url.toString());
      } else if (url.searchParams.has('saved')) {
        showNotification('Preferences saved successfully.', 'success');
        url.searchParams.delete('saved');
        window.history.replaceState({}, document.title, url.toString());
      }
    });

    function showNotification(message, type = 'success') {
      const toast = document.getElementById('notificationToast');
      if (!toast) return;
      const msgSpan = document.getElementById('notificationMessage');
      const icon = toast.querySelector('i');
      
      msgSpan.innerText = message;
      toast.classList.remove('success', 'error');
      
      if (type === 'error') {
        toast.classList.add('error');
        icon.className = 'ph-fill ph-warning-circle';
        icon.style.color = '#dc2626';
      } else {
        toast.classList.add('success');
        icon.className = 'ph-fill ph-check-circle';
        icon.style.color = '#16a34a';
      }
      
      toast.classList.add('show');
      clearTimeout(toast.timeoutId);
      toast.timeoutId = setTimeout(() => {
        toast.classList.remove('show');
      }, 4000);
    }

    async function toggleReadStatus(notificationID, isRead) {
      const action = isRead ? 'markAsUnread' : 'markAsRead';

      try {
        const response = await fetch('notifications.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: action, notificationID: notificationID })
        });
        const data = await response.json();

        if (data.success) {
          showNotification(isRead ? 'Notification marked as unread.' : 'Notification marked as read.', 'success');
          setTimeout(() => location.reload(), 800);
        } else {
          throw new Error(data.error || 'Failed to update notification status.');
        }
      } catch (err) {
        showNotification(err.message || 'Could not update notification status', 'error');
      }
    }

    async function handleSavePreferences(event) {
      if (event) event.preventDefault();

      const saveBtn = document.querySelector('.save-btn');
      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerText = 'Saving...';
      }

      const preferences = {
        email_reminders: document.getElementById('email_reminders')?.checked || false,
        assignment_reminders: document.getElementById('assignment_reminders')?.checked || false,
        exam_reminders: document.getElementById('exam_reminders')?.checked || false,
        study_session_reminders: document.getElementById('study_session_reminders')?.checked || false,
        assignment_submission_alert: document.getElementById('assignment_submission_alert')?.checked || false,
        overdue_reminders: document.getElementById('overdue_reminders')?.checked || false
      };

      try {
        const response = await fetch('notifications.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'savePreferences',
            preferences: preferences
          })
        });
        const data = await response.json();

        if (data.success) {
          showNotification('Preferences saved successfully.', 'success');
        } else {
          throw new Error(data.error || 'Failed to save preferences.');
        }
      } catch (err) {
        showNotification(err.message || 'Could not save notification preferences', 'error');
      } finally {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.innerText = 'Save Preferences';
        }
      }
    }
  </script>
</body>
</html>