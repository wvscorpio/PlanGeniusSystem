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
?>
<aside class="sidebar">
    <div class="logo-container">
        <div class="logo-title">PlanGenius</div>
        <div class="logo-subtitle">Student Planner</div>
    </div>
    
    <nav class="nav-menu">
        <a href="index.php" class="nav-item <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>"><i class="ph ph-squares-four"></i> Dashboard</a>
        <a href="academic_structure.php" class="nav-item <?php echo $current_page === 'academic_structure' ? 'active' : ''; ?>"><i class="ph ph-book-open"></i> Academic Structure</a>
        <a href="assignments.php" class="nav-item <?php echo $current_page === 'assignments' ? 'active' : ''; ?>"><i class="ph ph-file-text"></i> Assignments</a>
        <a href="exam.php" class="nav-item <?php echo $current_page === 'exams' ? 'active' : ''; ?>"><i class="ph ph-graduation-cap"></i> Exams</a>
        <a href="schedule.php" class="nav-item <?php echo $current_page === 'schedule' ? 'active' : ''; ?>"><i class="ph ph-calendar-blank"></i> Schedule</a>
        <a href="analytics.php" class="nav-item <?php echo $current_page === 'analytics' ? 'active' : ''; ?>"><i class="ph ph-chart-bar"></i> Analytics</a>
        <a href="notifications.php" class="nav-item <?php echo $current_page === 'notifications' ? 'active' : ''; ?>"><i class="ph ph-bell"></i> Notifications</a>
        <a href="profile.php" class="nav-item <?php echo $current_page === 'profile' ? 'active' : ''; ?>"><i class="ph ph-user"></i> Profile</a>
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
            <i class="ph ph-sign-out"></i> Logout
        </a>
    </div>
</aside>
