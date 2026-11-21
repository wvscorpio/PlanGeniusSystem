<?php
include 'dbConn.php';
session_start();
require_once __DIR__ . '/schedule_Manager.php';
$errors = [];
$message = '';

$studentID = trim($_SESSION['studentID'] ?? '');

// Ensure student is logged in
if ($studentID === '') {
    header('Location: login.php');
    exit;
}

function calculatePriority($targetDate) {
    $today = new DateTime('today');
    $target = new DateTime($targetDate);
    
    // Past due assignments require urgent attention, set to High priority
    if ($target < $today) {
        return 'High';
    }
    
    $daysRemaining = $today->diff($target)->days;
    
    if ($daysRemaining <= 3) { 
        return 'High'; 
    } elseif ($daysRemaining <= 7) { 
        return 'Medium'; 
    } else { 
        return 'Low'; 
    }
}

// Helper to save or replace subtasks for an assignment
function parseAndSaveSubtasks($conn, $assignmentID, $subTasksInput) {
    // Clear existing subtasks
    $delStmt = $conn->prepare('DELETE FROM "SubTask" WHERE "assignmentID" = :assignmentID');
    $delStmt->execute([':assignmentID' => $assignmentID]);

    $rawList = [];
    if (is_array($subTasksInput)) {
        $rawList = $subTasksInput;
    } else if (is_string($subTasksInput)) {
        $decoded = json_decode($subTasksInput, true);
        if (is_array($decoded)) {
            $rawList = $decoded;
        } else {
            $rawList = array_filter(array_map('trim', explode("\n", $subTasksInput)));
        }
    }

    $insertStmt = $conn->prepare('
        INSERT INTO "SubTask" ("assignmentID", "subTaskDescription", "isCompleted", "orderNo")
        VALUES (:assignmentID, :subTaskDescription, :isCompleted, :orderNo)
    ');

    $orderNo = 1;
    foreach ($rawList as $item) {
        $desc = '';
        $isCompleted = false;

        if (is_array($item)) {
            $desc = $item['subTaskDescription'] ?? $item['text'] ?? '';
            $isCompleted = !empty($item['isCompleted']) || !empty($item['checked']);
        } else if (is_string($item)) {
            $itemStr = trim($item);
            if (empty($itemStr)) continue;

            if (preg_match('/^\[(x|X)\]\s*(.*)/', $itemStr, $matches)) {
                $isCompleted = true;
                $desc = $matches[2];
            } elseif (preg_match('/^\[\s*\]\s*(.*)/', $itemStr, $matches)) {
                $isCompleted = false;
                $desc = $matches[1];
            } else {
                $isCompleted = false;
                $desc = $itemStr;
            }
        }

        // Clean up leading dashes or bullet symbols
        $desc = preg_replace('/^[\-\*•\>]\s*/', '', trim($desc));
        if ($desc === '') continue;

        $insertStmt->execute([
            ':assignmentID' => $assignmentID,
            ':subTaskDescription' => $desc,
            ':isCompleted' => $isCompleted ? 'true' : 'false',
            ':orderNo' => $orderNo++
        ]);
    }
}

// Handle JSON requests (AJAX API Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
        exit;
    }

    $action = trim($input['action'] ?? '');

    // ACTION: ADD ASSIGNMENT
    if ($action === 'addAssignment') {
        $assignmentTitle = trim($input['assignmentTitle'] ?? '');
        $courseID = intval($input['courseID'] ?? 0);
        $dueDate = trim($input['dueDate'] ?? '');
        $dueTime = trim($input['dueTime'] ?? '');
        $taskStatus = trim($input['taskStatus'] ?? 'Not Started');
        $assignmentType = trim($input['assignmentType'] ?? 'Assignment');
        $subTasks = $input['subTasks'] ?? '';
        $pdfFile = trim($input['pdfFile'] ?? '');
        $pdfHash = null;

        $filePath = ($pdfFile !== '') ? __DIR__ . '/uploads/assignments/' . $pdfFile : null;

        // Cleanup helper to delete orphaned PDF on validation failure
        $cleanupPdf = function() use ($filePath) {
            if ($filePath && file_exists($filePath)) {
                @unlink($filePath);
            }
        };

        if ($assignmentTitle === '' || !$courseID || $dueDate === '') {
            echo json_encode(['success' => false, 'error' => 'Title, course, and due date are required']);
            exit;
        }

        // --- DUE DATE VALIDATION ---
        try {
            $currentDate = new DateTime('today');
            $assignmentDate = new DateTime($dueDate);
            if ($assignmentDate < $currentDate) {
                echo json_encode(['success' => false, 'error' => 'The assignment due date has already passed. Please upload a valid upcoming assignment.']);
                exit;
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Invalid date format.']);
            exit;
        }

        try {
            $courseCheck = $conn->prepare('
                SELECT 1 FROM "Course" c 
                JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" 
                WHERE c."courseID" = :courseID AND s."studentID" = :studentID
            ');
            $courseCheck->execute([':courseID' => $courseID, ':studentID' => $studentID]);
            if (!$courseCheck->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Course not found or unauthorized']);
                exit;
            }

            if ($pdfFile !== '') {
                $filePath = __DIR__ . '/uploads/assignments/' . $pdfFile; 
                if (file_exists($filePath)) {
                    $pdfHash = hash_file('sha256', $filePath);
                    
                    $hashCheck = $conn->prepare('
                        SELECT 1 FROM "Assignment" a
                        JOIN "Course" c ON a."courseID" = c."courseID"
                        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                        WHERE s."studentID" = :studentID AND a."pdfHash" = :pdfHash
                        LIMIT 1
                    ');
                    $hashCheck->execute([':studentID' => $studentID, ':pdfHash' => $pdfHash]);
                    if ($hashCheck->fetch()) {
                        echo json_encode(['success' => false, 'error' => 'This assessment document has already been uploaded.']);
                        exit;
                    }
                }
            }

            // DUPLICATE RECORD VALIDATION
            $dupCheck = $conn->prepare('
                SELECT 1 FROM "Assignment" a
                JOIN "Course" c ON a."courseID" = c."courseID"
                JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                WHERE s."studentID" = :studentID
                  AND a."courseID" = :courseID
                  AND LOWER(a."assignmentTitle") = LOWER(:assignmentTitle)
                  AND LOWER(a."assignmentType") = LOWER(:assignmentType)
                LIMIT 1
            ');
            $dupCheck->execute([
                ':studentID' => $studentID,
                ':courseID' => $courseID,
                ':assignmentTitle' => $assignmentTitle,
                ':assignmentType' => $assignmentType
            ]);
            
            if ($dupCheck->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Duplicate detected: An assessment with this title and type already exists for this course.']);
                exit;
            }

            $priorityLevel = calculatePriority($dueDate);

            $stmt = $conn->prepare('
                INSERT INTO "Assignment" (
                    "courseID", "assignmentTitle", "dueDate", "dueTime", "taskStatus", 
                    "priorityLevel", "pdfFile", "pdfHash", "assignmentType", "studentID"
                )
                VALUES (
                    :courseID, :assignmentTitle, :dueDate, :dueTime, :taskStatus, 
                    :priorityLevel, :pdfFile, :pdfHash, :assignmentType, :studentID
                )
                RETURNING "assignmentID"
            ');

            $stmt->execute([
                ':courseID'        => $courseID,
                ':assignmentTitle' => $assignmentTitle,
                ':dueDate'         => $dueDate,
                ':dueTime'         => $dueTime !== '' ? $dueTime : null,
                ':taskStatus'      => $taskStatus,
                ':priorityLevel'   => $priorityLevel,
                ':pdfFile'         => $pdfFile === '' ? null : $pdfFile,
                ':pdfHash'         => $pdfHash,
                ':assignmentType'  => $assignmentType,
                ':studentID'       => $studentID
            ]);

            $newAssignmentID = $stmt->fetchColumn();

            // Save Subtasks into SubTask table
            parseAndSaveSubtasks($conn, $newAssignmentID, $subTasks);

            // Generate schedule + notifications for this assignment
            try {
                $scheduleManager = new ScheduleManager($conn);
                $semStmt = $conn->prepare('SELECT s."startDate", s."endDate" FROM "Course" c JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" WHERE c."courseID" = :courseID AND s."studentID" = :studentID LIMIT 1');
                $semStmt->execute([':courseID' => $courseID, ':studentID' => $studentID]);
                $sem = $semStmt->fetch(PDO::FETCH_ASSOC);
                $semStart = $sem['startDate'] ?? date('Y-m-d');
                $semEnd = $sem['endDate'] ?? date('Y-m-d');

                $subStmt = $conn->prepare('SELECT "subTaskID", "subTaskDescription", "isCompleted" FROM "SubTask" WHERE "assignmentID" = :assignmentID ORDER BY "orderNo" ASC');
                $subStmt->execute([':assignmentID' => $newAssignmentID]);
                $subtasks = [];
                foreach ($subStmt->fetchAll(PDO::FETCH_ASSOC) as $st) {
                    $subtasks[] = [
                        'subTaskID' => $st['subTaskID'],
                        'subTaskDescription' => $st['subTaskDescription'],
                        'isCompleted' => ($st['isCompleted'] === true || $st['isCompleted'] === 't' || $st['isCompleted'] == 1)
                    ];
                }

                $scheduleManager->syncAssignmentSchedule(
                    $studentID, 
                    $newAssignmentID, 
                    $dueDate, 
                    $subtasks, 
                    $semStart, 
                    $semEnd, 
                    $assignmentType
                );
            } catch (Exception $e) {
                // continue silently
            }

            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to add assessment.']);
            exit;
        }
    }

    // ACTION: UPDATE ASSIGNMENT
    if ($action === 'updateAssignment') {
        $assignmentID = intval($input['assignmentID'] ?? 0);
        $assignmentTitle = trim($input['assignmentTitle'] ?? '');
        $dueDate = trim($input['dueDate'] ?? '');
        $dueTime = trim($input['dueTime'] ?? '');
        $taskStatus = trim($input['taskStatus'] ?? 'Not Started');
        $assignmentType = trim($input['assignmentType'] ?? 'Assignment');
        $subTasks = $input['subTasks'] ?? '';

        if (!$assignmentID || $assignmentTitle === '' || $dueDate === '') {
            echo json_encode(['success' => false, 'error' => 'Title and due date are required']);
            exit;
        }

        try {
            $check = $conn->prepare('
                SELECT 1 FROM "Assignment" a
                JOIN "Course" c ON a."courseID" = c."courseID"
                JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                WHERE a."assignmentID" = :assignmentID AND s."studentID" = :studentID
            ');
            $check->execute([':assignmentID' => $assignmentID, ':studentID' => $studentID]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Assignment not found or unauthorized']);
                exit;
            }

            $priorityLevel = calculatePriority($dueDate);

            $stmt = $conn->prepare('
                UPDATE "Assignment" 
                SET "assignmentTitle" = :assignmentTitle, "dueDate" = :dueDate, "dueTime" = :dueTime, "taskStatus" = :taskStatus, "priorityLevel" = :priorityLevel, "assignmentType" = :assignmentType
                WHERE "assignmentID" = :assignmentID
            ');
            $stmt->execute([
                ':assignmentTitle' => $assignmentTitle,
                ':dueDate' => $dueDate,
                ':dueTime' => $dueTime !== '' ? $dueTime : null,
                ':taskStatus' => $taskStatus,
                ':priorityLevel' => $priorityLevel,
                ':assignmentType' => $assignmentType,
                ':assignmentID' => $assignmentID
            ]);

            // Save Subtasks into SubTask table
            parseAndSaveSubtasks($conn, $assignmentID, $subTasks);

            // Re-sync schedule + notifications
            try {
                $scheduleManager = new ScheduleManager($conn);
                $semStmt = $conn->prepare('SELECT s."startDate", s."endDate" FROM "Course" c JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" WHERE c."courseID" = (SELECT "courseID" FROM "Assignment" WHERE "assignmentID" = :assignmentID) AND s."studentID" = :studentID LIMIT 1');
                $semStmt->execute([':assignmentID' => $assignmentID, ':studentID' => $studentID]);
                $sem = $semStmt->fetch(PDO::FETCH_ASSOC);
                $semStart = $sem['startDate'] ?? date('Y-m-d');
                $semEnd = $sem['endDate'] ?? date('Y-m-d');

                $subStmt = $conn->prepare('SELECT "subTaskID", "subTaskDescription", "isCompleted" FROM "SubTask" WHERE "assignmentID" = :assignmentID ORDER BY "orderNo" ASC');
                $subStmt->execute([':assignmentID' => $assignmentID]);
                $subtasks = [];
                foreach ($subStmt->fetchAll(PDO::FETCH_ASSOC) as $st) {
                    $subtasks[] = [
                        'subTaskID' => $st['subTaskID'],
                        'subTaskDescription' => $st['subTaskDescription'],
                        'isCompleted' => ($st['isCompleted'] === true || $st['isCompleted'] === 't' || $st['isCompleted'] == 1)
                    ];
                }

                if ($taskStatus === 'Completed') {
                    $delS = $conn->prepare('DELETE FROM "Schedule" WHERE "referenceID" = :ref AND "referenceType" = \'Assignment\'');
                    $delS->execute([':ref' => $assignmentID]);
                } else {
                    $scheduleManager->syncAssignmentSchedule($studentID, $assignmentID, $dueDate, $subtasks, $semStart, $semEnd);
                }
            } catch (Exception $e) {
                // ignore
            }

            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to update assignment']);
            exit;
        }
    }

    // ACTION: UPDATE SUBTASK PROGRESS & TASK STATUS
    if ($action === 'updateSubtaskProgress') {
        $assignmentID = intval($input['assignmentID'] ?? 0);
        $subTasks = $input['subTasks'] ?? [];
        $taskStatus = trim($input['taskStatus'] ?? 'Not Started');

        try {
            $check = $conn->prepare('
                SELECT 1 FROM "Assignment" a
                JOIN "Course" c ON a."courseID" = c."courseID"
                JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                WHERE a."assignmentID" = :assignmentID AND s."studentID" = :studentID
            ');
            $check->execute([':assignmentID' => $assignmentID, ':studentID' => $studentID]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                exit;
            }

            $stmt = $conn->prepare('UPDATE "Assignment" SET "taskStatus" = :taskStatus WHERE "assignmentID" = :assignmentID');
            $stmt->execute([':taskStatus' => $taskStatus, ':assignmentID' => $assignmentID]);

            // Update subtasks
            parseAndSaveSubtasks($conn, $assignmentID, $subTasks);

            // Re-sync schedule for this assignment
            try {
                $scheduleManager = new ScheduleManager($conn);
                $aStmt = $conn->prepare('SELECT "dueDate", "courseID" FROM "Assignment" WHERE "assignmentID" = :assignmentID');
                $aStmt->execute([':assignmentID' => $assignmentID]);
                $aRow = $aStmt->fetch(PDO::FETCH_ASSOC);
                if ($aRow) {
                    $dueDate = $aRow['dueDate'];
                    $courseID = $aRow['courseID'];
                    $semStmt = $conn->prepare('SELECT s."startDate", s."endDate" FROM "Course" c JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" WHERE c."courseID" = :courseID AND s."studentID" = :studentID LIMIT 1');
                    $semStmt->execute([':courseID' => $courseID, ':studentID' => $studentID]);
                    $sem = $semStmt->fetch(PDO::FETCH_ASSOC);
                    $semStart = $sem['startDate'] ?? date('Y-m-d');
                    $semEnd = $sem['endDate'] ?? date('Y-m-d');

                    $subStmt = $conn->prepare('SELECT "subTaskID", "subTaskDescription", "isCompleted" FROM "SubTask" WHERE "assignmentID" = :assignmentID ORDER BY "orderNo" ASC');
                    $subStmt->execute([':assignmentID' => $assignmentID]);
                    $subtasks = [];
                    foreach ($subStmt->fetchAll(PDO::FETCH_ASSOC) as $st) {
                        $subtasks[] = [
                            'subTaskID' => $st['subTaskID'],
                            'subTaskDescription' => $st['subTaskDescription'],
                            'isCompleted' => ($st['isCompleted'] === true || $st['isCompleted'] === 't' || $st['isCompleted'] == 1)
                        ];
                    }

                    if ($taskStatus === 'Completed') {
                        $delS = $conn->prepare('DELETE FROM "Schedule" WHERE "referenceID" = :ref AND "referenceType" = \'Assignment\'');
                        $delS->execute([':ref' => $assignmentID]);
                    } else {
                        $scheduleManager->syncAssignmentSchedule($studentID, $assignmentID, $dueDate, $subtasks, $semStart, $semEnd);
                    }
                }
            } catch (Exception $e) {
                // ignore
            }

            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to save progress']);
            exit;
        }
    }

    // ACTION: DELETE ASSIGNMENT
    if ($action === 'deleteAssignment') {
        $assignmentID = intval($input['assignmentID'] ?? 0);
        try {
            $check = $conn->prepare('
                SELECT 1 FROM "Assignment" a
                JOIN "Course" c ON a."courseID" = c."courseID"
                JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                WHERE a."assignmentID" = :assignmentID AND s."studentID" = :studentID
            ');
            $check->execute([':assignmentID' => $assignmentID, ':studentID' => $studentID]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Assignment not found or unauthorized']);
                exit;
            }

            $delAssignment = $conn->prepare('DELETE FROM "Assignment" WHERE "assignmentID" = :assignmentID');
            $delAssignment->execute([':assignmentID' => $assignmentID]);
            
            try {
                $delS = $conn->prepare('DELETE FROM "Schedule" WHERE "referenceID" = :ref AND "referenceType" = \'Assignment\'');
                $delS->execute([':ref' => $assignmentID]);
                $delN = $conn->prepare('DELETE FROM "Notification" WHERE "studentID" = :studentID AND "notificationMessage" LIKE :pat');
                $delN->execute([':studentID' => $studentID, ':pat' => '%Assignment%']);
            } catch (Exception $e) {
                // ignore
            }
            
            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to delete assignment']);
            exit;
        }
    }
}

// Fetch Courses
$courses = [];
try {
    $stmt = $conn->prepare('
        SELECT DISTINCT c."courseID", c."courseCode", c."courseName", s."semesterName"
        FROM "Course" c
        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE s."studentID" = :studentID
          AND CURRENT_DATE >= s."startDate" 
          AND CURRENT_DATE <= s."endDate"
        ORDER BY c."courseCode"
    ');
    $stmt->execute([':studentID' => $studentID]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = 'Failed to fetch courses.';
}

// Fetch Assignments and Categorize
$assignments = [];
$activeGrouped = [];
$completedGrouped = [];

try {
    $stmt = $conn->prepare('
        SELECT a.*, c."courseCode", c."courseName"
        FROM "Assignment" a
        JOIN "Course" c ON a."courseID" = c."courseID"
        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE s."studentID" = :studentID
        AND CURRENT_DATE >= s."startDate" 
        AND CURRENT_DATE <= s."endDate"
        ORDER BY c."courseCode", a."dueDate" ASC
    ');
    $stmt->execute([':studentID' => $studentID]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Subtasks for all assignments
    $assignmentIDs = array_column($assignments, 'assignmentID');
    $subtasksMap = [];

    if (!empty($assignmentIDs)) {
        $inClause = implode(',', array_fill(0, count($assignmentIDs), '?'));
        $subStmt = $conn->prepare("
            SELECT \"subTaskID\", \"assignmentID\", \"subTaskDescription\", \"isCompleted\", \"orderNo\"
            FROM \"SubTask\"
            WHERE \"assignmentID\" IN ($inClause)
            ORDER BY \"orderNo\" ASC
        ");
        $subStmt->execute($assignmentIDs);
        $allSubtasks = $subStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allSubtasks as $st) {
            $st['isCompleted'] = ($st['isCompleted'] === true || $st['isCompleted'] === 't' || $st['isCompleted'] == 1);
            $subtasksMap[$st['assignmentID']][] = $st;
        }
    }

    $today = new DateTime('today');
    
    foreach ($assignments as &$a) {
        $a['subTasks'] = $subtasksMap[$a['assignmentID']] ?? [];

        $dueDateObj = new DateTime($a['dueDate']);
        
        // Dynamic status calculation
        $calculatedStatus = $a['taskStatus'];
        if ($a['taskStatus'] !== 'Completed' && $dueDateObj < $today) {
            $calculatedStatus = 'Past Due';
        }

        // Dynamic priority calculation based on current date
        $calculatedPriority = calculatePriority($a['dueDate']);

        // Persist priority or status updates to database if they changed over time
        if ($a['priorityLevel'] !== $calculatedPriority || $a['taskStatus'] !== $calculatedStatus) {
            $a['priorityLevel'] = $calculatedPriority;
            $a['taskStatus'] = $calculatedStatus;

            $updStmt = $conn->prepare('
                UPDATE "Assignment" 
                SET "priorityLevel" = :priorityLevel, "taskStatus" = :taskStatus 
                WHERE "assignmentID" = :assignmentID
            ');
            $updStmt->execute([
                ':priorityLevel' => $calculatedPriority,
                ':taskStatus'    => $calculatedStatus,
                ':assignmentID'  => $a['assignmentID']
            ]);
        }

        $courseHeader = $a['courseCode'] . ' - ' . $a['courseName'];
        
        if ($a['taskStatus'] === 'Completed') {
            if (!isset($completedGrouped[$courseHeader])) $completedGrouped[$courseHeader] = [];
            $completedGrouped[$courseHeader][] = $a;
        } else {
            if (!isset($activeGrouped[$courseHeader])) $activeGrouped[$courseHeader] = [];
            $activeGrouped[$courseHeader][] = $a;
        }
    }
    unset($a);
} catch (PDOException $e) {
    $errors[] = 'Failed to fetch assignments.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignments - PlanGenius</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body>
    <?php 
    $current_page = 'assignments';
    include 'includes/sidebar.php'; 
    ?>

    <main class="main-content">
        <?php include 'includes/header.php'; ?>

        <?php
            // Calculate Summary Metrics for Top KPI Bar
            $kpiPastDue = 0;
            $kpiDueSoon = 0;
            $kpiOngoing = 0;
            $kpiCompleted = 0;
            $todayDt = new DateTime('today');

            foreach ($assignments as $item) {
                if ($item['taskStatus'] === 'Completed') {
                    $kpiCompleted++;
                } else {
                    if ($item['taskStatus'] === 'Past Due') $kpiPastDue++;
                    if ($item['taskStatus'] === 'Ongoing') $kpiOngoing++;
                    $dObj = new DateTime($item['dueDate']);
                    if ($dObj >= $todayDt && $todayDt->diff($dObj)->days <= 7) {
                        $kpiDueSoon++;
                    }
                }
            }
        ?>

        <div class="dashboard-container">
            <div class="page-header flex-between">
                <div>
                    <h1 class="page-title">Assignments Management</h1>
                    <p class="page-subtitle">Manage your coursework and track progress</p>
                </div>
                <button class="btn btn-primary" onclick="openAddAssessmentModal()">
                    <i class="ph ph-plus"></i> Add Assignment
                </button>
            </div>

            <?php if (count($assignments) > 0): ?>
            <!-- TOP URGENCY SUMMARY CARDS -->
            <div class="summary-grid">
                <div class="summary-card urgent" onclick="triggerFilterByName('Past Due')">
                    <div class="summary-icon"><i class="ph-fill ph-warning-circle"></i></div>
                    <div>
                        <div class="summary-value"><?= $kpiPastDue ?></div>
                        <div class="summary-label">Past Due</div>
                    </div>
                </div>
                <div class="summary-card soon" onclick="triggerFilterByName('High Priority')">
                    <div class="summary-icon"><i class="ph-fill ph-clock-countdown"></i></div>
                    <div>
                        <div class="summary-value"><?= $kpiDueSoon ?></div>
                        <div class="summary-label">Due Within 7 Days</div>
                    </div>
                </div>
                <div class="summary-card ongoing" onclick="triggerFilterByName('Ongoing')">
                    <div class="summary-icon"><i class="ph-fill ph-spinner-gap"></i></div>
                    <div>
                        <div class="summary-value"><?= $kpiOngoing ?></div>
                        <div class="summary-label">In Progress</div>
                    </div>
                </div>
                <div class="summary-card done" onclick="triggerFilterByName('Completed')">
                    <div class="summary-icon"><i class="ph-fill ph-check-circle"></i></div>
                    <div>
                        <div class="summary-value"><?= $kpiCompleted ?></div>
                        <div class="summary-label">Completed</div>
                    </div>
                </div>
            </div>

            <!-- ORGANIZED SEARCH & GROUPED FILTERS -->
            <div class="filter-toolbar-card">
                <div class="search-row">
                    <div class="search-input-wrap">
                        <i class="ph ph-magnifying-glass"></i>
                        <input type="text" id="searchInput" class="form-control" placeholder="Search by course code, course name, or assignment title..." oninput="handleSearch(event)">
                    </div>
                    <button type="button" class="btn-reset-filters" id="resetFiltersBtn" onclick="resetAllFilters()">
                        <i class="ph-bold ph-arrow-counter-clockwise"></i> Reset Filters
                    </button>
                </div>

                <div class="filter-groups-row">
                    <div class="filter-group" id="statusFilterGroup">
                        <span class="filter-group-label">Status:</span>
                        <button class="filter-pill status-pill active" data-status="All" onclick="setStatusFilter('All', this)">All (<?= count($assignments) ?>)</button>
                        <button class="filter-pill status-pill" data-status="Not Started" onclick="setStatusFilter('Not Started', this)">Not Started</button>
                        <button class="filter-pill status-pill" data-status="Ongoing" onclick="setStatusFilter('Ongoing', this)">Ongoing (<?= $kpiOngoing ?>)</button>
                        <button class="filter-pill status-pill" data-status="Completed" onclick="setStatusFilter('Completed', this)">Completed (<?= $kpiCompleted ?>)</button>
                        <button class="filter-pill status-pill" data-status="Past Due" onclick="setStatusFilter('Past Due', this)">Past Due (<?= $kpiPastDue ?>)</button>
                    </div>

                    <div class="filter-divider"></div>

                    <div class="filter-group" id="priorityFilterGroup">
                        <span class="filter-group-label">Priority:</span>
                        <button class="filter-pill priority-pill active" data-priority="All" onclick="setPriorityFilter('All', this)">Any</button>
                        <button class="filter-pill priority-pill" data-priority="High" onclick="setPriorityFilter('High', this)">High</button>
                        <button class="filter-pill priority-pill" data-priority="Medium" onclick="setPriorityFilter('Medium', this)">Medium</button>
                        <button class="filter-pill priority-pill" data-priority="Low" onclick="setPriorityFilter('Low', this)">Low</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="empty-state-card" id="emptyState" style="<?= count($assignments) > 0 ? 'display:none' : '' ?>">
                <i class="ph ph-file-dashed" style="font-size: 48px; color: #cbd5e1; margin-bottom: 16px; display: block;"></i>
                <span id="emptyStateMessage">No assignments found.</span>
            </div>

            <?php
                // Build unified per-course folder data with overall progress
                $courseFolders = [];
                foreach ($assignments as $a) {
                    $cKey = $a['courseCode'] . ' - ' . $a['courseName'];
                    if (!isset($courseFolders[$cKey])) {
                        $courseFolders[$cKey] = [
                            'courseCode'   => $a['courseCode'],
                            'courseName'   => $a['courseName'],
                            'items'        => [],
                            'activeCount'  => 0,
                            'doneCount'    => 0,
                            'pastDueCount' => 0,
                            'nextDue'      => null,
                            'unitsDone'    => 0,
                            'unitsTotal'   => 0
                        ];
                    }
                    $courseFolders[$cKey]['items'][] = $a;

                    $subs = $a['subTasks'] ?? [];
                    if (count($subs) > 0) {
                        $courseFolders[$cKey]['unitsTotal'] += count($subs);
                        foreach ($subs as $st) {
                            if ($st['isCompleted']) $courseFolders[$cKey]['unitsDone']++;
                        }
                    } else {
                        $courseFolders[$cKey]['unitsTotal'] += 1;
                        if ($a['taskStatus'] === 'Completed') $courseFolders[$cKey]['unitsDone'] += 1;
                    }

                    if ($a['taskStatus'] === 'Completed') {
                        $courseFolders[$cKey]['doneCount']++;
                    } else {
                        $courseFolders[$cKey]['activeCount']++;
                        if ($a['taskStatus'] === 'Past Due') $courseFolders[$cKey]['pastDueCount']++;
                        if ($courseFolders[$cKey]['nextDue'] === null) {
                            $courseFolders[$cKey]['nextDue'] = $a['dueDate'];
                        }
                    }
                }
            ?>

            <?php if (!empty($courseFolders)): ?>
            <div class="folder-section-heading">
                <span><i class="ph-fill ph-folders"></i> Course Folders (Click a folder to view assignments)</span>
                <span id="folderSelectionHint" style="font-size: 12px; font-weight: 500; text-transform: none; letter-spacing: 0;">Select any course card below</span>
            </div>

            <!-- COURSE FOLDER CARDS GRID -->
            <div class="course-folders-grid" id="courseFoldersGrid">
                <?php $themeIdx = 0; ?>
                <?php foreach ($courseFolders as $cKey => $folder): ?>
                    <?php
                        $slug = 'folder-' . preg_replace('/[^a-zA-Z0-9]/', '-', $folder['courseCode']);
                        $pct = $folder['unitsTotal'] > 0 ? round(($folder['unitsDone'] / $folder['unitsTotal']) * 100) : 0;
                        $themeClass = 'folder-theme-' . ($themeIdx % 6);
                        $themeIdx++;
                    ?>
                    <div class="course-folder-card <?= $themeClass ?>"
                         id="card-<?= $slug ?>"
                         data-folder-slug="<?= $slug ?>"
                         data-course-key="<?= htmlspecialchars(strtolower($cKey)) ?>"
                         onclick="selectCourseFolder('<?= $slug ?>')">
                        
                        <i class="ph-fill ph-laptop folder-watermark-icon"></i>

                        <div>
                            <div class="folder-card-top">
                                <span class="folder-code-badge"><?= htmlspecialchars($folder['courseCode']) ?></span>
                                <span class="folder-card-action" title="Open folder">
                                    <i class="ph-bold ph-caret-down"></i>
                                </span>
                            </div>

                            <h3 class="folder-card-title"><?= htmlspecialchars($folder['courseName']) ?></h3>

                            <div class="folder-card-meta">
                                <span><?= $folder['activeCount'] ?> Active · <?= $folder['doneCount'] ?> Done</span>
                                <?php if ($folder['pastDueCount'] > 0): ?>
                                    <span class="folder-alert-tag"><?= $folder['pastDueCount'] ?> Past Due</span>
                                <?php elseif ($folder['nextDue']): ?>
                                    <span>· Due <?= date('M d', strtotime($folder['nextDue'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="folder-progress-area">
                            <div class="folder-progress-labels">
                                <span id="folder-pct-<?= $slug ?>"><?= $pct ?>% Complete</span>
                                <span id="folder-steps-<?= $slug ?>"><?= $folder['unitsDone'] ?>/<?= $folder['unitsTotal'] ?> steps</span>
                            </div>
                            <div class="folder-progress-track">
                                <div class="folder-progress-fill" id="folder-bar-<?= $slug ?>" style="width: <?= $pct ?>%;"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- OPENED FOLDER ITEMS PANEL -->
            <div id="assignmentsList">
                <?php $themeIdx = 0; ?>
                <?php foreach ($courseFolders as $cKey => $folder): ?>
                    <?php
                        $slug = 'folder-' . preg_replace('/[^a-zA-Z0-9]/', '-', $folder['courseCode']);
                        $themeClass = 'folder-theme-' . ($themeIdx % 6);
                        $themeIdx++;
                        // Sort active assignments first, completed at bottom
                        usort($folder['items'], function($x, $y) {
                            $xDone = ($x['taskStatus'] === 'Completed') ? 1 : 0;
                            $yDone = ($y['taskStatus'] === 'Completed') ? 1 : 0;
                            if ($xDone !== $yDone) return $xDone - $yDone;
                            return strcmp($x['dueDate'], $y['dueDate']);
                        });
                    ?>
                    <div class="folder-items-drawer course-group" id="panel-<?= $slug ?>" data-folder-slug="<?= $slug ?>" style="display: none;">
                        <div class="drawer-header">
                            <div class="drawer-title-wrap">
                                <span class="drawer-color-dot <?= $themeClass ?>"></span>
                                <h3 class="drawer-title course-group-title"><?= htmlspecialchars($cKey) ?></h3>
                                <span class="course-count-pill"><?= count($folder['items']) ?> Total</span>
                            </div>
                            <button type="button" class="btn-close-drawer" onclick="closeCourseFolder()">
                                <i class="ph-bold ph-x"></i> Close Folder
                            </button>
                        </div>

                        <?php foreach ($folder['items'] as $assignment): ?>
                            <?php 
                                $priorityClass = 'badge-priority-' . strtolower($assignment['priorityLevel']); 
                                $parsedSubtasks = $assignment['subTasks'] ?? [];
                                $totalSub = count($parsedSubtasks);
                                $completedSub = 0;
                                $firstIncompleteIdx = -1;
                                
                                foreach ($parsedSubtasks as $idx => $subtask) {
                                    if ($subtask['isCompleted']) {
                                        $completedSub++;
                                    } elseif ($firstIncompleteIdx === -1) {
                                        $firstIncompleteIdx = $idx;
                                    }
                                }
                                $progressPercent = $totalSub > 0 ? round(($completedSub / $totalSub) * 100) : 0;

                                $isCompleted = ($assignment['taskStatus'] === 'Completed');
                                $isPastDue = ($assignment['taskStatus'] === 'Past Due');
                                $dueDateObj = new DateTime($assignment['dueDate']);
                                $isCompletedLate = $isCompleted && ($dueDateObj < $todayDt);

                                $diffDays = (int)$todayDt->diff($dueDateObj)->format('%r%a');
                                if ($isCompleted) {
                                    $countdownText = 'Submitted';
                                    $countdownClass = 'countdown-done';
                                } elseif ($diffDays < 0) {
                                    $overdueDays = abs($diffDays);
                                    $countdownText = $overdueDays . ($overdueDays === 1 ? ' day overdue' : ' days overdue');
                                    $countdownClass = 'countdown-overdue';
                                } elseif ($diffDays === 0) {
                                    $countdownText = 'Due Today!';
                                    $countdownClass = 'countdown-overdue';
                                } elseif ($diffDays <= 3) {
                                    $countdownText = 'Due in ' . $diffDays . ($diffDays === 1 ? ' day' : ' days');
                                    $countdownClass = 'countdown-urgent';
                                } else {
                                    $countdownText = 'In ' . $diffDays . ' days';
                                    $countdownClass = 'countdown-normal';
                                }

                                $statusClass = 'badge-status-not-started';
                                if ($assignment['taskStatus'] === 'Ongoing') $statusClass = 'badge-status-ongoing';
                                elseif ($isCompleted) $statusClass = 'badge-status-completed';
                                elseif ($isPastDue) $statusClass = 'badge-status-past-due';
                                
                                $cardClass = 'assignment-item';
                                if ($isCompleted) $cardClass .= ' completed-card';
                                elseif ($isPastDue) $cardClass .= ' past-due-card';
                                elseif ($assignment['taskStatus'] === 'Ongoing') $cardClass .= ' ongoing-card';
                                elseif ($assignment['priorityLevel'] === 'High') $cardClass .= ' priority-high-card';
                            ?>
                            <div class="<?= $cardClass ?>" id="assignment-<?= $assignment['assignmentID'] ?>" data-priority="<?= htmlspecialchars($assignment['priorityLevel']) ?>" data-status="<?= htmlspecialchars($assignment['taskStatus']) ?>">
                                <div class="assignment-header">
                                    <input type="checkbox" 
                                           class="main-checkbox" 
                                           id="main-checkbox-<?= $assignment['assignmentID'] ?>" 
                                           <?= $isCompleted ? 'checked' : '' ?>
                                           onchange="toggleMainCheckbox(<?= $assignment['assignmentID'] ?>, this)"
                                           title="Mark entire assessment as completed">
                                    
                                    <div class="assignment-info">
                                        <div class="assignment-title"><?= htmlspecialchars($assignment['assignmentTitle']) ?></div>
                                        
                                        <div class="deadline-highlight-row">
                                            <span class="due-date-chip">
                                                <i class="ph-bold ph-calendar-blank"></i>
                                                Due <?= date('M d, Y', strtotime($assignment['dueDate'])) ?> at <?= !empty($assignment['dueTime']) ? date('g:i A', strtotime($assignment['dueTime'])) : '11:59 PM' ?>
                                            </span>
                                            <span class="countdown-pill <?= $countdownClass ?>">
                                                <i class="ph-fill ph-alarm"></i> <?= $countdownText ?>
                                            </span>
                                        </div>

                                        <div class="assignment-badges">
                                            <span class="badge badge-type"><?= htmlspecialchars($assignment['assignmentType'] ?? 'Assignment') ?></span>
                                            <span class="badge <?= $priorityClass ?>"><i class="ph ph-warning"></i> <?= htmlspecialchars($assignment['priorityLevel']) ?> Priority</span>
                                            <span id="status-badge-<?= $assignment['assignmentID'] ?>" class="badge <?= $statusClass ?>">
                                                <?php if ($isPastDue): ?><i class="ph ph-warning-circle"></i> <?php endif; ?>
                                                <?= htmlspecialchars($assignment['taskStatus']) ?>
                                            </span>
                                            <?php if ($isCompletedLate): ?>
                                                <span class="badge badge-completed-late"><i class="ph ph-clock-countdown"></i> Completed Late</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="assignment-actions">
                                        <?php if(!empty($assignment['pdfFile'])): ?>
                                            <a href="uploads/assignments/<?= htmlspecialchars($assignment['pdfFile']) ?>" target="_blank" title="View Document">
                                                <i class="ph ph-file-pdf"></i>
                                            </a>
                                        <?php endif; ?>
                                        <button onclick="openEditAssignment(<?= $assignment['assignmentID'] ?>)" title="Edit">
                                            <i class="ph ph-pencil"></i>
                                        </button>
                                        <button onclick="deleteAssignment(<?= $assignment['assignmentID'] ?>)" title="Delete">
                                            <i class="ph ph-trash"></i>
                                        </button>
                                    </div>
                                </div>

                                <?php if ($totalSub > 0): ?>
                                    <div class="subtasks-container">
                                        <div class="progress-wrapper">
                                            <div class="progress-text">
                                                <span>Task Progress (<strong id="progress-count-<?= $assignment['assignmentID'] ?>"><?= $completedSub ?>/<?= $totalSub ?></strong> steps done)</span>
                                                <span id="progress-text-<?= $assignment['assignmentID'] ?>"><?= $progressPercent ?>%</span>
                                            </div>
                                            <div class="progress-track">
                                                <div id="progress-bar-<?= $assignment['assignmentID'] ?>" class="progress-fill" style="width: <?= $progressPercent ?>%;"></div>
                                            </div>
                                        </div>
                                        <div class="subtask-list" id="subtask-list-<?= $assignment['assignmentID'] ?>">
                                            <?php foreach ($parsedSubtasks as $index => $subtask): ?>
                                                <?php 
                                                    $isExtra = $index >= 3;
                                                    $displayStyle = $isExtra ? 'display: none;' : '';
                                                    $extraClass = $isExtra ? ' extra-subtask' : '';
                                                    $isNextStep = (!$isCompleted && $index === $firstIncompleteIdx);
                                                    $nextClass = $isNextStep ? ' next-action-task' : '';
                                                ?>
                                                <label class="subtask-item<?= $extraClass . $nextClass ?>" style="<?= $displayStyle ?>">
                                                    <input type="checkbox" onchange="toggleSubtask(<?= $assignment['assignmentID'] ?>, <?= $index ?>, this)" <?= $subtask['isCompleted'] ? 'checked' : '' ?>>
                                                    <span id="subtask-text-<?= $assignment['assignmentID'] ?>-<?= $index ?>" style="<?= $subtask['isCompleted'] ? 'text-decoration: line-through; color: #94a3b8;' : '' ?>">
                                                        <?= htmlspecialchars($subtask['subTaskDescription']) ?>
                                                    </span>
                                                    <?php if ($isNextStep): ?>
                                                        <span class="next-step-tag">Next Step</span>
                                                    <?php endif; ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if ($totalSub > 3): ?>
                                            <button type="button" 
                                                    class="toggle-subtasks-btn" 
                                                    data-expanded="false" 
                                                    onclick="toggleSubtasksVisibility(<?= $assignment['assignmentID'] ?>, this)">
                                                Show <?= $totalSub - 3 ?> More Steps <i class="ph ph-caret-down"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="notification-toast" id="notificationToast">
            <i class="ph-fill ph-check-circle"></i> <span id="notificationMessage">Success!</span>
        </div>
    </main>

    <div class="modal-overlay" id="modalOverlay" onclick="closeAllModals()"></div>

    <div class="modal" id="deleteConfirmModal">
        <div class="modal-header">
            <h2 class="modal-title">Confirm Delete</h2>
            <button class="close-modal-btn" onclick="closeModal('deleteConfirmModal')"><i class="ph ph-x"></i></button>
        </div>
        <div class="modal-body">
            <p id="deleteConfirmMessage">Are you sure you want to delete this assessment?</p>
            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">
                <button class="btn btn-outline" onclick="closeModal('deleteConfirmModal')">Cancel</button>
                <button class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>

    <!-- SMART PDF UPLOAD ADD ASSESSMENT MODAL -->
    <div class="modal" id="addAssignmentModal">
        <div class="modal-header">
            <h2 class="modal-title" id="addModalTitle">Smart PDF Upload</h2>
            <button class="close-modal-btn" onclick="closeModal('addAssignmentModal')"><i class="ph ph-x"></i></button>
        </div>
        <div class="modal-body">
            <div id="addStepSmartPdf" class="add-step-view">
                <div class="upload-card">
                    <div class="form-group">
                        <label style="color:#2563eb;"><strong>1. Select Target Course</strong></label>
                        <select id="pdfCourseSelect" class="form-control" onchange="togglePdfUpload()" required>
                            <option value="">Select a course to enable upload...</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= $course['courseID'] ?>"><?= htmlspecialchars($course['courseCode']) ?> - <?= htmlspecialchars($course['courseName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group disabled-section" id="pdfUploadSection" style="margin-bottom:0;">
                        <label><strong>2. Drag & Drop Assessment PDF</strong></label>
                        <div class="drop-zone" id="dropZone">
                            <i class="ph ph-cloud-arrow-up drop-zone-icon"></i>
                            <div class="drop-zone-text">Drag and drop your PDF here</div>
                            <div class="drop-zone-subtext">Supports Assignment, Project, and Lab (Max 10MB)</div>
                            <input type="file" id="pdfFile" accept=".pdf" class="hidden-input">
                        </div>

                        <div class="upload-progress-container" id="uploadProgress" style="display:none;">
                            <div class="upload-status-text">
                                <span id="progressStatusText">Uploading...</span>
                                <span id="progressPercentText">0%</span>
                            </div>
                            <div class="upload-progress-bar">
                                <div class="upload-progress-fill" id="progressBar"></div>
                            </div>
                            
                            <div class="upload-steps-list">
                                <div class="step-item" id="stepUploadItem"><i class="ph ph-circle"></i> Uploading...</div>
                                <div class="step-item" id="stepExtractItem"><i class="ph ph-circle"></i> Extracting Information...</div>
                                <div class="step-item" id="stepScheduleItem"><i class="ph ph-circle"></i> Generating Schedule...</div>
                                <div class="step-item" id="stepSaveItem"><i class="ph ph-circle"></i> Saving...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- EDIT ASSIGNMENT MODAL -->
    <div class="modal" id="editAssignmentModal">
        <div class="modal-header">
            <h2 class="modal-title">Edit Assessment</h2>
            <button class="close-modal-btn" onclick="closeModal('editAssignmentModal')"><i class="ph ph-x"></i></button>
        </div>
        <div class="modal-body">
            <form id="editAssignmentForm" onsubmit="handleEditAssignment(event)">
                <input type="hidden" id="editAssignmentID">
                <div class="form-group">
                    <label>Assessment Type</label>
                    <select id="editAssignmentType" class="form-control" required>
                        <option value="Assignment">Assignment</option>
                        <option value="Project">Project</option>
                        <option value="Lab">Lab</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assessment Title</label>
                    <input id="editAssignmentTitle" type="text" class="form-control" required>
                </div>
                <div class="form-row" style="display: flex; gap: 12px;">
                    <div class="form-group" style="flex: 1;">
                        <label>Due Date</label>
                        <input id="editDueDate" type="date" class="form-control" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Due Time</label>
                        <input id="editDueTime" type="time" class="form-control" value="23:59" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Subtasks</label>
                    <textarea id="editSubTasks" class="form-control" rows="5" placeholder="Enter subtasks (one per line). Use [x] for completed tasks."></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="editAssignmentBtn">Save Changes</button>
            </form>
        </div>
    </div>

    <script>
        const existingAssignments = <?php echo json_encode($assignments); ?>;

        // --- SUBTASK EXPANSION TOGGLE ---
function toggleSubtasksVisibility(assignmentID, btn) {
    const list = document.getElementById(`subtask-list-${assignmentID}`);
    if (!list) return;

    const extraSubtasks = list.querySelectorAll('.extra-subtask');
    const isExpanded = btn.getAttribute('data-expanded') === 'true';

    extraSubtasks.forEach(task => {
        // Keep the active Next Step visible even when collapsing extra steps
        if (isExpanded && task.classList.contains('next-action-task')) {
            task.style.display = 'flex';
        } else {
            task.style.display = isExpanded ? 'none' : 'flex';
        }
    });

    if (isExpanded) {
        btn.setAttribute('data-expanded', 'false');
        btn.innerHTML = `Show ${extraSubtasks.length} More Steps <i class="ph ph-caret-down"></i>`;
    } else {
        btn.setAttribute('data-expanded', 'true');
        btn.innerHTML = 'Show Less <i class="ph ph-caret-up"></i>';
    }
}

        // --- MOVE "NEXT STEP" HIGHLIGHT TO FIRST UNCHECKED SUBTASK ---
        function updateNextStepHighlight(assignmentID) {
            const list = document.getElementById(`subtask-list-${assignmentID}`);
            if (!list) return;

            const assignment = existingAssignments.find(a => a.assignmentID == assignmentID);
            const isCompleted = assignment && assignment.taskStatus === 'Completed';

            const items = list.querySelectorAll('.subtask-item');
            let foundNext = false;

            items.forEach((item) => {
                const checkbox = item.querySelector('input[type="checkbox"]');
                const existingTag = item.querySelector('.next-step-tag');

                item.classList.remove('next-action-task');
                if (existingTag) {
                    existingTag.remove();
                }

                if (!isCompleted && !foundNext && checkbox && !checkbox.checked) {
                    foundNext = true;
                    item.classList.add('next-action-task');

                    // Ensure the new Next Step is visible even if extra subtasks are collapsed
                    if (item.style.display === 'none') {
                        item.style.display = 'flex';
                    }

                    const tag = document.createElement('span');
                    tag.className = 'next-step-tag';
                    tag.textContent = 'Next Step';
                    item.appendChild(tag);
                }
            });
        }

        // --- STATUS CALCULATOR ---
        function calculateTaskStatus(subtasks, dueDateStr, currentStatus = 'Not Started') {
            let total = 0;
            let completed = 0;

            if (Array.isArray(subtasks)) {
                total = subtasks.length;
                completed = subtasks.filter(t => t.isCompleted === true || t.isCompleted === 't' || t.isCompleted == 1).length;
            }

            let baseStatus = 'Not Started';
            if (total > 0) {
                if (completed === total) baseStatus = 'Completed';
                else if (completed > 0) baseStatus = 'Ongoing';
            } else {
                baseStatus = currentStatus === 'Completed' ? 'Completed' : currentStatus;
            }
            
            if (baseStatus === 'Completed') return 'Completed';
            
            if (dueDateStr) {
                const today = new Date();
                today.setHours(0,0,0,0);
                const due = new Date(dueDateStr);
                due.setHours(0,0,0,0);
                if (due < today) return 'Past Due';
            }
            
            return baseStatus;
        }

        // --- COURSE FOLDERS & GROUPED LIVE FILTERING ---
        let currentSearchTerm = '';
        let currentStatusFilter = 'All';
        let currentPriorityFilter = 'All';
        let activeFolderSlug = null;

        function selectCourseFolder(slug) {
            if (activeFolderSlug === slug) {
                closeCourseFolder();
                return;
            }
            activeFolderSlug = slug;

            document.querySelectorAll('.course-folder-card').forEach(card => {
                card.classList.toggle('active-folder', card.dataset.folderSlug === slug);
            });

            document.querySelectorAll('.folder-items-drawer').forEach(panel => {
                panel.style.display = (panel.dataset.folderSlug === slug) ? 'block' : 'none';
            });

            const activePanel = document.getElementById('panel-' + slug);
            if (activePanel) {
                activePanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        function closeCourseFolder() {
            activeFolderSlug = null;
            document.querySelectorAll('.course-folder-card').forEach(c => c.classList.remove('active-folder'));
            document.querySelectorAll('.folder-items-drawer').forEach(p => p.style.display = 'none');
        }

        function handleSearch(e) {
            currentSearchTerm = e.target.value;
            applyFiltersAndSearch();
        }

        function setStatusFilter(status, btnElement) {
            document.querySelectorAll('#statusFilterGroup .status-pill').forEach(b => b.classList.remove('active'));
            btnElement.classList.add('active');
            currentStatusFilter = status;
            applyFiltersAndSearch();
        }

        function setPriorityFilter(priority, btnElement) {
            document.querySelectorAll('#priorityFilterGroup .priority-pill').forEach(b => b.classList.remove('active'));
            btnElement.classList.add('active');
            currentPriorityFilter = priority;
            applyFiltersAndSearch();
        }

        function resetAllFilters() {
            currentSearchTerm = '';
            currentStatusFilter = 'All';
            currentPriorityFilter = 'All';
            const searchInput = document.getElementById('searchInput');
            if (searchInput) searchInput.value = '';

            document.querySelectorAll('#statusFilterGroup .status-pill').forEach(b => b.classList.toggle('active', b.dataset.status === 'All'));
            document.querySelectorAll('#priorityFilterGroup .priority-pill').forEach(b => b.classList.toggle('active', b.dataset.priority === 'All'));
            closeCourseFolder();
            applyFiltersAndSearch();
        }

        function triggerFilterByName(filterName) {
            resetAllFilters();
            if (filterName.includes('Priority')) {
                const pName = filterName.replace(' Priority', '').trim();
                const pBtn = document.querySelector(`#priorityFilterGroup .priority-pill[data-priority="${pName}"]`);
                if (pBtn) setPriorityFilter(pName, pBtn);
            } else {
                const sBtn = document.querySelector(`#statusFilterGroup .status-pill[data-status="${filterName}"]`);
                if (sBtn) setStatusFilter(filterName, sBtn);
            }
        }

        function applyFiltersAndSearch() {
            const term = currentSearchTerm.trim().toLowerCase();
            const isFiltering = (term !== '' || currentStatusFilter !== 'All' || currentPriorityFilter !== 'All');

            const resetBtn = document.getElementById('resetFiltersBtn');
            if (resetBtn) resetBtn.style.display = isFiltering ? 'inline-flex' : 'none';

            let totalVisibleItems = 0;

            document.querySelectorAll('.folder-items-drawer').forEach(panel => {
                const slug = panel.dataset.folderSlug;
                const folderCard = document.getElementById('card-' + slug);
                const courseHeader = panel.querySelector('.course-group-title').innerText.toLowerCase();
                let visibleInCourse = 0;

                panel.querySelectorAll('.assignment-item').forEach(item => {
                    const title = item.querySelector('.assignment-title').innerText.toLowerCase();
                    const status = item.dataset.status;
                    const priority = item.dataset.priority;

                    const matchesSearch = (term === '' || courseHeader.includes(term) || title.includes(term));
                    const matchesStatus = (currentStatusFilter === 'All' || status === currentStatusFilter);
                    const matchesPriority = (currentPriorityFilter === 'All' || priority === currentPriorityFilter);

                    if (matchesSearch && matchesStatus && matchesPriority) {
                        item.style.display = 'block';
                        visibleInCourse++;
                        totalVisibleItems++;
                    } else {
                        item.style.display = 'none';
                    }
                });

                if (folderCard) {
                    folderCard.style.display = (isFiltering && visibleInCourse === 0) ? 'none' : 'flex';
                }

                if (isFiltering) {
                    panel.style.display = visibleInCourse > 0 ? 'block' : 'none';
                } else {
                    panel.style.display = (activeFolderSlug === slug) ? 'block' : 'none';
                }
            });

            const emptyState = document.getElementById('emptyState');
            if (emptyState) {
                emptyState.style.display = totalVisibleItems === 0 ? 'block' : 'none';
                const emptyStateMessage = document.getElementById('emptyStateMessage');
                if (emptyStateMessage) {
                    emptyStateMessage.innerText = currentStatusFilter === 'Past Due'
                        ? 'No past due assignments found.'
                        : 'No assignments found.';
                }
            }
        }

        // --- MASTER COMPLETION CHECKBOX ---
        async function toggleMainCheckbox(assignmentID, checkboxElem) {
            const assignment = existingAssignments.find(a => a.assignmentID == assignmentID);
            if (!assignment) return;

            const isCompleted = checkboxElem.checked;
            let newStatus = 'Not Started';

            if (isCompleted) {
                newStatus = 'Completed';
            } else {
                const today = new Date(); today.setHours(0, 0, 0, 0);
                const due = new Date(assignment.dueDate); due.setHours(0, 0, 0, 0);
                newStatus = due < today ? 'Past Due' : 'Not Started';
            }

            assignment.taskStatus = newStatus;

            if (Array.isArray(assignment.subTasks)) {
                assignment.subTasks.forEach((st, i) => {
                    st.isCompleted = isCompleted;
                    const subCb = document.querySelector(`input[onchange="toggleSubtask(${assignmentID}, ${i}, this)"]`);
                    if (subCb) subCb.checked = isCompleted;

                    const textSpan = document.getElementById(`subtask-text-${assignmentID}-${i}`);
                    if (textSpan) {
                        textSpan.style.textDecoration = isCompleted ? 'line-through' : 'none';
                        textSpan.style.color = isCompleted ? '#94a3b8' : 'inherit';
                    }
                });

                const total = assignment.subTasks.length;
                const completed = isCompleted ? total : 0;
                const pct = isCompleted ? 100 : 0;

                const progCount = document.getElementById(`progress-count-${assignmentID}`);
                const progText = document.getElementById(`progress-text-${assignmentID}`);
                const progBar = document.getElementById(`progress-bar-${assignmentID}`);
                if (progCount) progCount.innerText = `${completed}/${total}`;
                if (progText) progText.innerText = pct + '%';
                if (progBar) progBar.style.width = pct + '%';

                updateNextStepHighlight(assignmentID);
            }

            const card = document.getElementById(`assignment-${assignmentID}`);
            if (card) {
                card.setAttribute('data-status', newStatus);
                card.classList.toggle('completed-card', isCompleted);

                const statusBadge = document.getElementById(`status-badge-${assignmentID}`);
                if (statusBadge) {
                    statusBadge.innerText = newStatus;
                    statusBadge.className = 'badge ' +
                        (newStatus === 'Not Started' ? 'badge-status-not-started' :
                        (newStatus === 'Ongoing' ? 'badge-status-ongoing' : 'badge-status-completed'));
                }
            }

            try {
                const response = await fetch('assignments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'updateSubtaskProgress',
                        assignmentID: assignmentID,
                        subTasks: assignment.subTasks,
                        taskStatus: newStatus
                    })
                });
                const data = await response.json();
                if (!data.success) throw new Error('Failed to save task progress');

                if (newStatus === 'Completed') {
                    showNotification('Assessment marked as completed.', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    setTimeout(() => location.reload(), 500);
                }
            } catch (err) {
                showNotification('Could not save progress to server', 'error');
                checkboxElem.checked = !isCompleted;
            }
        }

        async function toggleSubtask(assignmentID, index, checkboxElem) {
            const assignment = existingAssignments.find(a => a.assignmentID == assignmentID);
            if (!assignment || !assignment.subTasks[index]) return;

            assignment.subTasks[index].isCompleted = checkboxElem.checked;

            const textSpan = document.getElementById(`subtask-text-${assignmentID}-${index}`);
            if (checkboxElem.checked) {
                textSpan.style.textDecoration = 'line-through';
                textSpan.style.color = '#94a3b8';
            } else {
                textSpan.style.textDecoration = 'none';
                textSpan.style.color = 'inherit';
            }

            const total = assignment.subTasks.length;
            const completed = assignment.subTasks.filter(t => t.isCompleted).length;
            const percentage = Math.round((completed / total) * 100);

            let newStatus = calculateTaskStatus(assignment.subTasks, assignment.dueDate, assignment.taskStatus);
            assignment.taskStatus = newStatus;

            const progCount = document.getElementById(`progress-count-${assignmentID}`);
            if (progCount) progCount.innerText = `${completed}/${total}`;
            document.getElementById(`progress-text-${assignmentID}`).innerText = percentage + '%';
            document.getElementById(`progress-bar-${assignmentID}`).style.width = percentage + '%';

            // Update the Next Step highlight immediately
            updateNextStepHighlight(assignmentID);

            const card = document.getElementById(`assignment-${assignmentID}`);
            if (card) {
                card.setAttribute('data-status', newStatus);
                card.classList.toggle('completed-card', newStatus === 'Completed');

                const statusBadge = document.getElementById(`status-badge-${assignmentID}`);
                if (statusBadge) {
                    statusBadge.innerText = newStatus;
                    statusBadge.className = 'badge ' +
                        (newStatus === 'Not Started' ? 'badge-status-not-started' :
                        (newStatus === 'Ongoing' ? 'badge-status-ongoing' :
                        (newStatus === 'Past Due' ? 'badge-status-past-due' : 'badge-status-completed')));
                }
            }

            const mainCb = document.getElementById(`main-checkbox-${assignmentID}`);
            if (mainCb) mainCb.checked = (newStatus === 'Completed');

            try {
                const response = await fetch('assignments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'updateSubtaskProgress',
                        assignmentID: assignmentID,
                        subTasks: assignment.subTasks,
                        taskStatus: newStatus
                    })
                });
                const data = await response.json();
                if (!data.success) throw new Error('Failed to save task progress');

                if (newStatus === 'Completed') {
                    showNotification('Assessment marked as completed.', 'success');
                    setTimeout(() => location.reload(), 1500);
                }
            } catch (err) {
                showNotification('Could not save progress to server', 'error');
            }
        }

        // --- UTILITIES & MODAL LOGIC ---
        function openAddAssessmentModal() {
            document.getElementById('pdfCourseSelect').value = '';
            togglePdfUpload();
            dropZone.style.display = 'block';
            uploadProgress.style.display = 'none';
            fileInput.value = '';
            ['stepUploadItem', 'stepExtractItem', 'stepScheduleItem', 'stepSaveItem'].forEach(id => updateStepStatus(id, 'reset'));
            openModal('addAssignmentModal');
        }

        function openModal(id) {
            document.getElementById('modalOverlay').classList.add('active');
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById('modalOverlay').classList.remove('active');
            document.getElementById(id).classList.remove('active');
        }

        function closeAllModals() {
            document.querySelectorAll('.modal').forEach(m => m.classList.remove('active'));
            document.getElementById('modalOverlay').classList.remove('active');
        }

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

        function togglePdfUpload() {
            const courseSelect = document.getElementById('pdfCourseSelect').value;
            const uploadSection = document.getElementById('pdfUploadSection');
            if (courseSelect) {
                uploadSection.classList.remove('disabled-section');
            } else {
                uploadSection.classList.add('disabled-section');
            }
        }

        // --- SMART PDF AUTO-EXTRACTION & CREATION WORKFLOW ---
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('pdfFile');
        const uploadProgress = document.getElementById('uploadProgress');
        const progressBar = document.getElementById('progressBar');
        const progressPercentText = document.getElementById('progressPercentText');
        const progressStatusText = document.getElementById('progressStatusText');

        dropZone.addEventListener('click', () => fileInput.click());

        dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('dragover'); });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault(); dropZone.classList.remove('dragover');
            if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; processSelectedFile(e.dataTransfer.files[0]); }
        });

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length) { processSelectedFile(e.target.files[0]); }
        });

        function updateStepStatus(itemClass, status) {
            const item = document.getElementById(itemClass);
            if (!item) return;
            const icon = item.querySelector('i');
            if (status === 'active') {
                item.className = 'step-item active';
                icon.className = 'ph ph-circle-notch animate-spin';
            } else if (status === 'done') {
                item.className = 'step-item done';
                icon.className = 'ph-fill ph-check-circle';
            } else {
                item.className = 'step-item';
                icon.className = 'ph ph-circle';
            }
        }

        async function processSelectedFile(file) {
            const lockedCourseID = document.getElementById('pdfCourseSelect').value;
            if (!lockedCourseID) { showNotification('Please select a course first.', 'error'); fileInput.value = ''; return; }
            if (file.type !== 'application/pdf') { showNotification('Only PDF files are allowed.', 'error'); fileInput.value = ''; return; }
            if (file.size > 10 * 1024 * 1024) { showNotification('File exceeds 10MB limit.', 'error'); fileInput.value = ''; return; }

            dropZone.style.display = 'none';
            uploadProgress.style.display = 'block';

            updateStepStatus('stepUploadItem', 'active');
            progressStatusText.textContent = 'Uploading...';
            progressBar.style.width = '25%';
            progressPercentText.textContent = '25%';

            const formData = new FormData();
            formData.append('pdf', file); 

            try {
                const response = await fetch('upload_assignment_pdf.php', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.error || 'Extraction failed');
                }

                updateStepStatus('stepUploadItem', 'done');
                updateStepStatus('stepExtractItem', 'active');
                progressStatusText.textContent = 'Extracting Information...';
                progressBar.style.width = '50%';
                progressPercentText.textContent = '50%';

                await new Promise(r => setTimeout(r, 600));

                updateStepStatus('stepExtractItem', 'done');
                updateStepStatus('stepScheduleItem', 'active');
                progressStatusText.textContent = 'Generating Schedule...';
                progressBar.style.width = '75%';
                progressPercentText.textContent = '75%';

                await new Promise(r => setTimeout(r, 600));

                updateStepStatus('stepScheduleItem', 'done');
                updateStepStatus('stepSaveItem', 'active');
                progressStatusText.textContent = 'Saving...';
                progressBar.style.width = '90%';
                progressPercentText.textContent = '90%';

                const extracted = data.extractedData || {};
                const title = extracted.title || file.name.replace(/\.[^/.]+$/, "");
                const dueDate = extracted.due_date || new Date().toISOString().split('T')[0];
                const dueTime = extracted.due_time || '';
                const type = extracted.assessment_type || 'Assignment';
                const subtasks = Array.isArray(extracted.subtasks) ? extracted.subtasks : [];

                const saveRes = await fetch('assignments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'addAssignment',
                        assignmentTitle: title,
                        courseID: parseInt(lockedCourseID),
                        dueDate: dueDate,
                        dueTime: dueTime,
                        taskStatus: 'Not Started',
                        assignmentType: type,
                        subTasks: subtasks,
                        pdfFile: data.filePath || ''
                    })
                });

                const saveData = await saveRes.json();
                if (!saveData.success) {
                    throw new Error(saveData.error || 'Failed to save assessment to database');
                }

                updateStepStatus('stepSaveItem', 'done');
                progressBar.style.width = '100%';
                progressPercentText.textContent = '100%';
                progressStatusText.textContent = 'Assessment successfully created.';

                setTimeout(() => {
                    showNotification('Assessment successfully created.', 'success');
                    closeModal('addAssignmentModal');
                    location.reload();
                }, 1000);

            } catch (err) {
                console.error(err); 
                showNotification(err.message || 'Error processing PDF', 'error');
                dropZone.style.display = 'block';
                uploadProgress.style.display = 'none';
                fileInput.value = '';
                
                ['stepUploadItem', 'stepExtractItem', 'stepScheduleItem', 'stepSaveItem'].forEach(id => updateStepStatus(id, 'reset'));
            }
        }

        // --- EDIT ASSIGNMENT MODAL LOGIC ---
        function openEditAssignment(assignmentID) {
            const assignment = existingAssignments.find(a => a.assignmentID == assignmentID);
            if (!assignment) return;
            document.getElementById('editAssignmentID').value = assignmentID;
            document.getElementById('editAssignmentTitle').value = assignment.assignmentTitle;
            document.getElementById('editAssignmentType').value = assignment.assignmentType || 'Assignment';
            document.getElementById('editDueDate').value = assignment.dueDate;

            if (document.getElementById('editDueTime')) {
                document.getElementById('editDueTime').value = assignment.dueTime || '23:59';
            }
            
            // Convert subtasks array to multiline string format for textarea
            const subtaskLines = (assignment.subTasks || []).map(st => (st.isCompleted ? '[x] ' : '[ ] ') + st.subTaskDescription);
            document.getElementById('editSubTasks').value = subtaskLines.join('\n');
            
            openModal('editAssignmentModal');
        }

        async function handleEditAssignment(e) {
            e.preventDefault();
            const btn = document.getElementById('editAssignmentBtn');
            const assignmentID = document.getElementById('editAssignmentID').value;
            const title = document.getElementById('editAssignmentTitle').value;
            const assignmentType = document.getElementById('editAssignmentType').value;
            const dueDate = document.getElementById('editDueDate').value;
            const dueTime = document.getElementById('editDueTime') ? document.getElementById('editDueTime').value : '';
            const subTasksText = document.getElementById('editSubTasks').value;

            // Parse text lines to temp objects to calculate status
            const tempSubtasks = subTasksText.split('\n').map(l => l.trim()).filter(l => l.length > 0).map(line => {
                const isComp = /^\[(x|X)\]/.test(line);
                return { isCompleted: isComp };
            });

            const assignment = existingAssignments.find(a => a.assignmentID == assignmentID);
            const taskStatus = calculateTaskStatus(tempSubtasks, dueDate, assignment ? assignment.taskStatus : 'Not Started');

            btn.innerText = 'Saving...';
            btn.disabled = true;

            try {
                const response = await fetch('assignments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'updateAssignment',
                        assignmentID: parseInt(assignmentID),
                        assignmentTitle: title, 
                        assignmentType: assignmentType,
                        dueDate: dueDate, 
                        dueTime: dueTime,
                        subTasks: subTasksText, 
                        taskStatus: taskStatus
                    })
                });
                const data = await response.json();
                
                if (!data.success) throw new Error(data.error || 'Failed to update assessment');
                
                showNotification('Assessment updated successfully.', 'success');
                closeModal('editAssignmentModal');
                setTimeout(() => location.reload(), 1500);

            } catch (err) {
                showNotification(err.message || 'Error updating assessment', 'error');
                btn.innerText = 'Save Changes';
                btn.disabled = false;
            }
        }

        // --- DELETE CONFIRMATION MODAL LOGIC ---
        window.pendingDeleteAssignment = null;

        function deleteAssignment(assignmentID) {
            window.pendingDeleteAssignment = assignmentID;
            document.getElementById('deleteConfirmMessage').innerText = 'Are you sure you want to delete this assessment?';
            openModal('deleteConfirmModal');
        }

        document.getElementById('confirmDeleteBtn').addEventListener('click', async function() {
            const assignmentID = window.pendingDeleteAssignment;
            if (!assignmentID) return;
            
            closeModal('deleteConfirmModal');
            const btn = document.getElementById('confirmDeleteBtn');
            btn.innerText = 'Deleting...';
            btn.disabled = true;

            try {
                const response = await fetch('assignments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'deleteAssignment', assignmentID: parseInt(assignmentID) })
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Failed to delete assessment');
                
                showNotification('Assessment deleted successfully.', 'success');
                setTimeout(() => location.reload(), 1500); 
            } catch (err) {
                showNotification(err.message || 'Error deleting assessment', 'error');
                btn.innerText = 'Delete';
                btn.disabled = false;
            } finally {
                window.pendingDeleteAssignment = null;
            }
        });

        document.addEventListener("DOMContentLoaded", function() {
            const urlParams = new URLSearchParams(window.location.search);
            const filterParam = urlParams.get('filter');
            if (filterParam) {
                triggerFilterByName(filterParam);
            }
        });
    </script>
</body>
</html>