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

    if ($action === 'markAllRead') {
        $u = $conn->prepare('UPDATE "Notification" SET "notificationStatus" = \'Read\' WHERE "studentID" = :studentID AND "notificationStatus" = \'Unread\'');
        $success = $u->execute([':studentID' => $studentID]);
        echo json_encode(['success' => (bool)$success]);
        exit;
    }

    if ($action === 'deleteNotification') {
        $id = intval($jsonData['notificationID'] ?? 0);
        $u = $conn->prepare('DELETE FROM "Notification" WHERE "notificationID" = :id AND "studentID" = :studentID');
        $success = $u->execute([':id' => $id, ':studentID' => $studentID]);
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
// 3. Helper for Relative Timestamps
// -----------------------------------------------------------------------------
function formatRelativeNotifTime(DateTime $sentDate, DateTime $now): string {
    $diffSeconds = $now->getTimestamp() - $sentDate->getTimestamp();
    if ($diffSeconds < 60) return 'Just now';
    if ($diffSeconds < 3600) {
        $mins = floor($diffSeconds / 60);
        return $mins . 'm ago';
    }
    if ($diffSeconds < 86400) {
        $hrs = floor($diffSeconds / 3600);
        return $hrs . 'h ago';
    }
    $days = floor($diffSeconds / 86400);
    if ($days === 1) return 'Yesterday';
    if ($days < 7) return $days . 'd ago';
    return '';
}

// -----------------------------------------------------------------------------
// 4. Fetch Live Database Notifications (Active Semester Only)
// -----------------------------------------------------------------------------
$notifications = [];
$courseFilterOptions = [];

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
        $refType = strtolower(trim($r['referenceType'] ?? ''));

        $sentDate = new DateTime($r['sentDate'], new DateTimeZone('UTC'));
        $sentDate->setTimezone($tz);

        $diffDays = (int)$now->diff($sentDate)->format('%r%a');
        $relTime = formatRelativeNotifTime($sentDate, $now);

        $courseTag = 'GENERAL';
        if (preg_match('/\[(.*?)\]/', $msg, $matches)) {
            $courseTag = trim($matches[1]);
        }
        if ($courseTag !== '' && !in_array($courseTag, $courseFilterOptions, true)) {
            $courseFilterOptions[] = $courseTag;
        }

        $cleanMsg = trim(preg_replace('/\[.*?\]\s*/', '', $msg));

        // Determine underlying academic entity type independently of overdue status
        $isOverdue = (stripos($msg, 'OVERDUE') !== false);
        $isStudy = (
            $refType === 'studysession' ||
            $refType === 'schedule' ||
            stripos($msg, 'Study') !== false ||
            stripos($msg, 'Session') !== false
        );
        $isExam = (
            $refType === 'exam' ||
            stripos($msg, 'Exam') !== false ||
            stripos($msg, 'Quiz') !== false ||
            stripos($msg, 'Test') !== false ||
            stripos($msg, 'Midterm') !== false ||
            stripos($msg, 'Final') !== false
        );

        if ($isStudy) {
            $category = 'study_session';
            $icon = 'ph-book-open';
            $actionUrl = 'schedule.php';
            $actionLabel = 'View Schedule';
        } elseif ($isExam) {
            $category = 'exam';
            $icon = 'ph-exam';
            $actionUrl = 'exam.php';
            $actionLabel = 'Open Exams';
        } else {
            $category = 'assignment';
            $icon = 'ph-file-text';
            $actionUrl = $isOverdue ? 'assignments.php?filter=Past+Due' : 'assignments.php';
            $actionLabel = 'Open Assignments';
        }

        // Determine urgency color & semantic status label
        if ($isOverdue) {
            $color = '#ef4444';
            $icon = 'ph-warning-octagon';
            $statusBadgeLabel = 'Overdue Notice';
        } elseif (stripos($msg, 'TODAY') !== false || stripos($msg, '60 minutes') !== false || stripos($msg, '30 minutes') !== false) {
            $color = '#ef4444';
            $icon = 'ph-clock';
            $statusBadgeLabel = 'Immediate Alert';
        } elseif ($category === 'study_session') {
            $color = '#8b5cf6';
            $statusBadgeLabel = 'Study Session';
        } elseif ($category === 'exam') {
            if (abs($diffDays) <= 1) $color = '#ef4444';
            elseif (abs($diffDays) <= 3) $color = '#f97316';
            else $color = '#3b82f6';
            $statusBadgeLabel = 'Exam Countdown';
        } else {
            if (abs($diffDays) <= 1) $color = '#ef4444';
            elseif (abs($diffDays) <= 2) $color = '#f97316';
            elseif (abs($diffDays) <= 3) $color = '#eab308';
            else $color = '#3b82f6';
            $statusBadgeLabel = 'Assignment Reminder';
        }

        $formattedDate = $sentDate->format('j M Y, g:i A');
        if ($relTime !== '') {
            $formattedDate .= ' · ' . $relTime;
        }

        $notifications[] = [
            'id'          => $r['notificationID'],
            'course'      => $courseTag,
            'message'     => $cleanMsg,
            'sentDate'    => $formattedDate,
            'read'        => (strtolower($r['notificationStatus']) === 'read'),
            'color'       => $color,
            'category'    => $category,
            'isOverdue'   => $isOverdue,
            'statusLabel' => $statusBadgeLabel,
            'icon'        => $icon,
            'actionUrl'   => $actionUrl,
            'actionLabel' => $actionLabel
        ];
    }
} catch (Exception $e) {}

sort($courseFilterOptions);

$total = count($notifications);
$unread = count(array_filter($notifications, fn($n) => !$n['read']));
$overdueTotal = count(array_filter($notifications, fn($n) => $n['isOverdue']));
$assignmentTotal = count(array_filter($notifications, fn($n) => $n['category'] === 'assignment'));
$examTotal = count(array_filter($notifications, fn($n) => $n['category'] === 'exam'));
$studyTotal = count(array_filter($notifications, fn($n) => $n['category'] === 'study_session'));
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

    <div class="dashboard-container">
      <div class="notif-header-bar">
        <div>
          <h1 class="page-title">Notification Management</h1>
          <p class="page-subtitle">Automated schedule triggers, countdown alerts, and email preferences</p>
        </div>
      </div>

      <!-- 4-Card Interactive KPI Strip -->
      <div class="notif-kpi-grid">
        <div class="notif-kpi-card kpi-indigo clickable" onclick="quickFilterFromKpi('all')">
          <div class="notif-kpi-top">
            <span class="notif-kpi-label">Total Alerts</span>
            <div class="notif-kpi-icon" style="background:#eef2ff; color:#4f46e5;">
              <i class="ph-fill ph-bell"></i>
            </div>
          </div>
          <div class="notif-kpi-value" id="kpiTotalCount"><?php echo $total; ?></div>
          <div class="notif-kpi-sub">Active semester feed</div>
        </div>

        <div class="notif-kpi-card kpi-amber clickable" onclick="quickFilterFromKpi('unread')">
          <div class="notif-kpi-top">
            <span class="notif-kpi-label">Unread Messages</span>
            <div class="notif-kpi-icon" style="background:#fef3c7; color:#d97706;">
              <i class="ph-fill ph-envelope-simple"></i>
            </div>
          </div>
          <div class="notif-kpi-value" id="kpiUnreadCount" style="color:#d97706;"><?php echo $unread; ?></div>
          <div class="notif-kpi-sub">Click to view unread</div>
        </div>

        <div class="notif-kpi-card <?php echo $overdueTotal > 0 ? 'kpi-red' : 'kpi-green'; ?> clickable" onclick="quickFilterFromKpi('overdue')">
          <div class="notif-kpi-top">
            <span class="notif-kpi-label" style="<?php echo $overdueTotal > 0 ? 'color:#991b1b;' : ''; ?>">Overdue Alerts</span>
            <div class="notif-kpi-icon" style="background:<?php echo $overdueTotal > 0 ? '#fee2e2' : '#ecfdf5'; ?>; color:<?php echo $overdueTotal > 0 ? '#dc2626' : '#059669'; ?>;">
              <i class="ph-fill ph-warning-octagon"></i>
            </div>
          </div>
          <div class="notif-kpi-value" id="kpiOverdueCount" style="<?php echo $overdueTotal > 0 ? 'color:#dc2626;' : ''; ?>"><?php echo $overdueTotal; ?></div>
          <div class="notif-kpi-sub"><?php echo $overdueTotal > 0 ? 'Requires immediate attention' : 'No overdue alerts'; ?></div>
        </div>

        <div class="notif-kpi-card <?php echo $settings['email_reminders'] ? 'kpi-green' : 'kpi-slate'; ?>" id="kpiEmailCard">
          <div class="notif-kpi-top">
            <span class="notif-kpi-label">Email Delivery</span>
            <div class="notif-kpi-icon" id="kpiEmailIcon" style="background:<?php echo $settings['email_reminders'] ? '#ecfdf5' : '#f1f5f9'; ?>; color:<?php echo $settings['email_reminders'] ? '#059669' : '#64748b'; ?>;">
              <i class="ph-fill ph-paper-plane-tilt"></i>
            </div>
          </div>
          <div class="notif-kpi-value" id="kpiEmailStatus" style="color:<?php echo $settings['email_reminders'] ? '#16a34a' : '#64748b'; ?>;">
            <?php echo $settings['email_reminders'] ? 'Active' : 'Disabled'; ?>
          </div>
          <div class="notif-kpi-sub" id="kpiEmailSub">
            <?php echo $settings['email_reminders'] ? 'Automated email triggers on' : 'In-app alerts only'; ?>
          </div>
        </div>
      </div>

      <div class="main-grid">
        <!-- Left Panel: Notification Feed -->
        <div class="panel" id="notifFeedPanel">
          <div class="notif-feed-header">
            <h3 style="margin:0; font-size:16px; font-weight:700; color:#0f172a;">Notification Feed</h3>
            <div class="notif-bulk-actions">
              <button type="button" id="markAllReadBtn" class="btn-mark" style="<?php echo $unread > 0 ? '' : 'display:none;'; ?>" onclick="markAllAsReadAsync()">
                <i class="ph-bold ph-checks"></i> Mark All as Read
              </button>
            </div>
          </div>

          <!-- Search & Course Filter Bar -->
          <div class="notif-search-row">
            <div class="notif-search-input-wrap">
              <i class="ph ph-magnifying-glass"></i>
              <input type="text" id="notifSearchInput" placeholder="Search alerts by title, keyword, or course..." oninput="applyNotifFilters()">
            </div>

            <select id="notifCourseFilter" class="notif-course-select" onchange="applyNotifFilters()">
              <option value="ALL">All Courses</option>
              <?php foreach ($courseFilterOptions as $cCode): ?>
                <option value="<?php echo htmlspecialchars($cCode); ?>"><?php echo htmlspecialchars($cCode); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Category Filter Pills with Live Counts -->
          <div class="filter-pills" id="notifFilterPills">
            <button type="button" class="filter-btn active" data-filter="all" onclick="setCategoryPill('all', this)">
              All <span class="pill-count" id="count-all"><?php echo $total; ?></span>
            </button>
            <button type="button" class="filter-btn" data-filter="unread" onclick="setCategoryPill('unread', this)">
              Unread <span class="pill-count" id="count-unread"><?php echo $unread; ?></span>
            </button>
            <button type="button" class="filter-btn" data-filter="assignment" onclick="setCategoryPill('assignment', this)">
              Assignments <span class="pill-count" id="count-assignment"><?php echo $assignmentTotal; ?></span>
            </button>
            <button type="button" class="filter-btn" data-filter="exam" onclick="setCategoryPill('exam', this)">
              Exams <span class="pill-count" id="count-exam"><?php echo $examTotal; ?></span>
            </button>
            <button type="button" class="filter-btn" data-filter="study_session" onclick="setCategoryPill('study_session', this)">
              Study Sessions <span class="pill-count" id="count-study_session"><?php echo $studyTotal; ?></span>
            </button>
            <button type="button" class="filter-btn" data-filter="overdue" onclick="setCategoryPill('overdue', this)">
              Overdue <span class="pill-count" id="count-overdue"><?php echo $overdueTotal; ?></span>
            </button>
          </div>

          <div id="notifList">
            <?php if (empty($notifications)): ?>
              <div id="emptyNotifState" style="text-align:center; padding:44px 20px; color:#94a3b8;">
                <i class="ph ph-bell-slash" style="font-size:36px; display:block; margin-bottom:8px;"></i>
                No notifications available for this semester.
              </div>
            <?php else: ?>
              <?php foreach ($notifications as $n): ?>
                <div class="notif-card <?php echo !$n['read'] ? 'unread' : ''; ?>" 
                     id="notif-card-<?php echo $n['id']; ?>"
                     data-category="<?php echo $n['category']; ?>" 
                     data-overdue="<?php echo $n['isOverdue'] ? 'true' : 'false'; ?>"
                     data-course="<?php echo htmlspecialchars($n['course']); ?>"
                     data-read="<?php echo $n['read'] ? 'true' : 'false'; ?>"
                     style="--urgency-color: <?php echo $n['color']; ?>;">
                  <div class="notif-icon" style="background: <?php echo $n['color']; ?>15; color: <?php echo $n['color']; ?>;">
                    <i class="ph <?php echo $n['icon']; ?>"></i>
                  </div>
                  <div class="notif-content">
                    <div class="notif-top">
                      <div class="notif-top-left">
                        <span class="unread-dot-badge" title="Unread"></span>
                        <span class="course-badge"><?php echo htmlspecialchars($n['course']); ?></span>
                      </div>
                      <span class="notif-time-label"><?php echo htmlspecialchars($n['sentDate']); ?></span>
                    </div>
                    <div class="notif-desc"><?php echo htmlspecialchars($n['message']); ?></div>
                    <div class="notif-meta">
                      <span style="color:<?php echo $n['color']; ?>; font-weight:700;">● <?php echo htmlspecialchars($n['statusLabel']); ?></span>
                      <div class="notif-actions-group">
                        <a href="<?php echo htmlspecialchars($n['actionUrl']); ?>" class="btn-notif-link">
                          <?php echo htmlspecialchars($n['actionLabel']); ?> <i class="ph-bold ph-arrow-up-right"></i>
                        </a>
                        <button type="button" class="btn-mark" id="toggle-btn-<?php echo $n['id']; ?>" onclick="toggleReadStatus(<?php echo $n['id']; ?>)">
                          <?php echo $n['read'] ? 'Mark as Unread' : 'Mark as Read'; ?>
                        </button>
                        <button type="button" class="btn-delete" onclick="openDeleteModal(<?php echo $n['id']; ?>)">Delete</button>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
              <div id="noFilterMatchState" style="display:none; text-align:center; padding:40px 20px; color:#94a3b8;">
                No notifications match your current filter or search.
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Right Panel: Notification Settings -->
        <div class="panel notif-settings-panel">
          <h3 style="margin-top:0; font-size:16px; font-weight:700; color:#0f172a; margin-bottom:14px;">Notification Settings</h3>
          <p class="notif-settings-subtitle">Customize automated reminders and email alert delivery</p>

          <form id="settingsForm" method="post" onsubmit="handleSavePreferences(event)">
            <div class="toggle-row">
              <div>
                <div class="toggle-label">Email Alerts</div>
                <div class="timing-info">Send scheduled alerts to your student email</div>
              </div>
              <label class="notif-switch">
                <input type="checkbox" id="email_reminders" name="email_reminders" value="1" <?php echo $settings['email_reminders'] ? 'checked' : ''; ?>>
                <span class="notif-slider"></span>
              </label>
            </div>

            <div class="toggle-row">
              <div>
                <div class="toggle-label">Assignment Reminders</div>
                <div class="timing-info">3, 2, and 1 day(s) before the due date</div>
              </div>
              <label class="notif-switch">
                <input type="checkbox" id="assignment_reminders" name="assignment_reminders" value="1" <?php echo $settings['assignment_reminders'] ? 'checked' : ''; ?>>
                <span class="notif-slider"></span>
              </label>
            </div>

            <div class="toggle-row">
              <div>
                <div class="toggle-label">Exam Reminders</div>
                <div class="timing-info">6, 3, 2, and 1 day(s) before the exam</div>
              </div>
              <label class="notif-switch">
                <input type="checkbox" id="exam_reminders" name="exam_reminders" value="1" <?php echo $settings['exam_reminders'] ? 'checked' : ''; ?>>
                <span class="notif-slider"></span>
              </label>
            </div>

            <div class="toggle-row">
              <div>
                <div class="toggle-label">Study Session Alerts</div>
                <div class="timing-info">30 minutes before the scheduled session</div>
              </div>
              <label class="notif-switch">
                <input type="checkbox" id="study_session_reminders" name="study_session_reminders" value="1" <?php echo $settings['study_session_reminders'] ? 'checked' : ''; ?>>
                <span class="notif-slider"></span>
              </label>
            </div>

            <div class="toggle-row">
              <div>
                <div class="toggle-label">Assignment Submission Alerts</div>
                <div class="timing-info">60 minutes before the deadline</div>
              </div>
              <label class="notif-switch">
                <input type="checkbox" id="assignment_submission_alert" name="assignment_submission_alert" value="1" <?php echo $settings['assignment_submission_alert'] ? 'checked' : ''; ?>>
                <span class="notif-slider"></span>
              </label>
            </div>

            <div class="toggle-row" style="margin-bottom:8px; border-bottom:none;">
              <div>
                <div class="toggle-label">Overdue Notices</div>
                <div class="timing-info">Daily alert when a deadline or exam passes</div>
              </div>
              <label class="notif-switch">
                <input type="checkbox" id="overdue_reminders" name="overdue_reminders" value="1" <?php echo $settings['overdue_reminders'] ? 'checked' : ''; ?>>
                <span class="notif-slider"></span>
              </label>
            </div>

            <button type="submit" name="save_settings" class="save-btn">Save Preferences</button>
          </form>
        </div>
      </div>
    </div>
  </main>

  <!-- Delete Confirmation Modal -->
  <div id="deleteModalOverlay" class="modal-overlay" onclick="closeDeleteModal()">
    <div id="deleteModal" class="modal" onclick="event.stopPropagation()">
      <div class="modal-header">
        <h3 class="modal-title">Delete Notification</h3>
        <button type="button" class="close-modal-btn" onclick="closeDeleteModal()">&times;</button>
      </div>
      <div class="modal-body">
        <p style="margin-bottom: 20px; color: var(--text-muted); font-size: 14px;">
          Are you sure you want to delete this notification? This action cannot be undone.
        </p>
        <form id="deleteForm" method="post" style="margin:0;" onsubmit="handleDeleteAsync(event)">
          <input type="hidden" name="delete_notif_id" id="deleteNotifId" value="">
          <div style="display: flex; justify-content: flex-end; gap: 12px;">
            <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
            <button type="submit" id="confirmDeleteBtn" class="btn btn-danger">Delete</button>
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
    let activePillFilter = 'all';

    function setCategoryPill(type, btn) {
      activePillFilter = type;
      document.querySelectorAll('#notifFilterPills .filter-btn').forEach(b => b.classList.remove('active'));
      if (btn) btn.classList.add('active');
      applyNotifFilters();
    }

    function quickFilterFromKpi(type) {
      const targetBtn = document.querySelector(`#notifFilterPills .filter-btn[data-filter="${type}"]`);
      setCategoryPill(type, targetBtn);
      const panel = document.getElementById('notifFeedPanel');
      if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function applyNotifFilters() {
      const searchInput = document.getElementById('notifSearchInput');
      const courseSelect = document.getElementById('notifCourseFilter');
      const term = (searchInput ? searchInput.value : '').trim().toLowerCase();
      const selectedCourse = courseSelect ? courseSelect.value : 'ALL';

      const cards = document.querySelectorAll('.notif-card');
      let visibleCount = 0;

      cards.forEach(card => {
        const cat = card.getAttribute('data-category');
        const isOverdue = card.getAttribute('data-overdue') === 'true';
        const read = card.getAttribute('data-read');
        const course = card.getAttribute('data-course');
        const text = card.innerText.toLowerCase();

        let matchesPill = false;
        if (activePillFilter === 'all') {
          matchesPill = true;
        } else if (activePillFilter === 'unread') {
          matchesPill = (read === 'false');
        } else if (activePillFilter === 'overdue') {
          matchesPill = isOverdue;
        } else {
          matchesPill = (cat === activePillFilter);
        }

        const matchesCourse = (selectedCourse === 'ALL' || course === selectedCourse);
        const matchesSearch = (term === '' || text.includes(term));

        if (matchesPill && matchesCourse && matchesSearch) {
          card.style.display = 'flex';
          visibleCount++;
        } else {
          card.style.display = 'none';
        }
      });

      const noMatchEl = document.getElementById('noFilterMatchState');
      if (noMatchEl) {
        noMatchEl.style.display = (cards.length > 0 && visibleCount === 0) ? 'block' : 'none';
      }
    }

    function recalculateCounters() {
      const cards = Array.from(document.querySelectorAll('.notif-card'));
      const total = cards.length;
      const unread = cards.filter(c => c.getAttribute('data-read') === 'false').length;
      const overdue = cards.filter(c => c.getAttribute('data-overdue') === 'true').length;
      const assignment = cards.filter(c => c.getAttribute('data-category') === 'assignment').length;
      const exam = cards.filter(c => c.getAttribute('data-category') === 'exam').length;
      const study = cards.filter(c => c.getAttribute('data-category') === 'study_session').length;

      const setTxt = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.innerText = val;
      };

      setTxt('kpiTotalCount', total);
      setTxt('kpiUnreadCount', unread);
      setTxt('kpiOverdueCount', overdue);

      setTxt('count-all', total);
      setTxt('count-unread', unread);
      setTxt('count-assignment', assignment);
      setTxt('count-exam', exam);
      setTxt('count-study_session', study);
      setTxt('count-overdue', overdue);

      const markAllBtn = document.getElementById('markAllReadBtn');
      if (markAllBtn) {
        markAllBtn.style.display = unread > 0 ? 'inline-flex' : 'none';
      }

      if (total === 0) {
        const list = document.getElementById('notifList');
        if (list) {
          list.innerHTML = `
            <div style="text-align:center; padding:44px 20px; color:#94a3b8;">
              <i class="ph ph-bell-slash" style="font-size:36px; display:block; margin-bottom:8px;"></i>
              No notifications available for this semester.
            </div>
          `;
        }
      }
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
      }, 3500);
    }

    async function toggleReadStatus(notificationID) {
      const card = document.getElementById(`notif-card-${notificationID}`);
      const btn = document.getElementById(`toggle-btn-${notificationID}`);
      if (!card) return;

      const currentlyRead = card.getAttribute('data-read') === 'true';
      const action = currentlyRead ? 'markAsUnread' : 'markAsRead';

      try {
        const response = await fetch('notifications.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: action, notificationID: notificationID })
        });
        const data = await response.json();

        if (data.success) {
          const nextRead = !currentlyRead;
          card.setAttribute('data-read', nextRead ? 'true' : 'false');
          card.classList.toggle('unread', !nextRead);
          if (btn) btn.innerText = nextRead ? 'Mark as Unread' : 'Mark as Read';

          recalculateCounters();
          applyNotifFilters();
          showNotification(nextRead ? 'Notification marked as read.' : 'Notification marked as unread.', 'success');
        } else {
          throw new Error(data.error || 'Failed to update notification status.');
        }
      } catch (err) {
        showNotification(err.message || 'Could not update notification status', 'error');
      }
    }

    async function markAllAsReadAsync() {
      const btn = document.getElementById('markAllReadBtn');
      if (btn) btn.disabled = true;

      try {
        const response = await fetch('notifications.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'markAllRead' })
        });
        const data = await response.json();

        if (data.success) {
          document.querySelectorAll('.notif-card').forEach(card => {
            card.setAttribute('data-read', 'true');
            card.classList.remove('unread');
            const id = card.id.replace('notif-card-', '');
            const toggleBtn = document.getElementById(`toggle-btn-${id}`);
            if (toggleBtn) toggleBtn.innerText = 'Mark as Unread';
          });

          recalculateCounters();
          applyNotifFilters();
          showNotification('All notifications marked as read.', 'success');
        } else {
          throw new Error('Failed to mark all as read.');
        }
      } catch (err) {
        showNotification(err.message || 'Could not mark all notifications as read', 'error');
      } finally {
        if (btn) btn.disabled = false;
      }
    }

    async function handleDeleteAsync(event) {
      if (event) event.preventDefault();
      const id = parseInt(document.getElementById('deleteNotifId').value, 10);
      if (!id) return;

      const confirmBtn = document.getElementById('confirmDeleteBtn');
      if (confirmBtn) confirmBtn.disabled = true;

      try {
        const response = await fetch('notifications.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'deleteNotification', notificationID: id })
        });
        const data = await response.json();

        if (data.success) {
          const card = document.getElementById(`notif-card-${id}`);
          if (card) card.remove();
          closeDeleteModal();
          recalculateCounters();
          applyNotifFilters();
          showNotification('Notification deleted successfully.', 'success');
        } else {
          throw new Error('Failed to delete notification.');
        }
      } catch (err) {
        showNotification(err.message || 'Could not delete notification', 'error');
      } finally {
        if (confirmBtn) confirmBtn.disabled = false;
      }
    }

    async function handleSavePreferences(event) {
      if (event) event.preventDefault();

      const saveBtn = document.querySelector('.save-btn');
      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerText = 'Saving...';
      }

      const emailActive = document.getElementById('email_reminders')?.checked || false;

      const preferences = {
        email_reminders: emailActive,
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
          // Live update top Email Delivery KPI card
          const emailCard = document.getElementById('kpiEmailCard');
          const emailIcon = document.getElementById('kpiEmailIcon');
          const emailStatus = document.getElementById('kpiEmailStatus');
          const emailSub = document.getElementById('kpiEmailSub');

          if (emailCard && emailIcon && emailStatus && emailSub) {
            emailCard.classList.toggle('kpi-green', emailActive);
            emailCard.classList.toggle('kpi-slate', !emailActive);
            emailIcon.style.background = emailActive ? '#ecfdf5' : '#f1f5f9';
            emailIcon.style.color = emailActive ? '#059669' : '#64748b';
            emailStatus.style.color = emailActive ? '#16a34a' : '#64748b';
            emailStatus.innerText = emailActive ? 'Active' : 'Disabled';
            emailSub.innerText = emailActive ? 'Automated email triggers on' : 'In-app alerts only';
          }

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