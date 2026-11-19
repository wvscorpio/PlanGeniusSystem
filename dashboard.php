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
            SUM(CASE WHEN e."examStatus" != \'Completed\' AND e."examDate" >= CURRENT_DATE THEN 1 ELSE 0 END) as upcoming
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
// 2. FETCH RECENT UPCOMING TASKS FOR WIDE LISTS
// ------------------------------------------------------------------
$upcomingAssList = [];
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
        LIMIT 5
    ');
    $listQuery->execute([':studentID' => $studentID]);
    $upcomingAssList = $listQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Fetch Upcoming Exams (Active Semester Added)
try {
    $examListQuery = $conn->prepare('
        SELECT e.*, c."courseCode" 
        FROM "Exam" e
        JOIN "Course" c ON e."courseID" = c."courseID"
        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE s."studentID" = :studentID 
          AND s."startDate" <= CURRENT_DATE AND s."endDate" >= CURRENT_DATE
          AND e."examStatus" != \'Completed\' 
          AND e."examDate" >= CURRENT_DATE
        ORDER BY e."examDate" ASC
        LIMIT 5
    ');
    $examListQuery->execute([':studentID' => $studentID]);
    $upcomingExamList = $examListQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - PlanGenius</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        .progress-overview-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .progress-title { margin: 0; font-size: 18px; font-weight: 700; color: #1e293b; }
        .progress-pct { font-weight: bold; color: #10b981; font-size: 20px; }
        .progress-track { width: 100%; height: 12px; background: #f1f5f9; border-radius: 6px; overflow: hidden; }
        .progress-fill { height: 100%; background: #10b981; width: <?= $completionRate ?>%; transition: width 0.8s ease-in-out; }
        .progress-subtext { margin: 12px 0 0 0; font-size: 14px; color: #64748b; }

        .nav-card { display: flex; align-items: center; gap: 16px; padding: 20px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; text-decoration: none; color: inherit; transition: all 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.05); cursor: pointer; }
        .nav-card:hover { transform: translateY(-3px); box-shadow: 0 6px 12px rgba(0,0,0,0.1); border-color: #cbd5e1; }
        .nav-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 26px; }
        .nav-icon.assignments { background: #eff6ff; color: #3b82f6; }
        .nav-icon.exams { background: #fef2f2; color: #ef4444; }
        .nav-text h3 { margin: 0 0 4px 0; font-size: 16px; font-weight: 600; color: #1e293b; }
        .nav-text p { margin: 0; font-size: 13px; color: #64748b; }

        .list-item { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
        .list-item:last-child { border-bottom: none; }
        .item-info { display: flex; flex-direction: column; }
        .item-title { font-weight: 600; color: #1e293b; font-size: 14px; }
        .item-course { font-size: 12px; color: #64748b; }
        .item-date { font-size: 13px; color: #ef4444; font-weight: 500;}
    </style>
</head>
<body>
    <?php 
    $current_page = 'dashboard';
    include 'includes/sidebar.php'; 
    ?>

    <main class="main-content">
        <?php include 'includes/header.php'; ?>

        <div class="dashboard-container">
            <div class="page-header">
                <h1 class="page-title">Welcome back, <?= htmlspecialchars($_SESSION['firstName'] ?? 'Student') ?>!</h1>
                <p class="page-subtitle">Here is your academic progress overview.</p>
            </div>

            <div class="progress-overview-card">
                <div class="progress-header">
                    <h2 class="progress-title">Overall Task & Exam Completion</h2>
                    <span class="progress-pct"><?= $completionRate ?>%</span>
                </div>
                <div class="progress-track">
                    <div class="progress-fill"></div>
                </div>
                <p class="progress-subtext">You have completed <strong><?= $completedTasks ?></strong> out of <strong><?= $totalTasks ?></strong> total tasks and exams.</p>
            </div>

           <div class="stats-grid" style="margin-bottom: 30px;">
                <a href="assignments.php" class="stat-card" style="text-decoration: none; color: inherit; cursor: pointer; transition: all 0.2s ease;">
                    <div class="stat-header">
                        <span class="stat-title">Total Assignments</span>
                        <i class="ph ph-file-text stat-icon" style="color: #3b82f6;"></i>
                    </div>
                    <div class="stat-value"><?= $totalAssignments ?></div>
                    <div class="stat-label">Assignments, Project and Lab</div>
                </a>

                <a href="assignments.php?filter=Past+Due" class="stat-card" style="text-decoration: none; color: inherit; cursor: pointer; transition: all 0.2s ease;">
                    <div class="stat-header">
                        <span class="stat-title" style="<?= $pastDueAssignments > 0 ? 'color: #991b1b; font-weight: 600;' : '' ?>">Past Due Assignments</span>
                        <i class="ph ph-warning-circle stat-icon" style="color: #ef4444; font-size: 22px;"></i>
                    </div>
                    <div class="stat-value" style="<?= $pastDueAssignments > 0 ? 'color: #dc2626;' : '' ?>"><?= $pastDueAssignments ?></div>
                    <div class="stat-label">Overdue assignments, project and lab deadlines</div>
                </a>

                <a href="exam.php" class="stat-card" style="text-decoration: none; color: inherit; cursor: pointer; transition: all 0.2s ease; <?= $upcomingExams > 0 ? 'background-color: #fef2f2; border-color: #fecaca;' : '' ?>">
                    <div class="stat-header">
                        <span class="stat-title">Total Exams</span>
                        <i class="ph ph-exam stat-icon" style="color: #ef4444;"></i>
                    </div>
                    <div class="stat-value"><?= $totalExams ?></div>
                    <div class="stat-label">Major Examinations</div>
                </a>

                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Total Study Hours</span>
                        <i class="ph ph-timer stat-icon" style="color: #8b5cf6;"></i>
                    </div>
                    <div class="stat-value"><?= $studyHours ?>h</div>
                    <div class="stat-label">Completed study time</div>
                </div>
            </div>

            <div class="wide-cards-grid">
                
                <div class="wide-card">
                    <h2 class="wide-card-title">Upcoming Assignments</h2>
                    <p class="wide-card-subtitle">Your next active assignments and deadlines</p>
                    
                    <?php if (empty($upcomingAssList)): ?>
                        <div class="empty-state">No upcoming assignments</div>
                    <?php else: ?>
                        <div>
                            <?php foreach ($upcomingAssList as $item): ?>
                                <div class="list-item">
                                    <div class="item-info">
                                        <span class="item-title"><?= htmlspecialchars($item['assignmentTitle']) ?></span>
                                        <span class="item-course"><?= htmlspecialchars($item['courseCode']) ?> • <?= htmlspecialchars($item['assignmentType']) ?></span>
                                    </div>
                                    <div class="item-date">Due: <?= date('M d', strtotime($item['dueDate'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="wide-card">
                    <h2 class="wide-card-title">Upcoming Exams</h2>
                    <p class="wide-card-subtitle">Your next major examinations</p>
                    
                    <?php if (empty($upcomingExamList)): ?>
                        <div class="empty-state">No upcoming exams</div>
                    <?php else: ?>
                        <div>
                            <?php foreach ($upcomingExamList as $item): ?>
                                <div class="list-item">
                                    <div class="item-info">
                                        <span class="item-title"><?= htmlspecialchars($item['examType'] ?? 'Exam') ?></span>
                                        <span class="item-course"><?= htmlspecialchars($item['courseCode']) ?></span>
                                    </div>
                                    <div class="item-date">Date: <?= isset($item['examDate']) ? date('M d', strtotime($item['examDate'])) : 'TBD' ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        
    </main>
</body>
</html>