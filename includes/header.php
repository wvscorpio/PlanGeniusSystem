<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Header - PlanGenius</title>
    <link rel="stylesheet" href="/css/style.css">
</head>

<?php
// Fetch unread count and top 3 notifications for header drop-down (Active Semester Only)
$unreadCount = 0;
$headerNotifs = [];

if (isset($conn, $_SESSION['studentID'])) {
    // 1. Get total unread count
    $countStmt = $conn->prepare('
        SELECT COUNT(*) 
        FROM "Notification" n
        LEFT JOIN "Assignment" a ON LOWER(TRIM(n."referenceType")) = \'assignment\' AND CAST(n."referenceID" AS INTEGER) = a."assignmentID"
        LEFT JOIN "Exam" e ON LOWER(TRIM(n."referenceType")) = \'exam\' AND CAST(n."referenceID" AS INTEGER) = e."examID"
        LEFT JOIN "Schedule" sch ON LOWER(TRIM(n."referenceType")) IN (\'schedule\', \'studysession\') AND CAST(n."referenceID" AS INTEGER) = sch."scheduleID"
        LEFT JOIN "Course" c ON c."courseID" = COALESCE(a."courseID", e."courseID", sch."courseID")
        LEFT JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE n."studentID" = :id 
          AND LOWER(n."notificationStatus") = \'unread\'
          AND s."startDate" <= CURRENT_DATE 
          AND s."endDate" >= CURRENT_DATE
    ');
    $countStmt->execute([':id' => $_SESSION['studentID']]);
    $unreadCount = (int)$countStmt->fetchColumn();

    // 2. Fetch top 3 unread notifications for preview list
    $hStmt = $conn->prepare('
        SELECT n."notificationMessage", n."sentDate" 
        FROM "Notification" n
        LEFT JOIN "Assignment" a ON LOWER(TRIM(n."referenceType")) = \'assignment\' AND CAST(n."referenceID" AS INTEGER) = a."assignmentID"
        LEFT JOIN "Exam" e ON LOWER(TRIM(n."referenceType")) = \'exam\' AND CAST(n."referenceID" AS INTEGER) = e."examID"
        LEFT JOIN "Schedule" sch ON LOWER(TRIM(n."referenceType")) IN (\'schedule\', \'studysession\') AND CAST(n."referenceID" AS INTEGER) = sch."scheduleID"
        LEFT JOIN "Course" c ON c."courseID" = COALESCE(a."courseID", e."courseID", sch."courseID")
        LEFT JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE n."studentID" = :id 
          AND LOWER(n."notificationStatus") = \'unread\'
          AND s."startDate" <= CURRENT_DATE 
          AND s."endDate" >= CURRENT_DATE
        ORDER BY n."sentDate" DESC 
        LIMIT 3
    ');
    $hStmt->execute([':id' => $_SESSION['studentID']]);
    $headerNotifs = $hStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<header class="top-header" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; background: #ffffff; border-bottom: 1px solid #e2e8f0;">
    <div class="header-left">
        <!-- Sidebar Toggle Button -->
        <button class="close-btn" onclick="toggleSidebar()" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #64748b; display: flex; align-items: center; justify-content: center; padding: 4px;">
            <i class="ph ph-x" id="sidebarIcon"></i>
        </button>
    </div>

    <div class="header-right" style="display: flex; align-items: center; gap: 20px;">
        <div class="date-display" style="font-size: 14px; color: #64748b; font-weight: 500;">
            <?php echo date('l, F j, Y'); ?>
        </div>

        <!-- Bell Icon & Relative Dropdown Wrapper -->
        <div style="position: relative; display: inline-block;">
            <button onclick="event.stopPropagation(); document.getElementById('notifDropdown').classList.toggle('show');" 
                    style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; font-size: 20px; cursor: pointer; color: #334155; position: relative;">
                <i class="ph ph-bell"></i>
                <?php if ($unreadCount > 0): ?>
                    <span style="position: absolute; top: -8px; right: -7px; background: #ef4444; color: #ffffff; font-size: 10px; font-weight: 700; border-radius: 50%; padding: 5px 7px; line-height: 1;">
                        <?= $unreadCount ?>
                    </span>
                <?php endif; ?>
            </button>

            <!-- Dropdown Menu -->
            <div id="notifDropdown" class="notif-dropdown">
                <div style="font-weight: 700; padding: 15px 16px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #0f172a; display: flex; justify-content: space-between; align-items: center;">
                    <span>Notifications</span>
                    <span style="font-size: 10px; color: #ef4444; font-weight: 600;"><?= $unreadCount ?> Unread</span>
                </div>

                <div style="max-height: 250px; overflow-y: auto;">
                    <?php if (empty($headerNotifs)): ?>
                        <div style="padding: 16px; font-size: 12px; color: #94a3b8; text-align: center;">No new notifications</div>
                    <?php else: ?>
                        <?php foreach ($headerNotifs as $hn): ?>
                            <div style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: 12px; color: #334155; line-height: 1.4;">
                                <?= htmlspecialchars(substr($hn['notificationMessage'], 0, 50)) ?>...
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <a href="notifications.php" style="display: block; padding: 12px; text-align: center; font-size: 12px; font-weight: 700; color: #3b82f6; text-decoration: none; background: #f8fafc; border-top: 1px solid #e2e8f0;">
                    View All Notifications
                </a>
            </div>
        </div>
    </div>
</header>

<script>
function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        const icon = document.getElementById('sidebarIcon');

        if (sidebar) {
            sidebar.classList.toggle('collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            
            // Save state to local storage
            localStorage.setItem('sidebarCollapsed', isCollapsed ? 'true' : 'false');

            if (icon) {
                icon.className = isCollapsed ? 'ph ph-list' : 'ph ph-x';
            }
        }
    }

    // Restore sidebar state immediately when page loads
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.querySelector('.sidebar');
        const icon = document.getElementById('sidebarIcon');
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';

        if (sidebar && isCollapsed) {
            sidebar.classList.add('collapsed');
            if (icon) {
                icon.className = 'ph ph-list';
            }
        } else if (icon && sidebar) {
            icon.className = sidebar.classList.contains('collapsed') ? 'ph ph-list' : 'ph ph-x';
        }
    });
    
    window.addEventListener('click', function(e) {
        const dropdown = document.getElementById('notifDropdown');
        if (dropdown && dropdown.classList.contains('show')) {
            dropdown.classList.remove('show');
        }
    });
</script>