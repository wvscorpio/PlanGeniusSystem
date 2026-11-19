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
            <div class="avatar"><i class="ph ph-user"></i></div>
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