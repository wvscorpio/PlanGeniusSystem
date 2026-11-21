<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = isset($current_page) ? $current_page : '';
$userName = trim(($_SESSION['firstName'] ?? '') . ' ' . ($_SESSION['lastName'] ?? ''));
$userEmail = $_SESSION['email'] ?? 'guest@example.com';
if ($userName === '') {
    $userName = 'Guest User';
}

$hasSemesterPlan = false;
if (isset($_SESSION['studentID'])) {
    global $conn;
    if (!isset($conn) || $conn === null) {
        require_once __DIR__ . '/../dbConn.php'; 
    }

    $semCheckStmt = $conn->prepare('SELECT 1 FROM "SemesterPlan" WHERE "studentID" = :studentID LIMIT 1');
    $semCheckStmt->execute([':studentID' => $_SESSION['studentID']]);
    if ($semCheckStmt->fetch()) {
        $hasSemesterPlan = true;
    }
}

$sidebarAvatarThemes = [
    'indigo'  => 'linear-gradient(135deg, #6366f1 0%, #4f46e5 100%)',
    'violet'  => 'linear-gradient(135deg, #9333ea 0%, #7c3aed 100%)',
    'blue'    => 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)',
    'emerald' => 'linear-gradient(135deg, #10b981 0%, #047857 100%)',
    'amber'   => 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)',
    'rose'    => 'linear-gradient(135deg, #f43f5e 0%, #be123c 100%)',
    'cyan'    => 'linear-gradient(135deg, #06b6d4 0%, #0e7490 100%)',
    'slate'   => 'linear-gradient(135deg, #475569 0%, #1e293b 100%)',
];

// Load from DB once if not yet cached in the current login session
if (!isset($_SESSION['avatarColor']) && isset($_SESSION['studentID']) && isset($conn)) {
    try {
        $sidebarAvStmt = $conn->prepare('SELECT "avatarColor" FROM "Student" WHERE "studentID" = :studentID LIMIT 1');
        $sidebarAvStmt->execute([':studentID' => $_SESSION['studentID']]);
        $sidebarSavedColor = $sidebarAvStmt->fetchColumn();
        $_SESSION['avatarColor'] = ($sidebarSavedColor && isset($sidebarAvatarThemes[$sidebarSavedColor])) ? $sidebarSavedColor : 'indigo';
    } catch (PDOException $e) {
        $_SESSION['avatarColor'] = 'indigo';
    }
}

$sidebarActiveKey = $_SESSION['avatarColor'] ?? 'indigo';
$sidebarActiveGradient = $sidebarAvatarThemes[$sidebarActiveKey] ?? $sidebarAvatarThemes['indigo'];
$sidebarAvatarStyle = "background: {$sidebarActiveGradient}; color: #ffffff;";
?>
<aside class="sidebar">
    <!-- Sidebar Logo Header -->
    <a href="dashboard.php" class="logo-container">
        <div class="logo-icon-box">
            <i class="ph ph-books"></i>
        </div>
        <div class="logo-text">
            <span class="brand-title">Plan<span>Genius</span></span>
            <span class="brand-subtitle">Student Planner</span>
        </div>
    </a>
    
    <nav class="nav-menu">
        <?php if (!$hasSemesterPlan): ?>
            <a href="#" class="nav-item disabled-link" onclick="showNotification('Please create your Semester Plan first to access other features.', 'error'); return false;">
                <i class="ph ph-squares-four"></i>
                <span class="nav-text">Dashboard</span>
            </a>
        <?php else: ?>
            <a href="dashboard.php" class="nav-item <?= $current_page === 'dashboard' ? 'active' : '' ?>">
                <i class="ph ph-squares-four"></i>
                <span class="nav-text">Dashboard</span>
            </a>
        <?php endif; ?>

        <a href="academic_structure.php" class="nav-item <?= $current_page === 'academic_structure' ? 'active' : '' ?>">
            <i class="ph ph-book-open"></i>
            <span class="nav-text">Academic Structure</span>
        </a>

        <?php if (!$hasSemesterPlan): ?>
            <a href="#" class="nav-item disabled-link" onclick="showNotification('Please create your Semester Plan first to access other features.', 'error'); return false;">
                <i class="ph ph-file-text"></i>
                <span class="nav-text">Assignments</span>
            </a>
        <?php else: ?>
            <a href="assignments.php" class="nav-item <?= $current_page === 'assignments' ? 'active' : '' ?>">
                <i class="ph ph-file-text"></i>
                <span class="nav-text">Assignments</span>
            </a>
        <?php endif; ?>

        <?php if (!$hasSemesterPlan): ?>
            <a href="#" class="nav-item disabled-link" onclick="showNotification('Please create your Semester Plan first to access other features.', 'error'); return false;">
                <i class="ph ph-graduation-cap"></i>
                <span class="nav-text">Exams</span>
            </a>
        <?php else: ?>
            <a href="exam.php" class="nav-item <?= $current_page === 'exams' ? 'active' : '' ?>">
                <i class="ph ph-graduation-cap"></i>
                <span class="nav-text">Exams</span>
            </a>
        <?php endif; ?>

        <?php if (!$hasSemesterPlan): ?>
            <a href="#" class="nav-item disabled-link" onclick="showNotification('Please create your Semester Plan first to access other features.', 'error'); return false;">
                <i class="ph ph-calendar-blank"></i>
                <span class="nav-text">Schedule</span>
            </a>
        <?php else: ?>
            <a href="schedule.php" class="nav-item <?= $current_page === 'schedule' ? 'active' : '' ?>">
                <i class="ph ph-calendar-blank"></i>
                <span class="nav-text">Schedule</span>
            </a>
        <?php endif; ?>

        <?php if (!$hasSemesterPlan): ?>
            <a href="#" class="nav-item disabled-link" onclick="showNotification('Please create your Semester Plan first to access other features.', 'error'); return false;">
                <i class="ph ph-chart-bar"></i>
                <span class="nav-text">Analytics</span>
            </a>
        <?php else: ?>
            <a href="analytics.php" class="nav-item <?= $current_page === 'analytics' ? 'active' : '' ?>">
                <i class="ph ph-chart-bar"></i>
                <span class="nav-text">Analytics</span>
            </a>
        <?php endif; ?>

        <?php if (!$hasSemesterPlan): ?>
            <a href="#" class="nav-item disabled-link" onclick="showNotification('Please create your Semester Plan first to access other features.', 'error'); return false;">
                <i class="ph ph-bell"></i>
                <span class="nav-text">Notifications</span>
            </a>
        <?php else: ?>
            <a href="notifications.php" class="nav-item <?= $current_page === 'notifications' ? 'active' : '' ?>">
                <i class="ph ph-bell"></i>
                <span class="nav-text">Notifications</span>
            </a>
        <?php endif; ?>

        <a href="profile.php" class="nav-item <?= $current_page === 'profile' ? 'active' : '' ?>">
            <i class="ph ph-user"></i>
            <span class="nav-text">Profile</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="user-profile">
            <div class="avatar" style="<?= htmlspecialchars($sidebarAvatarStyle) ?>"><i class="ph ph-user"></i></div>
            <div class="user-info">
                <span class="user-name"><?= htmlspecialchars($userName) ?></span>
                <span class="user-email"><?= htmlspecialchars($userEmail) ?></span>
            </div>
        </div>
        <a href="logout.php" class="logout-btn">
            <i class="ph ph-sign-out"></i>
            <span class="nav-text">Logout</span>
        </a>
    </div>
</aside>