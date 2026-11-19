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
                ':dueDate'          => $dueDate,
                ':dueTime'          => $dueTime !== '' ? $dueTime : null,
                ':taskStatus'       => $taskStatus,
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

                /*if ($taskStatus === 'Completed') {
                    $delS = $conn->prepare('DELETE FROM "Schedule" WHERE "referenceID" = :ref AND "referenceType" = \'Assignment\'');
                    $delS->execute([':ref' => $assignmentID]);
                    $delN = $conn->prepare('DELETE FROM "Notification" WHERE "studentID" = :studentID AND "referenceID" = :refID AND "referenceType" = \'Assignment\'');
                    $delN->execute([':studentID' => $studentID, ':refID' => $assignmentID]);
                } else {
                    $scheduleManager->syncAssignmentSchedule($studentID, $assignmentID, $dueDate, $subtasks, $semStart, $semEnd);
                }*/
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

                    /*if ($taskStatus === 'Completed') {
                        $delS = $conn->prepare('DELETE FROM "Schedule" WHERE "referenceID" = :ref AND "referenceType" = \'Assignment\'');
                        $delS->execute([':ref' => $assignmentID]);
                        $delN = $conn->prepare('DELETE FROM "Notification" WHERE "studentID" = :studentID AND "notificationMessage" LIKE :pat');
                        $delN->execute([':studentID' => $studentID, ':pat' => '%Assignment%']);
                    } else {
                        $scheduleManager->syncAssignmentSchedule($studentID, $assignmentID, $dueDate, $subtasks, $semStart, $semEnd);
                    }*/
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
        
        .completed-card { background-color: #f8fafc; border-color: #cbd5e1; opacity: 0.85; }
        .completed-card .assignment-title { text-decoration: line-through; color: #94a3b8; }
        .completed-card .assignment-meta { color: #94a3b8; }

        .past-due-card { background-color: #fff5f5; border-color: #fca5a5; }
        .past-due-card .assignment-title { color: #991b1b; }

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
        
        .disabled-section { opacity: 0.5; pointer-events: none; filter: grayscale(1); transition: 0.3s; }

        .upload-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; background: #fff; }
        .drop-zone { border: 2px dashed #cbd5e1; border-radius: 8px; padding: 40px 20px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: #f8fafc; }
        .drop-zone:hover, .drop-zone.dragover { background: #f1f5f9; border-color: #3b82f6; }
        .drop-zone-icon { font-size: 48px; color: #94a3b8; margin-bottom: 16px; }
        .drop-zone-text { font-size: 16px; font-weight: 600; color: #334155; margin-bottom: 8px; }
        .drop-zone-subtext { font-size: 13px; color: #64748b; }
        .hidden-input { display: none; }
        
        .upload-progress-container { margin-top: 20px; }
        .upload-status-text { display: flex; justify-content: space-between; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #475569; }
        .upload-progress-bar { width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
        .upload-progress-fill { height: 100%; background: #3b82f6; width: 0%; transition: width 0.4s ease; }

        .upload-steps-list { margin-top: 20px; display: flex; flex-direction: column; gap: 10px; text-align: left; background: #f8fafc; padding: 16px; border-radius: 10px; border: 1px solid #e2e8f0; }
        .step-item { font-size: 13px; font-weight: 500; color: #94a3b8; display: flex; align-items: center; gap: 10px; transition: all 0.3s ease; }
        .step-item.active { color: #2563eb; font-weight: 600; }
        .step-item.done { color: #16a34a; font-weight: 600; }
        
        .toggle-subtasks-btn {
            margin-top: 10px;
            background: none;
            border: none;
            color: #3b82f6;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            padding: 0;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: color 0.2s ease;
        }
        .toggle-subtasks-btn:hover {
            color: #2563eb;
        }
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
                <button class="btn btn-primary" onclick="openAddAssessmentModal()">
                    <i class="ph ph-plus"></i> Add Assignment
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
                                        
                                        $parsedSubtasks = $assignment['subTasks'] ?? [];
                                        $totalSub = count($parsedSubtasks);
                                        $completedSub = 0;
                                        
                                        foreach ($parsedSubtasks as $subtask) {
                                            if ($subtask['isCompleted']) {
                                                $completedSub++;
                                            }
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
                                                <div class="assignment-meta">
                                                    Due: <?= date('M d, Y', strtotime($assignment['dueDate'])) ?>
                                                    <?php if (!empty($assignment['dueTime'])): ?>
                                                        at <?= date('g:i A', strtotime($assignment['dueTime'])) ?>
                                                    <?php else: ?>
                                                        at 11:59 PM
                                                    <?php endif; ?>
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
                                                        <span>Task Progress</span>
                                                        <span id="progress-text-<?= $assignment['assignmentID'] ?>"><?= $progressPercent ?>%</span>
                                                    </div>
                                                    <div class="progress-track">
                                                        <div id="progress-bar-<?= $assignment['assignmentID'] ?>" class="progress-fill" style="width: <?= $progressPercent ?>%;"></div>
                                                    </div>
                                                </div>
                                                <div class="subtask-list" id="subtask-list-<?= $assignment['assignmentID'] ?>">
                                                    <?php foreach ($parsedSubtasks as $index => $subtask): ?>
                                                        <?php 
                                                            $isExtra = $index >= 5;
                                                            $displayStyle = $isExtra ? 'display: none;' : '';
                                                            $extraClass = $isExtra ? ' extra-subtask' : '';
                                                        ?>
                                                        <label class="subtask-item<?= $extraClass ?>" style="<?= $displayStyle ?>">
                                                            <input type="checkbox" onchange="toggleSubtask(<?= $assignment['assignmentID'] ?>, <?= $index ?>, this)" <?= $subtask['isCompleted'] ? 'checked' : '' ?>>
                                                            <span id="subtask-text-<?= $assignment['assignmentID'] ?>-<?= $index ?>" style="<?= $subtask['isCompleted'] ? 'text-decoration: line-through; color: #94a3b8;' : '' ?>">
                                                                <?= htmlspecialchars($subtask['subTaskDescription']) ?>
                                                            </span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                                <?php if ($totalSub > 5): ?>
                                                    <button type="button" 
                                                            class="toggle-subtasks-btn" 
                                                            data-expanded="false" 
                                                            onclick="toggleSubtasksVisibility(<?= $assignment['assignmentID'] ?>, this)">
                                                        Show More <i class="ph ph-caret-down"></i>
                                                    </button>
                                                <?php endif; ?>
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
                task.style.display = isExpanded ? 'none' : 'flex';
            });

            if (isExpanded) {
                btn.setAttribute('data-expanded', 'false');
                btn.innerHTML = 'Show More <i class="ph ph-caret-down"></i>';
            } else {
                btn.setAttribute('data-expanded', 'true');
                btn.innerHTML = 'Show Less <i class="ph ph-caret-up"></i>';
            }
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

        // --- DYNAMIC LIVE FILTERING ---
        let currentSearchTerm = '';
        let currentFilter = 'All';

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.course-group').forEach(g => g.dataset.limit = 3);
            applyFiltersAndSearch();
        });

        function handleSearch(e) {
            currentSearchTerm = e.target.value;
            document.querySelectorAll('.course-group').forEach(g => g.dataset.limit = 3);
            applyFiltersAndSearch();
        }

        function filterTasks(type, btnElement) {
            document.querySelectorAll('.filter-pill').forEach(btn => btn.classList.remove('active'));
            btnElement.classList.add('active');
            currentFilter = type;
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
                    
                    const matchesSearch = term === '' || courseHeader.includes(term) || title.includes(term);
                    
                    let matchesFilter = true;
                    if (currentFilter !== 'All') {
                        if (['Not Started', 'Ongoing', 'Completed', 'Past Due'].includes(currentFilter)) {
                            matchesFilter = (status === currentFilter);
                        } else if (currentFilter.includes('Priority')) {
                            matchesFilter = (priority + ' Priority' === currentFilter);
                        }
                    }

                    if (matchesSearch && matchesFilter) {
                        matchCount++;
                        if (visibleInGroup < limit) {
                            item.style.display = 'block';
                            item.classList.remove('hidden-assignment');
                            visibleInGroup++;
                            totalVisibleAssignments++;
                        } else {
                            item.style.display = 'none';
                            item.classList.add('hidden-assignment');
                        }
                    } else {
                        item.style.display = 'none';
                    }
                });

                let loadMoreBtn = group.querySelector('.load-more-btn');
                if (matchCount > limit) {
                    if (!loadMoreBtn) {
                        loadMoreBtn = document.createElement('button');
                        loadMoreBtn.className = 'load-more-btn';
                        group.appendChild(loadMoreBtn);
                    }
                    loadMoreBtn.style.display = 'flex';
                    loadMoreBtn.innerHTML = `Load More (${matchCount - limit} remaining) <i class="ph ph-caret-down"></i>`;
                    
                    loadMoreBtn.onclick = () => {
                        group.dataset.limit = limit + 3;
                        applyFiltersAndSearch();
                    };
                } else {
                    if (loadMoreBtn) loadMoreBtn.style.display = 'none';
                }

                group.style.display = matchCount === 0 ? 'none' : 'block';
            });

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
                const isComp = /^\[(x\vert{}X)\]/.test(line);
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
            
            if (filterParam === 'Past Due') {
                const pastDuePill = Array.from(document.querySelectorAll('.filter-pill')).find(el => el.innerText.trim() === 'Past Due');
                if (pastDuePill) pastDuePill.click();
            }
        });
    </script>
</body>
</html>