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
// 2. Determine Selected Semester ID
// ---------------------------------------------------------
$selectedSemesterID = isset($_GET['semesterID']) ? (int)$_GET['semesterID'] : null;

// Validate if selectedSemesterID belongs to logged-in student
$validSelection = false;
if ($selectedSemesterID) {
    foreach ($semestersList as $sem) {
        if ($sem['semesterID'] == $selectedSemesterID) {
            $validSelection = true;
            break;
        }
    }
}

// Fallback to currently active semester or most recent semester if not specified/invalid
if (!$validSelection) {
    $today = date('Y-m-d');
    foreach ($semestersList as $sem) {
        if ($today >= $sem['startDate'] && $today <= $sem['endDate']) {
            $selectedSemesterID = $sem['semesterID'];
            $validSelection = true;
            break;
        }
    }
    if (!$validSelection && !empty($semestersList)) {
        $selectedSemesterID = $semestersList[0]['semesterID'];
    }
}

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
            
            // Check overdue
            if ($a['dueDate'] && strtotime($a['dueDate']) < strtotime(date('Y-m-d')) && $rawStatus !== 'Completed') {
                $overdue_count++;
            }

            // Status Distribution
            if ($rawStatus === 'Completed') {
                $status_counts['Completed']++;
                $assignmentCompletedCount++;
            } elseif ($rawStatus === 'Ongoing' || $rawStatus === 'In Progress') {
                $status_counts['In Progress']++;
            } else {
                $status_counts['Not Started']++;
            }
            
            // Priority Distribution
            $pri = $a['priorityLevel'];
            if (isset($priority_counts[$pri])) {
                $priority_counts[$pri]++;
            }

            // Subject Details aggregate
            if (isset($course_data[$a['courseID']])) {
                $course_data[$a['courseID']]['total_count']++;
                if ($rawStatus === 'Completed') {
                    $course_data[$a['courseID']]['completed_count']++;
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
            
            // Check overdue
            if ($e['examDate'] && strtotime($e['examDate']) < strtotime(date('Y-m-d')) && $rawStatus !== 'Completed') {
                $overdue_count++;
            }

            // Status Distribution
            if ($rawStatus === 'Completed') {
                $status_counts['Completed']++;
                $examCompletedCount++;
            } elseif ($rawStatus === 'Ongoing' || $rawStatus === 'In Progress') {
                $status_counts['In Progress']++;
            } else {
                $status_counts['Not Started']++;
            }
            
            // Map Difficulty Level to Priority Scale
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

            // Subject Details aggregate
            if (isset($course_data[$e['courseID']])) {
                $course_data[$e['courseID']]['total_count']++;
                if ($rawStatus === 'Completed') {
                    $course_data[$e['courseID']]['completed_count']++;
                }
            }
        }
    } catch (PDOException $e) {
        // Handle error gracefully
    }
}

// ---------------------------------------------------------
// 6. Aggregate Calculations for Top Cards & Charts
// ---------------------------------------------------------
$total_items = array_sum($status_counts);
$completed_tasks = $status_counts['Completed'];
$upcoming_tasks = $status_counts['In Progress'] + $status_counts['Not Started'];
$overall_progress = $total_items > 0 ? round(($completed_tasks / $total_items) * 100) : 0;

// Process completion rates for courses
$barChartLabels = [];
$barChartData = [];

foreach ($course_data as &$cd) {
    if ($cd['total_count'] > 0) {
        $cd['progress_percent'] = round(($cd['completed_count'] / $cd['total_count']) * 100);
    }
    $barChartLabels[] = $cd['code'];
    $barChartData[] = $cd['progress_percent'];
}
unset($cd);

// ---------------------------------------------------------
// 7. Save Weekly Historical Snapshot (Once per week per semester)
// ---------------------------------------------------------
if ($selectedSemesterID) {
    try {
        // Check if a snapshot has already been recorded in the current calendar week
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
            // Count Study Tasks
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
            
            // Total Study Hours = Completed Study Tasks * 2
            $totalStudyHours = $completedStudyTasks * 2;

            // Count SubTasks
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

            // Insert into AnalyticsHistory
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

            // Insert Course Level Analytics History
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

// Prepare Chart Data format
$chartStatusLabels = json_encode(array_keys($status_counts));
$chartStatusData = json_encode(array_values($status_counts));
$chartPriorityLabels = json_encode(array_keys($priority_counts));
$chartPriorityData = json_encode(array_values($priority_counts));
$chartBarLabels = json_encode($barChartLabels);
$chartBarData = json_encode($barChartData);
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
  <style>
    body { background-color: #fafafa; }
    .content-wrap { padding: 30px; }

    /* Analytics Header & Dropdown */
    .analytics-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
    .analytics-title-group h1 { font-size: 24px; font-weight: 700; color: #1e293b; margin: 0 0 4px 0; }
    .analytics-title-group p { font-size: 14px; color: #64748b; margin: 0; }
    .semester-selector-group { display: flex; align-items: center; gap: 10px; background: #ffffff; padding: 8px 14px; border: 1px solid #eaedf1; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
    .semester-selector-group label { font-size: 13px; font-weight: 600; color: #64748b; }
    .semester-select { border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 12px; font-size: 14px; font-weight: 600; color: #1e293b; background: #ffffff; cursor: pointer; outline: none; }
    .semester-select:focus { border-color: #3b82f6; }

    /* Top Stats Grid */
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px; }
    .stat-card {
        background: #ffffff;
        border-radius: 8px;
        padding: 20px 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border: 1px solid #eaedf1;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }
    .stat-left { display: flex; flex-direction: column; gap: 4px; }
    .stat-title-row { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #64748b; }
    .stat-icon { font-size: 18px; }
    .icon-blue { color: #3b82f6; }
    .icon-green { color: #10b981; }
    .icon-orange { color: #f97316; }
    .icon-red { color: #ef4444; }
    
    .stat-value { font-size: 26px; font-weight: 700; color: #1e293b; }
    .val-blue { color: #3b82f6; }

    /* Main Chart Grid */
    .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    .chart-card {
        background: #ffffff;
        border-radius: 8px;
        padding: 24px;
        border: 1px solid #eaedf1;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }
    .card-title { font-size: 15px; font-weight: 700; color: #1e293b; margin-bottom: 20px; }
    
    .pie-container { position: relative; height: 260px; width: 100%; display: flex; justify-content: center; }
    .bar-container { position: relative; height: 300px; width: 100%; }

    /* Subject Details & History Tables */
    .table-responsive { width: 100%; overflow-x: auto; margin-top: 10px; }
    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 12px 16px; border-bottom: 1px solid #eaedf1; color: #94a3b8; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    td { padding: 16px; border-bottom: 1px solid #eaedf1; font-size: 13px; color: #334155; font-weight: 500; }
    
    /* Progress Bars & Badges in Table */
    .td-progress { display: flex; align-items: center; gap: 12px; }
    .mini-progress-bar { width: 80px; height: 6px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
    .mini-progress-fill { height: 100%; background: #cbd5e1; transition: width 0.3s ease; }
    .mini-progress-text { font-size: 12px; color: #64748b; }
    
    .badge { padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; display: inline-block; text-align: center; min-width: 45px; }
    .badge-orange { background: #ffedd5; color: #ea580c; }
    .badge-green { background: #dcfce7; color: #16a34a; }

    @media (max-width: 1100px) {
        .stats-grid { grid-template-columns: 1fr 1fr; }
        .charts-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <?php include 'includes/sidebar.php'; ?>
  <main class="main-content">
    <?php include 'includes/header.php'; ?>

    <div class="content-wrap">
      
      <!-- Analytics Header with Semester Dropdown Selector -->
      <div class="analytics-header">
        <div class="analytics-title-group">
          <h1>Analytics</h1>
          <p>Track your academic progress and history by semester.</p>
        </div>
        <form method="GET" action="analytics.php" style="margin: 0;">
          <div class="semester-selector-group">
            <label for="semesterID">Semester:</label>
            <select name="semesterID" id="semesterID" class="semester-select" onchange="this.form.submit()">
              <?php if (empty($semestersList)): ?>
                <option value="">No Semesters Available</option>
              <?php else: ?>
                <?php foreach ($semestersList as $sem): ?>
                  <option value="<?= $sem['semesterID'] ?>" <?= $sem['semesterID'] == $selectedSemesterID ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sem['semesterName']) ?>
                  </option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
        </form>
      </div>

      <!-- Top Stats Row -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-left">
            <div class="stat-title-row"><i class="ph ph-trend-up stat-icon icon-blue"></i> Overall Progress</div>
          </div>
          <div class="stat-value val-blue"><?php echo $overall_progress; ?>%</div>
        </div>

        <div class="stat-card">
          <div class="stat-left">
            <div class="stat-title-row"><i class="ph ph-check-circle stat-icon icon-green"></i> Completed Items</div>
          </div>
          <div class="stat-value"><?php echo $completed_tasks; ?></div>
        </div>

        <div class="stat-card">
          <div class="stat-left">
            <div class="stat-title-row"><i class="ph ph-clock stat-icon icon-orange"></i> Upcoming Items</div>
          </div>
          <div class="stat-value"><?php echo $upcoming_tasks; ?></div>
        </div>

        <div class="stat-card">
          <div class="stat-left">
            <div class="stat-title-row"><i class="ph ph-warning-circle stat-icon icon-red"></i> Overdue Items</div>
          </div>
          <div class="stat-value"><?php echo $overdue_count; ?></div>
        </div>
      </div>

      <!-- Pie Charts Row -->
      <div class="charts-grid">
        <div class="chart-card">
          <div class="card-title">Assessment & Exam Status</div>
          <div class="pie-container">
            <canvas id="statusChart"></canvas>
          </div>
        </div>

        <div class="chart-card">
          <div class="card-title">Priority & Difficulty Distribution</div>
          <div class="pie-container">
            <canvas id="priorityChart"></canvas>
          </div>
        </div>
      </div>

      <!-- Bar Chart Row -->
      <div class="chart-card" style="margin-bottom: 20px;">
        <div class="card-title">Subject Completion Rate (%)</div>
        <div class="bar-container">
          <canvas id="completionBarChart"></canvas>
        </div>
      </div>

      <!-- Subject Details Table Row -->
      <div class="chart-card" style="margin-bottom: 20px;">
        <div class="card-title">Subject Details</div>
        <div class="table-responsive">
          <table>
            <thead>
              <tr>
                <th>Course Code</th>
                <th>Course Name</th>
                <th>Progress</th>
                <th>Completion Rate</th>
              </tr>
            </thead>
            <tbody>
              <?php if(empty($course_data)): ?>
              <tr>
                  <td colspan="4" style="text-align:center; color:#94a3b8;">No course data found for this semester.</td>
              </tr>
              <?php else: ?>
                  <?php foreach($course_data as $course): ?>
                  <tr>
                    <td style="font-weight: 700;"><?= htmlspecialchars($course['code']) ?></td>
                    <td><?= htmlspecialchars($course['name']) ?></td>
                    <td>
                      <div class="td-progress">
                        <div class="mini-progress-bar">
                          <?php 
                            $w = $course['total_count'] > 0 ? ($course['completed_count'] / $course['total_count']) * 100 : 0; 
                          ?>
                          <div class="mini-progress-fill" style="width: <?= $w ?>%;"></div>
                        </div>
                        <span class="mini-progress-text"><?= $course['completed_count'] ?>/<?= $course['total_count'] ?></span>
                      </div>
                    </td>
                    <td>
                      <?php 
                         $rateClass = $course['progress_percent'] == 100 ? 'badge-green' : 'badge-orange';
                         $rateText = $course['progress_percent'] . '%';
                      ?>
                      <span class="badge <?= $rateClass ?>"><?= $rateText ?></span>
                    </td>
                  </tr>
                  <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Analytics History Table Row -->
      <div class="chart-card">
        <div class="card-title">Analytics History</div>
        <div class="table-responsive">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Semester</th>
                <th>Study Hours</th>
                <th>Completed Tasks</th>
                <th>Overall Progress</th>
                <th>Completed Items</th>
                <th>Upcoming Items</th>
                <th>Overdue Items</th>
              </tr>
            </thead>
            <tbody>
              <?php if(empty($analyticsHistoryList)): ?>
              <tr>
                  <td colspan="8" style="text-align:center; color:#94a3b8;">No analytics history available for this semester.</td>
              </tr>
              <?php else: ?>
                  <?php foreach($analyticsHistoryList as $history): ?>
                  <tr>
                    <td style="font-weight: 700;"><?= date('d M Y', strtotime($history['recordDate'])) ?></td>
                    <td><?= htmlspecialchars($history['semesterName']) ?></td>
                    <td><?= $history['totalStudyHours'] ?> hours</td>
                    <td><?= $history['completedStudyTasks'] ?> / <?= $history['totalStudyTasks'] ?></td>
                    <td>
                      <span class="badge <?= $history['overallProgress'] == 100 ? 'badge-green' : 'badge-orange' ?>">
                        <?= $history['overallProgress'] ?>%
                      </span>
                    </td>
                    <td><?= $history['completedItems'] ?></td>
                    <td><?= $history['upcomingItems'] ?></td>
                    <td><?= $history['overdueItems'] ?></td>
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
        
        const pieOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        padding: 20,
                        font: { family: 'Inter, sans-serif', size: 12 }
                    }
                }
            }
        };

        // 1. Status Pie Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'pie',
            data: {
                labels: <?= $chartStatusLabels ?>,
                datasets: [{
                    data: <?= $chartStatusData ?>,
                    backgroundColor: ['#10b981', '#3b82f6', '#64748b'], 
                    borderWidth: 1,
                    borderColor: '#ffffff'
                }]
            },
            options: pieOptions
        });

        // 2. Priority/Difficulty Pie Chart
        const priorityCtx = document.getElementById('priorityChart').getContext('2d');
        new Chart(priorityCtx, {
            type: 'pie',
            data: {
                labels: <?= $chartPriorityLabels ?>,
                datasets: [{
                    data: <?= $chartPriorityData ?>,
                    backgroundColor: ['#ef4444', '#f59e0b', '#10b981'],
                    borderWidth: 1,
                    borderColor: '#ffffff'
                }]
            },
            options: pieOptions
        });

        // 3. Subject Completion Rate Bar Chart
        const barCtx = document.getElementById('completionBarChart').getContext('2d');
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: <?= $chartBarLabels ?>,
                datasets: [{
                    label: 'Completion %',
                    data: <?= $chartBarData ?>,
                    backgroundColor: '#cbd5e1', 
                    borderRadius: 4,
                    barPercentage: 0.5
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
                                return context.raw + '% completed';
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
                        ticks: { stepSize: 25 }
                    },
                    x: {
                        grid: { display: false, drawBorder: false }
                    }
                }
            }
        });

    });
  </script>
</body>
</html>