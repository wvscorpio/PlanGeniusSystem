<?php
include 'dbConn.php';
session_start();
require_once __DIR__ . '/schedule_Manager.php';
$errors = [];
$message = '';

$studentID = $_SESSION['studentID'] ?? null;

if (!$studentID) {
    header('Location: login.php');
    exit;
}

// Fetch current preferred study time
$scheduleManager = new ScheduleManager($conn);
$userPreferredStudyTime = $scheduleManager->getPreferredStudyTime($studentID);

// Ensure upload directory exists
$uploadDir = 'uploads/exams/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

function calculatePriority($examDate) {
    $today = new DateTime('today');
    $exam = new DateTime($examDate);
    $daysUntilDue = $today->diff($exam)->days;
    
    if ($exam < $today) {
        return 'Low';
    }
    if ($daysUntilDue <= 3) { return 'High'; } 
    elseif ($daysUntilDue <= 7) { return 'Medium'; } 
    else { return 'Low'; }
}

function calculateExamStatus($examDate, $currentStatus = 'Not Started') {
    if ($currentStatus === 'Completed') return 'Completed';
    $today = new DateTime('today');
    $exam = new DateTime($examDate);
    
    if ($exam < $today) {
        return 'Past Due';
    } elseif ($exam == $today) {
        return 'Ongoing';
    } else {
        return 'Not Started';
    }
}

function generateStudySchedule($type, $title, $examDate, $examTime, $coverage, $difficulty) {
    $today = new DateTime('today');
    $examDateObj = new DateTime($examDate);
    
    $daysRemaining = 0;
    if ($examDateObj >= $today) {
        $daysRemaining = $today->diff($examDateObj)->days;
    }
    
    $hours = ($difficulty === 'Hard') ? 3 : (($difficulty === 'Medium') ? 2 : 1);
    $schedule = [];
    $chapters = [];
    
    if (preg_match('/(\d+)\s*(?:-|to)\s*(?:Chapter\s*)?(\d+)/i', $coverage, $matches)) {
        $start = intval($matches[1]);
        $end = intval($matches[2]);
        for ($c = $start; $c <= $end; $c++) {
            $chapters[] = "Chapter $c";
        }
    } elseif (!empty($coverage)) {
        $chapters = array_filter(array_map('trim', explode(',', $coverage)));
    }
    
    if (empty($chapters)) {
        $chapters = ['General Revision', 'Practice Questions', 'Key Concepts Review'];
    }
    
    if ($daysRemaining <= 0) {
        $schedule[] = "[ ] Final Revision for $title ($hours hours)";
    } else {
        $chapterCount = count($chapters);
        $daysToPlan = min($daysRemaining, max($chapterCount + 1, 3)); 
        $chaptersPerDay = max(1, ceil($chapterCount / max(1, $daysToPlan - 1)));
        
        $dayIndex = 1;
        for ($i = 0; $i < $chapterCount; $i += $chaptersPerDay) {
            $chunk = array_slice($chapters, $i, $chaptersPerDay);
            $chunkStr = implode(' & ', $chunk);
            $schedule[] = "[ ] Day $dayIndex: Study $chunkStr ($hours hours)";
            $dayIndex++;
        }
        
        if ($dayIndex <= $daysRemaining) {
            $schedule[] = "[ ] Day $dayIndex: Practice Exam & Final Review (" . ($hours + 1) . " hours)";
        }
    }
    
    $formattedTime = !empty($examTime) ? date('g:i A', strtotime($examTime)) : 'TBA';
    $schedule[] = "[ ] Assessment Day: $title at " . $formattedTime;
    
    return implode("\n", $schedule);
}

// Fetch Active Semester Info & Active Courses
$activeSemesterName = "Active Semester";
$activeCourses = [];

try {
    $semStmt = $conn->prepare('
        SELECT "semesterID", "semesterName"
        FROM "SemesterPlan"
        WHERE "studentID" = :studentID
          AND CURRENT_DATE >= "startDate" 
          AND CURRENT_DATE <= "endDate"
        LIMIT 1
    ');
    $semStmt->execute([':studentID' => $studentID]);
    $activeSem = $semStmt->fetch(PDO::FETCH_ASSOC);

    if ($activeSem) {
        $activeSemesterName = $activeSem['semesterName'];
        
        $courseStmt = $conn->prepare('
            SELECT "courseID", "courseCode", "courseName"
            FROM "Course"
            WHERE "semesterID" = :semesterID
            ORDER BY "courseCode" ASC
        ');
        $courseStmt->execute([':semesterID' => $activeSem['semesterID']]);
        $activeCourses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $errors[] = 'Failed to fetch active semester courses.';
}

// Handle JSON POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    header('Content-Type: application/json');
    $action = trim($input['action'] ?? '');

    // ACTION: BATCH SAVE FINAL EXAMINATIONS
    if ($action === 'saveBatchFinalExams') {
        $examsToSave = $input['exams'] ?? [];
        $pdfFile = trim($input['pdfFile'] ?? '');

        if (empty($examsToSave) || !is_array($examsToSave)) {
            echo json_encode(['success' => false, 'error' => 'No valid course exam records provided']);
            exit;
        }

        try {
            $conn->beginTransaction();
            $scheduleManager = new ScheduleManager($conn);

            foreach ($examsToSave as $item) {
                $courseID = intval($item['courseID'] ?? 0);
                $rawExamDate = trim($item['examDate'] ?? '');
                $rawExamTime = trim($item['examTime'] ?? '');
                
                $examDate = !empty($rawExamDate) ? date('Y-m-d', strtotime($rawExamDate)) : date('Y-m-d');
                $examTime = null;

                if (!empty($rawExamTime)) {
                    $timeParts = explode('-', $rawExamTime);
                    $startTime = trim($timeParts[0]);
                    $examTime = date('H:i:s', strtotime($startTime));
                }

                $examLocation = trim($item['examLocation'] ?? '');
                $section = trim($item['section'] ?? '');
                $difficulty = trim($item['difficulty'] ?? 'Medium');
                $type = trim($item['type'] ?? 'Theory');

                if (!$courseID) {
                    continue;
                }

                $cCheck = $conn->prepare('
                    SELECT c."courseCode", c."courseName", s."startDate", s."endDate" 
                    FROM "Course" c
                    JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                    WHERE c."courseID" = :courseID AND s."studentID" = :studentID
                ');
                $cCheck->execute([':courseID' => $courseID, ':studentID' => $studentID]);
                $cData = $cCheck->fetch(PDO::FETCH_ASSOC);
                if (!$cData) continue;

                $calculatedStatus = calculateExamStatus($examDate);
                $examCategory = 'Final Examination';
                $examTitle = $cData['courseCode'] . ' Final Examination (' . $type . ')' . ($section !== '' ? ' - Sec ' . $section : '');

                $stmt = $conn->prepare('
                    INSERT INTO public."Exam" 
                        ("courseID", "examType", "examTitle", "examDate", "examTime", "examLocation", "examStatus", "difficultyLevel", "pdfFile")
                    VALUES 
                        (:courseID, :examType, :examTitle, :examDate, :examTime, :examLocation, :examStatus, :difficultyLevel, :pdfFile)
                    RETURNING "examID"
                ');
                $stmt->execute([
                    ':courseID'        => $courseID,
                    ':examType'        => $examCategory,
                    ':examTitle'       => $examTitle,
                    ':examDate'        => $examDate,
                    ':examTime'        => $examTime,
                    ':examLocation'    => $examLocation,
                    ':examStatus'      => $calculatedStatus,
                    ':difficultyLevel' => $difficulty,
                    ':pdfFile'         => $pdfFile
                ]);
                $newExamID = $stmt->fetchColumn();

                $formattedDate = date('d F Y', strtotime($examDate));
                $notifMsg = "Final Examination for {$cData['courseCode']} scheduled on {$formattedDate}";
                $notifStmt = $conn->prepare('
                    INSERT INTO "Notification" ("studentID", "notificationMessage", "sentDate", "notificationStatus")
                    VALUES (:studentID, :message, CURRENT_TIMESTAMP, \'Unread\')
                ');
                $notifStmt->execute([':studentID' => $studentID, ':message' => $notifMsg]);

                if ($newExamID) {
                    $semStart = $cData['startDate'] ?? date('Y-m-d');
                    $semEnd = $cData['endDate'] ?? date('Y-m-d');
                    $scheduleManager->syncExamSchedule($studentID, (int)$newExamID, $examDate, $examCategory, $semStart, $semEnd, $difficulty, $calculatedStatus, $courseID);
                }
            }

            $conn->commit();
            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'error' => 'Failed to save final examination records: ' . $e->getMessage()]);
            exit;
        }
    }

    // ACTION: ADD SINGLE ASSESSMENT
    if ($action === 'addExam') {
        $courseID = intval($input['courseID'] ?? 0);
        $assessmentCategory = trim($input['assessmentCategory'] ?? 'Quiz');
        $title = trim($input['title'] ?? '');
        $examDate = trim($input['examDate'] ?? '');
        $examTime = trim($input['examTime'] ?? '');
        $examLocation = trim($input['examLocation'] ?? '');
        $difficultyLevel = trim($input['difficultyLevel'] ?? 'Medium');
        $coverage = trim($input['coverage'] ?? '');
        $subTasks = trim($input['subTasks'] ?? '');
        $examStatus = trim($input['examStatus'] ?? 'Not Started');

        $storedType = !empty($title) ? ($assessmentCategory . ': ' . $title) : $assessmentCategory;

        if (!$courseID || $examDate === '') {
            echo json_encode(['success' => false, 'error' => 'Course and date are required']);
            exit;
        }

        try {
            $courseCheck = $conn->prepare('
                SELECT c."courseCode" 
                FROM "Course" c 
                JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" 
                WHERE c."courseID" = :courseID AND s."studentID" = :studentID
            ');
            $courseCheck->execute([':courseID' => $courseID, ':studentID' => $studentID]);
            $courseData = $courseCheck->fetch(PDO::FETCH_ASSOC);
            if (!$courseData) {
                echo json_encode(['success' => false, 'error' => 'Course not found or unauthorized']);
                exit;
            }

            if (empty($subTasks)) {
                $assessmentTitle = $courseData['courseCode'] . ' ' . $storedType;
                $subTasks = generateStudySchedule($storedType, $assessmentTitle, $examDate, $examTime, $coverage, $difficultyLevel);
            }

            $calculatedStatus = calculateExamStatus($examDate, $examStatus);

            $stmt = $conn->prepare('
                INSERT INTO public."Exam" 
                    ("courseID", "examType", "examTitle", "examDate", "examTime", "examLocation", "examStatus", "difficultyLevel", "pdfFile")
                VALUES 
                    (:courseID, :examType, :examTitle, :examDate, :examTime, :examLocation, :examStatus, :difficultyLevel, NULL)
                RETURNING "examID"
            ');
            $stmt->execute([
                ':courseID'        => $courseID,
                ':examType'        => $assessmentCategory, 
                ':examTitle'       => $title,             
                ':examDate'        => $examDate,
                ':examTime'        => !empty($examTime) ? $examTime : null,
                ':examLocation'    => $examLocation,
                ':examStatus'      => $calculatedStatus,
                ':difficultyLevel' => $difficultyLevel
            ]);
            
            $newExamID = (int)$stmt->fetchColumn();

            $formattedDate = date('d F Y', strtotime($examDate));
            $notifMsg = "New assessment added: {$courseData['courseCode']} - {$storedType} on {$formattedDate}";
            
            $notifStmt = $conn->prepare('
                INSERT INTO "Notification" ("studentID", "notificationMessage", "sentDate", "notificationStatus")
                VALUES (:studentID, :message, CURRENT_TIMESTAMP, \'Unread\')
            ');
            $notifStmt->execute([':studentID' => $studentID, ':message' => $notifMsg]);

            if ($newExamID) {
                try {
                    $scheduleManager = new ScheduleManager($conn);
                    $semStmt = $conn->prepare('SELECT s."startDate", s."endDate" FROM "Course" c JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" WHERE c."courseID" = :courseID AND s."studentID" = :studentID LIMIT 1');
                    $semStmt->execute([':courseID' => $courseID, ':studentID' => $studentID]);
                    $sem = $semStmt->fetch(PDO::FETCH_ASSOC);
                    $semStart = $sem['startDate'] ?? date('Y-m-d');
                    $semEnd = $sem['endDate'] ?? date('Y-m-d');

                    $scheduleManager->syncExamSchedule($studentID, $newExamID, $examDate, $storedType, $semStart, $semEnd, $difficultyLevel, $calculatedStatus, $courseID);
                } catch (Exception $e) {
                    // silent
                }
            }

            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to add assessment record.']);
            exit;
        }
    }

    // ACTION: UPDATE SINGLE ASSESSMENT (EDIT)
    if ($action === 'updateExam') {
        $examID = intval($input['examID'] ?? 0);
        $courseID = intval($input['courseID'] ?? 0);
        $assessmentCategory = trim($input['assessmentCategory'] ?? 'Quiz');
        $title = trim($input['title'] ?? '');
        $examDate = trim($input['examDate'] ?? '');
        $examTime = trim($input['examTime'] ?? '');
        $examLocation = trim($input['examLocation'] ?? '');
        $difficultyLevel = trim($input['difficultyLevel'] ?? 'Medium');

        if (!$examID || !$courseID || $examDate === '') {
            echo json_encode(['success' => false, 'error' => 'All required fields must be filled.']);
            exit;
        }

        try {
            $check = $conn->prepare('
                SELECT e."examID"
                FROM "Exam" e
                JOIN "Course" c ON e."courseID" = c."courseID"
                JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                WHERE e."examID" = :examID AND s."studentID" = :studentID
            ');
            $check->execute([':examID' => $examID, ':studentID' => $studentID]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Assessment not found or unauthorized']);
                exit;
            }

            $calculatedStatus = calculateExamStatus($examDate);
            $storedType = !empty($title) ? ($assessmentCategory . ': ' . $title) : $assessmentCategory;

            $stmt = $conn->prepare('
                UPDATE public."Exam" 
                SET "courseID" = :courseID,
                    "examType" = :examType,
                    "examTitle" = :title,
                    "examDate" = :examDate,
                    "examTime" = :examTime,
                    "examLocation" = :examLocation,
                    "examStatus" = :examStatus,
                    "difficultyLevel" = :difficultyLevel
                WHERE "examID" = :examID
            ');
            $stmt->execute([
                ':courseID'        => $courseID,
                ':examType'        => $assessmentCategory,
                ':title'           => $title,
                ':examDate'        => $examDate,
                ':examTime'        => !empty($examTime) ? $examTime : null,
                ':examLocation'    => $examLocation,
                ':examStatus'      => $calculatedStatus,
                ':difficultyLevel' => $difficultyLevel,
                ':examID'          => $examID
            ]);

            try {
                $scheduleManager = new ScheduleManager($conn);
                $semStmt = $conn->prepare('SELECT s."startDate", s."endDate" FROM "Course" c JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" WHERE c."courseID" = :courseID AND s."studentID" = :studentID LIMIT 1');
                $semStmt->execute([':courseID' => $courseID, ':studentID' => $studentID]);
                $sem = $semStmt->fetch(PDO::FETCH_ASSOC);
                $semStart = $sem['startDate'] ?? date('Y-m-d');
                $semEnd = $sem['endDate'] ?? date('Y-m-d');

                $scheduleManager->syncExamSchedule($studentID, $examID, $examDate, $storedType, $semStart, $semEnd, $difficultyLevel, $calculatedStatus, $courseID);
            } catch (Exception $e) {
                // silent
            }

            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to update assessment record.']);
            exit;
        }
    }

    // ACTION: UPDATE SUBTASK PROGRESS
    if ($action === 'updateSubtaskProgress') {
        $examID = intval($input['examID'] ?? 0);
        $subTasks = trim($input['subTasks'] ?? '');
        $examStatus = trim($input['examStatus'] ?? 'Not Started');

        try {
            $check = $conn->prepare('
                SELECT 1 FROM "Exam" e
                JOIN "Course" c ON e."courseID" = c."courseID"
                JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                WHERE e."examID" = :examID AND s."studentID" = :studentID
            ');
            $check->execute([':examID' => $examID, ':studentID' => $studentID]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                exit;
            }

            $stmt = $conn->prepare('UPDATE "Exam" SET "examStatus" = :examStatus WHERE "examID" = :examID');
            $stmt->execute([':examStatus' => $examStatus, ':examID' => $examID]);

            try {
                $scheduleManager = new ScheduleManager($conn);
                $eStmt = $conn->prepare('SELECT "examDate", "courseID" FROM "Exam" WHERE "examID" = :examID');
                $eStmt->execute([':examID' => $examID]);
                $er = $eStmt->fetch(PDO::FETCH_ASSOC);
                if ($er) {
                    $examDate = $er['examDate'];
                    $courseID = $er['courseID'];
                    $semStmt = $conn->prepare('SELECT s."startDate", s."endDate" FROM "Course" c JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" WHERE c."courseID" = :courseID AND s."studentID" = :studentID LIMIT 1');
                    $semStmt->execute([':courseID' => $courseID, ':studentID' => $studentID]);
                    $sem = $semStmt->fetch(PDO::FETCH_ASSOC);
                    $semStart = $sem['startDate'] ?? date('Y-m-d');
                    $semEnd = $sem['endDate'] ?? date('Y-m-d');

                    if ($examStatus === 'Completed') {
                        $delS = $conn->prepare('DELETE FROM "Schedule" WHERE "referenceID" = :ref AND "referenceType" = \'Exam\'');
                        $delS->execute([':ref' => $examID]);
                        $delN = $conn->prepare('DELETE FROM "Notification" WHERE "studentID" = :studentID AND "notificationMessage" LIKE :pat');
                        $delN->execute([':studentID' => $studentID, ':pat' => '%Exam%']);
                    } else {
                        $scheduleManager->syncExamSchedule($studentID, $examID, $examDate, 'Exam', $semStart, $semEnd);
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

    // ACTION: DELETE ASSESSMENT
    if ($action === 'deleteExam') {
        $examID = intval($input['examID'] ?? 0);
        try {
            $check = $conn->prepare('
                SELECT e."pdfFile" FROM "Exam" e
                JOIN "Course" c ON e."courseID" = c."courseID"
                JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                WHERE e."examID" = :examID AND s."studentID" = :studentID
            ');
            $check->execute([':examID' => $examID, ':studentID' => $studentID]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'Assessment not found or unauthorized']);
                exit;
            }

            if (!empty($row['pdfFile']) && file_exists($row['pdfFile'])) {
                @unlink($row['pdfFile']);
            }

            $scheduleManager = new ScheduleManager($conn);
            $scheduleManager->cleanupTaskData($studentID, $examID, 'Exam');

            $delExam = $conn->prepare('DELETE FROM "Exam" WHERE "examID" = :examID');
            $delExam->execute([':examID' => $examID]);
            
            try {
                $delS = $conn->prepare('DELETE FROM "Schedule" WHERE CAST("referenceID" AS INTEGER) = :ref AND "referenceType" = \'Exam\'');
                $delS->execute([':ref' => $examID]);
                
                $delN = $conn->prepare('DELETE FROM "Notification" WHERE "studentID" = :studentID AND "notificationMessage" LIKE :pat');
                $delN->execute([':studentID' => $studentID, ':pat' => '%Exam%']);
            } catch (Exception $e) {
                // ignore
            }

            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to delete record']);
            exit;
        }
    }

    // ACTION: UPDATE PREFERRED STUDY TIME
    if ($action === 'updatePreferredStudyTime') {
        $preferredStudyTime = trim($input['preferredStudyTime'] ?? '');
        if (empty($preferredStudyTime)) {
            echo json_encode(['success' => false, 'error' => 'Preferred study time is required.']);
            exit;
        }

        try {
            $scheduleManager = new ScheduleManager($conn);
            $success = $scheduleManager->updatePreferredStudyTime($studentID, $preferredStudyTime);
            echo json_encode(['success' => (bool)$success]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to update preferred study time.']);
            exit;
        }
    }

    if ($action === 'updateExamStudyProgress') {
        $examID = intval($input['examID'] ?? 0);
        $subTasks = $input['subTasks'] ?? [];
        $examStatus = trim($input['examStatus'] ?? 'Not Started');

        try {
            // Verify ownership
            $check = $conn->prepare('
                SELECT 1 FROM "Exam" e
                JOIN "Course" c ON e."courseID" = c."courseID"
                JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                WHERE e."examID" = :examID AND s."studentID" = :studentID
            ');
            $check->execute([':examID' => $examID, ':studentID' => $studentID]);
            
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized or exam not found']);
                exit;
            }

            // Encode updated study sessions back to JSON
            $jsonSubtasks = json_encode($subTasks);

            // Update exam status and subTasks in the database
            $stmt = $conn->prepare('
                UPDATE "Exam" 
                SET "studytask" = :studytask, "examStatus" = :examStatus 
                WHERE "examID" = :examID'
            );
            $stmt->execute([
                ':studytask'  => json_encode($data['studytask'] ?? []),
                ':examStatus' => $data['examStatus'],
                ':examID'     => $data['examID']
            ]);

            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to save study progress']);
            exit;
        }
    }

    // Fallback for any unhandled JSON actions to prevent outputting HTML
    echo json_encode(['success' => false, 'error' => 'Invalid or unhandled action.']);
    exit;
}
// Fetch All Examinations & Group
$exams = [];
$activeGrouped = [];
$completedGrouped = [];

try {
    $stmt = $conn->prepare('
        SELECT e.*, c."courseCode", c."courseName"
        FROM "Exam" e
        JOIN "Course" c ON e."courseID" = c."courseID"
        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE s."studentID" = :studentID
        ORDER BY c."courseCode", e."examDate" ASC
    ');
    $stmt->execute([':studentID' => $studentID]);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today = new DateTime('today');
    
    foreach ($exams as &$e) {
        $examDateObj = new DateTime($e['examDate']);
        
        if ($e['examStatus'] !== 'Completed') {
            if ($examDateObj < $today) {
                $e['examStatus'] = 'Past Due';
            } elseif ($examDateObj == $today) {
                $e['examStatus'] = 'Ongoing';
            } else {
                $e['examStatus'] = 'Not Started';
            }
        }

        $e['priorityLevel'] = calculatePriority($e['examDate']);
        $courseHeader = $e['courseCode'] . ' - ' . $e['courseName'];
        
        if ($e['examStatus'] === 'Completed') {
            if (!isset($completedGrouped[$courseHeader])) $completedGrouped[$courseHeader] = [];
            $completedGrouped[$courseHeader][] = $e;
        } else {
            if (!isset($activeGrouped[$courseHeader])) $activeGrouped[$courseHeader] = [];
            $activeGrouped[$courseHeader][] = $e;
        }
    }
} catch (PDOException $e) {
    $errors[] = 'Failed to fetch assessment records.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Management - PlanGenius</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        .filter-menu { display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 5px; }
        .filter-pill { padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 500; cursor: pointer; background: #f1f5f9; color: #475569; border: 1px solid transparent; transition: all 0.2s ease; white-space: nowrap; }
        .filter-pill:hover { background: #e2e8f0; }
        .filter-pill.active { background: #3b82f6; color: white; border-color: #2563eb; }

        .course-group { margin-bottom: 30px; }
        .course-group-title { font-size: 18px; font-weight: 700; color: #1e293b; padding-bottom: 10px; margin-bottom: 15px; border-bottom: 2px solid #e2e8f0; }
        
        .exam-item { background-color: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; transition: all 0.3s ease; display: block; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .exam-item:hover { box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); transform: translateY(-1px); }
        
        .completed-card { background-color: #f8fafc; border-color: #cbd5e1; opacity: 0.85; }
        .completed-card .exam-title { text-decoration: line-through; color: #94a3b8; }
        
        .past-due-card { background-color: #fff5f5; border-color: #fca5a5; }
        .past-due-card .exam-title { color: #991b1b; }

        .badge-status-not-started { background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .badge-status-ongoing { background-color: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }
        .badge-status-completed { background-color: #d1fae5; color: #047857; border: 1px solid #6ee7b7; }
        .badge-status-past-due { background-color: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
        
        .badge-easy { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .badge-medium { background: #fef3c7; color: #b45309; border: 1px solid #fde047; }
        .badge-hard { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

        .completed-section-title { font-size: 18px; font-weight: 700; color: #64748b; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; padding-bottom: 10px; border-bottom: 2px dashed #e2e8f0; }

        .exam-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
        .main-checkbox { width: 22px; height: 22px; cursor: pointer; accent-color: #10b981; margin-top: 2px; flex-shrink: 0; }
        .exam-info { flex: 1; }
        .exam-title { font-size: 16px; font-weight: 600; margin-bottom: 4px; transition: all 0.3s ease; color: #0f172a; }
        .exam-meta { font-size: 13px; color: #64748b; margin-bottom: 8px; display: flex; flex-wrap: wrap; gap: 12px; }
        .exam-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
        
        .exam-actions { display: flex; gap: 8px; margin-left: 16px; }
        .exam-actions button, .exam-actions a { padding: 8px; border: none; background: none; cursor: pointer; color: #64748b; font-size: 18px; text-decoration: none; border-radius: 6px; transition: all 0.2s ease; }
        .exam-actions button:hover, .exam-actions a:hover { color: #0f172a; background: #f1f5f9; }
        
        .subtasks-container { margin-top: 15px; border-top: 1px dashed #e2e8f0; padding-top: 15px; }
        .progress-wrapper { margin-bottom: 15px; }
        .progress-text { display: flex; justify-content: space-between; font-size: 12px; color: #64748b; margin-bottom: 6px; font-weight: 500; }
        .progress-track { width: 100%; height: 6px; background: #f1f5f9; border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; background: #10b981; transition: width 0.4s ease; }
        .subtask-list { display: flex; flex-direction: column; gap: 8px; }
        .subtask-item { display: flex; align-items: flex-start; gap: 10px; font-size: 14px; cursor: pointer; color: #334155; }
        .subtask-item input[type="checkbox"] { margin-top: 3px; accent-color: #10b981; cursor: pointer; width: 16px; height: 16px; }

        /* Assessment Card Types */
        .assessment-card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 10px; }
        .assessment-select-card { background: #ffffff; border: 2px solid #e2e8f0; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.25s ease; position: relative; }
        .assessment-select-card:hover { border-color: #3b82f6; transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.1); }
        .assessment-icon-wrapper { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px auto; font-size: 24px; }
        .assessment-select-card.final-exam .assessment-icon-wrapper { background: #eff6ff; color: #2563eb; }
        .assessment-select-card.quiz .assessment-icon-wrapper { background: #f0fdf4; color: #16a34a; }
        .assessment-select-card.test .assessment-icon-wrapper { background: #faf5ff; color: #9333ea; }
        .assessment-card-title { font-size: 16px; font-weight: 700; color: #1e293b; margin-bottom: 4px; }
        .assessment-card-subtitle { font-size: 12px; color: #64748b; }

        /* Course Selector Cards Grid */
        .course-card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; margin: 16px 0; }
        .course-select-card { background: #fff; border: 2px solid #e2e8f0; border-radius: 10px; padding: 16px; cursor: pointer; transition: all 0.2s ease; user-select: none; position: relative; }
        .course-select-card:hover { border-color: #94a3b8; }
        .course-select-card.selected { border-color: #2563eb; background: #eff6ff; }
        .course-card-status { position: absolute; top: 12px; right: 12px; font-size: 14px; font-weight: 700; color: #cbd5e1; }
        .course-select-card.selected .course-card-status { color: #2563eb; }
        .course-card-code { font-size: 15px; font-weight: 700; color: #1e293b; margin-bottom: 4px; }
        .course-card-name { font-size: 13px; color: #64748b; line-height: 1.3; }

        .drop-zone { border: 2px dashed #cbd5e1; border-radius: 8px; padding: 30px 20px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: #f8fafc; }
        .drop-zone:hover { background: #f1f5f9; border-color: #3b82f6; }

        /* Extracted Exam Form Row */
        .extracted-exam-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
        .extracted-exam-header { font-size: 15px; font-weight: 700; color: #1e293b; margin-bottom: 10px; border-bottom: 1px solid #cbd5e1; padding-bottom: 6px; }
        .extracted-exam-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; font-size: 13px; color: #475569; margin-bottom: 12px; }
        .extracted-exam-details span { display: block; }
        .extracted-exam-details strong { color: #0f172a; }
        .extracted-input-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
    </style>
</head>
<body>
    <?php 
    $current_page = 'exams';
    include 'includes/sidebar.php'; 
    ?>

    <main class="main-content">
        <?php include 'includes/header.php'; ?>

        <div class="dashboard-container">
            <div class="page-header flex-between">
                <div>
                    <h1 class="page-title">Exam Management</h1>
                    <p class="page-subtitle">Track final examinations, quizzes, tests, and study revision schedules</p>
                </div>
                <button class="btn btn-primary" onclick="openAssessmentModal()">
                    <i class="ph ph-plus"></i> Add Assessment
                </button>
            </div>

            <?php if (count($exams) > 0): ?>
            <div class="search-container" style="position: relative; margin-bottom: 20px;">
                <i class="ph ph-magnifying-glass" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 18px;"></i>
                <input type="text" id="searchInput" class="form-control" style="padding-left: 44px; border-radius: 999px;" placeholder="Search assessments by course code or title..." oninput="handleSearch(event)">
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

            <div class="empty-state-card" id="emptyState" style="<?= count($exams) > 0 ? 'display:none' : '' ?>">
                <i class="ph ph-calendar-x" style="font-size: 48px; color: #cbd5e1; margin-bottom: 16px; display: block;"></i>
                <span id="emptyStateMessage">No examinations, quizzes, or tests found.</span>
            </div>

            <div id="examsList">
                <?php foreach (['active' => $activeGrouped, 'completed' => $completedGrouped] as $sectionType => $group): ?>
                    <?php if (empty($group)) continue; ?>
                    
                    <div id="<?= $sectionType === 'active' ? 'activeSection' : 'completedSection' ?>" style="<?= $sectionType === 'completed' ? 'margin-top: 40px;' : '' ?>">
                        
                        <?php if ($sectionType === 'completed'): ?>
                            <div class="completed-section-title"><i class="ph-fill ph-check-circle"></i> Completed Assessments</div>
                        <?php endif; ?>

                        <?php foreach ($group as $courseHeader => $courseExams): ?>
                            <div class="course-group">
                                <h3 class="course-group-title"><i class="ph ph-books"></i> <?= htmlspecialchars($courseHeader) ?></h3>
                                
                                <?php foreach ($courseExams as $exam): ?>
                                    <?php 
                                        $priorityClass = 'badge-priority-' . strtolower($exam['priorityLevel']); 
                                        
                                        $subtaskList = array_filter(array_map('trim', explode("\n", $exam['subTasks'] ?? '')));
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
                                            $parsedSubtasks[] = ['text' => $taskText, 'checked' => $isChecked];
                                        }
                                        $progressPercent = $totalSub > 0 ? round(($completedSub / $totalSub) * 100) : 0;

                                        $isCompleted = ($exam['examStatus'] === 'Completed');
                                        $isPastDue = ($exam['examStatus'] === 'Past Due');

                                        $statusClass = 'badge-status-not-started';
                                        if ($exam['examStatus'] === 'Ongoing') $statusClass = 'badge-status-ongoing';
                                        elseif ($isCompleted) $statusClass = 'badge-status-completed';
                                        elseif ($isPastDue) $statusClass = 'badge-status-past-due';
                                        
                                        $cardClass = 'exam-item';
                                        if ($isCompleted) $cardClass .= ' completed-card';
                                        if ($isPastDue) $cardClass .= ' past-due-card';

                                        $diffClass = ($exam['difficultyLevel'] === 'Hard') ? 'badge-hard' : (($exam['difficultyLevel'] === 'Easy') ? 'badge-easy' : 'badge-medium');
                                    ?>
                                    <div class="<?= $cardClass ?>" id="exam-<?= $exam['examID'] ?>" data-priority="<?= htmlspecialchars($exam['priorityLevel']) ?>" data-status="<?= htmlspecialchars($exam['examStatus']) ?>">
                                        <div class="exam-header">
                                            <input type="checkbox" 
                                                   class="main-checkbox" 
                                                   id="main-checkbox-<?= $exam['examID'] ?>" 
                                                   <?= $isCompleted ? 'checked' : '' ?>
                                                   onchange="toggleMainCheckbox(<?= $exam['examID'] ?>, this)"
                                                   title="Mark assessment as completed">
                                            
                                            <div class="exam-info">
                                                <?php 
                                                    $examType = $exam['examType'] ?? 'Final Examination';
                                                    $examTitle = $exam['examTitle'] ?? '';
                                                    if (!empty($examTitle)) {
                                                        $displayExamTitle = (stripos($examTitle, $examType) !== false) ? $examTitle : ($examType . ': ' . $examTitle);
                                                    } else {
                                                        $displayExamTitle = $examType;
                                                    }
                                                ?>
                                                <div class="exam-title"><?= htmlspecialchars($exam['courseCode']) ?> - <?= htmlspecialchars($displayExamTitle) ?></div>
                                                <div class="exam-meta">
                                                    <span><i class="ph ph-calendar"></i> Date: <?= date('M d, Y', strtotime($exam['examDate'])) ?></span>
                                                    <?php if(!empty($exam['examTime'])): ?>
                                                        <span><i class="ph ph-clock"></i> Time: <?= date('g:i A', strtotime($exam['examTime'])) ?></span>
                                                    <?php endif; ?>
                                                    <?php if(!empty($exam['examLocation'])): ?>
                                                        <span><i class="ph ph-map-pin"></i> Venue: <?= htmlspecialchars($exam['examLocation']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="exam-badges">
                                                    <span class="badge badge-type"><?= htmlspecialchars(strtok($exam['examType'] ?? 'Final Examination', ':')) ?></span>
                                                    <span class="badge <?= $diffClass ?>"><?= htmlspecialchars($exam['difficultyLevel']) ?></span>
                                                    <span class="badge <?= $priorityClass ?>"><i class="ph ph-warning"></i> <?= htmlspecialchars($exam['priorityLevel']) ?> Priority</span>
                                                    <span id="status-badge-<?= $exam['examID'] ?>" class="badge <?= $statusClass ?>">
                                                        <?= htmlspecialchars($exam['examStatus']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="exam-actions">
                                                <button onclick="openEditAssessmentModal(<?= htmlspecialchars(json_encode($exam), ENT_QUOTES, 'UTF-8') ?>)" title="Edit">
                                                    <i class="ph ph-pencil-simple"></i>
                                                </button>
                                                <button onclick="deleteExam(<?= $exam['examID'] ?>)" title="Delete">
                                                    <i class="ph ph-trash"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <?php if ($totalSub > 0): ?>
                                            <div class="subtasks-container">
                                                <div class="progress-wrapper">
                                                    <div class="progress-text">
                                                        <span>Study Progress</span>
                                                        <span id="progress-text-<?= $exam['examID'] ?>"><?= $progressPercent ?>%</span>
                                                    </div>
                                                    <div class="progress-track">
                                                        <div id="progress-bar-<?= $exam['examID'] ?>" class="progress-fill" style="width: <?= $progressPercent ?>%;"></div>
                                                    </div>
                                                </div>
                                                <div class="subtask-list">
                                                    <?php foreach ($parsedSubtasks as $index => $subtask): ?>
                                                        <label class="subtask-item">
                                                            <input type="checkbox" onchange="toggleSubtask(<?= $exam['examID'] ?>, <?= $index ?>, this)" <?= $subtask['checked'] ? 'checked' : '' ?>>
                                                            <span id="subtask-text-<?= $exam['examID'] ?>-<?= $index ?>" style="<?= $subtask['checked'] ? 'text-decoration: line-through; color: #94a3b8;' : '' ?>">
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

    <!-- ADD / EDIT ASSESSMENT MODAL -->
    <div class="modal" id="addAssessmentModal" style="max-width: 650px;">
        <div class="modal-header">
            <h2 class="modal-title" id="assessmentModalTitle">Add Assessment</h2>
            <button class="close-modal-btn" onclick="closeModal('addAssessmentModal')"><i class="ph ph-x"></i></button>
        </div>

        <div class="modal-body">
            <!-- STEP 1: CARD TYPE SELECTION -->
            <div id="stepCardSelection">
                <p style="color: #64748b; font-size: 14px; margin-bottom: 12px;">Select the type of assessment you want to add:</p>
                <div class="assessment-card-grid">
                    <div class="assessment-select-card final-exam" onclick="selectAssessmentType('Final Examination')">
                        <div class="assessment-icon-wrapper"><i class="ph ph-graduation-cap"></i></div>
                        <div class="assessment-card-title">Final Examination</div>
                        <div class="assessment-card-subtitle">Automated timetable PDF extraction for active semester courses</div>
                    </div>
                    <div class="assessment-select-card quiz" onclick="selectAssessmentType('Quiz')">
                        <div class="assessment-icon-wrapper"><i class="ph ph-note"></i></div>
                        <div class="assessment-card-title">Quiz</div>
                        <div class="assessment-card-subtitle">Schedule single course quiz revision</div>
                    </div>
                    <div class="assessment-select-card test" onclick="selectAssessmentType('Test')">
                        <div class="assessment-icon-wrapper"><i class="ph ph-exam"></i></div>
                        <div class="assessment-card-title">Test</div>
                        <div class="assessment-card-subtitle">Schedule mid-term or class test</div>
                    </div>
                </div>
            </div>

            <!-- STEP 2: FINAL EXAMINATION COURSE SELECTION & PDF FLOW -->
            <div id="stepFinalExamFlow" style="display: none;">
                <div style="margin-bottom: 12px;">
                    <p style="font-size: 13px; color: #2563eb; font-weight: 600;" id="activeSemesterTitle"><?= htmlspecialchars($activeSemesterName) ?></p>
                </div>

                <label style="font-size: 13px; font-weight: 600; color: #475569;">Select Active Courses to include:</label>
                
                <div class="course-card-grid" id="courseCardsGrid">
                    <?php if (empty($activeCourses)): ?>
                        <p style="font-size: 13px; color: #94a3b8; grid-column: 1/-1;">No active courses found for the current semester.</p>
                    <?php else: ?>
                        <?php foreach ($activeCourses as $c): ?>
                            <div class="course-select-card selected" 
                                    data-course-id="<?= htmlspecialchars($c['courseID'] ?? $c['courseid'] ?? '') ?>" 
                                    data-course-code="<?= htmlspecialchars($c['courseCode'] ?? $c['coursecode'] ?? '') ?>"
                                    data-course-name="<?= htmlspecialchars($c['courseName'] ?? $c['coursename'] ?? '') ?>"
                                    onclick="toggleCourseCardSelection(this)">
                                  <div class="course-card-code"><?= htmlspecialchars($c['courseCode']) ?></div>
                                  <div class="course-card-name"><?= htmlspecialchars($c['courseName']) ?></div>
                                  <div style="margin-top: 10px;" onclick="event.stopPropagation()">
                                      <label style="font-size: 11px; font-weight: 700; color: #475569;">Your Section:</label>
                                      <input type="text" class="course-section-input form-control" placeholder="e.g. 01" style="padding: 4px 8px; font-size: 12px; margin-top: 4px;">
                                  </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-top: 20px;">
                    <label><strong>Upload Final Examination Timetable PDF</strong></label>
                    <div class="drop-zone" id="examDropZone" onclick="document.getElementById('examPdfInput').click()">
                        <i class="ph ph-file-pdf" style="font-size: 36px; color: #3b82f6; margin-bottom: 8px; display: block;"></i>
                        <span id="dropZoneText" style="font-size: 14px; font-weight: 600; color: #334155;">Click to upload or drag & drop PDF timetable</span>
                        <input type="file" id="examPdfInput" accept=".pdf" style="display: none;" onchange="handleExamPdfUpload(this.files)">
                    </div>
                </div>

                <div id="extractedResultsContainer" style="display: none; margin-top: 20px;">
                    <h4 style="font-size: 14px; font-weight: 700; color: #1e293b; margin-bottom: 12px;">Extracted Examination Schedules</h4>
                    <div id="extractedExamCardsList"></div>
                    <button type="button" class="btn btn-primary btn-block" style="margin-top: 15px;" onclick="saveFinalExaminationBatch()">Save Final Examination</button>
                </div>
            </div>

            <!-- STEP 3: SINGLE ASSESSMENT FORM (QUIZ / TEST / SINGLE EDIT) -->
            <div id="stepSingleAssessmentForm" style="display: none;">
                <form id="singleExamForm" onsubmit="handleSaveSingleAssessment(event)">
                    <input type="hidden" id="editExamID" value="0">
                    <input type="hidden" id="singleCategory" value="Quiz">
                    <div class="form-group">
                        <label>Course</label>
                        <select id="singleCourseID" class="form-control" required>
                            <option value="">Select Course</option>
                            <?php foreach ($activeCourses as $ac): ?>
                                <option value="<?= $ac['courseID'] ?>"><?= htmlspecialchars($ac['courseCode']) ?> - <?= htmlspecialchars($ac['courseName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label id="titleLabel">Title / Topic</label>
                        <input type="text" id="singleTitle" class="form-control" placeholder="e.g. Chapter 1-3 Quiz" required>
                    </div>
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" id="singleExamDate" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Time</label>
                        <input type="time" id="singleExamTime" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Location / Room</label>
                        <input type="text" id="singleExamLocation" class="form-control" placeholder="e.g. DK1 or Online">
                    </div>
                    <div class="form-group">
                        <label>Difficulty</label>
                        <select id="singleDifficulty" class="form-control">
                            <option value="Easy">Easy</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="Hard">Hard</option>
                        </select>
                    </div>
                  <div class="preferred-time-card" id="preferredTimeCard" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; display: none; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i class="ph ph-clock" style="font-size: 24px; color: #3b82f6;"></i>
                        <div>
                            <strong style="font-size: 15px; color: #1e293b; display: block;">User Preferred Study Time</strong>
                            <span style="font-size: 13px; color: #64748b;">Set your preferred daily start time for exam study sessions (Default: 7:00 PM)</span>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="time" id="userPreferredTimeInput" class="form-control" value="<?= htmlspecialchars(date('H:i', strtotime($userPreferredStudyTime))) ?>" style="width: 140px; font-size: 14px;">
                        <button type="button" class="btn btn-primary" onclick="saveUserPreferredStudyTime()" style="padding: 8px 16px; font-size: 13px;">
                            Save Preference
                        </button>
                    </div>
                </div>
                    <button type="submit" id="saveAssessmentBtn" class="btn btn-primary btn-block">Save Assessment</button>
                </form>
            </div>

        </div>
    </div>

    <!-- DELETE CONFIRMATION MODAL -->
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

    <script>
        const existingExams = <?php echo json_encode($exams); ?>;
        let uploadedPdfPath = '';
        let extractedPdfExams = [];

        function openAssessmentModal() {
            document.getElementById('assessmentModalTitle').innerText = 'Add Assessment';
            document.getElementById('editExamID').value = '0';
            document.getElementById('stepCardSelection').style.display = 'block';
            document.getElementById('stepFinalExamFlow').style.display = 'none';
            document.getElementById('stepSingleAssessmentForm').style.display = 'none';
            document.getElementById('extractedResultsContainer').style.display = 'none';
            document.getElementById('dropZoneText').innerText = 'Click to upload or drag & drop PDF timetable';
            document.getElementById('examPdfInput').value = '';

            // Hide Preferred Study Time when adding an assessment
            const preferredTimeCard = document.getElementById('preferredTimeCard');
            if (preferredTimeCard) preferredTimeCard.style.display = 'none';

            openModal('addAssessmentModal');
        }

        function selectAssessmentType(type) {
            document.getElementById('stepCardSelection').style.display = 'none';
            
            let displayTitleType = (type === 'Final Examination') ? 'Final Exam' : type;
            document.getElementById('assessmentModalTitle').innerText = 'Add Assessment: ' + displayTitleType;

            if (type === 'Final Examination') {
                document.getElementById('stepFinalExamFlow').style.display = 'block';
            } else {
                document.getElementById('editExamID').value = '0';
                document.getElementById('singleCategory').value = type;
                document.getElementById('singleCourseID').value = '';
                document.getElementById('singleTitle').value = '';
                document.getElementById('singleExamDate').value = '';
                document.getElementById('singleExamTime').value = '';
                document.getElementById('singleExamLocation').value = '';
                document.getElementById('singleDifficulty').value = 'Medium';

                // Ensure Preferred Study Time stays hidden when selecting Quiz/Test for adding
                const preferredTimeCard = document.getElementById('preferredTimeCard');
                if (preferredTimeCard) preferredTimeCard.style.display = 'none';
                
                const titleInput = document.getElementById('singleTitle');
                if (type === 'Quiz') {
                    titleInput.placeholder = 'e.g. Chapter 1-3 Quiz';
                } else if (type === 'Test') {
                    titleInput.placeholder = 'e.g. Mid-Term Test';
                } else {
                    titleInput.placeholder = 'e.g. Final Examination Paper';
                }

                document.getElementById('stepSingleAssessmentForm').style.display = 'block';
            }
        }

        function openEditAssessmentModal(exam) {
            document.getElementById('stepCardSelection').style.display = 'none';
            document.getElementById('stepFinalExamFlow').style.display = 'none';
            document.getElementById('stepSingleAssessmentForm').style.display = 'block';

            const category = exam.examType || 'Quiz';
            let displayTitleType = (category === 'Final Examination' || category === 'Final') ? 'Final Exam' : category;

            document.getElementById('assessmentModalTitle').innerText = 'Edit Assessment: ' + displayTitleType;
            document.getElementById('editExamID').value = exam.examID;
            document.getElementById('singleCategory').value = category;
            document.getElementById('singleCourseID').value = exam.courseID;
            document.getElementById('singleTitle').value = exam.examTitle || '';
            document.getElementById('singleExamDate').value = exam.examDate || '';
            document.getElementById('singleExamTime').value = exam.examTime ? exam.examTime.substring(0, 5) : '';
            document.getElementById('singleExamLocation').value = exam.examLocation || '';
            document.getElementById('singleDifficulty').value = exam.difficultyLevel || 'Medium';

            // Show Preferred Study Time ONLY when editing an assessment
            const preferredTimeCard = document.getElementById('preferredTimeCard');
            if (preferredTimeCard) preferredTimeCard.style.display = 'flex';
            
            openModal('addAssessmentModal');
        }

        function toggleCourseCardSelection(card) {
            card.classList.toggle('selected');
            const statusSpan = card.querySelector('.course-card-status');
            if (statusSpan) {
                statusSpan.innerText = card.classList.contains('selected') ? '[✓]' : '[ ]';
            }
        }

        async function handleExamPdfUpload(files) {
            if (!files.length) return;
            const file = files[0];
            if (file.type !== 'application/pdf') {
                showNotification('Please upload a valid PDF file.', 'error');
                return;
            }

            document.getElementById('dropZoneText').innerText = 'Extracting examination data...';

            const formData = new FormData();
            formData.append('examPdf', file); 
            formData.append('type', 'exam');

            try {
                const response = await fetch('upload_exam_pdf.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || 'Failed to extract PDF data');
                }

                uploadedPdfPath = data.filePath || '';
                extractedPdfExams = data.exams || [];
                renderExtractedExamsUI();

            } catch (err) {
                showNotification(err.message || 'Error processing PDF', 'error');
                document.getElementById('dropZoneText').innerText = 'Click to upload or drag & drop PDF timetable';
            }
        }

        function renderExtractedExamsUI() {
            const selectedCourseCards = Array.from(document.querySelectorAll('.course-select-card.selected'));
            if (selectedCourseCards.length === 0) {
                showNotification('Please select at least one active course.', 'error');
                return;
            }

            const container = document.getElementById('extractedExamCardsList');
            container.innerHTML = '';

            selectedCourseCards.forEach(card => {
                const cId = card.getAttribute('data-course-id') || card.dataset.courseId;
                const cCode = card.getAttribute('data-course-code') || card.dataset.courseCode;
                const cName = card.getAttribute('data-course-name') || card.dataset.courseName;

                const matchedExtracted = extractedPdfExams.find(e => 
                    e.course && e.course.toUpperCase().includes(cCode.toUpperCase())
                ) || {};

                const dateVal = matchedExtracted.day || '';
                const timeVal = matchedExtracted.time_slot || '09:00 AM - 12:00 PM';
                const locVal = matchedExtracted.location || 'DK1';

                const cardHtml = `
                    <div class="extracted-exam-card" data-course-id="${cId}">
                        <div class="extracted-exam-header">${cCode} - ${cName}</div>
                        <div class="extracted-exam-details">
                            <span><strong>Date:</strong> ${dateVal ? dateVal : 'Extracted from PDF'}</span>
                            <span><strong>Time:</strong> ${timeVal}</span>
                            <span><strong>Location:</strong> ${locVal}</span>
                        </div>
                        <input type="hidden" class="ex-date" value="${dateVal}">
                        <input type="hidden" class="ex-time" value="${timeVal}">
                        <input type="hidden" class="ex-location" value="${locVal}">
                        
                        <div class="extracted-input-grid">
                            <div>
                                <label style="font-size: 11px; font-weight: 600; color: #475569;">Section</label>
                                <input type="text" class="form-control ex-section" value="${card.querySelector('.course-section-input')?.value || ''}" readonly style="padding: 6px 10px; font-size: 13px; background-color: #e2e8f0; cursor: not-allowed;">
                            </div>
                            <div>
                                <label style="font-size: 11px; font-weight: 600; color: #475569;">Difficulty</label>
                                <select class="form-control ex-difficulty" style="padding: 6px 10px; font-size: 13px;">
                                    <option value="Easy">Easy</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="Hard">Hard</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-size: 11px; font-weight: 600; color: #475569;">Type</label>
                                <select class="form-control ex-type" style="padding: 6px 10px; font-size: 13px;">
                                    <option value="Theory">Theory</option>
                                    <option value="Technical">Technical</option>
                                    <option value="Math">Math</option>
                                </select>
                            </div>
                        </div>
                    </div>
                `;
                container.innerHTML += cardHtml;
            });

            document.getElementById('extractedResultsContainer').style.display = 'block';
            document.getElementById('dropZoneText').innerText = 'PDF Extracted Successfully';
        }

        async function saveFinalExaminationBatch() {
            const cards = document.querySelectorAll('.extracted-exam-card');
            const examsToSave = [];

            cards.forEach(card => {
                examsToSave.push({
                    courseID: card.getAttribute('data-course-id') || card.dataset.courseId,
                    examDate: card.querySelector('.ex-date').value,
                    examTime: card.querySelector('.ex-time').value,
                    examLocation: card.querySelector('.ex-location').value,
                    section: card.querySelector('.ex-section').value,
                    difficulty: card.querySelector('.ex-difficulty').value,
                    type: card.querySelector('.ex-type').value
                });
            });

            try {
                const response = await fetch('exam.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'saveBatchFinalExams',
                        exams: examsToSave,
                        pdfFile: uploadedPdfPath
                    })
                });
                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || 'Failed to save examination records');
                }

                showNotification('Final Examination records saved successfully.', 'success');
                closeModal('addAssessmentModal');
                setTimeout(() => location.reload(), 1000);

            } catch (err) {
                showNotification(err.message || 'Error saving examinations', 'error');
            }
        }

        async function handleSaveSingleAssessment(e) {
            e.preventDefault();
            const examID = parseInt(document.getElementById('editExamID').value || '0');
            const category = document.getElementById('singleCategory').value;
            const courseID = document.getElementById('singleCourseID').value;
            const title = document.getElementById('singleTitle').value;
            const examDate = document.getElementById('singleExamDate').value;
            const examTime = document.getElementById('singleExamTime').value;
            const examLocation = document.getElementById('singleExamLocation').value;
            const difficultyLevel = document.getElementById('singleDifficulty').value;

            const action = examID > 0 ? 'updateExam' : 'addExam';
            const payload = {
                action: action,
                assessmentCategory: category,
                courseID: parseInt(courseID),
                title: title,
                examDate: examDate,
                examTime: examTime,
                examLocation: examLocation,
                difficultyLevel: difficultyLevel
            };

            if (examID > 0) {
                payload.examID = examID;
            }

            try {
                const response = await fetch('exam.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();

                if (!data.success) throw new Error(data.error || 'Failed to save assessment');

                showNotification(examID > 0 ? 'Assessment updated successfully.' : 'Assessment saved successfully.', 'success');
                closeModal('addAssessmentModal');
                setTimeout(() => location.reload(), 1200);
            } catch (err) {
                showNotification(err.message || 'Error saving assessment', 'error');
            }
        }

        async function toggleSubtask(examID, index, checkboxElem) {
            const exam = existingExams.find(e => e.examID == examID);
            if (!exam) return;

            let lines = exam.subTasks.split('\n').map(l => l.trim()).filter(l => l.length > 0);
            let line = lines[index];
            
            let coreText = line.replace(/^\[(x|X|\s)?\]\s*/, '');
            lines[index] = (checkboxElem.checked ? '[x] ' : '[ ] ') + coreText;
            exam.subTasks = lines.join('\n');

            const textSpan = document.getElementById(`subtask-text-${examID}-${index}`);
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
            const newStatus = (completed === total) ? 'Completed' : 'Not Started';

            document.getElementById(`progress-text-${examID}`).innerText = percentage + '%';
            document.getElementById(`progress-bar-${examID}`).style.width = percentage + '%';

            try {
                await fetch('exam.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'updateSubtaskProgress',
                        examID: examID,
                        subTasks: exam.subTasks,
                        examStatus: newStatus 
                    })
                });
            } catch (err) {
                showNotification('Could not save progress', 'error');
            }
        }

        async function toggleMainCheckbox(examID, checkboxElem) {
            const isChecked = checkboxElem.checked;
            const newStatus = isChecked ? 'Completed' : 'Not Started';

            try {
                const response = await fetch('exam.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'updateSubtaskProgress',
                        examID: examID,
                        subTasks: '',
                        examStatus: newStatus
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showNotification('Status updated', 'success');
                    setTimeout(() => location.reload(), 800);
                }
            } catch (err) {
                showNotification('Failed to update status', 'error');
            }
        }

        let examIdToDelete = null;

        function deleteExam(examID) {
            examIdToDelete = examID;
            document.getElementById('confirmDeleteBtn').onclick = confirmDeleteExam;
            openModal('deleteConfirmModal');
        }

        async function confirmDeleteExam() {
            if (!examIdToDelete) return;

            try {
                const response = await fetch('exam.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'deleteExam', examID: parseInt(examIdToDelete) })
                });
                const data = await response.json();
                
                if (data.success) {
                    closeModal('deleteConfirmModal');
                    showNotification('Deleted successfully', 'success');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showNotification(data.error || 'Failed to delete', 'error');
                }
            } catch (err) {
                showNotification('Failed to delete', 'error');
            }
        }

        function handleSearch(e) {
            const term = e.target.value.toLowerCase();
            document.querySelectorAll('.exam-item').forEach(item => {
                const text = item.innerText.toLowerCase();
                item.style.display = text.includes(term) ? 'block' : 'none';
            });
        }

        function filterTasks(type, btnElement) {
            document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
            btnElement.classList.add('active');

            document.querySelectorAll('.exam-item').forEach(item => {
                const status = item.dataset.status;
                const priority = item.dataset.priority;

                if (type === 'All') {
                    item.style.display = 'block';
                } else if (['Not Started', 'Ongoing', 'Completed', 'Past Due'].includes(type)) {
                    item.style.display = (status === type) ? 'block' : 'none';
                } else if (type.includes('Priority')) {
                    item.style.display = (priority + ' Priority' === type) ? 'block' : 'none';
                }
            });
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

        function showNotification(msg, type = 'success') {
            const toast = document.getElementById('notificationToast');
            if (!toast) return;
            const msgSpan = document.getElementById('notificationMessage');
            const icon = toast.querySelector('i');
            
            msgSpan.innerText = msg;
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

        async function saveUserPreferredStudyTime() {
            const timeVal = document.getElementById('userPreferredTimeInput').value;
            if (!timeVal) {
                showNotification('Please select a valid study time.', 'error');
                return;
            }

            try {
                const response = await fetch('exam.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'updatePreferredStudyTime',
                        preferredStudyTime: timeVal
                    })
                });
                const data = await response.json();

                if (data.success) {
                    showNotification('Preferred study time updated successfully.', 'success');
                } else {
                    throw new Error(data.error || 'Failed to update preferred study time');
                }
            } catch (err) {
                showNotification(err.message || 'Error updating study time', 'error');
            }
        }
    </script>
</body>
</html>