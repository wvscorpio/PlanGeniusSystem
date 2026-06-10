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
        $pdfFile = trim($input['pdfFile'] ?? null);

        if ($assignmentTitle === '' || !$courseID || $dueDate === '') {
            echo json_encode(['success' => false, 'error' => 'Title, course, and due date are required']);
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

            $priorityLevel = calculatePriority($dueDate);

            $stmt = $conn->prepare('
                INSERT INTO "Assignment" ("courseID", "assignmentTitle", "dueDate", "taskStatus", "priorityLevel", "pdfFile", "assignmentType", "subTasks")
                VALUES (:courseID, :assignmentTitle, :dueDate, :taskStatus, :priorityLevel, :pdfFile, :assignmentType, :subTasks)
            ');
            $stmt->execute([
                ':courseID' => $courseID,
                ':assignmentTitle' => $assignmentTitle,
                ':dueDate' => $dueDate,
                ':taskStatus' => $taskStatus,
                ':priorityLevel' => $priorityLevel,
                ':pdfFile' => $pdfFile,
                ':assignmentType' => $assignmentType,
                ':subTasks' => $subTasks
            ]);

            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to add assignment']);
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

// Fetch Assignments
$assignments = [];
$groupedAssignments = [];
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
    
    foreach ($assignments as $a) {
        $courseHeader = $a['courseCode'] . ' - ' . $a['courseName'];
        if (!isset($groupedAssignments[$courseHeader])) {
            $groupedAssignments[$courseHeader] = [];
        }
        $groupedAssignments[$courseHeader][] = $a;
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
        /* Specific Layout Styles not covered by global style.css */
        .filter-menu { display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 5px; }
        .filter-pill { padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 500; cursor: pointer; background: #f1f5f9; color: #475569; border: 1px solid transparent; transition: all 0.2s ease; white-space: nowrap; }
        .filter-pill:hover { background: #e2e8f0; }
        .filter-pill.active { background: #3b82f6; color: white; border-color: #2563eb; }

        .course-group { margin-bottom: 30px; }
        .course-group-title { font-size: 18px; font-weight: 700; color: #1e293b; padding-bottom: 10px; margin-bottom: 15px; border-bottom: 2px solid #e2e8f0; }
        .assignment-item { background-color: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; transition: box-shadow 0.2s; display: block; }
        .assignment-item:hover { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .assignment-header { display: flex; justify-content: space-between; align-items: flex-start; }
        .assignment-info { flex: 1; }
        .assignment-title { font-size: 16px; font-weight: 600; margin-bottom: 4px; }
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

        /* Modern Upload UI Styles */
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
                    <h1 class="page-title">Assignments & Assessments</h1>
                    <p class="page-subtitle">Manage your coursework and track progress</p>
                </div>
                <button class="btn btn-primary" onclick="openModal('addAssignmentModal')">
                    <i class="ph ph-plus"></i> Add Assessment
                </button>
            </div>

            <?php if (count($assignments) > 0): ?>
            <div class="filter-menu">
                <button class="filter-pill active" onclick="filterTasks('All', this)">All</button>
                <button class="filter-pill" onclick="filterTasks('High', this)">High Priority</button>
                <button class="filter-pill" onclick="filterTasks('Medium', this)">Medium Priority</button>
                <button class="filter-pill" onclick="filterTasks('Low', this)">Low Priority</button>
            </div>
            <?php endif; ?>

            <div class="empty-state-card" id="emptyState" style="<?= count($assignments) > 0 ? 'display:none' : '' ?>">
                <i class="ph ph-file-dashed" style="font-size: 48px; color: #cbd5e1; margin-bottom: 16px; display: block;"></i>
                No assignments found. Click "Add Assessment" to get started!
            </div>

            <div id="assignmentsList">
                <?php foreach ($groupedAssignments as $courseHeader => $courseAssignments): ?>
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

                                // Determine Status Badge Class Based on DB Value
                                $statusClass = 'badge-status-not-started';
                                if ($assignment['taskStatus'] === 'Ongoing') $statusClass = 'badge-status-ongoing';
                                elseif ($assignment['taskStatus'] === 'Completed') $statusClass = 'badge-status-completed';
                            ?>
                            <div class="assignment-item" id="assignment-<?= $assignment['assignmentID'] ?>" data-priority="<?= htmlspecialchars($assignment['priorityLevel']) ?>">
                                <div class="assignment-header">
                                    <div class="assignment-info">
                                        <div class="assignment-title"><?= htmlspecialchars($assignment['assignmentTitle']) ?></div>
                                        <div class="assignment-meta">Due: <?= date('M d, Y', strtotime($assignment['dueDate'])) ?></div>
                                        <div class="assignment-badges">
                                            <span class="badge badge-type"><?= htmlspecialchars($assignment['assignmentType'] ?? 'Assignment') ?></span>
                                            <span class="badge <?= $priorityClass ?>"><i class="ph ph-warning"></i> <?= htmlspecialchars($assignment['priorityLevel']) ?> Priority</span>
                                            <span id="status-badge-<?= $assignment['assignmentID'] ?>" class="badge <?= $statusClass ?>">
                                                <?= htmlspecialchars($assignment['taskStatus']) ?>
                                            </span>
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
        </div>

        <div class="notification-toast" id="notificationToast">
            <i class="ph-fill ph-check-circle"></i> <span id="notificationMessage">Success!</span>
        </div>
    </main>

    <div class="modal-overlay" id="modalOverlay" onclick="closeAllModals()"></div>

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

        // --- DYNAMIC STATUS CALCULATOR ---
        function calculateTaskStatus(subTasksText) {
            if (!subTasksText) return 'Not Started';
            let lines = subTasksText.split('\n').map(l => l.trim()).filter(l => l.length > 0);
            let total = lines.length;
            
            if (total === 0) return 'Not Started';
            
            let completed = lines.filter(l => l.match(/^\[(x|X)\]/)).length;
            
            if (completed === total) return 'Completed';
            if (completed > 0) return 'Ongoing';
            return 'Not Started';
        }

        // --- DYNAMIC FILTER LOGIC ---
        function filterTasks(priority, btnElem) {
            document.querySelectorAll('.filter-pill').forEach(btn => btn.classList.remove('active'));
            btnElem.classList.add('active');

            document.querySelectorAll('.course-group').forEach(group => {
                let hasVisibleTask = false;
                group.querySelectorAll('.assignment-item').forEach(item => {
                    if (priority === 'All' || item.getAttribute('data-priority') === priority) {
                        item.style.display = 'block';
                        hasVisibleTask = true;
                    } else {
                        item.style.display = 'none';
                    }
                });
                group.style.display = hasVisibleTask ? 'block' : 'none';
            });
        }

        // --- SUBTASK CHECKBOX LOGIC (WITH AUTO-STATUS UPDATE) ---
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
            
            // Calculate new Status using helper
            let newStatus = calculateTaskStatus(assignment.subTasks);
            assignment.taskStatus = newStatus;

            // Update Progress Bar UI
            document.getElementById(`progress-text-${assignmentID}`).innerText = percentage + '%';
            document.getElementById(`progress-bar-${assignmentID}`).style.width = percentage + '%';

            // Update Badge Color & Text Dynamically
            const statusBadge = document.getElementById(`status-badge-${assignmentID}`);
            if (statusBadge) {
                statusBadge.innerText = newStatus;
                statusBadge.className = 'badge ' + 
                    (newStatus === 'Not Started' ? 'badge-status-not-started' : 
                    (newStatus === 'Ongoing' ? 'badge-status-ongoing' : 'badge-status-completed'));
            }

            // Save to Backend
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
            document.getElementById('notificationMessage').innerText = message;
            const cls = type === 'error' ? 'error' : 'success';
            toast.classList.remove('success', 'error');
            toast.classList.add(cls, 'show');
            setTimeout(() => {
                toast.classList.remove('show');
                toast.classList.remove(cls);
            }, 3000);
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
                    if (!data.success) { showNotification(data.error || 'Extraction failed', 'error'); return; }

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

                    showNotification('Document extracted! Please review the details.');
                }, 500);
            } catch (err) {
                console.error(err); uploadProgress.style.display = 'none'; showNotification(err.message || 'Error processing PDF', 'error'); removeFileBtn.click();
            }
        }

        // --- SAVING ASSIGNMENTS (WITH AUTO-STATUS) ---
        async function handleAddFromPDF(e) {
            e.preventDefault();
            const title = document.getElementById('extractedTitle').value;
            const courseID = document.getElementById('pdfCourseSelect').value; 
            const dueDate = document.getElementById('extractedDueDate').value; 
            const assignmentType = document.getElementById('extractedAssignmentType').value;
            const subTasks = document.getElementById('extractedSubTasks').value;
            const pdfFile = document.getElementById('extractedPdfFile').value;

            if(!title || !courseID || !dueDate) { showNotification('Title, Course, and Due Date are required.', 'error'); return; }

            // Auto-calculate status before saving
            const taskStatus = calculateTaskStatus(subTasks);

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
                location.reload();
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

            // Auto-calculate status before saving
            const taskStatus = calculateTaskStatus(subTasks);

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
                location.reload();
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

            // Auto-calculate status before saving
            const taskStatus = calculateTaskStatus(subTasks);

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
                location.reload();
            } catch (err) {
                showNotification(err.message || 'Error updating assessment', 'error');
                btn.innerText = 'Save Changes';
                btn.disabled = false;
            }
        }

        async function deleteAssignment(assignmentID) {
            if (!confirm('Are you sure you want to delete this assessment?')) return;

            try {
                const response = await fetch('assignments.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'deleteAssignment', assignmentID: parseInt(assignmentID) })
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Failed to delete assessment');
                location.reload(); 
            } catch (err) {
                showNotification(err.message || 'Error deleting assessment', 'error');
            }
        }
    </script>
</body>
</html>