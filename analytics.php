<?php
include 'dbConn.php';
session_start();

$studentID = $_SESSION['studentID'] ?? null;

if (!$studentID) {
    header('Location: login.php');
    exit;
}

$current_page = 'analytics';

// ---------------------------------------------------------
// 1. Fetch Student's Semesters for Dropdown Selection
// ---------------------------------------------------------
$semestersList = [];
try {
    $stmtSemesters = $conn->prepare('
        SELECT "semesterID", "semesterName", "startDate", "endDate"
        FROM "SemesterPlan"
        WHERE "studentID" = :studentID
        ORDER BY "startDate" DESC
    ');
    $stmtSemesters->execute([':studentID' => $studentID]);
    $semestersList = $stmtSemesters->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error gracefully
}

// ---------------------------------------------------------
// 2. Determine Selected Semester ID & Archive State
// ---------------------------------------------------------
$selectedSemesterID = isset($_GET['semesterID']) ? (int)$_GET['semesterID'] : null;
$selectedSemesterObj = null;

$validSelection = false;
if ($selectedSemesterID) {
    foreach ($semestersList as $sem) {
        if ($sem['semesterID'] == $selectedSemesterID) {
            $validSelection = true;
            $selectedSemesterObj = $sem;
            break;
        }
    }
}

if (!$validSelection) {
    $today = date('Y-m-d');
    foreach ($semestersList as $sem) {
        if ($today >= $sem['startDate'] && $today <= $sem['endDate']) {
            $selectedSemesterID = $sem['semesterID'];
            $selectedSemesterObj = $sem;
            $validSelection = true;
            break;
        }
    }
    if (!$validSelection && !empty($semestersList)) {
        $selectedSemesterID = $semestersList[0]['semesterID'];
        $selectedSemesterObj = $semestersList[0];
    }
}

$isPastSemester = (
    $selectedSemesterObj !== null &&
    !empty($selectedSemesterObj['endDate']) &&
    $selectedSemesterObj['endDate'] < date('Y-m-d')
);

// ---------------------------------------------------------
// 3. Fetch Courses for Selected Semester
// ---------------------------------------------------------
$course_data = [];

if ($selectedSemesterID) {
    try {
        $stmtCourses = $conn->prepare('
            SELECT c."courseID", c."courseCode", c."courseName"
            FROM "Course" c
            WHERE c."semesterID" = :semesterID
            ORDER BY c."courseCode"
        ');
        $stmtCourses->execute([':semesterID' => $selectedSemesterID]);
        $courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

        foreach ($courses as $c) {
            $course_data[$c['courseID']] = [
                'code' => $c['courseCode'],
                'name' => $c['courseName'],
                'ass_total' => 0,
                'ass_completed' => 0,
                'exam_total' => 0,
                'exam_completed' => 0,
                'overdue_count' => 0,
                'total_count' => 0,
                'completed_count' => 0,
                'progress_percent' => 0
            ];
        }
    } catch (PDOException $e) {
        // Handle error gracefully
    }
}

// ---------------------------------------------------------
// 4. Fetch Assignments & Process Data for Selected Semester
// ---------------------------------------------------------
$status_counts = ['Completed' => 0, 'In Progress' => 0, 'Not Started' => 0];
$priority_counts = ['High' => 0, 'Medium' => 0, 'Low' => 0];
$overdue_count = 0;
$overdue_assignments = 0;
$overdue_exams = 0;
$assignmentTotalCount = 0;
$assignmentCompletedCount = 0;

if ($selectedSemesterID) {
    try {
        $stmt = $conn->prepare('
            SELECT a."assignmentID", a."taskStatus", a."priorityLevel", c."courseID", a."dueDate"
            FROM "Assignment" a
            JOIN "Course" c ON a."courseID" = c."courseID"
            WHERE c."semesterID" = :semesterID
        ');
        $stmt->execute([':semesterID' => $selectedSemesterID]);
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($assignments as $a) {
            $rawStatus = $a['taskStatus'];
            $assignmentTotalCount++;
            $isOverdue = ($a['dueDate'] && strtotime($a['dueDate']) < strtotime(date('Y-m-d')) && $rawStatus !== 'Completed');

            if ($isOverdue) {
                $overdue_count++;
                $overdue_assignments++;
            }

            if ($rawStatus === 'Completed') {
                $status_counts['Completed']++;
                $assignmentCompletedCount++;
            } elseif ($rawStatus === 'Ongoing' || $rawStatus === 'In Progress') {
                $status_counts['In Progress']++;
            } else {
                $status_counts['Not Started']++;
            }
            
            $pri = $a['priorityLevel'];
            if (isset($priority_counts[$pri])) {
                $priority_counts[$pri]++;
            }

            if (isset($course_data[$a['courseID']])) {
                $course_data[$a['courseID']]['ass_total']++;
                $course_data[$a['courseID']]['total_count']++;
                if ($rawStatus === 'Completed') {
                    $course_data[$a['courseID']]['ass_completed']++;
                    $course_data[$a['courseID']]['completed_count']++;
                }
                if ($isOverdue) {
                    $course_data[$a['courseID']]['overdue_count']++;
                }
            }
        }
    } catch (PDOException $e) {
        // Handle error gracefully
    }
}

// ---------------------------------------------------------
// 5. Fetch Exams & Process Data for Selected Semester
// ---------------------------------------------------------
$examTotalCount = 0;
$examCompletedCount = 0;

if ($selectedSemesterID) {
    try {
        $stmtExams = $conn->prepare('
            SELECT e."examID", e."examStatus", e."difficultyLevel", c."courseID", e."examDate"
            FROM "Exam" e
            JOIN "Course" c ON e."courseID" = c."courseID"
            WHERE c."semesterID" = :semesterID
        ');
        $stmtExams->execute([':semesterID' => $selectedSemesterID]);
        $exams = $stmtExams->fetchAll(PDO::FETCH_ASSOC);

        foreach ($exams as $e) {
            $rawStatus = $e['examStatus'];
            $examTotalCount++;
            $isOverdue = ($e['examDate'] && strtotime($e['examDate']) < strtotime(date('Y-m-d')) && $rawStatus !== 'Completed');

            if ($isOverdue) {
                $overdue_count++;
                $overdue_exams++;
            }

            if ($rawStatus === 'Completed') {
                $status_counts['Completed']++;
                $examCompletedCount++;
            } elseif ($rawStatus === 'Ongoing' || $rawStatus === 'In Progress') {
                $status_counts['In Progress']++;
            } else {
                $status_counts['Not Started']++;
            }
            
            $diff = $e['difficultyLevel'];
            if ($diff === 'Hard' || $diff === 'High') {
                $priority_counts['High']++;
            } elseif ($diff === 'Medium') {
                $priority_counts['Medium']++;
            } elseif ($diff === 'Easy' || $diff === 'Low') {
                $priority_counts['Low']++;
            } else {
                $priority_counts['Medium']++;
            }

            if (isset($course_data[$e['courseID']])) {
                $course_data[$e['courseID']]['exam_total']++;
                $course_data[$e['courseID']]['total_count']++;
                if ($rawStatus === 'Completed') {
                    $course_data[$e['courseID']]['exam_completed']++;
                    $course_data[$e['courseID']]['completed_count']++;
                }
                if ($isOverdue) {
                    $course_data[$e['courseID']]['overdue_count']++;
                }
            }
        }
    } catch (PDOException $e) {
        // Handle error gracefully
    }
}

// ---------------------------------------------------------
// 6. Live Study Hours & Aggregate Calculations
// ---------------------------------------------------------
$totalStudyTasks = 0;
$completedStudyTasks = 0;
$totalStudyHours = 0;

if ($selectedSemesterID) {
    try {
        $studyStmt = $conn->prepare('
            SELECT 
                COUNT(*) as total_tasks,
                SUM(CASE WHEN (st."isCompleted" = true OR st."isCompleted" = \'true\' OR st."isCompleted" = \'t\' OR st."isCompleted" = \'1\') THEN 1 ELSE 0 END) as completed_tasks
            FROM public."StudyTask" st
            JOIN "Exam" e ON st."examID" = e."examID"
            JOIN "Course" c ON e."courseID" = c."courseID"
            WHERE c."semesterID" = :semesterID
        ');
        $studyStmt->execute([':semesterID' => $selectedSemesterID]);
        $studyRes = $studyStmt->fetch(PDO::FETCH_ASSOC);
        $totalStudyTasks = (int)($studyRes['total_tasks'] ?? 0);
        $completedStudyTasks = (int)($studyRes['completed_tasks'] ?? 0);
        $totalStudyHours = $completedStudyTasks * 2;
    } catch (PDOException $e) {}
}

$total_items = array_sum($status_counts);
$completed_tasks = $status_counts['Completed'];
$upcoming_tasks = $status_counts['In Progress'] + $status_counts['Not Started'];
$on_schedule_upcoming = max(0, $upcoming_tasks - $overdue_count);
$overall_progress = $total_items > 0 ? round(($completed_tasks / $total_items) * 100) : 0;

// Process completion rates & dynamic bar colors for courses
$barChartLabels = [];
$barChartData = [];
$barChartColors = [];
$barChartMeta = [];

$focusCourse = null;

foreach ($course_data as &$cd) {
    if ($cd['total_count'] > 0) {
        $cd['progress_percent'] = round(($cd['completed_count'] / $cd['total_count']) * 100);
    }

    if (!$isPastSemester && $cd['total_count'] > 0 && $cd['progress_percent'] < 100) {
        if (
            $focusCourse === null ||
            $cd['overdue_count'] > $focusCourse['overdue_count'] ||
            ($cd['overdue_count'] === $focusCourse['overdue_count'] && $cd['progress_percent'] < $focusCourse['progress_percent'])
        ) {
            $focusCourse = $cd;
        }
    }

    $barChartLabels[] = $cd['code'];
    $barChartData[] = $cd['progress_percent'];

    if ($cd['total_count'] === 0) {
        $barChartColors[] = '#e2e8f0';
        $barChartMeta[] = 'No tasks or exams assigned yet';
    } elseif ($cd['progress_percent'] >= 75) {
        $barChartColors[] = '#10b981';
        $barChartMeta[] = "{$cd['completed_count']}/{$cd['total_count']} completed";
    } elseif ($cd['progress_percent'] >= 40) {
        $barChartColors[] = '#6366f1';
        $barChartMeta[] = "{$cd['completed_count']}/{$cd['total_count']} completed";
    } elseif ($cd['progress_percent'] > 0) {
        $barChartColors[] = '#f59e0b';
        $barChartMeta[] = "{$cd['completed_count']}/{$cd['total_count']} completed";
    } else {
        $barChartColors[] = '#ef4444';
        $barChartMeta[] = "0/{$cd['total_count']} completed (Not started)";
    }
}
unset($cd);

// ---------------------------------------------------------
// 7. Save Weekly Historical Snapshot (Active/Current Semesters Only)
// ---------------------------------------------------------
if ($selectedSemesterID && !$isPastSemester) {
    try {
        $checkStmt = $conn->prepare('
            SELECT "analyticsID" 
            FROM "AnalyticsHistory" 
            WHERE "studentID" = :studentID 
              AND "semesterID" = :semesterID 
              AND DATE_TRUNC(\'week\', "recordDate") = DATE_TRUNC(\'week\', CURRENT_DATE)
            LIMIT 1
        ');
        $checkStmt->execute([
            ':studentID' => $studentID,
            ':semesterID' => $selectedSemesterID
        ]);
        $existingSnapshotID = $checkStmt->fetchColumn();

        if (!$existingSnapshotID) {
            $subStmt = $conn->prepare('
                SELECT 
                    COUNT(*) as total_subtasks,
                    SUM(CASE WHEN (st."isCompleted" = true OR st."isCompleted" = \'true\' OR st."isCompleted" = \'t\' OR st."isCompleted" = \'1\') THEN 1 ELSE 0 END) as completed_subtasks
                FROM "SubTask" st
                JOIN "Assignment" a ON st."assignmentID" = a."assignmentID"
                JOIN "Course" c ON a."courseID" = c."courseID"
                WHERE c."semesterID" = :semesterID
            ');
            $subStmt->execute([':semesterID' => $selectedSemesterID]);
            $subRes = $subStmt->fetch(PDO::FETCH_ASSOC);
            $totalSubtasks = (int)($subRes['total_subtasks'] ?? 0);
            $completedSubtasks = (int)($subRes['completed_subtasks'] ?? 0);

            $insertHist = $conn->prepare('
                INSERT INTO "AnalyticsHistory" (
                    "studentID", "semesterID", "recordDate", "overallProgress", 
                    "totalStudyHours", "completedStudyTasks", "totalStudyTasks", 
                    "completedSubtasks", "totalSubtasks", "completedItems", 
                    "upcomingItems", "overdueItems", "assignmentCompleted", "examCompleted"
                ) VALUES (
                    :studentID, :semesterID, CURRENT_DATE, :overallProgress,
                    :totalStudyHours, :completedStudyTasks, :totalStudyTasks,
                    :completedSubtasks, :totalSubtasks, :completedItems,
                    :upcomingItems, :overdueItems, :assignmentCompleted, :examCompleted
                ) RETURNING "analyticsID"
            ');
            $insertHist->execute([
                ':studentID' => $studentID,
                ':semesterID' => $selectedSemesterID,
                ':overallProgress' => $overall_progress,
                ':totalStudyHours' => $totalStudyHours,
                ':completedStudyTasks' => $completedStudyTasks,
                ':totalStudyTasks' => $totalStudyTasks,
                ':completedSubtasks' => $completedSubtasks,
                ':totalSubtasks' => $totalSubtasks,
                ':completedItems' => $completed_tasks,
                ':upcomingItems' => $upcoming_tasks,
                ':overdueItems' => $overdue_count,
                ':assignmentCompleted' => $assignmentCompletedCount,
                ':examCompleted' => $examCompletedCount
            ]);
            $newAnalyticsID = $insertHist->fetchColumn();

            if ($newAnalyticsID && !empty($course_data)) {
                $insertCourseHist = $conn->prepare('
                    INSERT INTO "AnalyticsCourseHistory" (
                        "analyticsID", "semesterID", "courseID", "courseCode", "courseName", "totalItems", "completedItems", "progressPercent"
                    ) VALUES (
                        :analyticsID, :semesterID, :courseID, :courseCode, :courseName, :totalItems, :completedItems, :progressPercent
                    )
                ');
                foreach ($course_data as $cId => $cd) {
                    $insertCourseHist->execute([
                        ':analyticsID' => $newAnalyticsID,
                        ':semesterID' => $selectedSemesterID,
                        ':courseID' => $cId,
                        ':courseCode' => $cd['code'],
                        ':courseName' => $cd['name'],
                        ':totalItems' => $cd['total_count'],
                        ':completedItems' => $cd['completed_count'],
                        ':progressPercent' => $cd['progress_percent']
                    ]);
                }
            }
        }
    } catch (PDOException $e) {
        // Handle insertion error gracefully
    }
}

// ---------------------------------------------------------
// 8. Fetch Analytics History Records for Selected Semester
// ---------------------------------------------------------
$analyticsHistoryList = [];
if ($selectedSemesterID) {
    try {
        $stmtHistory = $conn->prepare('
            SELECT ah.*, s."semesterName"
            FROM "AnalyticsHistory" ah
            JOIN "SemesterPlan" s ON ah."semesterID" = s."semesterID"
            WHERE ah."studentID" = :studentID AND ah."semesterID" = :semesterID
            ORDER BY ah."recordDate" DESC, ah."analyticsID" DESC
        ');
        $stmtHistory->execute([
            ':studentID' => $studentID,
            ':semesterID' => $selectedSemesterID
        ]);
        $analyticsHistoryList = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Handle error gracefully
    }
}

// Build chronological trend arrays for line chart
$chronologicalHistory = array_reverse($analyticsHistoryList);
$trendDates = [];
$trendProgress = [];
$trendHours = [];

foreach ($chronologicalHistory as $h) {
    $trendDates[] = date('d M', strtotime($h['recordDate']));
    $trendProgress[] = (int)$h['overallProgress'];
    $trendHours[] = (int)$h['totalStudyHours'];
}

// Prepare Chart JSON Data
$chartStatusLabels = json_encode(array_keys($status_counts));
$chartStatusData = json_encode(array_values($status_counts));
$chartPriorityLabels = json_encode(array_keys($priority_counts));
$chartPriorityData = json_encode(array_values($priority_counts));
$chartBarLabels = json_encode($barChartLabels);
$chartBarData = json_encode($barChartData);
$chartBarColors = json_encode($barChartColors);
$chartBarMeta = json_encode($barChartMeta);
$chartTrendDates = json_encode($trendDates);
$chartTrendProgress = json_encode($trendProgress);
$chartTrendHours = json_encode($trendHours);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PlanGenius — Analytics</title>
  <link rel="stylesheet" href="css/style.css">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
  <?php include 'includes/sidebar.php'; ?>
  <main class="main-content">
    <?php include 'includes/header.php'; ?>

    <div class="dashboard-container">
      
      <!-- Analytics Header with Semester Dropdown Selector -->
      <div class="analytics-header">
        <div class="analytics-title-group">
          <h1>
            Academic Analytics
            <?php if ($isPastSemester): ?>
              <span class="archive-semester-tag"><i class="ph-fill ph-archive"></i> Past Semester</span>
            <?php endif; ?>
          </h1>
          <p>Track completion rates, workload priority, study hours, and weekly progress by semester.</p>
        </div>
        <form method="GET" action="analytics.php" style="margin: 0;">
          <div class="semester-selector-group">
            <label for="semesterID"><i class="ph-fill ph-calendar-blank" style="color:#6366f1;"></i> Semester:</label>
            <select name="semesterID" id="semesterID" class="semester-select" onchange="this.form.submit()">
              <?php if (empty($semestersList)): ?>
                <option value="">No Semesters Available</option>
              <?php else: ?>
                <?php foreach ($semestersList as $sem): ?>
                  <?php $semEnded = (!empty($sem['endDate']) && $sem['endDate'] < date('Y-m-d')); ?>
                  <option value="<?= $sem['semesterID'] ?>" <?= $sem['semesterID'] == $selectedSemesterID ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sem['semesterName']) ?><?= $semEnded ? ' (Ended)' : '' ?>
                  </option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
        </form>
      </div>

      <!-- 5-Card KPI Strip -->
      <div class="analytics-kpi-grid">
        <div class="analytics-kpi-card kpi-blue">
          <div class="kpi-top-row">
            <span class="kpi-label">Overall Progress</span>
            <div class="kpi-icon-wrap" style="background:#eff6ff; color:#2563eb;">
              <i class="ph-bold ph-trend-up"></i>
            </div>
          </div>
          <div class="kpi-main-val" style="color:#2563eb;"><?= $overall_progress ?>%</div>
          <div class="kpi-sub-hint"><?= $completed_tasks ?> of <?= $total_items ?> total items done</div>
        </div>

        <div class="analytics-kpi-card kpi-green">
          <div class="kpi-top-row">
            <span class="kpi-label">Completed Items</span>
            <div class="kpi-icon-wrap" style="background:#ecfdf5; color:#059669;">
              <i class="ph-fill ph-check-circle"></i>
            </div>
          </div>
          <div class="kpi-main-val"><?= $completed_tasks ?></div>
          <div class="kpi-sub-hint"><?= $assignmentCompletedCount ?> assignments · <?= $examCompletedCount ?> exams</div>
        </div>

        <?php if ($isPastSemester): ?>
          <div class="analytics-kpi-card kpi-neutral">
            <div class="kpi-top-row">
              <span class="kpi-label">Incomplete Items</span>
              <div class="kpi-icon-wrap" style="background:#e2e8f0; color:#475569;">
                <i class="ph-fill ph-minus-circle"></i>
              </div>
            </div>
            <div class="kpi-main-val"><?= $upcoming_tasks ?></div>
            <div class="kpi-sub-hint"><?= $status_counts['In Progress'] ?> partial · <?= $status_counts['Not Started'] ?> not started</div>
          </div>

          <div class="analytics-kpi-card <?= $overdue_count > 0 ? 'kpi-red' : 'kpi-green' ?>">
            <div class="kpi-top-row">
              <span class="kpi-label" style="<?= $overdue_count > 0 ? 'color:#991b1b;' : '' ?>">Missed Deadlines</span>
              <div class="kpi-icon-wrap" style="background:<?= $overdue_count > 0 ? '#fee2e2' : '#ecfdf5' ?>; color:<?= $overdue_count > 0 ? '#dc2626' : '#059669' ?>;">
                <i class="ph-fill ph-warning-circle"></i>
              </div>
            </div>
            <div class="kpi-main-val" style="<?= $overdue_count > 0 ? 'color:#dc2626;' : '' ?>"><?= $overdue_count ?></div>
            <div class="kpi-sub-hint">
              <?= $overdue_count > 0 ? "{$overdue_assignments} assignments · {$overdue_exams} exams" : 'No missed deadlines' ?>
            </div>
          </div>

          <div class="analytics-kpi-card kpi-purple">
            <div class="kpi-top-row">
              <span class="kpi-label">Study Hours</span>
              <div class="kpi-icon-wrap" style="background:#f3e8ff; color:#7c3aed;">
                <i class="ph-fill ph-timer"></i>
              </div>
            </div>
            <div class="kpi-main-val"><?= $totalStudyHours ?>h</div>
            <div class="kpi-sub-hint"><?= $completedStudyTasks ?>/<?= $totalStudyTasks ?> revision sessions done</div>
          </div>
        <?php else: ?>
          <a href="schedule.php" class="analytics-kpi-card kpi-amber">
            <div class="kpi-top-row">
              <span class="kpi-label">Active Upcoming</span>
              <div class="kpi-icon-wrap" style="background:#fffbeb; color:#d97706;">
                <i class="ph-fill ph-clock"></i>
              </div>
            </div>
            <div class="kpi-main-val"><?= $on_schedule_upcoming ?></div>
            <div class="kpi-sub-hint"><?= $status_counts['In Progress'] ?> in progress · <?= $upcoming_tasks ?> total open</div>
          </a>

          <a href="assignments.php?filter=Past+Due" class="analytics-kpi-card <?= $overdue_count > 0 ? 'kpi-red' : 'kpi-green' ?>">
            <div class="kpi-top-row">
              <span class="kpi-label" style="<?= $overdue_count > 0 ? 'color:#991b1b;' : '' ?>">Overdue Items</span>
              <div class="kpi-icon-wrap" style="background:<?= $overdue_count > 0 ? '#fee2e2' : '#ecfdf5' ?>; color:<?= $overdue_count > 0 ? '#dc2626' : '#059669' ?>;">
                <i class="ph-fill ph-warning-circle"></i>
              </div>
            </div>
            <div class="kpi-main-val" style="<?= $overdue_count > 0 ? 'color:#dc2626;' : '' ?>"><?= $overdue_count ?></div>
            <div class="kpi-sub-hint">
              <?= $overdue_count > 0 ? "{$overdue_assignments} assignments · {$overdue_exams} exams" : 'All deadlines on track' ?>
            </div>
          </a>

          <a href="exam.php" class="analytics-kpi-card kpi-purple">
            <div class="kpi-top-row">
              <span class="kpi-label">Study Hours</span>
              <div class="kpi-icon-wrap" style="background:#f3e8ff; color:#7c3aed;">
                <i class="ph-fill ph-timer"></i>
              </div>
            </div>
            <div class="kpi-main-val"><?= $totalStudyHours ?>h</div>
            <div class="kpi-sub-hint"><?= $completedStudyTasks ?>/<?= $totalStudyTasks ?> revision sessions done</div>
          </a>
        <?php endif; ?>
      </div>

      <!-- Focus Banner (Active Semesters) vs Archive Summary Banner (Past Semesters) -->
      <?php if ($total_items > 0): ?>
        <?php if ($isPastSemester): ?>
          <div class="analytics-insight-banner is-archive">
            <div class="insight-left">
              <div class="insight-badge-icon"><i class="ph-fill ph-archive"></i></div>
              <div class="insight-text">
                <strong>Completed Semester Archive — <?= htmlspecialchars($selectedSemesterObj['semesterName']) ?></strong>
                <span>
                  Ended on <b><?= date('d M Y', strtotime($selectedSemesterObj['endDate'])) ?></b> · Final completion rate: <b><?= $overall_progress ?>%</b> (<?= $completed_tasks ?>/<?= $total_items ?> items) with <b><?= $totalStudyHours ?>h</b> study time recorded.
                </span>
              </div>
            </div>
          </div>
        <?php elseif ($focusCourse !== null): ?>
          <div class="analytics-insight-banner">
            <div class="insight-left">
              <div class="insight-badge-icon"><i class="ph-fill ph-lightbulb"></i></div>
              <div class="insight-text">
                <strong>Priority Focus: <?= htmlspecialchars($focusCourse['code']) ?> — <?= htmlspecialchars($focusCourse['name']) ?></strong>
                <span>
                  Currently at <b><?= $focusCourse['progress_percent'] ?>%</b> completion
                  (<?= $focusCourse['completed_count'] ?>/<?= $focusCourse['total_count'] ?> items)<?= $focusCourse['overdue_count'] > 0 ? " with <b>{$focusCourse['overdue_count']} overdue</b> deliverable(s)." : '.' ?>
                </span>
              </div>
            </div>
            <a href="<?= $overdue_assignments > 0 ? 'assignments.php?filter=Past+Due' : 'exam.php' ?>" class="btn btn-primary" style="font-size:13px; padding:8px 14px;">
              Review Pending Work <i class="ph-bold ph-arrow-right"></i>
            </a>
          </div>
        <?php else: ?>
          <div class="analytics-insight-banner is-healthy">
            <div class="insight-left">
              <div class="insight-badge-icon"><i class="ph-fill ph-trophy"></i></div>
              <div class="insight-text">
                <strong>All Semester Deliverables Completed!</strong>
                <span>You have completed 100% of your assignments and exams for this semester.</span>
              </div>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <!-- Doughnut Charts Row -->
      <div class="analytics-charts-grid">
        <div class="analytics-card">
          <div class="analytics-card-header">
            <div>
              <h2 class="analytics-card-title">Assessment & Exam Status</h2>
              <p class="analytics-card-subtitle">Completion state across all semester assignments and exams</p>
            </div>
          </div>
          <div class="pie-container">
            <canvas id="statusChart"></canvas>
          </div>
        </div>

        <div class="analytics-card">
          <div class="analytics-card-header">
            <div>
              <h2 class="analytics-card-title">Priority & Difficulty Distribution</h2>
              <p class="analytics-card-subtitle">Workload breakdown by task priority and exam difficulty</p>
            </div>
          </div>
          <div class="pie-container">
            <canvas id="priorityChart"></canvas>
          </div>
        </div>
      </div>

      <!-- Subject Completion Rate Bar Chart -->
      <div class="analytics-card">
        <div class="analytics-card-header">
          <div>
            <h2 class="analytics-card-title">Subject Completion Rate (%)</h2>
            <p class="analytics-card-subtitle">Color-coded progress per course (hover bars for exact item counts)</p>
          </div>
        </div>
        <div class="bar-container">
          <canvas id="completionBarChart"></canvas>
        </div>
      </div>

      <!-- Weekly Progress & Study Hours Trend Chart -->
      <?php if (count($chronologicalHistory) >= 2): ?>
      <div class="analytics-card">
        <div class="analytics-card-header">
          <div>
            <h2 class="analytics-card-title">Weekly Progress & Study Hours Trend</h2>
            <p class="analytics-card-subtitle">Historical growth tracked from your weekly semester snapshots</p>
          </div>
        </div>
        <div class="trend-container">
          <canvas id="trendChart"></canvas>
        </div>
      </div>
      <?php endif; ?>

      <!-- Subject Details Breakdown Table -->
      <div class="analytics-card">
        <div class="analytics-card-header">
          <div>
            <h2 class="analytics-card-title">Subject Details Breakdown</h2>
            <p class="analytics-card-subtitle">Detailed assignment, exam, and overdue counts per enrolled course</p>
          </div>
        </div>
        <div class="analytics-table-wrap">
          <table class="analytics-table">
            <thead>
              <tr>
                <th>Course Code</th>
                <th>Course Name</th>
                <th>Assignments</th>
                <th>Exams</th>
                <th><?= $isPastSemester ? 'Missed' : 'Overdue' ?></th>
                <th>Overall Progress</th>
                <th>Completion Rate</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($course_data)): ?>
              <tr>
                <td colspan="7" style="text-align:center; color:#94a3b8; padding:24px;">No course data found for this semester.</td>
              </tr>
              <?php else: ?>
                <?php foreach ($course_data as $course): ?>
                  <?php
                    $hasItems = $course['total_count'] > 0;
                    $pct = $course['progress_percent'];

                    if (!$hasItems) {
                        $rateClass = 'rate-neutral';
                        $rateText = 'No Items';
                        $fillColor = '#cbd5e1';
                    } elseif ($pct >= 75) {
                        $rateClass = 'rate-green';
                        $rateText = $pct . '%';
                        $fillColor = '#10b981';
                    } elseif ($pct >= 40) {
                        $rateClass = 'rate-blue';
                        $rateText = $pct . '%';
                        $fillColor = '#6366f1';
                    } elseif ($pct > 0) {
                        $rateClass = 'rate-amber';
                        $rateText = $pct . '%';
                        $fillColor = '#f59e0b';
                    } else {
                        $rateClass = 'rate-red';
                        $rateText = '0%';
                        $fillColor = '#ef4444';
                    }
                  ?>
                  <tr>
                    <td style="font-weight: 700; color:#0f172a;"><?= htmlspecialchars($course['code']) ?></td>
                    <td><?= htmlspecialchars($course['name']) ?></td>
                    <td><?= $course['ass_completed'] ?> / <?= $course['ass_total'] ?></td>
                    <td><?= $course['exam_completed'] ?> / <?= $course['exam_total'] ?></td>
                    <td>
                      <?php if ($course['overdue_count'] > 0): ?>
                        <span class="rate-pill rate-red"><?= $course['overdue_count'] ?> <?= $isPastSemester ? 'missed' : 'overdue' ?></span>
                      <?php else: ?>
                        <span style="color:#94a3b8;">0</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="td-progress">
                        <div class="mini-progress-bar">
                          <div class="mini-progress-fill" style="width: <?= $pct ?>%; background: <?= $fillColor ?>;"></div>
                        </div>
                        <span class="mini-progress-text"><?= $course['completed_count'] ?>/<?= $course['total_count'] ?></span>
                      </div>
                    </td>
                    <td>
                      <span class="rate-pill <?= $rateClass ?>"><?= $rateText ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Analytics History Table Row -->
      <div class="analytics-card" style="margin-bottom: 0;">
        <div class="analytics-card-header">
          <div>
            <h2 class="analytics-card-title">Weekly Analytics History</h2>
            <p class="analytics-card-subtitle">Automated weekly snapshots with changes (+ / -) compared to the previous recorded week</p>
          </div>
        </div>
        <div class="analytics-table-wrap">
          <table class="analytics-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Semester</th>
                <th>Study Hours</th>
                <th>Study Sessions</th>
                <th>Overall Progress</th>
                <th>Completed Items</th>
                <th>Open Items</th>
                <th>Overdue Items</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($analyticsHistoryList)): ?>
              <tr>
                <td colspan="8" style="text-align:center; color:#94a3b8; padding:24px;">No analytics history available for this semester.</td>
              </tr>
              <?php else: ?>
                <?php $histCount = count($analyticsHistoryList); ?>
                <?php foreach ($analyticsHistoryList as $idx => $history): ?>
                  <?php
                    $prevSnap = ($idx + 1 < $histCount) ? $analyticsHistoryList[$idx + 1] : null;
                    $prevDateLabel = $prevSnap ? date('d M Y', strtotime($prevSnap['recordDate'])) : '';
                    $hoursDiff = $prevSnap ? ((int)$history['totalStudyHours'] - (int)$prevSnap['totalStudyHours']) : 0;
                    $progDiff = $prevSnap ? ((int)$history['overallProgress'] - (int)$prevSnap['overallProgress']) : 0;
                    $overdueDiff = $prevSnap ? ((int)$history['overdueItems'] - (int)$prevSnap['overdueItems']) : 0;

                    $hProg = (int)$history['overallProgress'];
                    $hPillClass = $hProg >= 75 ? 'rate-green' : ($hProg >= 40 ? 'rate-blue' : ($hProg > 0 ? 'rate-amber' : 'rate-neutral'));
                  ?>
                  <tr>
                    <td style="font-weight: 700; color:#0f172a;"><?= date('d M Y', strtotime($history['recordDate'])) ?></td>
                    <td><?= htmlspecialchars($history['semesterName']) ?></td>
                    <td>
                      <?= (int)$history['totalStudyHours'] ?> hours
                      <?php if ($hoursDiff > 0): ?>
                        <span class="delta-tag delta-up" title="+<?= $hoursDiff ?>h compared to <?= $prevDateLabel ?>">+<?= $hoursDiff ?>h</span>
                      <?php endif; ?>
                    </td>
                    <td><?= (int)$history['completedStudyTasks'] ?> / <?= (int)$history['totalStudyTasks'] ?></td>
                    <td>
                      <span class="rate-pill <?= $hPillClass ?>"><?= $hProg ?>%</span>
                      <?php if ($progDiff > 0): ?>
                        <span class="delta-tag delta-up" title="+<?= $progDiff ?>% compared to <?= $prevDateLabel ?>">+<?= $progDiff ?>%</span>
                      <?php endif; ?>
                    </td>
                    <td><?= (int)$history['completedItems'] ?></td>
                    <td><?= (int)$history['upcomingItems'] ?></td>
                    <td>
                      <?= (int)$history['overdueItems'] ?>
                      <?php if ($overdueDiff > 0): ?>
                        <span class="delta-tag delta-warn" title="+<?= $overdueDiff ?> overdue compared to <?= $prevDateLabel ?>">+<?= $overdueDiff ?></span>
                      <?php elseif ($overdueDiff < 0): ?>
                        <span class="delta-tag delta-up" title="<?= $overdueDiff ?> overdue compared to <?= $prevDateLabel ?>"><?= $overdueDiff ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </main>

  <script>
    document.addEventListener("DOMContentLoaded", function() {
        const totalItemsCount = <?= (int)$total_items ?>;
        const highPriorityCount = <?= (int)$priority_counts['High'] ?>;

        // Custom plugin to display centered summary text inside Doughnut charts
        const centerTextPlugin = {
            id: 'centerTextPlugin',
            afterDraw(chart) {
                const { ctx, chartArea } = chart;
                if (!chartArea || !chart.config.options.centerText) return;

                const { main, sub } = chart.config.options.centerText;
                const centerX = (chartArea.left + chartArea.right) / 2;
                const centerY = (chartArea.top + chartArea.bottom) / 2;

                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';

                ctx.font = '800 24px Inter, sans-serif';
                ctx.fillStyle = '#0f172a';
                ctx.fillText(main, centerX, centerY - 8);

                ctx.font = '600 11.5px Inter, sans-serif';
                ctx.fillStyle = '#64748b';
                ctx.fillText(sub, centerX, centerY + 13);
                ctx.restore();
            }
        };

        const commonDoughnutOptions = {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '66%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        padding: 18,
                        font: { family: 'Inter, sans-serif', size: 12, weight: '600' }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            const val = ctx.raw || 0;
                            const sum = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = sum > 0 ? Math.round((val / sum) * 100) : 0;
                            return ` ${ctx.label}: ${val} item${val === 1 ? '' : 's'} (${pct}%)`;
                        }
                    }
                }
            }
        };

        // 1. Status Doughnut Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: <?= $chartStatusLabels ?>,
                datasets: [{
                    data: <?= $chartStatusData ?>,
                    backgroundColor: ['#10b981', '#3b82f6', '#64748b'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                ...commonDoughnutOptions,
                centerText: {
                    main: String(totalItemsCount),
                    sub: 'Total Items'
                }
            },
            plugins: [centerTextPlugin]
        });

        // 2. Priority / Difficulty Doughnut Chart
        const priorityCtx = document.getElementById('priorityChart').getContext('2d');
        new Chart(priorityCtx, {
            type: 'doughnut',
            data: {
                labels: <?= $chartPriorityLabels ?>,
                datasets: [{
                    data: <?= $chartPriorityData ?>,
                    backgroundColor: ['#ef4444', '#f59e0b', '#10b981'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                ...commonDoughnutOptions,
                centerText: {
                    main: String(highPriorityCount),
                    sub: 'High / Hard'
                }
            },
            plugins: [centerTextPlugin]
        });

        // 3. Subject Completion Rate Bar Chart
        const barMeta = <?= $chartBarMeta ?>;
        const barCtx = document.getElementById('completionBarChart').getContext('2d');
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: <?= $chartBarLabels ?>,
                datasets: [{
                    label: 'Completion %',
                    data: <?= $chartBarData ?>,
                    backgroundColor: <?= $chartBarColors ?>,
                    borderRadius: 6,
                    barPercentage: 0.48,
                    minBarLength: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const detail = barMeta[context.dataIndex] || '';
                                return ` ${context.raw}% — ${detail}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        grid: {
                            borderDash: [5, 5],
                            color: '#e2e8f0',
                            drawBorder: false
                        },
                        ticks: {
                            stepSize: 25,
                            callback: (val) => val + '%'
                        }
                    },
                    x: {
                        grid: { display: false, drawBorder: false },
                        ticks: { font: { weight: '700' } }
                    }
                }
            }
        });

        // 4. Weekly Progress & Study Hours Dual-Axis Trend Chart
        const trendCanvas = document.getElementById('trendChart');
        if (trendCanvas) {
            new Chart(trendCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: <?= $chartTrendDates ?>,
                    datasets: [
                        {
                            label: 'Overall Progress (%)',
                            data: <?= $chartTrendProgress ?>,
                            borderColor: '#6366f1',
                            backgroundColor: 'rgba(99, 102, 241, 0.12)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Study Hours Logged',
                            data: <?= $chartTrendHours ?>,
                            borderColor: '#10b981',
                            backgroundColor: '#10b981',
                            borderDash: [5, 4],
                            tension: 0.35,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 18,
                                font: { family: 'Inter, sans-serif', size: 12, weight: '600' }
                            }
                        }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            position: 'left',
                            beginAtZero: true,
                            max: 100,
                            grid: { color: '#e2e8f0', borderDash: [4, 4] },
                            ticks: { callback: (v) => v + '%' }
                        },
                        y1: {
                            type: 'linear',
                            position: 'right',
                            beginAtZero: true,
                            grid: { drawOnChartArea: false },
                            ticks: { callback: (v) => v + 'h' }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });
        }
    });
  </script>
</body>
</html>