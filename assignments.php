<?php
include 'dbConn.php';
session_start();
$errors = [];
$message = '';

$studentID = $_SESSION['studentID'] ?? null;

if (!$studentID) {
    header('Location: login.php');
    exit;
}

function calculatePriority($dueDate) {
    $today = new DateTime('today');
    $due = new DateTime($dueDate);
    $daysUntilDue = $today->diff($due)->days;
    
    if ($daysUntilDue <= 3) { return 'High'; } 
    elseif ($daysUntilDue <= 7) { return 'Medium'; } 
    else { return 'Low'; }
}

// Handle JSON requests (AJAX API Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0) {
    $input = json_decode(file_get_contents('php://input'), true);
    header('Content-Type: application/json');

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
        $taskStatus = trim($input['taskStatus'] ?? 'Not Started');
        $assignmentType = trim($input['assignmentType'] ?? 'Assignment');
        $subTasks = trim($input['subTasks'] ?? '');
        $pdfFile = trim($input['pdfFile'] ?? '');
        $pdfHash = null;

        if ($assignmentTitle === '' || !$courseID || $dueDate === '') {
            echo json_encode(['success' => false, 'error' => 'Title, course, and due date are required']);
            exit;
        }

        // --- NEW: DUE DATE VALIDATION ---
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
                INSERT INTO "Assignment" ("courseID", "assignmentTitle", "dueDate", "taskStatus", "priorityLevel", "pdfFile", "pdfHash", "assignmentType", "subTasks")
                VALUES (:courseID, :assignmentTitle, :dueDate, :taskStatus, :priorityLevel, :pdfFile, :pdfHash, :assignmentType, :subTasks)
            ');
            $stmt->execute([
                ':courseID' => $courseID,
                ':assignmentTitle' => $assignmentTitle,
                ':dueDate' => $dueDate,
                ':taskStatus' => $taskStatus,
                ':priorityLevel' => $priorityLevel,
                ':pdfFile' => $pdfFile === '' ? null : $pdfFile,
                ':pdfHash' => $pdfHash,
                ':assignmentType' => $assignmentType,
                ':subTasks' => $subTasks
            ]);

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
        $taskStatus = trim($input['taskStatus'] ?? 'Not Started');
        $assignmentType = trim($input['assignmentType'] ?? 'Assignment');
        $subTasks = trim($input['subTasks'] ?? '');

        if (!$assignmentID || $assignmentTitle === '' || $dueDate === '') {
            echo json_encode(['success' => false, 'error' => 'Title and due date are required']);
            exit;
        }

        // --- NEW: DUE DATE VALIDATION ---
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

            // DUPLICATE RECORD VALIDATION (on edit)
            $dupCheck = $conn->prepare('
                SELECT 1 FROM "Assignment" a
                WHERE a."assignmentID" != :assignmentID
                  AND a."courseID" = (SELECT "courseID" FROM "Assignment" WHERE "assignmentID" = :assignmentID)
                  AND LOWER(a."assignmentTitle") = LOWER(:assignmentTitle)
                  AND LOWER(a."assignmentType") = LOWER(:assignmentType)
                LIMIT 1
            ');
            $dupCheck->execute([
                ':assignmentID' => $assignmentID,
                ':assignmentTitle' => $assignmentTitle,
                ':assignmentType' => $assignmentType
            ]);
            
            if ($dupCheck->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Duplicate detected: Another assessment with this title and type already exists for this course.']);
                exit;
            }

            $priorityLevel = calculatePriority($dueDate);

            $stmt = $conn->prepare('
                UPDATE "Assignment" 
                SET "assignmentTitle" = :assignmentTitle, "dueDate" = :dueDate, "taskStatus" = :taskStatus, "priorityLevel" = :priorityLevel, "assignmentType" = :assignmentType, "subTasks" = :subTasks
                WHERE "assignmentID" = :assignmentID
            ');
            $stmt->execute([
                ':assignmentTitle' => $assignmentTitle,
                ':dueDate' => $dueDate,
                ':taskStatus' => $taskStatus,
                ':priorityLevel' => $priorityLevel,
                ':assignmentType' => $assignmentType,
                ':subTasks' => $subTasks,
                ':assignmentID' => $assignmentID
            ]);

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
        $subTasks = trim($input['subTasks'] ?? '');
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

            $stmt = $conn->prepare('UPDATE "Assignment" SET "subTasks" = :subTasks, "taskStatus" = :taskStatus WHERE "assignmentID" = :assignmentID');
            $stmt->execute([':subTasks' => $subTasks, ':taskStatus' => $taskStatus, ':assignmentID' => $assignmentID]);

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

// Fetch Assignments and Categorize into Active vs Completed
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
        ORDER BY c."courseCode", a."dueDate" ASC
    ');
    $stmt->execute([':studentID' => $studentID]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today = new DateTime('today');
    
    foreach ($assignments as &$a) {
        // AUTOMATIC PAST DUE CALCULATION
        $dueDateObj = new DateTime($a['dueDate']);
        if ($a['taskStatus'] !== 'Completed' && $dueDateObj < $today) {
            $a['taskStatus'] = 'Past Due';
        }

        $a['priorityLevel'] = calculatePriority($a['dueDate']);
        $courseHeader = $a['courseCode'] . ' - ' . $a['courseName'];
        
        if ($a['taskStatus'] === 'Completed') {
            if (!isset($completedGrouped[$courseHeader])) $completedGrouped[$courseHeader] = [];
            $completedGrouped[$courseHeader][] = $a;
        } else {
            if (!isset($activeGrouped[$courseHeader])) $activeGrouped[$courseHeader] = [];
            $activeGrouped[$courseHeader][] = $a;
        }
    }
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
    <style>
        .filter-menu { display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 5px; }
        .filter-pill { padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 500; cursor: pointer; background: #f1f5f9; color: #475569; border: 1px solid transparent; transition: all 0.2s ease; white-space: nowrap; }
        .filter-pill:hover { background: #e2e8f0; }
        .filter-pill.active { background: #3b82f6; color: white; border-color: #2563eb; }

        .course-group { margin-bottom: 30px; }
        .course-group-title { font-size: 18px; font-weight: 700; color: #1e293b; padding-bottom: 10px; margin-bottom: 15px; border-bottom: 2px solid #e2e8f0; }
        
        .assignment-item { background-color: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; transition: all 0.3s ease; display: block; }
        .assignment-item:hover { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        
        /* Completed Card UI Styling */
        .completed-card { background-color: #f8fafc; border-color: #cbd5e1; opacity: 0.85; }
        .completed-card .assignment-title { text-decoration: line-through; color: #94a3b8; }
        .completed-card .assignment-meta { color: #94a3b8; }

        /* Past Due Card UI Styling */
        .past-due-card { background-color: #fff5f5; border-color: #fca5a5; }
        .past-due-card .assignment-title { color: #991b1b; }

        /* Status Badges */
        .badge-status-past-due { background-color: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
        .badge-completed-late { background-color: #ffedd5; color: #ea580c; border: 1px solid #fed7aa; }
        
        .completed-section-title { font-size: 18px; font-weight: 700; color: #64748b; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; padding-bottom: 10px; border-bottom: 2px dashed #e2e8f0; }

        .assignment-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
        .main-checkbox { width: 22px; height: 22px; cursor: pointer; accent-color: #10b981; margin-top: 2px; flex-shrink: 0; }
        .assignment-info { flex: 1; }
        .assignment-title { font-size: 16px; font-weight: 600; margin-bottom: 4px; transition: all 0.3s ease;}
        .assignment-meta { font-size: 13px; color: #7b7f87; margin-bottom: 8px; }
        .assignment-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px;}
        
        .assignment-actions { display: flex; gap: 8px; margin-left: 16px; }
        .assignment-actions button, .assignment-actions a { padding: 8px; border: none; background: none; cursor: pointer; color: #7b7f87; font-size: 18px; text-decoration: none;}
        .assignment-actions button:hover, .assignment-actions a:hover { color: #000; }
        
        .subtasks-container { margin-top: 15px; border-top: 1px dashed #e2e8f0; padding-top: 15px; }
        .progress-wrapper { margin-bottom: 15px; }
        .progress-text { display: flex; justify-content: space-between; font-size: 12px; color: #64748b; margin-bottom: 6px; font-weight: 500;}
        .progress-track { width: 100%; height: 6px; background: #f1f5f9; border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; background: #10b981; transition: width 0.4s ease; }
        .subtask-list { display: flex; flex-direction: column; gap: 8px; }
        .subtask-item { display: flex; align-items: flex-start; gap: 10px; font-size: 14px; cursor: pointer; color: #334155; }
        .subtask-item input[type="checkbox"] { margin-top: 3px; accent-color: #10b981; cursor: pointer; width: 16px; height: 16px; }
        
        .form-tabs { display: flex; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; }
        .tab-btn { padding: 10px 20px; background: none; border: none; border-bottom: 2px solid transparent; cursor: pointer; font-weight: 500; color: #7b7f87; }
        .tab-btn.active { color: #3b82f6; border-bottom-color: #3b82f6; }
        .disabled-section { opacity: 0.5; pointer-events: none; filter: grayscale(1); transition: 0.3s; }

        .upload-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; background: #fff; }
        .drop-zone { border: 2px dashed #cbd5e1; border-radius: 8px; padding: 40px 20px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: #f8fafc; }
        .drop-zone:hover, .drop-zone.dragover { background: #f1f5f9; border-color: #3b82f6; }
        .drop-zone-icon { font-size: 48px; color: #94a3b8; margin-bottom: 16px; }
        .drop-zone-text { font-size: 16px; font-weight: 600; color: #334155; margin-bottom: 8px; }
        .drop-zone-subtext { font-size: 13px; color: #64748b; }
        .hidden-input { display: none; }
        
        .file-preview { display: none; align-items: center; justify-content: space-between; padding: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-top: 16px; }
        .file-preview-info { display: flex; align-items: center; gap: 12px; }
        .file-preview-icon { font-size: 32px; color: #ef4444; }
        .file-name { font-size: 14px; font-weight: 600; color: #1e293b; word-break: break-all;}
        .file-size { font-size: 12px; color: #64748b; }
        .remove-file-btn { background: none; border: none; color: #ef4444; cursor: pointer; font-size: 20px; transition: color 0.2s; padding: 4px; border-radius: 4px; }
        .remove-file-btn:hover { color: #b91c1c; background: #fee2e2; }
        
        .upload-progress-container { display: none; margin-top: 20px; }
        .upload-status-text { display: flex; justify-content: space-between; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #475569; }
        .upload-progress-bar { width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
        .upload-progress-fill { height: 100%; background: #3b82f6; width: 0%; transition: width 0.3s ease; }
    </style>
</head>
<body>
    <?php 
    $current_page = 'assignments';
    include 'includes/sidebar.php'; 
    ?>

    <main class="main-content">
        <?php include 'includes/header.php'; ?>

        <div class="dashboard-container">
            <div class="page-header flex-between">
                <div>
                    <h1 class="page-title">Assignments Management</h1>
                    <p class="page-subtitle">Manage your coursework and track progress</p>
                </div>
                <button class="btn btn-primary" onclick="openModal('addAssignmentModal')">
                    <i class="ph ph-plus"></i> Add Assessment
                </button>
            </div>

            <?php if (count($assignments) > 0): ?>

            <div class="search-container" style="position: relative; margin-bottom: 20px;">
                <i class="ph ph-magnifying-glass" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 18px;"></i>
                <input type="text" id="searchInput" class="form-control" style="padding-left: 44px; border-radius: 999px;" placeholder="Search by course code or assessment title..." oninput="handleSearch(event)">
            </div>
            <div class="filter-menu">
                <button class="filter-pill active" onclick="filterTasks('All', this)">All</button>
                <button class="filter-pill" onclick="filterTasks('Not Started', this)">Not Started</button>
                <button class="filter-pill" onclick="filterTasks('Ongoing', this)">Ongoing</button>
                <button class="filter-pill" onclick="filterTasks('Completed', this)">Completed</button>
                <button class="filter-pill" onclick="filterTasks('Past Due', this)">Past Due</button>
                <button class="filter-pill" onclick="filterTasks('High Priority', this)">High Priority</button>
                <button class="filter-pill" onclick="filterTasks('Medium Priority', this)">Medium Priority</button>
                <button class="filter-pill" onclick="filterTasks('Low Priority', this)">Low Priority</button>
            </div>
            <?php endif; ?>

            <div class="empty-state-card" id="emptyState" style="<?= count($assignments) > 0 ? 'display:none' : '' ?>">
                <i class="ph ph-file-dashed" style="font-size: 48px; color: #cbd5e1; margin-bottom: 16px; display: block;"></i>
                <span id="emptyStateMessage">No assignments found.</span>
            </div>

            <div id="assignmentsList">
                
                <?php foreach (['active' => $activeGrouped, 'completed' => $completedGrouped] as $sectionType => $group): ?>
                    <?php if (empty($group)) continue; ?>
                    
                    <div id="<?= $sectionType === 'active' ? 'activeSection' : 'completedSection' ?>" style="<?= $sectionType === 'completed' ? 'margin-top: 40px;' : '' ?>">
                        
                        <?php if ($sectionType === 'completed'): ?>
                            <div class="completed-section-title"><i class="ph-fill ph-check-circle"></i> Completed Assessments</div>
                        <?php endif; ?>

                        <?php foreach ($group as $courseHeader => $courseAssignments): ?>
                            <div class="course-group">
                                <h3 class="course-group-title"><i class="ph ph-books"></i> <?= htmlspecialchars($courseHeader) ?></h3>
                                
                                <?php foreach ($courseAssignments as $assignment): ?>
                                    <?php 
                                        $priorityClass = 'badge-priority-' . strtolower($assignment['priorityLevel']); 
                                        
                                        // Parse Subtasks
                                        $subtaskList = array_filter(array_map('trim', explode("\n", $assignment['subTasks'] ?? '')));
                                        $totalSub = count($subtaskList);
                                        $completedSub = 0;
                                        $parsedSubtasks = [];
                                        
                                        foreach ($subtaskList as $idx => $task) {
                                            $isChecked = false;
                                            if (preg_match('/^\[(x|X)\]\s*(.*)/', $task, $matches)) {
                                                $isChecked = true;
                                                $completedSub++;
                                                $taskText = $matches[2];
                                            } elseif (preg_match('/^\[\s*\]\s*(.*)/', $task, $matches)) {
                                                $taskText = $matches[1];
                                            } else {
                                                $taskText = $task;
                                            }
                                            $parsedSubtasks[] = ['text' => $taskText, 'checked' => $isChecked, 'original' => $task];
                                        }
                                        $progressPercent = $totalSub > 0 ? round(($completedSub / $totalSub) * 100) : 0;

                                        $isCompleted = ($assignment['taskStatus'] === 'Completed');
                                        $isPastDue = ($assignment['taskStatus'] === 'Past Due');
                                        $isCompletedLate = $isCompleted && (new DateTime($assignment['dueDate']) < new DateTime('today'));

                                        $statusClass = 'badge-status-not-started';
                                        if ($assignment['taskStatus'] === 'Ongoing') $statusClass = 'badge-status-ongoing';
                                        elseif ($isCompleted) $statusClass = 'badge-status-completed';
                                        elseif ($isPastDue) $statusClass = 'badge-status-past-due';
                                        
                                        $cardClass = 'assignment-item';
                                        if ($isCompleted) $cardClass .= ' completed-card';
                                        if ($isPastDue) $cardClass .= ' past-due-card';
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
                                                <div class="assignment-meta">Due: <?= date('M d, Y', strtotime($assignment['dueDate'])) ?></div>
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
                                                        <span>Task Progress</span>
                                                        <span id="progress-text-<?= $assignment['assignmentID'] ?>"><?= $progressPercent ?>%</span>
                                                    </div>
                                                    <div class="progress-track">
                                                        <div id="progress-bar-<?= $assignment['assignmentID'] ?>" class="progress-fill" style="width: <?= $progressPercent ?>%;"></div>
                                                    </div>
                                                </div>
                                                <div class="subtask-list">
                                                    <?php foreach ($parsedSubtasks as $index => $subtask): ?>
                                                        <label class="subtask-item">
                                                            <input type="checkbox" onchange="toggleSubtask(<?= $assignment['assignmentID'] ?>, <?= $index ?>, this)" <?= $subtask['checked'] ? 'checked' : '' ?>>
                                                            <span id="subtask-text-<?= $assignment['assignmentID'] ?>-<?= $index ?>" style="<?= $subtask['checked'] ? 'text-decoration: line-through; color: #94a3b8;' : '' ?>">
                                                                <?= htmlspecialchars($subtask['text']) ?>
                                                            </span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
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

    <div class="modal" id="addAssignmentModal">
        <div class="modal-header">
            <h2 class="modal-title">Add Assessment</h2>
            <button class="close-modal-btn" onclick="closeModal('addAssignmentModal')"><i class="ph ph-x"></i></button>
        </div>
        <div class="modal-body">
            <form id="addAssignmentForm">
                <div class="form-tabs">
                    <button type="button" class="tab-btn active" onclick="switchTab('manual')">Manual Entry</button>
                    <button type="button" class="tab-btn" onclick="switchTab('pdf')">Smart PDF Upload</button>
                </div>

                <div id="manualTab" class="form-tab active">
                    <div class="form-group">
                        <label>Course (Active Semester Only)</label>
                        <select id="courseID" class="form-control" required>
                            <option value="">Select a course</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= $course['courseID'] ?>"><?= htmlspecialchars($course['courseCode']) ?> - <?= htmlspecialchars($course['courseName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Assessment Type</label>
                        <select id="assignmentType" class="form-control" required>
                            <option value="Assignment">Assignment</option>
                            <option value="Lab">Lab</option>
                            <option value="Project">Project</option>
                            <option value="Quiz">Quiz</option>
                            <option value="Test">Test</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Assessment Title</label>
                        <input id="assignmentTitle" type="text" class="form-control" placeholder="e.g., Phase 1 Submission" required>
                    </div>
                    <div class="form-group">
                        <label>Due Date</label>
                        <input id="dueDate" type="date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Subtasks</label>
                        <textarea id="subTasks" class="form-control" placeholder="List subtasks here (one per line)..." rows="3"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block" id="addAssignmentBtn" onclick="handleAddAssignment(event)">Add Assessment</button>
                </div>

                <div id="pdfTab" class="form-tab" style="display:none">
                    <div class="upload-card">
                        <div class="form-group">
                            <label style="color:#e63946;"><strong>1. Select Target Course</strong></label>
                            <select id="pdfCourseSelect" class="form-control" onchange="togglePdfUpload()" required>
                                <option value="">Select a course to enable upload...</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?= $course['courseID'] ?>"><?= htmlspecialchars($course['courseCode']) ?> - <?= htmlspecialchars($course['courseName']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group disabled-section" id="pdfUploadSection" style="margin-bottom:0;">
                            <label><strong>2. Upload Assessment Document</strong></label>
                            <div class="drop-zone" id="dropZone">
                                <i class="ph ph-cloud-arrow-up drop-zone-icon"></i>
                                <div class="drop-zone-text">Drag and drop your PDF here</div>
                                <div class="drop-zone-subtext">or click to browse from your computer (Max 10MB)</div>
                                <input type="file" id="pdfFile" accept=".pdf" class="hidden-input">
                            </div>

                            <div class="file-preview" id="filePreview">
                                <div class="file-preview-info">
                                    <i class="ph-fill ph-file-pdf file-preview-icon"></i>
                                    <div>
                                        <div class="file-name" id="previewFileName">document.pdf</div>
                                        <div class="file-size" id="previewFileSize">0 MB</div>
                                    </div>
                                </div>
                                <button type="button" class="remove-file-btn" id="removeFileBtn" title="Remove file">
                                    <i class="ph ph-trash"></i>
                                </button>
                            </div>

                            <div class="upload-progress-container" id="uploadProgress">
                                <div class="upload-status-text">
                                    <span id="progressStatusText">Extracting details using AI...</span>
                                    <span id="progressPercentText">0%</span>
                                </div>
                                <div class="upload-progress-bar">
                                    <div class="upload-progress-fill" id="progressBar"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="extractionResults" style="display:none;margin-top:20px;padding:20px;background:#f0fdf4;border-radius:12px;border:1px solid #bbf7d0;">
                        <h3 style="margin:0 0 15px 0;font-size:15px;font-weight:700;color:#16a34a;display:flex;align-items:center;gap:8px;">
                            <i class="ph-fill ph-magic-wand"></i> Extraction Complete! Review Details:
                        </h3>
                        <input type="hidden" id="extractedPdfFile" value="">
                        <div class="form-group">
                            <label>Assessment Type</label>
                            <select id="extractedAssignmentType" class="form-control" required>
                                <option value="Assignment">Assignment</option>
                                <option value="Lab">Lab</option>
                                <option value="Project">Project</option>
                                <option value="Quiz">Quiz</option>
                                <option value="Test">Test</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Assessment Title</label>
                            <input id="extractedTitle" type="text" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Due Date</label>
                            <input id="extractedDueDate" type="date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Extracted Subtasks</label>
                            <textarea id="extractedSubTasks" class="form-control" rows="4"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block" id="addFromPdfBtn" onclick="handleAddFromPDF(event)">
                            <i class="ph ph-check-circle"></i> Save Assessment
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

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
                        <option value="Lab">Lab</option>
                        <option value="Project">Project</option>
                        <option value="Quiz">Quiz</option>
                        <option value="Test">Test</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assessment Title</label>
                    <input id="editAssignmentTitle" type="text" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Due Date</label>
                    <input id="editDueDate" type="date" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Subtasks</label>
                    <textarea id="editSubTasks" class="form-control" rows="5"></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="editAssignmentBtn">Save Changes</button>
            </form>
        </div>
    </div>

    <script>
        const existingAssignments = <?php echo json_encode($assignments); ?>;

        // --- STATUS CALCULATOR WITH PAST DUE CAPABILITIES ---
        function calculateTaskStatus(subTasksText, dueDateStr, currentStatus = 'Not Started') {
            let lines = subTasksText ? subTasksText.split('\n').map(l => l.trim()).filter(l => l.length > 0) : [];
            let total = lines.length;
            let completed = lines.filter(l => l.match(/^\[(x|X)\]/)).length;
            
            let baseStatus = 'Not Started';
            if (total > 0) {
                if (completed === total) baseStatus = 'Completed';
                else if (completed > 0) baseStatus = 'Ongoing';
            } else {
                baseStatus = currentStatus === 'Completed' ? 'Completed' : currentStatus;
            }
            
            if (baseStatus === 'Completed') return 'Completed';
            
            // Check past due
            if (dueDateStr) {
                const today = new Date();
                today.setHours(0,0,0,0);
                const due = new Date(dueDateStr);
                due.setHours(0,0,0,0);
                if (due < today) return 'Past Due';
            }
            
            return baseStatus;
        }

        // --- DYNAMIC LIVE FILTERING ---
        let currentSearchTerm = '';
let currentFilter = 'All';

// Initialize the limits when the page loads
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.course-group').forEach(g => g.dataset.limit = 3);
    applyFiltersAndSearch();
});

function handleSearch(e) {
    currentSearchTerm = e.target.value;
    // Reset limits so search results start collapsed neatly
    document.querySelectorAll('.course-group').forEach(g => g.dataset.limit = 3);
    applyFiltersAndSearch();
}

function filterTasks(type, btnElement) {
    document.querySelectorAll('.filter-pill').forEach(btn => btn.classList.remove('active'));
    btnElement.classList.add('active');
    currentFilter = type;
    // Reset limits when changing tabs
    document.querySelectorAll('.course-group').forEach(g => g.dataset.limit = 3);
    applyFiltersAndSearch();
}

function applyFiltersAndSearch() {
    const term = currentSearchTerm.toLowerCase();
    const courseGroups = document.querySelectorAll('.course-group');
    let totalVisibleAssignments = 0;

    courseGroups.forEach(group => {
        const courseHeader = group.querySelector('.course-group-title').innerText.toLowerCase();
        const assignments = Array.from(group.querySelectorAll('.assignment-item'));
        
        let matchCount = 0;
        let visibleInGroup = 0;
        const limit = parseInt(group.dataset.limit || 3);

        assignments.forEach(item => {
            const title = item.querySelector('.assignment-title').innerText.toLowerCase();
            const status = item.dataset.status;
            const priority = item.dataset.priority;
            
            // 1. Check Search Match
            const matchesSearch = term === '' || courseHeader.includes(term) || title.includes(term);
            
            // 2. Check Filter Match
            let matchesFilter = true;
            if (currentFilter !== 'All') {
                if (['Not Started', 'Ongoing', 'Completed', 'Past Due'].includes(currentFilter)) {
                    matchesFilter = (status === currentFilter);
                } else if (currentFilter.includes('Priority')) {
                    matchesFilter = (priority + ' Priority' === currentFilter);
                }
            }

            // 3. Apply Visibility Logic
            if (matchesSearch && matchesFilter) {
                matchCount++;
                if (visibleInGroup < limit) {
                    item.style.display = 'block'; // Show item
                    item.classList.remove('hidden-assignment');
                    visibleInGroup++;
                    totalVisibleAssignments++;
                } else {
                    item.style.display = 'none'; // Exceeds Load More limit
                    item.classList.add('hidden-assignment');
                }
            } else {
                item.style.display = 'none'; // Fails search/filter
            }
        });

        // 4. Handle "Load More" Button Logic
        let loadMoreBtn = group.querySelector('.load-more-btn');
        if (matchCount > limit) {
            // Create button if it doesn't exist
            if (!loadMoreBtn) {
                loadMoreBtn = document.createElement('button');
                loadMoreBtn.className = 'load-more-btn';
                group.appendChild(loadMoreBtn);
            }
            loadMoreBtn.style.display = 'flex';
            loadMoreBtn.innerHTML = `Load More (${matchCount - limit} remaining) <i class="ph ph-caret-down"></i>`;
            
            // When clicked, increase limit by 3 and re-render
            loadMoreBtn.onclick = () => {
                group.dataset.limit = limit + 3;
                applyFiltersAndSearch();
            };
        } else {
            // Hide button if we've shown all matching items in this course
            if (loadMoreBtn) loadMoreBtn.style.display = 'none';
        }

        // 5. Hide the entire Course block if nothing inside matches
        group.style.display = matchCount === 0 ? 'none' : 'block';
    });

    // 6. Handle the main empty state illustration
        const emptyState = document.getElementById('emptyState');
        if (totalVisibleAssignments === 0) {
            emptyState.style.display = 'block';
            const emptyStateMessage = document.getElementById('emptyStateMessage');
            if (emptyStateMessage) {
                emptyStateMessage.innerText = currentFilter === 'Past Due' 
                    ? 'No past due assignments found.' 
                    : 'No assignments found.';
            }
        } else {
            emptyState.style.display = 'none';
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
                const today = new Date(); today.setHours(0,0,0,0);
                const due = new Date(assignment.dueDate); due.setHours(0,0,0,0);
                newStatus = due < today ? 'Past Due' : 'Not Started';
            }

            let newSubTasksText = assignment.subTasks || '';
            if (newSubTasksText.trim() !== '') {
                let lines = newSubTasksText.split('\n').map(l => l.trim()).filter(l => l.length > 0);
                for (let i = 0; i < lines.length; i++) {
                    let coreText = lines[i].replace(/^\[(x|X|\s)?\]\s*/, '');
                    lines[i] = (isCompleted ? '[x] ' : '[ ] ') + coreText;
                    
                    const subCb = document.querySelector(`input[onchange="toggleSubtask(${assignmentID}, ${i}, this)"]`);
                    if (subCb) subCb.checked = isCompleted;
                    
                    const textSpan = document.getElementById(`subtask-text-${assignmentID}-${i}`);
                    if (textSpan) {
                        textSpan.style.textDecoration = isCompleted ? 'line-through' : 'none';
                        textSpan.style.color = isCompleted ? '#94a3b8' : 'inherit';
                    }
                }
                assignment.subTasks = lines.join('\n');
                
                const pct = isCompleted ? 100 : 0;
                const progText = document.getElementById(`progress-text-${assignmentID}`);
                const progBar = document.getElementById(`progress-bar-${assignmentID}`);
                if (progText) progText.innerText = pct + '%';
                if (progBar) progBar.style.width = pct + '%';
            }

            const card = document.getElementById(`assignment-${assignmentID}`);
            if (card) {
                card.setAttribute('data-status', newStatus);
                if (isCompleted) card.classList.add('completed-card');
                else card.classList.remove('completed-card');
                
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
            if (!assignment) return;

            let lines = assignment.subTasks.split('\n').map(l => l.trim()).filter(l => l.length > 0);
            let line = lines[index];
            
            let coreText = line.replace(/^\[(x|X|\s)?\]\s*/, '');
            lines[index] = (checkboxElem.checked ? '[x] ' : '[ ] ') + coreText;
            assignment.subTasks = lines.join('\n');

            const textSpan = document.getElementById(`subtask-text-${assignmentID}-${index}`);
            if (checkboxElem.checked) {
                textSpan.style.textDecoration = 'line-through';
                textSpan.style.color = '#94a3b8';
            } else {
                textSpan.style.textDecoration = 'none';
                textSpan.style.color = 'inherit';
            }

            const total = lines.length;
            const completed = lines.filter(l => l.match(/^\[(x|X)\]/)).length;
            const percentage = Math.round((completed / total) * 100);
            
            let newStatus = calculateTaskStatus(assignment.subTasks, assignment.dueDate, assignment.taskStatus);
            assignment.taskStatus = newStatus;

            document.getElementById(`progress-text-${assignmentID}`).innerText = percentage + '%';
            document.getElementById(`progress-bar-${assignmentID}`).style.width = percentage + '%';

            const card = document.getElementById(`assignment-${assignmentID}`);
            if (card) {
                card.setAttribute('data-status', newStatus);
                if (newStatus === 'Completed') card.classList.add('completed-card');
                else card.classList.remove('completed-card');
                
                const statusBadge = document.getElementById(`status-badge-${assignmentID}`);
                if (statusBadge) {
                    statusBadge.innerText = newStatus;
                    statusBadge.className = 'badge ' + 
                        (newStatus === 'Not Started' ? 'badge-status-not-started' : 
                        (newStatus === 'Ongoing' ? 'badge-status-ongoing' : 'badge-status-completed'));
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
                        assignmentID: assignmentID, subTasks: assignment.subTasks, taskStatus: newStatus 
                    })
                });
                const data = await response.json();
                if (!data.success) throw new Error('Failed to save task progress');

                if (newStatus === 'Completed') {
                    showNotification('Assessment marked as completed.', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else if (percentage !== 100 && mainCb && !checkboxElem.checked && percentage === ((completed-1)/total)*100) {
                   setTimeout(() => location.reload(), 500);
                }
            } catch (err) {
                showNotification('Could not save progress to server', 'error');
            }
        }

        // --- UTILITIES ---
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

        function switchTab(tabId) {
            document.querySelectorAll('.form-tab').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById(tabId + 'Tab').style.display = 'block';
            event.target.classList.add('active');
        }

        function showNotification(message, type = 'success') {
            const toast = document.getElementById('notificationToast');
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

        // --- PDF EXTRACTION ---
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('pdfFile');
        const filePreview = document.getElementById('filePreview');
        const previewFileName = document.getElementById('previewFileName');
        const previewFileSize = document.getElementById('previewFileSize');
        const removeFileBtn = document.getElementById('removeFileBtn');
        const uploadProgress = document.getElementById('uploadProgress');
        const progressBar = document.getElementById('progressBar');
        const progressPercentText = document.getElementById('progressPercentText');
        const extractionResults = document.getElementById('extractionResults');

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

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

        removeFileBtn.addEventListener('click', () => {
            fileInput.value = '';
            filePreview.style.display = 'none';
            dropZone.style.display = 'block';
            uploadProgress.style.display = 'none';
            extractionResults.style.display = 'none';
        });

        async function processSelectedFile(file) {
            const lockedCourseID = document.getElementById('pdfCourseSelect').value;
            if (!lockedCourseID) { showNotification('Please select a course first.', 'error'); fileInput.value = ''; return; }
            if (file.type !== 'application/pdf') { showNotification('Only PDF files are allowed.', 'error'); fileInput.value = ''; return; }
            if (file.size > 10 * 1024 * 1024) { showNotification('File exceeds 10MB limit.', 'error'); fileInput.value = ''; return; }

            previewFileName.textContent = file.name;
            previewFileSize.textContent = formatFileSize(file.size);
            dropZone.style.display = 'none';
            filePreview.style.display = 'flex';
            
            uploadProgress.style.display = 'block';
            progressBar.style.width = '30%';
            progressPercentText.textContent = '30%';

            const formData = new FormData();
            formData.append('pdf', file); 

            try {
                setTimeout(() => { if(progressBar.style.width !== '100%') { progressBar.style.width = '60%'; progressPercentText.textContent = '60%'; } }, 800);

                const response = await fetch('upload_assignment_pdf.php', { method: 'POST', body: formData });
                const data = await response.json();
                
                progressBar.style.width = '100%';
                progressPercentText.textContent = '100%';

                setTimeout(() => { 
                    uploadProgress.style.display = 'none'; 
                    if (!data.success) { 
                        showNotification(data.error || 'Extraction failed', 'error'); 
                        removeFileBtn.click(); 
                        return; 
                    }

                    extractionResults.style.display = 'block';
                    document.getElementById('extractedPdfFile').value = data.filePath || ''; 
                    document.getElementById('extractedTitle').value = data.extractedData?.title || '';
                    if (data.extractedData?.assessment_type) document.getElementById('extractedAssignmentType').value = data.extractedData.assessment_type;
                    if (data.extractedData?.subtasks && Array.isArray(data.extractedData.subtasks)) {
                        document.getElementById('extractedSubTasks').value = data.extractedData.subtasks.join('\n');
                    } else {
                        document.getElementById('extractedSubTasks').value = '';
                    }
                    if(data.extractedData?.due_date) document.getElementById('extractedDueDate').value = data.extractedData.due_date;

                    showNotification('Document extracted! Please review the details.', 'success');
                }, 500);
            } catch (err) {
                console.error(err); uploadProgress.style.display = 'none'; showNotification(err.message || 'Error processing PDF', 'error'); removeFileBtn.click();
            }
        }

        // --- SAVING ASSIGNMENTS ---
        async function handleAddFromPDF(e) {
            e.preventDefault();
            const title = document.getElementById('extractedTitle').value;
            const courseID = document.getElementById('pdfCourseSelect').value; 
            const dueDate = document.getElementById('extractedDueDate').value; 
            const assignmentType = document.getElementById('extractedAssignmentType').value;
            const subTasks = document.getElementById('extractedSubTasks').value;
            const pdfFile = document.getElementById('extractedPdfFile').value;

            if(!title || !courseID || !dueDate) { showNotification('Title, Course, and Due Date are required.', 'error'); return; }

            const taskStatus = calculateTaskStatus(subTasks, 'Not Started');

            const btn = document.getElementById('addFromPdfBtn');
            btn.innerHTML = '<i class="ph ph-spinner animate-spin"></i> Saving...';
            btn.disabled = true;

            try {
                const response = await fetch('assignments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'addAssignment',
                        assignmentTitle: title, courseID: parseInt(courseID),
                        dueDate: dueDate, taskStatus: taskStatus,
                        assignmentType: assignmentType, subTasks: subTasks, pdfFile: pdfFile
                    })
                });
                const data = await response.json();
                
                if (!data.success) throw new Error(data.error || 'Failed to save assessment');
                
                showNotification('Assessment added successfully.', 'success');
                closeModal('addAssignmentModal');
                setTimeout(() => location.reload(), 1500);

            } catch(err) {
                showNotification(err.message || 'Error saving assessment', 'error');
                btn.innerHTML = '<i class="ph ph-check-circle"></i> Save Assessment';
                btn.disabled = false;
            }
        }

        async function handleAddAssignment(e) {
            e.preventDefault();
            const title = document.getElementById('assignmentTitle').value;
            const courseID = document.getElementById('courseID').value;
            const dueDate = document.getElementById('dueDate').value;
            const assignmentType = document.getElementById('assignmentType').value;
            const subTasks = document.getElementById('subTasks').value;

            if(!title || !courseID || !dueDate) { showNotification('Title, Course, and Due Date are required.', 'error'); return; }

            const taskStatus = calculateTaskStatus(subTasks, 'Not Started');

            const btn = document.getElementById('addAssignmentBtn');
            btn.innerText = 'Saving...';
            btn.disabled = true;

            try {
                const response = await fetch('assignments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'addAssignment',
                        assignmentTitle: title, courseID: parseInt(courseID),
                        dueDate: dueDate, taskStatus: taskStatus,
                        assignmentType: assignmentType, subTasks: subTasks
                    })
                });
                const data = await response.json();
                
                if (!data.success) throw new Error(data.error || 'Failed to add assessment');
                
                showNotification('Assessment added successfully.', 'success');
                closeModal('addAssignmentModal');
                setTimeout(() => location.reload(), 1500);

            } catch (err) {
                showNotification(err.message || 'Error adding assessment', 'error');
                btn.innerText = 'Add Assessment';
                btn.disabled = false;
            }
        }

        function openEditAssignment(assignmentID) {
            const assignment = existingAssignments.find(a => a.assignmentID == assignmentID);
            if (!assignment) return;
            document.getElementById('editAssignmentID').value = assignmentID;
            document.getElementById('editAssignmentTitle').value = assignment.assignmentTitle;
            document.getElementById('editAssignmentType').value = assignment.assignmentType || 'Assignment';
            document.getElementById('editDueDate').value = assignment.dueDate;
            document.getElementById('editSubTasks').value = assignment.subTasks || '';
            openModal('editAssignmentModal');
        }

        async function handleEditAssignment(e) {
            e.preventDefault();
            const btn = document.getElementById('editAssignmentBtn');
            const assignmentID = document.getElementById('editAssignmentID').value;
            const title = document.getElementById('editAssignmentTitle').value;
            const assignmentType = document.getElementById('editAssignmentType').value;
            const dueDate = document.getElementById('editDueDate').value;
            const subTasks = document.getElementById('editSubTasks').value;

            const assignment = existingAssignments.find(a => a.assignmentID == assignmentID);
            const taskStatus = calculateTaskStatus(subTasks, assignment ? assignment.taskStatus : 'Not Started');

            btn.innerText = 'Saving...';
            btn.disabled = true;

            try {
                const response = await fetch('assignments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'updateAssignment',
                        assignmentID: parseInt(assignmentID),
                        assignmentTitle: title, assignmentType: assignmentType,
                        dueDate: dueDate, subTasks: subTasks, taskStatus: taskStatus
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
    
    if (filterParam === 'Past Due') {
        // Find your filter pills and click/trigger the 'Past Due' one automatically
        const pastDuePill = Array.from(document.querySelectorAll('.filter-pill')).find(el => el.innerText.trim() === 'Past Due');
        if (pastDuePill) pastDuePill.click();
    }
});
    </script>
</body>
</html>