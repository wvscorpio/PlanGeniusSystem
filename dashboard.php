<?php
include 'dbConn.php';
session_start();

$studentID = $_SESSION['studentID'] ?? null;
if (!$studentID) {
    header('Location: login.php');
    exit;
}

// 1. DYNAMIC DATABASE CALCULATION FOR STATS
$totalAssignments = 0;
$upcomingAssignments = 0;
$completedAssignments = 0;
$pastDueAssignments = 0;

$totalExams = 0;
$upcomingExams = 0;
$urgentExams = 0;
$completedExams = 0;

$studyHours = 0;

// A. FETCH ASSIGNMENT STATS (Includes Tests & Quizzes)
try {
    $assQuery = $conn->prepare('
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN a."taskStatus" = \'Completed\' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN a."taskStatus" != \'Completed\' AND a."dueDate" >= CURRENT_DATE THEN 1 ELSE 0 END) as upcoming,
            SUM(CASE WHEN a."taskStatus" != \'Completed\' AND a."dueDate" < CURRENT_DATE THEN 1 ELSE 0 END) as past_due
        FROM "Assignment" a
        JOIN "Course" c ON a."courseID" = c."courseID"
        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE s."studentID" = :studentID
            AND s."startDate" <= CURRENT_DATE AND s."endDate" >= CURRENT_DATE
    ');
    $assQuery->execute([':studentID' => $studentID]);
    $assStats = $assQuery->fetch(PDO::FETCH_ASSOC);

    if ($assStats) {
        $totalAssignments = (int)$assStats['total'];
        $completedAssignments = (int)$assStats['completed'];
        $upcomingAssignments = (int)$assStats['upcoming'];
        $pastDueAssignments = (int)($assStats['past_due'] ?? 0);
    }
} catch (PDOException $e) {}

// B. FETCH EXAM STATS (Connected to "Exam" Table)
try {
    $examQuery = $conn->prepare('
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN e."examStatus" = \'Completed\' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN e."examStatus" != \'Completed\' AND e."examDate" >= CURRENT_DATE THEN 1 ELSE 0 END) as upcoming,
            SUM(CASE WHEN e."examStatus" != \'Completed\' AND e."examDate" >= CURRENT_DATE AND e."examDate" <= CURRENT_DATE + INTERVAL \'7 days\' THEN 1 ELSE 0 END) as urgent
        FROM "Exam" e
        JOIN "Course" c ON e."courseID" = c."courseID"
        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE s."studentID" = :studentID
          AND s."startDate" <= CURRENT_DATE AND s."endDate" >= CURRENT_DATE
    ');
    $examQuery->execute([':studentID' => $studentID]);
    $examStats = $examQuery->fetch(PDO::FETCH_ASSOC);

    if ($examStats) {
        $totalExams = (int)$examStats['total'];
        $completedExams = (int)$examStats['completed'];
        $upcomingExams = (int)($examStats['upcoming'] ?? 0);
        $urgentExams = (int)($examStats['urgent'] ?? 0);
    }
} catch (PDOException $e) {}

// C. DYNAMICALLY CALCULATE ACTUAL TOTAL STUDY HOURS
try {
    // Count only completed study tasks/sessions for the student
    $studyTaskQuery = $conn->prepare('
        SELECT COUNT(*)
        FROM public."StudyTask" st
        JOIN "Exam" e ON st."examID" = e."examID"
        JOIN "Course" c ON e."courseID" = c."courseID"
        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE s."studentID" = :studentID
          AND s."startDate" <= CURRENT_DATE AND s."endDate" >= CURRENT_DATE
          AND (st."isCompleted" = true OR st."isCompleted" = \'true\' OR st."isCompleted" = \'t\' OR st."isCompleted" = \'1\')
    ');
    $studyTaskQuery->execute([':studentID' => $studentID]);
    $completedStudyTasks = (int)($studyTaskQuery->fetchColumn() ?? 0);

    // Multiply completed sessions by duration (2 hours per session)
    $sessionDuration = 2; 
    $studyHours = $completedStudyTasks * $sessionDuration;
} catch (PDOException $e) {}

// D. CALCULATE OVERALL PROGRESS
$totalTasks = $totalAssignments + $totalExams;
$completedTasks = $completedAssignments + $completedExams;
$completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;


// ------------------------------------------------------------------
// 2. FETCH RECENT UPCOMING & OVERDUE TASKS FOR WIDE LISTS
// ------------------------------------------------------------------
$upcomingAssList = [];
$pastDueAssList = [];
$upcomingExamList = [];

// Fetch Upcoming Assignments (Cleaned & Active Semester Only)
try {
    $listQuery = $conn->prepare('
        SELECT a.*, c."courseCode" 
        FROM "Assignment" a
        JOIN "Course" c ON a."courseID" = c."courseID"
        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE s."studentID" = :studentID 
          AND s."startDate" <= CURRENT_DATE AND s."endDate" >= CURRENT_DATE
          AND a."taskStatus" != \'Completed\' 
          AND a."dueDate" >= CURRENT_DATE
        ORDER BY a."dueDate" ASC
        LIMIT 6
    ');
    $listQuery->execute([':studentID' => $studentID]);
    $upcomingAssList = $listQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch Past Due Assignments so overdue deadlines can be highlighted directly
try {
    $pastDueQuery = $conn->prepare('
        SELECT a.*, c."courseCode" 
        FROM "Assignment" a
        JOIN "Course" c ON a."courseID" = c."courseID"
        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE s."studentID" = :studentID 
          AND s."startDate" <= CURRENT_DATE AND s."endDate" >= CURRENT_DATE
          AND a."taskStatus" != \'Completed\' 
          AND a."dueDate" < CURRENT_DATE
        ORDER BY a."dueDate" ASC
        LIMIT 6
    ');
    $pastDueQuery->execute([':studentID' => $studentID]);
    $pastDueAssList = $pastDueQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch Upcoming Exams with Study Session Progress (Active Semester Added)
try {
    $examListQuery = $conn->prepare('
        SELECT e.*, c."courseCode", c."courseName",
            (SELECT COUNT(*) FROM public."StudyTask" st WHERE st."examID" = e."examID") AS total_sessions,
            (SELECT COUNT(*) FROM public."StudyTask" st WHERE st."examID" = e."examID" AND (st."isCompleted" = true OR st."isCompleted" = \'true\' OR st."isCompleted" = \'t\' OR st."isCompleted" = \'1\')) AS completed_sessions
        FROM "Exam" e
        JOIN "Course" c ON e."courseID" = c."courseID"
        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE s."studentID" = :studentID 
          AND s."startDate" <= CURRENT_DATE AND s."endDate" >= CURRENT_DATE
          AND e."examStatus" != \'Completed\' 
          AND e."examDate" >= CURRENT_DATE
        ORDER BY e."examDate" ASC, e."examTime" ASC
        LIMIT 6
    ');
    $examListQuery->execute([':studentID' => $studentID]);
    $upcomingExamList = $examListQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Helper for signed day countdowns
$todayDt = new DateTime('today');
function formatCountdownBadge($targetDateStr, $todayDt) {
    $targetDt = new DateTime($targetDateStr);
    $diffDays = (int)$todayDt->diff($targetDt)->format('%r%a');

    if ($diffDays < 0) {
        $daysAgo = abs($diffDays);
        return ['label' => $daysAgo . ($daysAgo === 1 ? ' day overdue' : ' days overdue'), 'class' => 'countdown-overdue', 'days' => $diffDays];
    } elseif ($diffDays === 0) {
        return ['label' => 'Due Today!', 'class' => 'countdown-urgent', 'days' => 0];
    } elseif ($diffDays === 1) {
        return ['label' => 'Tomorrow', 'class' => 'countdown-urgent', 'days' => 1];
    } elseif ($diffDays <= 7) {
        return ['label' => 'In ' . $diffDays . ' days', 'class' => 'countdown-urgent', 'days' => $diffDays];
    } else {
        return ['label' => 'In ' . $diffDays . ' days', 'class' => 'countdown-normal', 'days' => $diffDays];
    }
}

$nextSpotlightExam = $upcomingExamList[0] ?? null;
$nextSpotlightAss = $upcomingAssList[0] ?? ($pastDueAssList[0] ?? null);
$isSpotlightAssOverdue = empty($upcomingAssList) && !empty($pastDueAssList);
$defaultShowPastDue = empty($upcomingAssList) && !empty($pastDueAssList);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - PlanGenius</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body>
    <?php 
    $current_page = 'dashboard';
    include 'includes/sidebar.php'; 
    ?>

    <main class="main-content">
        <?php include 'includes/header.php'; ?>

        <div class="dashboard-container">
            <div class="page-header flex-between">
                <div>
                    <h1 class="page-title">Welcome back, <?= htmlspecialchars($_SESSION['firstName'] ?? 'Student') ?>!</h1>
                    <p class="page-subtitle">Here is your academic progress overview and upcoming critical dates.</p>
                </div>
            </div>

            <!-- OVERALL PROGRESS CARD -->
            <div class="progress-overview-card">
                <div class="progress-header">
                    <h2 class="progress-title">Overall Task & Exam Completion</h2>
                    <span class="progress-pct"><?= $completionRate ?>%</span>
                </div>
                <div class="dash-progress-track">
                    <div class="dash-progress-fill" style="width: <?= $completionRate ?>%;"></div>
                </div>
                <div class="progress-footer-row">
                    <p class="progress-subtext">You have completed <strong><?= $completedTasks ?></strong> out of <strong><?= $totalTasks ?></strong> total tasks and exams.</p>
                    <div class="progress-breakdown-pills">
                        <span class="breakdown-pill">Assignments: <?= $completedAssignments ?>/<?= $totalAssignments ?> Done</span>
                        <span class="breakdown-pill">Exams: <?= $completedExams ?>/<?= $totalExams ?> Done</span>
                    </div>
                </div>
            </div>

            <!-- IMPORTANT DATES & CRITICAL DEADLINES SPOTLIGHT -->
            <?php if ($nextSpotlightExam || $nextSpotlightAss): ?>
            <div class="spotlight-section">
                <div class="spotlight-section-label">
                    <i class="ph-fill ph-sparkle" style="color: #2563eb;"></i> Important Dates & Priority Focus
                </div>
                <div class="spotlight-grid">
                    <?php if ($nextSpotlightAss): ?>
                        <?php
                            $assBadge = formatCountdownBadge($nextSpotlightAss['dueDate'], $todayDt);
                            $spotCardClass = $isSpotlightAssOverdue ? 'overdue-spotlight' : 'deadline-spotlight';
                            $spotEyebrow = $isSpotlightAssOverdue ? 'Urgent Overdue Deadline' : 'Next Assignment Due Date';
                            $spotIcon = $isSpotlightAssOverdue ? 'ph-warning-circle' : 'ph-clock-countdown';
                        ?>
                        <a href="<?= $isSpotlightAssOverdue ? 'assignments.php?filter=Past+Due' : 'assignments.php' ?>" class="spotlight-card <?= $spotCardClass ?>">
                            <div class="spotlight-main">
                                <span class="spotlight-eyebrow">
                                    <i class="ph-fill <?= $spotIcon ?>"></i> <?= $spotEyebrow ?>
                                </span>
                                <div class="spotlight-title"><?= htmlspecialchars($nextSpotlightAss['assignmentTitle']) ?></div>
                                <div class="spotlight-meta">
                                    <span class="dash-course-tag"><?= htmlspecialchars($nextSpotlightAss['courseCode']) ?></span>
                                    <span><?= htmlspecialchars($nextSpotlightAss['assignmentType']) ?></span>
                                </div>
                            </div>
                            <div class="spotlight-date-box">
                                <span class="spotlight-date-text"><?= date('D, M d, Y', strtotime($nextSpotlightAss['dueDate'])) ?></span>
                                <span class="countdown-pill <?= $assBadge['class'] ?>"><?= $assBadge['label'] ?></span>
                            </div>
                        </a>
                    <?php endif; ?>

                    <?php if ($nextSpotlightExam): ?>
                        <?php
                            $exBadge = formatCountdownBadge($nextSpotlightExam['examDate'], $todayDt);
                            $exCountdownLabel = ($exBadge['days'] === 0) ? 'Exam Today!' : $exBadge['label'];
                            $spExType = $nextSpotlightExam['examType'] ?? 'Exam';
                            $spExTitle = trim($nextSpotlightExam['examTitle'] ?? '');
                            $spDisplayTitle = ($spExTitle !== '')
                                ? ((stripos($spExTitle, $spExType) !== false) ? $spExTitle : ($spExType . ': ' . $spExTitle))
                                : $spExType;
                        ?>
                        <a href="exam.php" class="spotlight-card exam-spotlight">
                            <div class="spotlight-main">
                                <span class="spotlight-eyebrow">
                                    <i class="ph-fill ph-graduation-cap"></i> Next Important Exam Date
                                </span>
                                <div class="spotlight-title"><?= htmlspecialchars($nextSpotlightExam['courseCode']) ?> — <?= htmlspecialchars($spDisplayTitle) ?></div>
                                <div class="spotlight-meta">
                                    <?php if (!empty($nextSpotlightExam['examTime'])): ?>
                                        <span><i class="ph-bold ph-clock"></i> <?= date('g:i A', strtotime($nextSpotlightExam['examTime'])) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($nextSpotlightExam['examLocation'])): ?>
                                        <span><i class="ph-fill ph-map-pin"></i> <?= htmlspecialchars($nextSpotlightExam['examLocation']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="spotlight-date-box">
                                <span class="spotlight-date-text"><?= date('D, M d, Y', strtotime($nextSpotlightExam['examDate'])) ?></span>
                                <span class="countdown-pill <?= $exBadge['class'] ?>"><?= $exCountdownLabel ?></span>
                            </div>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- KPI STAT CARDS -->
            <div class="stats-grid" style="margin-bottom: 26px;">
                <a href="assignments.php" class="stat-card stat-card-link stat-assignment-card">
                    <div class="stat-header">
                        <span class="stat-title">Total Assignments</span>
                        <div class="stat-icon-badge" style="background: #eff6ff; color: #2563eb;">
                            <i class="ph-fill ph-file-text"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?= $totalAssignments ?></div>
                    <div class="stat-label"><?= $upcomingAssignments ?> active · <?= $completedAssignments ?> completed</div>
                </a>

                <a href="assignments.php?filter=Past+Due" class="stat-card stat-card-link <?= $pastDueAssignments > 0 ? 'stat-alert-card' : '' ?>">
                    <div class="stat-header">
                        <span class="stat-title" style="<?= $pastDueAssignments > 0 ? 'color: #991b1b; font-weight: 700;' : '' ?>">Past Due Assignments</span>
                        <div class="stat-icon-badge" style="background: #fee2e2; color: #dc2626;">
                            <i class="ph-fill ph-warning-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value" style="<?= $pastDueAssignments > 0 ? 'color: #dc2626;' : '' ?>"><?= $pastDueAssignments ?></div>
                    <div class="stat-label"><?= $pastDueAssignments > 0 ? 'Requires immediate attention' : 'All deadlines on track' ?></div>
                </a>

                <a href="exam.php" class="stat-card stat-card-link stat-exam-card">
                    <div class="stat-header">
                        <span class="stat-title">Upcoming Exams</span>
                        <div class="stat-icon-badge" style="background: #fef3c7; color: #d97706;">
                            <i class="ph-fill ph-exam"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?= $upcomingExams ?></div>
                    <div class="stat-label"><?= $urgentExams ?> within 7 days · <?= $totalExams ?> total</div>
                </a>

                <a href="schedule.php" class="stat-card stat-card-link stat-study-card">
                    <div class="stat-header">
                        <span class="stat-title">Total Study Hours</span>
                        <div class="stat-icon-badge" style="background: #f3e8ff; color: #7c3aed;">
                            <i class="ph-fill ph-timer"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?= $studyHours ?>h</div>
                    <div class="stat-label">Completed revision time</div>
                </a>
            </div>

            <!-- DUAL DEADLINES & IMPORTANT EXAM DATES BOARDS -->
            <div class="wide-cards-grid">
                
                <!-- LEFT BOARD: ASSIGNMENT DUE DATES -->
                <div class="wide-card">
                    <div class="wide-card-header">
                        <div>
                            <h2 class="wide-card-title">Assignment Deadlines</h2>
                            <p class="wide-card-subtitle">Track due dates and overdue deliverables</p>
                        </div>
                        <a href="assignments.php" class="view-all-link">View All <i class="ph-bold ph-arrow-right"></i></a>
                    </div>

                    <?php if (!empty($upcomingAssList) || !empty($pastDueAssList)): ?>
                        <div class="card-tabs">
                            <button type="button" class="card-tab-btn <?= !$defaultShowPastDue ? 'active' : '' ?>" id="tabUpcomingAss" onclick="switchDashboardAssTab('upcoming')">
                                Upcoming (<?= count($upcomingAssList) ?>)
                            </button>
                            <button type="button" class="card-tab-btn tab-overdue <?= $defaultShowPastDue ? 'active' : '' ?>" id="tabPastDueAss" onclick="switchDashboardAssTab('pastdue')">
                                Past Due (<?= $pastDueAssignments ?>)
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- UPCOMING ASSIGNMENTS LIST -->
                    <div id="upcomingAssPanel" style="<?= $defaultShowPastDue ? 'display: none;' : '' ?>">
                        <?php if (empty($upcomingAssList)): ?>
                            <div class="empty-state">No upcoming assignments</div>
                        <?php else: ?>
                            <div class="dash-item-list">
                                <?php foreach ($upcomingAssList as $item): ?>
                                    <?php
                                        $badge = formatCountdownBadge($item['dueDate'], $todayDt);
                                        $rowUrgency = ($badge['days'] <= 3) ? 'urgent-row' : '';
                                        $subTasks = json_decode($item['subTasks'] ?? '[]', true);
                                        $subTotal = is_array($subTasks) ? count($subTasks) : 0;
                                        $subDone = is_array($subTasks) ? count(array_filter($subTasks, fn($s) => !empty($s['isCompleted']))) : 0;
                                    ?>
                                    <a href="assignments.php" class="dash-item-row <?= $rowUrgency ?>">
                                        <div class="dash-item-body">
                                            <div class="dash-item-top">
                                                <span class="dash-course-tag"><?= htmlspecialchars($item['courseCode']) ?></span>
                                                <span class="badge badge-type"><?= htmlspecialchars($item['assignmentType']) ?></span>
                                            </div>
                                            <div class="dash-item-title"><?= htmlspecialchars($item['assignmentTitle']) ?></div>
                                            <?php if ($subTotal > 0): ?>
                                                <div class="dash-item-meta">
                                                    <span><i class="ph ph-check-square"></i> <?= $subDone ?>/<?= $subTotal ?> steps completed</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="dash-item-right">
                                            <span class="dash-due-label">Due: <?= date('M d, Y', strtotime($item['dueDate'])) ?></span>
                                            <span class="countdown-pill <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- PAST DUE ASSIGNMENTS LIST -->
                    <div id="pastDueAssPanel" style="<?= $defaultShowPastDue ? '' : 'display: none;' ?>">
                        <?php if (empty($pastDueAssList)): ?>
                            <div class="empty-state">No past due assignments</div>
                        <?php else: ?>
                            <div class="dash-item-list">
                                <?php foreach ($pastDueAssList as $item): ?>
                                    <?php
                                        $badge = formatCountdownBadge($item['dueDate'], $todayDt);
                                        $subTasks = json_decode($item['subTasks'] ?? '[]', true);
                                        $subTotal = is_array($subTasks) ? count($subTasks) : 0;
                                        $subDone = is_array($subTasks) ? count(array_filter($subTasks, fn($s) => !empty($s['isCompleted']))) : 0;
                                    ?>
                                    <a href="assignments.php?filter=Past+Due" class="dash-item-row overdue-row">
                                        <div class="dash-item-body">
                                            <div class="dash-item-top">
                                                <span class="dash-course-tag"><?= htmlspecialchars($item['courseCode']) ?></span>
                                                <span class="badge badge-type"><?= htmlspecialchars($item['assignmentType']) ?></span>
                                            </div>
                                            <div class="dash-item-title"><?= htmlspecialchars($item['assignmentTitle']) ?></div>
                                            <?php if ($subTotal > 0): ?>
                                                <div class="dash-item-meta">
                                                    <span><i class="ph ph-check-square"></i> <?= $subDone ?>/<?= $subTotal ?> steps completed</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="dash-item-right">
                                            <span class="dash-due-label" style="color: #dc2626;">Due: <?= date('M d, Y', strtotime($item['dueDate'])) ?></span>
                                            <span class="countdown-pill <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- RIGHT BOARD: IMPORTANT EXAM DATES -->
                <div class="wide-card">
                    <div class="wide-card-header">
                        <div>
                            <h2 class="wide-card-title">Important Exam Dates</h2>
                            <p class="wide-card-subtitle">Upcoming final examinations, tests, and quizzes</p>
                        </div>
                        <a href="exam.php" class="view-all-link">View All <i class="ph-bold ph-arrow-right"></i></a>
                    </div>
                    
                    <?php if (empty($upcomingExamList)): ?>
                        <div class="empty-state">No upcoming exams</div>
                    <?php else: ?>
                        <div class="dash-item-list">
                            <?php foreach ($upcomingExamList as $item): ?>
                                <?php
                                    $exBadge = formatCountdownBadge($item['examDate'], $todayDt);
                                    $isSoon = ($exBadge['days'] >= 0 && $exBadge['days'] <= 7);
                                    $countdownText = ($exBadge['days'] === 0) ? 'Exam Today!' : $exBadge['label'];

                                    $exType = $item['examType'] ?? 'Exam';
                                    $exTitle = trim($item['examTitle'] ?? '');
                                    if ($exTitle !== '') {
                                        $displayExTitle = (stripos($exTitle, $exType) !== false) ? $exTitle : ($exType . ': ' . $exTitle);
                                    } else {
                                        $displayExTitle = $exType;
                                    }

                                    $totSess = (int)($item['total_sessions'] ?? 0);
                                    $doneSess = (int)($item['completed_sessions'] ?? 0);
                                ?>
                                <a href="exam.php" class="dash-item-row exam-row <?= $isSoon ? 'urgent-row' : '' ?>">
                                    <div class="dash-date-tile <?= $isSoon ? 'urgent' : '' ?>">
                                        <span class="dash-date-month"><?= date('M', strtotime($item['examDate'])) ?></span>
                                        <span class="dash-date-day"><?= date('d', strtotime($item['examDate'])) ?></span>
                                    </div>
                                    <div class="dash-item-body">
                                        <div class="dash-item-top">
                                            <span class="dash-course-tag"><?= htmlspecialchars($item['courseCode']) ?></span>
                                            <span class="badge badge-type"><?= htmlspecialchars($exType) ?></span>
                                        </div>
                                        <div class="dash-item-title"><?= htmlspecialchars($displayExTitle) ?></div>
                                        <div class="dash-item-meta">
                                            <?php if (!empty($item['examTime'])): ?>
                                                <span><i class="ph ph-clock"></i> <?= date('g:i A', strtotime($item['examTime'])) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($item['examLocation'])): ?>
                                                <span><i class="ph ph-map-pin"></i> <?= htmlspecialchars($item['examLocation']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($totSess > 0): ?>
                                                <span><i class="ph ph-book-open"></i> <?= $doneSess ?>/<?= $totSess ?> sessions</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="dash-item-right">
                                        <span class="dash-due-label"><?= date('D, M d', strtotime($item['examDate'])) ?></span>
                                        <span class="countdown-pill <?= $exBadge['class'] ?>"><?= $countdownText ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        
    </main>

    <script>
        function switchDashboardAssTab(tab) {
            const upcomingPanel = document.getElementById('upcomingAssPanel');
            const pastDuePanel = document.getElementById('pastDueAssPanel');
            const tabUpcoming = document.getElementById('tabUpcomingAss');
            const tabPastDue = document.getElementById('tabPastDueAss');

            if (!upcomingPanel || !pastDuePanel) return;

            if (tab === 'pastdue') {
                upcomingPanel.style.display = 'none';
                pastDuePanel.style.display = 'block';
                if (tabUpcoming) tabUpcoming.classList.remove('active');
                if (tabPastDue) tabPastDue.classList.add('active');
            } else {
                upcomingPanel.style.display = 'block';
                pastDuePanel.style.display = 'none';
                if (tabUpcoming) tabUpcoming.classList.add('active');
                if (tabPastDue) tabPastDue.classList.remove('active');
            }
        }
    </script>
</body>
</html>