<?php
include 'dbConn.php';
session_start();
require_once __DIR__ . '/schedule_Manager.php';
$errors = [];
$message = '';

/*$studentID = $_SESSION['studentID'] ?? null;

if (!$studentID) {
    header('Location: login.php');
    exit;
}*/

$studentID = trim($_SESSION['studentID'] ?? $_SESSION['student_id'] ?? '');

if ($studentID !== '') {
    $stmt = $conn->prepare('SELECT * FROM "Exam" WHERE "studentID" = :studentID');
    $stmt->bindValue(':studentID', $studentID, PDO::PARAM_STR);
    $stmt->execute();

    // fetchAll() retrieves an array of all matching rows
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*if (!empty($exams)) {
        foreach ($exams as $exam) {
            // Updated column keys to match your DB schema ("examTitle" & "examID")
            echo htmlspecialchars($exam['examTitle'] ?? $exam['examID'], ENT_QUOTES, 'UTF-8');
        }
    }*/
}

// Fetch current preferred study time
$scheduleManager = new ScheduleManager($conn);
$userPreferredStudyTime = $scheduleManager->getPreferredStudyTime($studentID);

// Ensure upload directory exists
$uploadDir = 'uploads/exams/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

/*function calculatePriority($examDate) {
    $today = new DateTime('today');
    $exam = new DateTime($examDate);
    $daysUntilDue = $today->diff($exam)->days;
    
    if ($exam < $today) {
        return 'Low';
    }
    if ($daysUntilDue <= 3) { return 'High'; } 
    elseif ($daysUntilDue <= 7) { return 'Medium'; } 
    else { return 'Low'; }
}*/

function calculatePriority($targetDate) {
    $today = new DateTime('today');
    $target = new DateTime($targetDate);
    
    // Past dates default to Low priority
    if ($target < $today) {
        return 'Low';
    }
    
    // Difference is guaranteed to be 0 or positive future days
    $daysRemaining = $today->diff($target)->days;
    
    if ($daysRemaining <= 3) { 
        return 'High'; 
    } elseif ($daysRemaining <= 7) { 
        return 'Medium'; 
    } else { 
        return 'Low'; 
    }
}

//UNCOMMENT NANTI: ORIGINAL FUNCTION. JANGAN DELETE
/*function calculateExamStatus($examDate, $currentStatus = 'Not Started') {
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
}*/

//DEMO: test function
function calculateExamStatus($examDate, $currentStatus = 'Not Started') {
    if ($currentStatus === 'Completed') return 'Completed';
    $today = new DateTime('today');
    $exam = new DateTime($examDate);
    
    //  Comment out the past due condition:
    //if ($exam < $today) {
    //    return 'Past Due';
    //} else 
     if ($exam == $today) {
        return 'Ongoing';
    } else {
        return 'Not Started';
    }
}

// Helper function to map UI exam types to DB check constraint values ('Test', 'Quiz', 'Final')
function normalizeExamType($type) {
    $type = trim($type);
    if (strcasecmp($type, 'Final Examination') === 0 || strcasecmp($type, 'Final Exam') === 0) {
        return 'Final';
    }
    if (in_array($type, ['Test', 'Quiz', 'Final'])) {
        return $type;
    }
    return 'Final';
}

/**
 * Helper function to parse exam Start Time and End Time.
 * If only Start Time is provided (or End Time is missing/invalid), defaults End Time to Start Time + 3 hours.
 */
function parseExamStartAndEndTime($rawTimeStr) {
    $rawTimeStr = trim($rawTimeStr);
    if (empty($rawTimeStr)) {
        return [null, null];
    }

    $startTimeStr = null;
    $endTimeStr = null;

    if (strpos($rawTimeStr, '-') !== false || stristr($rawTimeStr, 'to') !== false) {
        $parts = preg_split('/\s*(?:-|to)\s*/i', $rawTimeStr);
        $startTimeStr = trim($parts[0] ?? '');
        $endTimeStr = trim($parts[1] ?? '');
    } else {
        $startTimeStr = $rawTimeStr;
    }

    $startTs = !empty($startTimeStr) ? strtotime($startTimeStr) : false;
    if ($startTs === false) {
        return [null, null];
    }

    $startTimeFormatted = date('H:i:s', $startTs);

    $endTs = !empty($endTimeStr) ? strtotime($endTimeStr) : false;
    if ($endTs === false || $endTs <= $startTs) {
        $endTs = $startTs + (3 * 3600); // Default fixed 3 hours calculation
    }

    $endTimeFormatted = date('H:i:s', $endTs);

    return [$startTimeFormatted, $endTimeFormatted];
}

/**
 * Automatically populates StudyTask database records for all generated study sessions of an exam.
 * Initial status for each generated session task is false (Not Completed).
 */
function generateStudyTasksForExam($conn, $examID, $studentID, $examTitle = '', $preserveCompleted = false) {
    $schedStmt = $conn->prepare('
        SELECT "scheduleID", "studyDate", "studyTime", "status"
        FROM "Schedule"
        WHERE "studentID" = :studentID
          AND LOWER(TRIM("referenceType")) = \'exam\'
          AND "referenceID" = :examID
        ORDER BY "studyDate" ASC, "studyTime" ASC
    ');
    $schedStmt->execute([':studentID' => $studentID, ':examID' => (string)$examID]);
    $sessions = $schedStmt->fetchAll(PDO::FETCH_ASSOC);
    $schedStmt->closeCursor();

    $existingStatus = [];
    if ($preserveCompleted) {
        $prevStmt = $conn->prepare('SELECT "orderNo", "isCompleted" FROM public."StudyTask" WHERE "examID" = :examID');
        $prevStmt->execute([':examID' => $examID]);
        while ($row = $prevStmt->fetch(PDO::FETCH_ASSOC)) {
            $isDone = filter_var($row['isCompleted'], FILTER_VALIDATE_BOOLEAN) || $row['isCompleted'] === 't' || $row['isCompleted'] === 'true' || $row['isCompleted'] === 1 || $row['isCompleted'] === '1';
            $existingStatus[(int)$row['orderNo']] = $isDone;
        }
        $prevStmt->closeCursor();
    }

    $delStmt = $conn->prepare('DELETE FROM public."StudyTask" WHERE "examID" = :examID');
    $delStmt->execute([':examID' => $examID]);
    $delStmt->closeCursor();

    if (!empty($sessions)) {
        $insStmt = $conn->prepare('
            INSERT INTO public."StudyTask" ("examID", "taskDescription", "isCompleted", "orderNo")
            VALUES (:examID, :taskDescription, :isCompleted, :orderNo)
        ');
        foreach ($sessions as $index => $s) {
            $orderNo = $index + 1;
            $dateFormatted = date('M d, Y', strtotime($s['studyDate']));
            $timeFormatted = !empty($s['studyTime']) ? (' at ' . date('g:i A', strtotime($s['studyTime']))) : '';
            $taskDesc = "Study Session " . $orderNo . ": " . $dateFormatted . $timeFormatted;

            $isCompleted = false;
            if ($preserveCompleted && isset($existingStatus[$orderNo])) {
                $isCompleted = $existingStatus[$orderNo];
            } elseif ($s['status'] === 'Completed') {
                $isCompleted = true;
            }

            $insStmt->execute([
                ':examID'          => $examID,
                ':taskDescription' => $taskDesc,
                ':isCompleted'     => $isCompleted ? 'true' : 'false',
                ':orderNo'         => $orderNo
            ]);
        }
        $insStmt->closeCursor();
    } else {
        $insStmt = $conn->prepare('
            INSERT INTO public."StudyTask" ("examID", "taskDescription", "isCompleted", "orderNo")
            VALUES (:examID, :taskDescription, false, 1)
        ');
        $insStmt->execute([
            ':examID'          => $examID,
            ':taskDescription' => 'Review course material for ' . $examTitle
        ]);
        $insStmt->closeCursor();
    }
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
            SELECT "courseID", "courseCode", "courseName", "section"
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

        // Check if any extracted exam date has already passed
        $todayStr = date('Y-m-d');
        // Fix: Replace slashes with hyphens BEFORE calling strtotime
        foreach ($examsToSave as $item) {
            $rawExamDate = trim($item['examDate'] ?? '');
            if (!empty($rawExamDate)) {
                $normalizedDate = str_replace('/', '-', $rawExamDate);
                $ts = strtotime($normalizedDate);
                //UNCOMMENT NANTI: ORIGINAL FUNCTION. JANGAN DELETE
                /*if ($ts !== false && date('Y-m-d', $ts) < $todayStr) {
                    echo json_encode(['success' => false, 'error' => 'The exam date has already passed.']);
                    exit;
                }*/
            }
        }

        // Ensure formatted date uses normalized string
        $examDate = !empty($rawExamDate) ? date('Y-m-d', strtotime(str_replace('/', '-', $rawExamDate))) : date('Y-m-d');

        try {
            $conn->beginTransaction();
            $scheduleManager = new ScheduleManager($conn);

            foreach ($examsToSave as $item) {
                $courseID = intval($item['courseID'] ?? 0);
                $rawExamDate = trim($item['examDate'] ?? '');
                $rawExamTime = trim($item['examTime'] ?? '');
                
                $examDate = !empty($rawExamDate) ? date('Y-m-d', strtotime($rawExamDate)) : date('Y-m-d');
                
                list($examTime, $examEndTime) = parseExamStartAndEndTime($rawExamTime);

                $examLocation = trim($item['examLocation'] ?? '');
                $section = trim($item['section'] ?? '');
                $difficulty = trim($item['difficulty'] ?? 'Medium');
                //$type = trim($item['type'] ?? 'Theory');

                if (!$courseID) {
                    continue;
                }

                $cCheck = $conn->prepare('
                    SELECT c."courseCode", c."courseName", c."section", s."startDate", s."endDate" 
                    FROM "Course" c
                    JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                    WHERE c."courseID" = :courseID AND s."studentID" = :studentID
                ');
                $cCheck->execute([':courseID' => $courseID, ':studentID' => $studentID]);
                $cData = $cCheck->fetch(PDO::FETCH_ASSOC);
                $cCheck->closeCursor();

                if (!$cData) continue;

                // Always use the section from Academic Structure if available
                if (!empty(trim($cData['section'] ?? ''))) {
                    $section = trim($cData['section']);
                }

                // Time Conflict and 30-Minute Buffer Validation against existing exams
                if (!empty($examTime)) {
                    $dupCheck = $conn->prepare('
                        SELECT e.* FROM "Exam" e
                        JOIN "Course" c ON e."courseID" = c."courseID"
                        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                        WHERE s."studentID" = :studentID
                          AND e."examDate" = :examDate
                    ');
                    $dupCheck->execute([':studentID' => $studentID, ':examDate' => $examDate]);
                    $existingExams = $dupCheck->fetchAll(PDO::FETCH_ASSOC);

                    $newStartTs = strtotime($examDate . ' ' . $examTime);
                    $newEndTs = strtotime($examDate . ' ' . $examEndTime);

                    foreach ($existingExams as $ex) {
                        $exStartRaw = trim($ex['examTime'] ?? '');
                        if (empty($exStartRaw)) continue;

                        $exEndRaw = trim($ex['examEndTime'] ?? $ex['endTime'] ?? '');
                        list($exStartFormatted, $exEndFormatted) = parseExamStartAndEndTime(!empty($exEndRaw) ? ($exStartRaw . ' - ' . $exEndRaw) : $exStartRaw);

                        $exStartTs = strtotime($examDate . ' ' . $exStartFormatted);
                        $exEndTs = strtotime($examDate . ' ' . $exEndFormatted);

                        if ($exStartTs !== false) {
                            if ($newStartTs < ($exEndTs + 1800) && $exStartTs < ($newEndTs + 1800)) {
                                echo json_encode([
                                    'success' => false,
                                    'error'   => "Final Exam for {$cData['courseCode']} conflicts with an existing assessment or falls within the 30-minute buffer period."
                                ]);
                                exit;
                            }
                        }
                    }
                }

                $calculatedStatus = calculateExamStatus($examDate);
                $examCategory = normalizeExamType('Final');
                $examTitle = 'Final Examination' . ($section !== '' ? ' - Sec ' . $section : '');

                $stmt = $conn->prepare('
                    INSERT INTO public."Exam" 
                        ("courseID", "examType", "examTitle", "examDate", "examTime", "examEndTime", "examLocation", "examStatus", "difficultyLevel", "pdfFile", "studentID")
                    VALUES 
                        (:courseID, :examType, :examTitle, :examDate, :examTime, :examEndTime, :examLocation, :examStatus, :difficultyLevel, :pdfFile, :studentID)
                    RETURNING "examID"
                ');

                $stmt->execute([
                    ':courseID'        => $courseID,
                    ':examType'        => $examCategory,
                    ':examTitle'       => $examTitle,
                    ':examDate'        => $examDate,
                    ':examTime'        => $examTime,
                    ':examEndTime'     => $examEndTime,
                    ':examLocation'    => $examLocation,
                    ':examStatus'      => $calculatedStatus,
                    ':difficultyLevel' => $difficulty,
                    ':pdfFile'         => $pdfFile,
                    ':studentID'       => $studentID  // Added missing binding
                ]);
                $newExamID = (int)$stmt->fetchColumn();
                $stmt->closeCursor();

                $formattedDate = date('d F Y', strtotime($examDate));
                $notifMsg = "Final Examination for {$cData['courseCode']} scheduled on {$formattedDate}";
                $notifStmt = $conn->prepare('
                    INSERT INTO "Notification" ("studentID", "notificationMessage", "sentDate", "notificationStatus")
                    VALUES (:studentID, :message, CURRENT_TIMESTAMP, \'Unread\')
                ');
                $notifStmt->execute([':studentID' => $studentID, ':message' => $notifMsg]);
                $notifStmt->closeCursor();

                if ($newExamID) {
                    try {
                        $semStart = $cData['startDate'] ?? date('Y-m-d');
                        $semEnd = $cData['endDate'] ?? date('Y-m-d');
                        $scheduleManager->syncExamSchedule($studentID, $newExamID, $examDate, $examCategory, $semStart, $semEnd, $difficulty, $calculatedStatus, $courseID);
                        generateStudyTasksForExam($conn, $newExamID, $studentID, $examTitle);
                    } catch (Throwable $e) {
                        // Prevent individual sync errors from failing transaction
                    }
                }
            }

            $conn->commit();
            echo json_encode(['success' => true]);
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            echo json_encode(['success' => false, 'error' => 'Failed to save final examination records: ' . $e->getMessage()]);
            exit;
        }
    }

    // ACTION: ADD SINGLE ASSESSMENT (QUIZ / TEST / MANUAL FINAL)
    if ($action === 'addExam') {
        $courseID = intval($input['courseID'] ?? 0);
        $assessmentCategory = trim($input['assessmentCategory'] ?? 'Quiz');
        $title = trim($input['title'] ?? '');
        $examDate = trim($input['examDate'] ?? '');
        $examTime = trim($input['examTime'] ?? '');
        $examEndTime = trim($input['examEndTime'] ?? $input['endTime'] ?? '');
        $examLocation = trim($input['examLocation'] ?? '');
        $difficultyLevel = trim($input['difficultyLevel'] ?? 'Medium');
        $examStatus = trim($input['examStatus'] ?? 'Not Started');

        $dbExamType = normalizeExamType($assessmentCategory);
        $storedType = !empty($title) ? ($assessmentCategory . ': ' . $title) : $assessmentCategory;

        if (!$courseID || $examDate === '') {
            echo json_encode(['success' => false, 'error' => 'Course and date are required.']);
            exit;
        }

        // Quiz and Test mandatory time checks
        if ($dbExamType !== 'Final' && (empty($examTime) || empty($examEndTime))) {
            echo json_encode(['success' => false, 'error' => 'Start Time and End Time are required for Quiz and Test.']);
            exit;
        }

        // Final Exam default 3-hour duration calculation if End Time is missing
        if ($dbExamType === 'Final' && !empty($examTime) && empty($examEndTime)) {
            list($examTime, $examEndTime) = parseExamStartAndEndTime($examTime);
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

            // Enhanced 30-Minute Buffer & Overlap Validation
            if (!empty($examTime) && !empty($examEndTime)) {
                $newStartTs = strtotime($examDate . ' ' . $examTime);
                $newEndTs = strtotime($examDate . ' ' . $examEndTime);

                if ($newEndTs <= $newStartTs) {
                    echo json_encode(['success' => false, 'error' => 'End Time must be after Start Time.']);
                    exit;
                }

                $dupCheck = $conn->prepare('
                    SELECT e.* FROM "Exam" e
                    JOIN "Course" c ON e."courseID" = c."courseID"
                    JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                    WHERE s."studentID" = :studentID
                      AND e."examDate" = :examDate
                ');
                $dupCheck->execute([
                    ':studentID' => $studentID,
                    ':examDate'  => $examDate
                ]);
                $existingExams = $dupCheck->fetchAll(PDO::FETCH_ASSOC);

                foreach ($existingExams as $ex) {
                    $exStartRaw = trim($ex['examTime'] ?? '');
                    if (empty($exStartRaw)) continue;

                    $exEndRaw = trim($ex['examEndTime'] ?? $ex['endTime'] ?? '');
                    list($exStartFormatted, $exEndFormatted) = parseExamStartAndEndTime(!empty($exEndRaw) ? ($exStartRaw . ' - ' . $exEndRaw) : $exStartRaw);

                    $exStartTs = strtotime($examDate . ' ' . $exStartFormatted);
                    $exEndTs = strtotime($examDate . ' ' . $exEndFormatted);

                    if ($exStartTs !== false) {
                        // Requires a 30-minute (1800-second) buffer gap between all assessments
                        if ($newStartTs < ($exEndTs + 1800) && $exStartTs < ($newEndTs + 1800)) {
                            echo json_encode([
                                'success' => false,
                                'error'   => 'Assessment time conflicts with an existing assessment schedule or falls within the required 30-minute buffer period.'
                            ]);
                            exit;
                        }
                    }
                }
            }

            $calculatedStatus = calculateExamStatus($examDate, $examStatus);

            $stmt = $conn->prepare('
                INSERT INTO public."Exam" 
                    ("courseID", "examType", "examTitle", "examDate", "examTime", "examEndTime", "examLocation", "examStatus", "difficultyLevel", "pdfFile", "studentID")
                VALUES 
                    (:courseID, :examType, :examTitle, :examDate, :examTime, :examEndTime, :examLocation, :examStatus, :difficultyLevel, NULL, :studentID)
                RETURNING "examID"
            ');

            $stmt->execute([
                ':courseID'        => $courseID,
                ':examType'        => $dbExamType, 
                ':examTitle'       => $title,             
                ':examDate'        => $examDate,
                ':examTime'        => !empty($examTime) ? $examTime : null,
                ':examEndTime'     => !empty($examEndTime) ? $examEndTime : null,
                ':examLocation'    => $examLocation,
                ':examStatus'      => $calculatedStatus,
                ':difficultyLevel' => $difficultyLevel,
                ':studentID'       => $studentID  // Added missing binding
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
                    generateStudyTasksForExam($conn, $newExamID, $studentID, $title);
                } catch (Exception $e) {
                    // silent
                }
            }

            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to add assessment record: ' . $e->getMessage()]);
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
        $examEndTime = trim($input['examEndTime'] ?? $input['endTime'] ?? '');
        $examLocation = trim($input['examLocation'] ?? '');
        $difficultyLevel = trim($input['difficultyLevel'] ?? 'Medium');

        $dbExamType = normalizeExamType($assessmentCategory);
        $storedType = !empty($title) ? ($assessmentCategory . ': ' . $title) : $assessmentCategory;

        if (!$examID || !$courseID || $examDate === '') {
            echo json_encode(['success' => false, 'error' => 'All required fields must be filled.']);
            exit;
        }

        // Quiz and Test mandatory time checks
        if ($dbExamType !== 'Final' && (empty($examTime) || empty($examEndTime))) {
            echo json_encode(['success' => false, 'error' => 'Start Time and End Time are required for Quiz and Test.']);
            exit;
        }

        // Final Exam default 3-hour duration calculation if End Time is missing
        if ($dbExamType === 'Final' && !empty($examTime) && empty($examEndTime)) {
            list($examTime, $examEndTime) = parseExamStartAndEndTime($examTime);
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

            if (!empty($examTime) && !empty($examEndTime)) {
                $newStartTs = strtotime($examDate . ' ' . $examTime);
                $newEndTs = strtotime($examDate . ' ' . $examEndTime);

                if ($newEndTs <= $newStartTs) {
                    echo json_encode(['success' => false, 'error' => 'End Time must be after Start Time.']);
                    exit;
                }

                $dupCheck = $conn->prepare('
                    SELECT e.* FROM "Exam" e
                    JOIN "Course" c ON e."courseID" = c."courseID"
                    JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                    WHERE s."studentID" = :studentID
                      AND e."examDate" = :examDate
                      AND e."examID" != :examID
                ');
                $dupCheck->execute([
                    ':studentID' => $studentID,
                    ':examDate'  => $examDate,
                    ':examID'    => $examID
                ]);
                $existingExams = $dupCheck->fetchAll(PDO::FETCH_ASSOC);

                foreach ($existingExams as $ex) {
                    $exStartRaw = trim($ex['examTime'] ?? '');
                    if (empty($exStartRaw)) continue;

                    $exEndRaw = trim($ex['examEndTime'] ?? $ex['endTime'] ?? '');
                    list($exStartFormatted, $exEndFormatted) = parseExamStartAndEndTime(!empty($exEndRaw) ? ($exStartRaw . ' - ' . $exEndRaw) : $exStartRaw);

                    $exStartTs = strtotime($examDate . ' ' . $exStartFormatted);
                    $exEndTs = strtotime($examDate . ' ' . $exEndFormatted);

                    if ($exStartTs !== false) {
                        if ($newStartTs < ($exEndTs + 1800) && $exStartTs < ($newEndTs + 1800)) {
                            echo json_encode([
                                'success' => false,
                                'error'   => 'Assessment time conflicts with an existing assessment schedule or falls within the required 30-minute buffer period.'
                            ]);
                            exit;
                        }
                    }
                }
            }

            $calculatedStatus = calculateExamStatus($examDate);

            $stmt = $conn->prepare('
                UPDATE public."Exam" 
                SET "courseID" = :courseID,
                    "examType" = :examType,
                    "examTitle" = :title,
                    "examDate" = :examDate,
                    "examTime" = :examTime,
                    "examEndTime" = :examEndTime,
                    "examLocation" = :examLocation,
                    "examStatus" = :examStatus,
                    "difficultyLevel" = :difficultyLevel
                WHERE "examID" = :examID
            ');
            $stmt->execute([
                ':courseID'        => $courseID,
                ':examType'        => $dbExamType,
                ':title'           => $title,
                ':examDate'        => $examDate,
                ':examTime'        => !empty($examTime) ? $examTime : null,
                ':examEndTime'     => !empty($examEndTime) ? $examEndTime : null,
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
                generateStudyTasksForExam($conn, $examID, $studentID, $title, true);
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

    // ACTION: UPDATE EXAM STUDY PROGRESS (UPDATES EXISTING STUDYTASK RECORD ONLY)
    if ($action === 'updateExamStudyProgress' || $action === 'updateSubtaskProgress') {
        $examID = intval($input['examID'] ?? 0);
        $scheduleID = intval($input['scheduleID'] ?? 0);
        $studyTaskID = intval($input['studyTaskID'] ?? 0);
        $isCompleted = !empty($input['isCompleted']);
        $examStatus = trim($input['examStatus'] ?? '');

        try {
            // Update existing StudyTask record
            if ($studyTaskID > 0) {
                $taskUpd = $conn->prepare('
                    UPDATE public."StudyTask"
                    SET "isCompleted" = :isCompleted
                    WHERE "studyTaskID" = :studyTaskID
                ');
                $taskUpd->execute([
                    ':isCompleted' => $isCompleted ? 'true' : 'false',
                    ':studyTaskID' => $studyTaskID
                ]);
            } elseif ($examID > 0 && ($examStatus === 'Completed' || $examStatus === 'Not Started' || isset($input['isCompleted']))) {
                // Bulk update all StudyTasks for exam master checkbox toggle
                $taskUpdAll = $conn->prepare('
                    UPDATE public."StudyTask"
                    SET "isCompleted" = :isCompleted
                    WHERE "examID" = :examID
                ');
                $taskUpdAll->execute([
                    ':isCompleted' => $isCompleted ? 'true' : 'false',
                    ':examID'      => $examID
                ]);
            }

            $check = $conn->prepare('
                SELECT e."examDate" FROM "Exam" e
                JOIN "Course" c ON e."courseID" = c."courseID"
                JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                WHERE e."examID" = :examID AND s."studentID" = :studentID
            ');
            $check->execute([':examID' => $examID, ':studentID' => $studentID]);
            $examRow = $check->fetch(PDO::FETCH_ASSOC);
            if (!$examRow) {
                echo json_encode(['success' => false, 'error' => 'Unauthorized or exam not found']);
                exit;
            }

            if ($scheduleID > 0) {
                $newSessionStatus = $isCompleted ? 'Completed' : 'Pending';
                $sUpd = $conn->prepare('
                    UPDATE "Schedule" 
                    SET "status" = :status 
                    WHERE "scheduleID" = :scheduleID 
                      AND "studentID" = :studentID
                ');
                $sUpd->execute([
                    ':status'     => $newSessionStatus, 
                    ':scheduleID' => $scheduleID,
                    ':studentID'  => $studentID
                ]);
            } elseif ($examStatus === 'Completed' || $examStatus === 'Not Started') {
                $allSessionStatus = ($examStatus === 'Completed') ? 'Completed' : 'Pending';
                $sUpdAll = $conn->prepare('
                    UPDATE "Schedule" 
                    SET "status" = :status 
                    WHERE "studentID" = :studentID 
                      AND LOWER(TRIM("referenceType")) = \'exam\'
                      AND "referenceID" = :examID
                ');
                $sUpdAll->execute([
                    ':status'    => $allSessionStatus, 
                    ':examID'    => (string)$examID,
                    ':studentID' => $studentID
                ]);
            }

            // Calculate progress based on existing StudyTask table records
            $tCheck = $conn->prepare('
                SELECT "isCompleted" FROM public."StudyTask"
                WHERE "examID" = :examID
            ');
            $tCheck->execute([':examID' => $examID]);
            $taskStatuses = $tCheck->fetchAll(PDO::FETCH_COLUMN);

            $totalSessions = count($taskStatuses);
            $completedSessions = 0;
            foreach ($taskStatuses as $st) {
                if ($st === true || $st === 't' || $st === 'true' || $st === 1 || $st === '1') {
                    $completedSessions++;
                }
            }

            if ($totalSessions > 0 && $completedSessions === $totalSessions) {
                $calculatedStatus = 'Completed';
            } elseif ($completedSessions > 0) {
                $calculatedStatus = 'Ongoing';
            } else {
                $calculatedStatus = calculateExamStatus($examRow['examDate']);
            }

            $updExam = $conn->prepare('UPDATE "Exam" SET "examStatus" = :status WHERE "examID" = :examID');
            $updExam->execute([':status' => $calculatedStatus, ':examID' => $examID]);

            echo json_encode([
                'success' => true, 
                'examStatus' => $calculatedStatus, 
                'completedSessions' => $completedSessions, 
                'totalSessions' => $totalSessions
            ]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to save study progress: ' . $e->getMessage()]);
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
                $delS = $conn->prepare('DELETE FROM "Schedule" WHERE "referenceID" = :ref AND LOWER(TRIM("referenceType")) = \'exam\'');
                $delS->execute([':ref' => (string)$examID]);
                
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
        AND CURRENT_DATE >= s."startDate" 
        AND CURRENT_DATE <= s."endDate"
        ORDER BY c."courseCode", e."examDate" ASC
    ');
    $stmt->execute([':studentID' => $studentID]);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch matching Schedule study sessions for all exams of this student
    $examSchedules = [];
    $schedStmt = $conn->prepare('
        SELECT "scheduleID", "courseID", "studyDate", "studyTime", "priorityLevel", "referenceID", "status"
        FROM "Schedule"
        WHERE "studentID" = :studentID
          AND LOWER(TRIM("referenceType")) = \'exam\'
        ORDER BY "studyDate" ASC, "studyTime" ASC
    ');
    $schedStmt->execute([':studentID' => $studentID]);
    $rawSchedules = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawSchedules as $sch) {
        $refID = (int)$sch['referenceID'];
        if (!isset($examSchedules[$refID])) {
            $examSchedules[$refID] = [];
        }
        $examSchedules[$refID][] = $sch;
    }

    // Verify & sync StudyTasks for any existing exam missing individual session StudyTask records
    foreach ($exams as $e) {
        $exID = (int)$e['examID'];
        $sessionCount = isset($examSchedules[$exID]) ? count($examSchedules[$exID]) : 0;
        
        $chkTask = $conn->prepare('SELECT COUNT(*) FROM public."StudyTask" WHERE "examID" = :examID');
        $chkTask->execute([':examID' => $exID]);
        $taskCount = (int)$chkTask->fetchColumn();
        $chkTask->closeCursor();

        if ($sessionCount > 0 && ($taskCount === 0 || $taskCount !== $sessionCount)) {
            generateStudyTasksForExam($conn, $exID, $studentID, $e['examTitle'] ?? '', true);
        }
    }

    // Fetch StudyTasks for all exams
    $studyTasks = [];   
    $taskStmt = $conn->prepare('
        SELECT st."studyTaskID", st."examID", st."taskDescription", st."isCompleted", st."orderNo"
        FROM public."StudyTask" st
        JOIN "Exam" e ON st."examID" = e."examID"
        JOIN "Course" c ON e."courseID" = c."courseID"
        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE s."studentID" = :studentID
        AND CURRENT_DATE >= s."startDate" 
        AND CURRENT_DATE <= s."endDate"
        ORDER BY st."orderNo" ASC
    ');
    $taskStmt->execute([':studentID' => $studentID]);
    $rawTasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawTasks as $st) {
        $exID = (int)$st['examID'];
        if (!isset($studyTasks[$exID])) {
            $studyTasks[$exID] = [];
        }
        $studyTasks[$exID][] = $st;
    }

    /*$today = new DateTime('today');
    
    foreach ($exams as &$e) {
        $examID = (int)$e['examID'];
        $e['sessions'] = $examSchedules[$examID] ?? [];
        $e['studyTasks'] = $studyTasks[$examID] ?? [];
        
        $totalTasks = count($e['studyTasks']);
        $completedTasks = 0;
        foreach ($e['studyTasks'] as $st) {
            $isTaskDone = filter_var($st['isCompleted'], FILTER_VALIDATE_BOOLEAN) || $st['isCompleted'] === 't' || $st['isCompleted'] === 'true' || $st['isCompleted'] === 1 || $st['isCompleted'] === '1';
            if ($isTaskDone) {
                $completedTasks++;
            }
        }

        $examDateObj = new DateTime($e['examDate']);
        
        if ($e['examStatus'] !== 'Completed') {
            if ($totalTasks > 0 && $completedTasks === $totalTasks) {
                $e['examStatus'] = 'Completed';
            } elseif ($examDateObj < $today) {
                $e['examStatus'] = 'Past Due';
            } elseif ($examDateObj == $today) {
                $e['examStatus'] = 'Ongoing';
            } else {
                $e['examStatus'] = ($completedTasks > 0) ? 'Ongoing' : 'Not Started';
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
    }*/

    $today = new DateTime('today');
    
    foreach ($exams as &$e) {
        $examID = (int)$e['examID'];
        $e['sessions'] = $examSchedules[$examID] ?? [];
        $e['studyTasks'] = $studyTasks[$examID] ?? [];
        
        $totalTasks = count($e['studyTasks']);
        $completedTasks = 0;
        foreach ($e['studyTasks'] as $st) {
            $isTaskDone = filter_var($st['isCompleted'], FILTER_VALIDATE_BOOLEAN) || $st['isCompleted'] === 't' || $st['isCompleted'] === 'true' || $st['isCompleted'] === 1 || $st['isCompleted'] === '1';
            if ($isTaskDone) {
                $completedTasks++;
            }
        }

        $examDateObj = new DateTime($e['examDate']);
        
        //UNCOMMENT NANTI: JANGAN DELETE ORIGINAL FUNCTION
        /*if ($e['examStatus'] !== 'Completed') {
            if ($totalTasks > 0 && $completedTasks === $totalTasks) {
                $e['examStatus'] = 'Completed';
            } elseif ($examDateObj < $today) {
                $e['examStatus'] = 'Past Due';
            } elseif ($examDateObj == $today) {
                $e['examStatus'] = 'Ongoing';
            } else {
                $e['examStatus'] = ($completedTasks > 0) ? 'Ongoing' : 'Not Started';
            }
        }*/
        
        //DEMO TEST
        if ($e['examStatus'] !== 'Completed') {
            if ($totalTasks > 0 && $completedTasks === $totalTasks) {
                $e['examStatus'] = 'Completed';
             
            
            } elseif ($examDateObj == $today) {
                $e['examStatus'] = 'Ongoing';
            } else {
                $e['examStatus'] = ($completedTasks > 0) ? 'Ongoing' : 'Not Started';
            }
        }

        // Dynamically compute current priority level based on today's date
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
    unset($e);
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
</head>
<body>
    <?php 
    $current_page = 'exams';
    include 'includes/sidebar.php'; 
    ?>

    <main class="main-content">
        <?php include 'includes/header.php'; ?>

        <?php
            // Calculate Summary Metrics for Exams
            $kpiUpcoming7 = 0;
            $kpiActiveExams = 0;
            $kpiOngoingExams = 0;
            $kpiCompletedExams = 0;
            $todayExamDt = new DateTime('today');

            foreach ($exams as $exItem) {
                if ($exItem['examStatus'] === 'Completed') {
                    $kpiCompletedExams++;
                } else {
                    $kpiActiveExams++;
                    if ($exItem['examStatus'] === 'Ongoing') $kpiOngoingExams++;
                    $exDt = new DateTime($exItem['examDate']);
                    if ($exDt >= $todayExamDt && $todayExamDt->diff($exDt)->days <= 7) {
                        $kpiUpcoming7++;
                    }
                }
            }
        ?>

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
            <!-- TOP EXAM OVERVIEW CARDS -->
            <div class="summary-grid">
                <div class="summary-card upcoming" onclick="triggerExamFilterByName('High Priority')">
                    <div class="summary-icon"><i class="ph-fill ph-alarm"></i></div>
                    <div>
                        <div class="summary-value"><?= $kpiUpcoming7 ?></div>
                        <div class="summary-label">Exams Within 7 Days</div>
                    </div>
                </div>
                <div class="summary-card finals" onclick="triggerExamFilterByName('All')">
                    <div class="summary-icon"><i class="ph-fill ph-graduation-cap"></i></div>
                    <div>
                        <div class="summary-value"><?= $kpiActiveExams ?></div>
                        <div class="summary-label">Active Assessments</div>
                    </div>
                </div>
                <div class="summary-card ongoing" onclick="triggerExamFilterByName('Ongoing')">
                    <div class="summary-icon"><i class="ph-fill ph-book-open-text"></i></div>
                    <div>
                        <div class="summary-value"><?= $kpiOngoingExams ?></div>
                        <div class="summary-label">Revision Ongoing</div>
                    </div>
                </div>
                <div class="summary-card done" onclick="triggerExamFilterByName('Completed')">
                    <div class="summary-icon"><i class="ph-fill ph-check-circle"></i></div>
                    <div>
                        <div class="summary-value"><?= $kpiCompletedExams ?></div>
                        <div class="summary-label">Completed</div>
                    </div>
                </div>
            </div>

            <!-- ORGANIZED SEARCH & GROUPED FILTERS -->
            <div class="filter-toolbar-card">
                <div class="search-row">
                    <div class="search-input-wrap">
                        <i class="ph ph-magnifying-glass"></i>
                        <input type="text" id="searchInput" class="form-control" placeholder="Search assessments by course code, course name, or title..." oninput="handleSearch(event)">
                    </div>
                    <button type="button" class="btn-reset-filters" id="resetFiltersBtn" onclick="resetAllFilters()">
                        <i class="ph-bold ph-arrow-counter-clockwise"></i> Reset Filters
                    </button>
                </div>

                <div class="filter-groups-row">
                    <div class="filter-group" id="statusFilterGroup">
                        <span class="filter-group-label">Status:</span>
                        <button class="filter-pill status-pill active" data-status="All" onclick="setStatusFilter('All', this)">All (<?= count($exams) ?>)</button>
                        <button class="filter-pill status-pill" data-status="Not Started" onclick="setStatusFilter('Not Started', this)">Not Started</button>
                        <button class="filter-pill status-pill" data-status="Ongoing" onclick="setStatusFilter('Ongoing', this)">Ongoing (<?= $kpiOngoingExams ?>)</button>
                        <button class="filter-pill status-pill" data-status="Completed" onclick="setStatusFilter('Completed', this)">Completed (<?= $kpiCompletedExams ?>)</button>
                        <button class="filter-pill status-pill" data-status="Past Due" onclick="setStatusFilter('Past Due', this)">Past Due</button>
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

            <div class="empty-state-card" id="emptyState" style="<?= count($exams) > 0 ? 'display:none' : '' ?>">
                <i class="ph ph-calendar-x" style="font-size: 48px; color: #cbd5e1; margin-bottom: 16px; display: block;"></i>
                <span id="emptyStateMessage">No examinations, quizzes, or tests found.</span>
            </div>

            <?php
                // Build unified per-course folder data with study progress
                $examFolders = [];
                foreach ($exams as $ex) {
                    $cKey = $ex['courseCode'] . ' - ' . $ex['courseName'];
                    if (!isset($examFolders[$cKey])) {
                        $examFolders[$cKey] = [
                            'courseCode'  => $ex['courseCode'],
                            'courseName'  => $ex['courseName'],
                            'items'       => [],
                            'activeCount' => 0,
                            'doneCount'   => 0,
                            'nextExam'    => null,
                            'unitsDone'   => 0,
                            'unitsTotal'  => 0
                        ];
                    }
                    $examFolders[$cKey]['items'][] = $ex;

                    $tasks = $ex['studyTasks'] ?? [];
                    if (count($tasks) > 0) {
                        $examFolders[$cKey]['unitsTotal'] += count($tasks);
                        foreach ($tasks as $st) {
                            $isDone = filter_var($st['isCompleted'], FILTER_VALIDATE_BOOLEAN) || $st['isCompleted'] === 't' || $st['isCompleted'] === 'true' || $st['isCompleted'] === 1 || $st['isCompleted'] === '1';
                            if ($isDone) $examFolders[$cKey]['unitsDone']++;
                        }
                    } else {
                        $examFolders[$cKey]['unitsTotal'] += 1;
                        if ($ex['examStatus'] === 'Completed') $examFolders[$cKey]['unitsDone'] += 1;
                    }

                    if ($ex['examStatus'] === 'Completed') {
                        $examFolders[$cKey]['doneCount']++;
                    } else {
                        $examFolders[$cKey]['activeCount']++;
                        if ($examFolders[$cKey]['nextExam'] === null) {
                            $examFolders[$cKey]['nextExam'] = $ex['examDate'];
                        }
                    }
                }
            ?>

            <?php if (!empty($examFolders)): ?>
            <div class="folder-section-heading">
                <span><i class="ph-fill ph-folders"></i> Course Exam Folders (Click a folder to view assessments)</span>
                <span style="font-size: 12px; font-weight: 500; text-transform: none; letter-spacing: 0;">Select any course card below</span>
            </div>

            <!-- COURSE FOLDER CARDS GRID -->
            <div class="course-folders-grid" id="courseFoldersGrid">
                <?php $themeIdx = 0; ?>
                <?php foreach ($examFolders as $cKey => $folder): ?>
                    <?php
                        $slug = 'exam-folder-' . preg_replace('/[^a-zA-Z0-9]/', '-', $folder['courseCode']);
                        $pct = $folder['unitsTotal'] > 0 ? round(($folder['unitsDone'] / $folder['unitsTotal']) * 100) : 0;
                        $themeClass = 'folder-theme-' . ($themeIdx % 6);
                        $themeIdx++;
                    ?>
                    <div class="course-folder-card <?= $themeClass ?>"
                         id="card-<?= $slug ?>"
                         data-folder-slug="<?= $slug ?>"
                         data-course-key="<?= htmlspecialchars(strtolower($cKey)) ?>"
                         onclick="selectCourseFolder('<?= $slug ?>')">

                        <i class="ph-fill ph-graduation-cap folder-watermark-icon"></i>

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
                                <?php if ($folder['nextExam']): ?>
                                    <span>· Next: <?= date('M d', strtotime($folder['nextExam'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="folder-progress-area">
                            <div class="folder-progress-labels">
                                <span id="folder-pct-<?= $slug ?>"><?= $pct ?>% Ready</span>
                                <span id="folder-steps-<?= $slug ?>"><?= $folder['unitsDone'] ?>/<?= $folder['unitsTotal'] ?> sessions</span>
                            </div>
                            <div class="folder-progress-track">
                                <div class="folder-progress-fill" id="folder-bar-<?= $slug ?>" style="width: <?= $pct ?>%;"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- OPENED FOLDER EXAMS PANEL -->
            <div id="examsList">
                <?php $themeIdx = 0; ?>
                <?php foreach ($examFolders as $cKey => $folder): ?>
                    <?php
                        $slug = 'exam-folder-' . preg_replace('/[^a-zA-Z0-9]/', '-', $folder['courseCode']);
                        $themeClass = 'folder-theme-' . ($themeIdx % 6);
                        $themeIdx++;
                        usort($folder['items'], function($x, $y) {
                            $xDone = ($x['examStatus'] === 'Completed') ? 1 : 0;
                            $yDone = ($y['examStatus'] === 'Completed') ? 1 : 0;
                            if ($xDone !== $yDone) return $xDone - $yDone;
                            return strcmp($x['examDate'], $y['examDate']);
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

                        <?php foreach ($folder['items'] as $exam): ?>
                            <?php 
                                $priorityClass = 'badge-priority-' . strtolower($exam['priorityLevel']); 
                                $parsedTasks = $exam['studyTasks'] ?? [];
                                $totalTasks = count($parsedTasks);
                                $completedTasks = 0;
                                $firstPendingTaskIdx = -1;

                                foreach ($parsedTasks as $idx => $st) {
                                    $isTaskDone = filter_var($st['isCompleted'], FILTER_VALIDATE_BOOLEAN) || $st['isCompleted'] === 't' || $st['isCompleted'] === 'true' || $st['isCompleted'] === 1 || $st['isCompleted'] === '1';
                                    if ($isTaskDone) {
                                        $completedTasks++;
                                    } elseif ($firstPendingTaskIdx === -1) {
                                        $firstPendingTaskIdx = $idx;
                                    }
                                }
                                $progressPercent = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

                                $isCompleted = ($exam['examStatus'] === 'Completed');
                                $isPastDue = ($exam['examStatus'] === 'Past Due');
                                $isFinalExam = (stripos($exam['examType'] ?? '', 'Final') !== false);

                                $exDateObj = new DateTime($exam['examDate']);
                                $daysToExam = (int)$todayExamDt->diff($exDateObj)->format('%r%a');
                                if ($isCompleted) {
                                    $examCountdownLabel = 'Completed';
                                    $examCountdownClass = 'countdown-exam-done';
                                } elseif ($daysToExam < 0) {
                                    $examCountdownLabel = abs($daysToExam) . 'd ago';
                                    $examCountdownClass = 'countdown-exam-past';
                                } elseif ($daysToExam === 0) {
                                    $examCountdownLabel = 'Exam Today!';
                                    $examCountdownClass = 'countdown-exam-soon';
                                } elseif ($daysToExam <= 7) {
                                    $examCountdownLabel = 'In ' . $daysToExam . ($daysToExam === 1 ? ' day' : ' days');
                                    $examCountdownClass = 'countdown-exam-soon';
                                } else {
                                    $examCountdownLabel = 'In ' . $daysToExam . ' days';
                                    $examCountdownClass = 'countdown-exam-upcoming';
                                }

                                $statusClass = 'badge-status-not-started';
                                if ($exam['examStatus'] === 'Ongoing') $statusClass = 'badge-status-ongoing';
                                elseif ($isCompleted) $statusClass = 'badge-status-completed';
                                elseif ($isPastDue) $statusClass = 'badge-status-past-due';
                                
                                $cardClass = 'exam-item';
                                if ($isCompleted) $cardClass .= ' completed-card';
                                elseif ($isPastDue) $cardClass .= ' past-due-card';
                                elseif ($exam['examStatus'] === 'Ongoing') $cardClass .= ' ongoing-card';
                                elseif ($isFinalExam) $cardClass .= ' exam-final-card';
                                elseif ($daysToExam >= 0 && $daysToExam <= 7) $cardClass .= ' exam-urgent-card';

                                $diffClass = ($exam['difficultyLevel'] === 'Hard') ? 'badge-hard' : (($exam['difficultyLevel'] === 'Easy') ? 'badge-easy' : 'badge-medium');
                            ?>
                            <div class="<?= $cardClass ?>" id="exam-<?= $exam['examID'] ?>" data-priority="<?= htmlspecialchars($exam['priorityLevel']) ?>" data-status="<?= htmlspecialchars($exam['examStatus']) ?>">
                                <div class="exam-header">
                                    <input type="checkbox" 
                                           class="main-checkbox" 
                                           id="main-exam-checkbox-<?= $exam['examID'] ?>" 
                                           <?= $isCompleted ? 'checked' : '' ?>
                                           onchange="toggleMainExamCheckbox(<?= $exam['examID'] ?>, this)"
                                           title="Mark entire assessment as completed">
                                    
                                    <div class="exam-info">
                                        <?php 
                                            $examType = $exam['examType'] ?? 'Final';
                                            $examTitle = $exam['examTitle'] ?? '';
                                            if (!empty($examTitle)) {
                                                $displayExamTitle = (stripos($examTitle, $examType) !== false) ? $examTitle : ($examType . ': ' . $examTitle);
                                            } else {
                                                $displayExamTitle = $examType;
                                            }
                                        ?>
                                        <div class="exam-title"><?= htmlspecialchars($exam['courseCode']) ?> - <?= htmlspecialchars($displayExamTitle) ?></div>
                                        
                                        <div class="exam-logistics-bar">
                                            <span class="logistics-chip">
                                                <i class="ph-bold ph-calendar-blank"></i> <?= date('D, M d, Y', strtotime($exam['examDate'])) ?>
                                            </span>
                                            <span class="exam-countdown-pill <?= $examCountdownClass ?>">
                                                <i class="ph-fill ph-hourglass-medium"></i> <?= $examCountdownLabel ?>
                                            </span>
                                            <?php if(!empty($exam['examTime'])): ?>
                                                <span class="logistics-chip">
                                                    <i class="ph-bold ph-clock"></i> <?= date('g:i A', strtotime($exam['examTime'])) ?><?= !empty($exam['examEndTime']) ? ' - ' . date('g:i A', strtotime($exam['examEndTime'])) : '' ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if(!empty($exam['examLocation'])): ?>
                                                <span class="logistics-chip venue-chip">
                                                    <i class="ph-fill ph-map-pin"></i> Venue: <?= htmlspecialchars($exam['examLocation']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="exam-badges">
                                            <span class="badge badge-type"><?= htmlspecialchars(strtok($exam['examType'] ?? 'Final', ':')) ?></span>
                                            <span class="badge <?= $diffClass ?>"><?= htmlspecialchars($exam['difficultyLevel']) ?></span>
                                            <span class="badge <?= $priorityClass ?>"><i class="ph ph-warning"></i> <?= htmlspecialchars($exam['priorityLevel']) ?> Priority</span>
                                            <span id="exam-status-badge-<?= $exam['examID'] ?>" class="badge <?= $statusClass ?>">
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

                                <?php if ($totalTasks > 0): ?>
                                    <div class="subtasks-container">
                                        <div class="progress-wrapper">
                                            <div class="progress-text">
                                                <span>Study Progress: <strong id="progress-count-<?= $exam['examID'] ?>"><?= $completedTasks ?>/<?= $totalTasks ?> completed</strong></span>
                                                <span id="progress-text-<?= $exam['examID'] ?>"><?= $progressPercent ?>%</span>
                                            </div>
                                            <div class="progress-track">
                                                <div id="progress-bar-<?= $exam['examID'] ?>" class="progress-fill" style="width: <?= $progressPercent ?>%;"></div>
                                            </div>
                                        </div>
                                        <div class="subtask-list" id="session-list-<?= $exam['examID'] ?>">
                                            <?php foreach ($parsedTasks as $index => $st): ?>
                                                <?php 
                                                    $isExtra = $index >= 3;
                                                    $isTaskDone = filter_var($st['isCompleted'], FILTER_VALIDATE_BOOLEAN) || $st['isCompleted'] === 't' || $st['isCompleted'] === 'true' || $st['isCompleted'] === 1 || $st['isCompleted'] === '1';
                                                    $isNextSession = (!$isCompleted && $index === $firstPendingTaskIdx);
                                                    $displayStyle = ($isExtra && !$isNextSession) ? 'display: none;' : '';
                                                    $extraClass = $isExtra ? ' extra-subtask' : '';
                                                    $nextSessionClass = $isNextSession ? ' next-session-item' : '';
                                                    $taskID = (int)$st['studyTaskID'];
                                                    $schedID = isset($exam['sessions'][$index]['scheduleID']) ? (int)$exam['sessions'][$index]['scheduleID'] : 0;
                                                ?>
                                                <label class="subtask-item<?= $extraClass . $nextSessionClass ?>" style="<?= $displayStyle ?>">
                                                    <input type="checkbox" 
                                                           data-task-id="<?= $taskID ?>"
                                                           onchange="toggleExamStudySession(<?= $exam['examID'] ?>, <?= $taskID ?>, <?= $schedID ?>, this)" 
                                                           <?= $isTaskDone ? 'checked' : '' ?>>
                                                    <span id="session-text-<?= $exam['examID'] ?>-<?= $taskID ?>" 
                                                          style="<?= $isTaskDone ? 'text-decoration: line-through; color: #94a3b8;' : '' ?>">
                                                        <?= htmlspecialchars($st['taskDescription']) ?>
                                                    </span>
                                                    <?php if ($isNextSession): ?>
                                                        <span class="session-tag">Next Session</span>
                                                    <?php endif; ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>

                                        <?php if ($totalTasks > 3): ?>
                                            <button type="button" 
                                                    class="toggle-subtasks-btn" 
                                                    data-expanded="false" 
                                                    onclick="toggleSubtasksVisibility(<?= $exam['examID'] ?>, this)">
                                                Show <?= $totalTasks - 3 ?> More Sessions <i class="ph ph-caret-down"></i>
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

                <label style="font-size: 13px; font-weight: 600; color: #475569;">
                    Select Active Courses to include <span style="font-size: 12px; font-weight: 400; color: #64748b;">(click card to select / deselect)</span>:
                </label>
                
                <div class="course-card-grid" id="courseCardsGrid">
                    <?php if (empty($activeCourses)): ?>
                        <p style="font-size: 13px; color: #94a3b8; grid-column: 1/-1;">No active courses found for the current semester.</p>
                    <?php else: ?>
                        <?php foreach ($activeCourses as $c): ?>
                            <?php $courseSection = trim($c['section'] ?? ''); ?>
                            <div class="course-select-card selected" 
                                data-course-id="<?= htmlspecialchars($c['courseID'] ?? $c['courseid'] ?? '') ?>" 
                                data-course-code="<?= htmlspecialchars($c['courseCode'] ?? $c['coursecode'] ?? '') ?>"
                                data-course-name="<?= htmlspecialchars($c['courseName'] ?? $c['coursename'] ?? '') ?>"
                                data-course-section="<?= htmlspecialchars($courseSection) ?>"
                                onclick="toggleCourseCardSelection(this)">
                                
                                <!-- Top Right Icon -->
                                <div class="course-card-status">
                                    <i class="ph-fill ph-check-circle" style="font-size: 20px; color: #16a34a;"></i>
                                </div>

                                <div class="course-card-code"><?= htmlspecialchars($c['courseCode']) ?></div>
                                <div class="course-card-name"><?= htmlspecialchars($c['courseName']) ?></div>
                                <div style="margin-top: 8px; font-size: 12px; font-weight: 600; color: #2563eb;">
                                    Section: <?= $courseSection !== '' ? htmlspecialchars($courseSection) : '<span style="color: #94a3b8;">Not set</span>' ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-top: 20px;">
                    <label><strong>Upload Final Examination Timetable PDF</strong></label>
                    
                    <!-- DROP ZONE -->
                    <div class="drop-zone" id="examDropZone" onclick="document.getElementById('examPdfInput').click()">
                        <i class="ph ph-file-pdf" style="font-size: 36px; color: #3b82f6; margin-bottom: 8px; display: block;"></i>
                        <span id="dropZoneText" style="font-size: 14px; font-weight: 600; color: #334155;">Click to upload or drag & drop PDF timetable</span>
                        <input type="file" id="examPdfInput" accept=".pdf" style="display: none;" onchange="handleExamPdfUpload(this.files)">
                    </div>

                    <!-- MULTI-STEP PROGRESS DISPLAY -->
                    <div class="upload-progress-container" id="examUploadProgress" style="display:none; margin-top:15px;">
                        <div class="upload-status-text" style="display:flex; justify-content:space-between; font-size:13px; font-weight:600; margin-bottom:8px; color:#475569;">
                            <span id="examProgressStatusText">Uploading...</span>
                            <span id="examProgressPercentText">0%</span>
                        </div>
                        <div class="upload-progress-bar" style="width:100%; height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden;">
                            <div class="upload-progress-fill" id="examProgressBar" style="height:100%; background:#3b82f6; width:0%; transition:width 0.4s ease;"></div>
                        </div>
                        
                        <div class="upload-steps-list" style="margin-top:15px; display:flex; flex-direction:column; gap:10px; background:#f8fafc; padding:16px; border-radius:10px; border:1px solid #e2e8f0;">
                            <div class="step-item" id="examStepUpload"><i class="ph ph-circle"></i> Uploading...</div>
                            <div class="step-item" id="examStepExtract"><i class="ph ph-circle"></i> Extracting Information...</div>
                            <div class="step-item" id="examStepVerify"><i class="ph ph-circle"></i> Verifying Courses & Sections...</div>
                            <div class="step-item" id="examStepComplete"><i class="ph ph-circle"></i> Ready for Confirmation</div>
                        </div>

                        <!-- PERSISTENT ERROR & INSTRUCTION BANNER -->
                        <div id="examExtractionAlert" class="extraction-error-box" style="display: none;"></div>

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
                    <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <label>Start Time <span id="startTimeReq" style="color:red;">*</span></label>
                            <input type="time" id="singleExamTime" class="form-control">
                        </div>
                        <div>
                            <label>End Time <span id="endTimeReq" style="color:red;">*</span></label>
                            <input type="time" id="singleExamEndTime" class="form-control">
                        </div>
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
        // Store existing exams & StudyTask data
        const existingExams = <?php echo json_encode(array_values($exams)); ?>;
        let uploadedPdfPath = '';
        let extractedPdfExams = [];

        function openAssessmentModal() {
            document.getElementById('assessmentModalTitle').innerText = 'Add Assessment';
            document.getElementById('editExamID').value = '0';
            document.getElementById('stepCardSelection').style.display = 'block';
            document.getElementById('stepFinalExamFlow').style.display = 'none';
            document.getElementById('stepSingleAssessmentForm').style.display = 'none';
            document.getElementById('extractedResultsContainer').style.display = 'none';
            document.getElementById('examDropZone').style.display = 'block';
            document.getElementById('dropZoneText').innerText = 'Click to upload or drag & drop PDF timetable';
            document.getElementById('examPdfInput').value = '';
            document.getElementById('examUploadProgress').style.display = 'none';
            document.getElementById('examProgressBar').style.background = '#3b82f6';
            document.getElementById('examExtractionAlert').style.display = 'none';

            uploadedPdfPath = '';
            extractedPdfExams = [];

            document.querySelectorAll('.course-select-card').forEach(c => c.classList.remove('course-missing-error'));
            ['examStepUpload', 'examStepExtract', 'examStepVerify', 'examStepComplete'].forEach(id => updateExamStepStatus(id, 'reset'));

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
                document.getElementById('singleExamEndTime').value = '';
                document.getElementById('singleExamLocation').value = '';
                document.getElementById('singleDifficulty').value = 'Medium';

                const startTimeInput = document.getElementById('singleExamTime');
                const endTimeInput = document.getElementById('singleExamEndTime');
                const startTimeReq = document.getElementById('startTimeReq');
                const endTimeReq = document.getElementById('endTimeReq');

                if (type === 'Quiz' || type === 'Test') {
                    startTimeInput.required = true;
                    endTimeInput.required = true;
                    if (startTimeReq) startTimeReq.style.display = 'inline';
                    if (endTimeReq) endTimeReq.style.display = 'inline';
                } else {
                    startTimeInput.required = false;
                    endTimeInput.required = false;
                    if (startTimeReq) startTimeReq.style.display = 'none';
                    if (endTimeReq) endTimeReq.style.display = 'none';
                }

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
            document.getElementById('singleExamEndTime').value = exam.examEndTime ? exam.examEndTime.substring(0, 5) : '';
            document.getElementById('singleExamLocation').value = exam.examLocation || '';
            document.getElementById('singleDifficulty').value = exam.difficultyLevel || 'Medium';

            const startTimeInput = document.getElementById('singleExamTime');
            const endTimeInput = document.getElementById('singleExamEndTime');
            const startTimeReq = document.getElementById('startTimeReq');
            const endTimeReq = document.getElementById('endTimeReq');

            if (category === 'Quiz' || category === 'Test') {
                startTimeInput.required = true;
                endTimeInput.required = true;
                if (startTimeReq) startTimeReq.style.display = 'inline';
                if (endTimeReq) endTimeReq.style.display = 'inline';
            } else {
                startTimeInput.required = false;
                endTimeInput.required = false;
                if (startTimeReq) startTimeReq.style.display = 'none';
                if (endTimeReq) endTimeReq.style.display = 'none';
            }

            const preferredTimeCard = document.getElementById('preferredTimeCard');
            if (preferredTimeCard) preferredTimeCard.style.display = 'flex';
            
            openModal('addAssessmentModal');
        }

        function toggleCourseCardSelection(card) {
            card.classList.toggle('selected');
            card.classList.remove('course-missing-error');
            const statusIcon = card.querySelector('.course-card-status i');
            
            if (statusIcon) {
                if (card.classList.contains('selected')) {
                    statusIcon.className = 'ph-fill ph-check-circle';
                    statusIcon.style.color = '#16a34a';
                } else {
                    statusIcon.className = 'ph-fill ph-x-circle';
                    statusIcon.style.color = '#dc2626';
                }
            }

            // Re-verify immediately if a PDF has already been extracted
            if (extractedPdfExams.length > 0) {
                syncVerificationState();
            }
        }

        function updateExamStepStatus(itemId, status) {
            const item = document.getElementById(itemId);
            if (!item) return;
            const icon = item.querySelector('i');
            if (status === 'active') {
                item.className = 'step-item active';
                if (icon) icon.className = 'ph ph-circle-notch animate-spin';
            } else if (status === 'done') {
                item.className = 'step-item done';
                if (icon) icon.className = 'ph-fill ph-check-circle';
            } else if (status === 'error') {
                item.className = 'step-item error';
                if (icon) icon.className = 'ph-fill ph-x-circle';
            } else {
                item.className = 'step-item';
                if (icon) icon.className = 'ph ph-circle';
            }
        }

        function syncVerificationState() {
            const progressBar = document.getElementById('examProgressBar');
            const percentText = document.getElementById('examProgressPercentText');
            const statusText = document.getElementById('examProgressStatusText');
            const dropZone = document.getElementById('examDropZone');

            const isVerified = renderExtractedExamsUI();

            if (isVerified) {
                updateExamStepStatus('examStepVerify', 'done');
                updateExamStepStatus('examStepComplete', 'done');
                statusText.textContent = 'PDF Extracted & Verified Successfully';
                progressBar.style.background = '#3b82f6';
                progressBar.style.width = '100%';
                percentText.textContent = '100%';
                dropZone.style.display = 'none';
            } else {
                updateExamStepStatus('examStepVerify', 'error');
                updateExamStepStatus('examStepComplete', 'reset');
                statusText.textContent = 'Verification Incomplete — Action Required';
                progressBar.style.background = '#dc2626';
                progressBar.style.width = '75%';
                percentText.textContent = '75%';
                // Keep drop zone visible so the user can also re-upload another PDF
                dropZone.style.display = 'block';
                document.getElementById('dropZoneText').innerText = 'Click to upload a different PDF timetable';
                document.getElementById('examPdfInput').value = '';
            }
        }

        /**
         * Normalizes section input and extracted section strings for comparison
         * Handles numeric zero-padding (e.g. "03" vs "3") and label prefixes (e.g. "Sec 3" vs "3").
         */
        function normalizeSection(sec) {
            if (sec === null || sec === undefined) return '';
            let str = sec.toString().trim();
            // Remove explicit label prefixes if entered by the user
            str = str.replace(/^(section|sec\.?|seksyen|group|kumpulan)\s*/i, '');
            // Strip leading zeros for numeric sections (e.g., '03' -> '3')
            if (/^\d+$/.test(str)) {
                str = parseInt(str, 10).toString();
            }
            return str.toUpperCase();
        }

        async function handleExamPdfUpload(files) {
            if (!files.length) return;
            const file = files[0];
            if (file.type !== 'application/pdf') {
                showNotification('Please upload a valid PDF file.', 'error');
                return;
            }

            const dropZone = document.getElementById('examDropZone');
            const progressContainer = document.getElementById('examUploadProgress');
            const progressBar = document.getElementById('examProgressBar');
            const percentText = document.getElementById('examProgressPercentText');
            const statusText = document.getElementById('examProgressStatusText');
            const alertBox = document.getElementById('examExtractionAlert');

            // Reset UI state before upload
            dropZone.style.display = 'none';
            alertBox.style.display = 'none';
            document.getElementById('extractedResultsContainer').style.display = 'none';
            progressContainer.style.display = 'block';
            progressBar.style.background = '#3b82f6';

            // Step 1: Uploading
            updateExamStepStatus('examStepUpload', 'active');
            updateExamStepStatus('examStepExtract', 'reset');
            updateExamStepStatus('examStepVerify', 'reset');
            updateExamStepStatus('examStepComplete', 'reset');
            statusText.textContent = 'Uploading...';
            progressBar.style.width = '25%';
            percentText.textContent = '25%';

            const formData = new FormData();
            formData.append('pdf', file);

            try {
                const response = await fetch('upload_exam_pdf.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || 'Failed to extract PDF data');
                }

                // Step 2: Extracting Information
                updateExamStepStatus('examStepUpload', 'done');
                updateExamStepStatus('examStepExtract', 'active');
                statusText.textContent = 'Extracting Information...';
                progressBar.style.width = '50%';
                percentText.textContent = '50%';

                await new Promise(r => setTimeout(r, 400));

                // Step 3: Verifying Courses & Sections
                updateExamStepStatus('examStepExtract', 'done');
                updateExamStepStatus('examStepVerify', 'active');
                statusText.textContent = 'Verifying Courses & Sections...';
                progressBar.style.width = '75%';
                percentText.textContent = '75%';

                uploadedPdfPath = data.filePath || '';
                extractedPdfExams = data.extractedData || data.exams || [];

                await new Promise(r => setTimeout(r, 400));

                // Render extracted course cards & update final step status
                syncVerificationState();

            } catch (err) {
                showNotification(err.message || 'Error processing PDF', 'error');
                
                dropZone.style.display = 'block';
                progressContainer.style.display = 'none';
                document.getElementById('examPdfInput').value = '';
                ['examStepUpload', 'examStepExtract', 'examStepVerify', 'examStepComplete'].forEach(id => updateExamStepStatus(id, 'reset'));
            }
        }

        function renderExtractedExamsUI() {
            const alertBox = document.getElementById('examExtractionAlert');
            const container = document.getElementById('extractedExamCardsList');
            const resultsContainer = document.getElementById('extractedResultsContainer');

            // Clear previous highlights
            document.querySelectorAll('.course-select-card').forEach(c => c.classList.remove('course-missing-error'));
            container.innerHTML = '';

            const selectedCourseCards = Array.from(document.querySelectorAll('.course-select-card.selected'));
            if (selectedCourseCards.length === 0) {
                resultsContainer.style.display = 'none';
                alertBox.innerHTML = `
                    <strong><i class="ph-fill ph-warning-circle"></i> No Courses Selected</strong>
                    Please click at least one course card above to include it in the timetable verification.
                `;
                alertBox.style.display = 'block';
                showNotification('Please select at least one active course.', 'error');
                return false;
            }

            const errorMessages = [];

            for (const card of selectedCourseCards) {
                const cId = card.getAttribute('data-course-id') || card.dataset.courseId;
                const cCode = (card.getAttribute('data-course-code') || card.dataset.courseCode || '').trim();
                const cName = card.getAttribute('data-course-name') || card.dataset.courseName;
                const academicSection = (card.getAttribute('data-course-section') || card.dataset.courseSection || '').trim();

                if (!academicSection) {
                    card.classList.add('course-missing-error');
                    errorMessages.push(`<b>${cCode}</b>: Section is not set in Academic Structure.`);
                    continue;
                }

                const cleanCode = cCode.replace(/[\s-]/g, '').toUpperCase();

                // 1. Filter extracted PDF records matching the Course Code
                const courseMatches = extractedPdfExams.filter(e => {
                    if (!e.course) return false;
                    const cleanPdfCourse = e.course.replace(/[\s-]/g, '').toUpperCase();
                    return cleanPdfCourse.includes(cleanCode) || cleanCode.includes(cleanPdfCourse);
                });

                // 2. Collect error if Course Code does not exist in the PDF
                if (courseMatches.length === 0) {
                    card.classList.add('course-missing-error');
                    errorMessages.push(`<b>${cCode} (${cName})</b>: Course code not found in the uploaded timetable PDF.`);
                    continue;
                }

                // 3. Match extracted PDF record based on the Academic Structure section
                const normAcademicSection = normalizeSection(academicSection);
                const matchedExtracted = courseMatches.find(e => normalizeSection(e.section) === normAcademicSection);

                if (!matchedExtracted) {
                    card.classList.add('course-missing-error');
                    errorMessages.push(`<b>${cCode}</b>: Course found in PDF, but <b>Section ${academicSection}</b> does not match.`);
                    continue;
                }

                const dateVal = matchedExtracted.iso_date || matchedExtracted.day || matchedExtracted.date || '';
                const displayDate = matchedExtracted.display_date || dateVal;
                const timeVal = matchedExtracted.time_slot || matchedExtracted.time || '09:00 AM - 12:00 PM';
                const locVal = matchedExtracted.location || 'DK1';
                const safeSection = academicSection.replace(/"/g, '&quot;');

                const cardHtml = `
                    <div class="extracted-exam-card" data-course-id="${cId}">
                        <div class="extracted-exam-header">${cCode} - ${cName} (Section ${safeSection})</div>
                        <div class="extracted-exam-details">
                            <span><strong>Date:</strong> ${displayDate ? displayDate : 'Not Found in PDF'}</span>
                            <span><strong>Time:</strong> ${timeVal}</span>
                            <span><strong>Location:</strong> ${locVal}</span>
                        </div>
                        <input type="hidden" class="ex-date" value="${dateVal}">
                        <input type="hidden" class="ex-time" value="${timeVal}">
                        <input type="hidden" class="ex-location" value="${locVal}">
                        
                        <div class="extracted-input-grid" style="grid-template-columns: 1fr 1fr;">
                            <div>
                                <label style="font-size: 11px; font-weight: 600; color: #475569;">Section (Academic Structure)</label>
                                <input type="text" class="form-control ex-section" value="${safeSection}" readonly style="padding: 6px 10px; font-size: 13px; background-color: #e2e8f0; cursor: not-allowed;">
                            </div>
                            <div>
                                <label style="font-size: 11px; font-weight: 600; color: #475569;">Difficulty</label>
                                <select class="form-control ex-difficulty" style="padding: 6px 10px; font-size: 13px;">
                                    <option value="Easy">Easy</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="Hard">Hard</option>
                                </select>
                            </div>
                        </div>
                    </div>
                `;
                container.innerHTML += cardHtml;
            }

            if (errorMessages.length > 0) {
                resultsContainer.style.display = 'none';
                alertBox.innerHTML = `
                    <strong><i class="ph-fill ph-warning-circle"></i> Unable to Proceed — Timetable Mismatch</strong>
                    <ul>${errorMessages.map(msg => `<li>${msg}</li>`).join('')}</ul>
                    <div><strong>How to fix:</strong> Click the highlighted red course card(s) above to deselect courses that do not have a final exam, or upload the correct timetable PDF below.</div>
                `;
                alertBox.style.display = 'block';
                showNotification('Some selected courses were not found in the timetable PDF.', 'error');
                return false;
            }

            alertBox.style.display = 'none';
            resultsContainer.style.display = 'block';
            return true;
        }

        async function saveFinalExaminationBatch() {
            const cards = document.querySelectorAll('.extracted-exam-card');
            const examsToSave = [];
            let hasPastDate = false;

            const today = new Date();
            today.setHours(0, 0, 0, 0);

            cards.forEach(card => {
                const rawDate = card.querySelector('.ex-date').value;
                if (rawDate) {
                    const parts = rawDate.split('-').map(Number);
                    const parsedTs = parts.length === 3 && parts.every(Number.isFinite)
                        ? new Date(parts[0], parts[1] - 1, parts[2]).getTime()
                        : NaN;
                    if (!isNaN(parsedTs)) {
                        const examDateObj = new Date(parsedTs);
                        examDateObj.setHours(0, 0, 0, 0);
                        if (examDateObj < today) {
                            hasPastDate = true;
                        }
                    }
                }

                examsToSave.push({
                    courseID: card.getAttribute('data-course-id') || card.dataset.courseId,
                    examDate: card.querySelector('.ex-date').value,
                    examTime: card.querySelector('.ex-time').value,
                    examLocation: card.querySelector('.ex-location').value,
                    section: card.querySelector('.ex-section').value,
                    difficulty: card.querySelector('.ex-difficulty').value,
                    //type: card.querySelector('.ex-type').value
                });
            });

            //UNCOMMENT NANTI: ORIGINAL FUNCTION. JANGAN DELETE THE BELOW IF YOU WANT TO PREVENT SAVING EXAMS WITH PAST DATES
            /*if (hasPastDate) {
                showNotification('The exam date has already passed.', 'error');
                return;
            }*/

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
            const examEndTime = document.getElementById('singleExamEndTime').value;
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
                examEndTime: examEndTime,
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

        // Move "NEXT SESSION" highlight & badge to the first unchecked session
        function updateNextSessionHighlight(examID) {
            const list = document.getElementById(`session-list-${examID}`);
            if (!list) return;

            const exam = existingExams.find(e => e.examID == examID);
            const isCompleted = exam && exam.examStatus === 'Completed';

            const items = list.querySelectorAll('.subtask-item');
            let foundNext = false;

            items.forEach((item) => {
                const checkbox = item.querySelector('input[type="checkbox"]');
                const existingTag = item.querySelector('.session-tag');

                item.classList.remove('next-session-item');
                if (existingTag) {
                    existingTag.remove();
                }

                if (!isCompleted && !foundNext && checkbox && !checkbox.checked) {
                    foundNext = true;
                    item.classList.add('next-session-item');

                    // Keep the new Next Session visible even if extra sessions are collapsed
                    if (item.style.display === 'none') {
                        item.style.display = 'flex';
                    }

                    const tag = document.createElement('span');
                    tag.className = 'session-tag';
                    tag.textContent = 'Next Session';
                    item.appendChild(tag);
                }
            });
        }

        // Toggle individual existing StudyTask record
        async function toggleExamStudySession(examID, studyTaskID, scheduleID, checkboxElem) {
            const exam = existingExams.find(e => e.examID == examID);
            if (!exam) return;

            const isCompleted = checkboxElem.checked;

            if (exam.studyTasks && Array.isArray(exam.studyTasks)) {
                const task = exam.studyTasks.find(t => t.studyTaskID == studyTaskID);
                if (task) {
                    task.isCompleted = isCompleted;
                }
            }

            // Update UI text strike-through
            const textSpan = document.getElementById(`session-text-${examID}-${studyTaskID}`);
            if (textSpan) {
                textSpan.style.textDecoration = isCompleted ? 'line-through' : 'none';
                textSpan.style.color = isCompleted ? '#94a3b8' : 'inherit';
            }

            // Save status update to existing StudyTask record in Database
            try {
                const response = await fetch('exam.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'updateExamStudyProgress',
                        examID: examID,
                        studyTaskID: studyTaskID,
                        scheduleID: scheduleID,
                        isCompleted: isCompleted
                    })
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Failed to save progress');

                const total = data.totalSessions;
                const completed = data.completedSessions;
                const percentage = total > 0 ? Math.round((completed / total) * 100) : 0;
                const newStatus = data.examStatus;

                exam.examStatus = newStatus;

                // Update Progress Bar & Counter Label
                const countElem = document.getElementById(`progress-count-${examID}`);
                const pctElem = document.getElementById(`progress-text-${examID}`);
                const barElem = document.getElementById(`progress-bar-${examID}`);

                if (countElem) countElem.innerText = `${completed}/${total} completed`;
                if (pctElem) pctElem.innerText = `${percentage}%`;
                if (barElem) barElem.style.width = `${percentage}%`;

                // Update the Next Session highlight immediately
                updateNextSessionHighlight(examID);

                // Update Card & Badge UI
                const card = document.getElementById(`exam-${examID}`);
                if (card) {
                    card.setAttribute('data-status', newStatus);
                    if (newStatus === 'Completed') card.classList.add('completed-card');
                    else card.classList.remove('completed-card');

                    const statusBadge = document.getElementById(`exam-status-badge-${examID}`);
                    if (statusBadge) {
                        statusBadge.innerText = newStatus;
                        statusBadge.className = 'badge badge-status-' + newStatus.toLowerCase().replace(/\s+/g, '-');
                    }

                    moveExamCardDOM(examID, newStatus);
                }

                // Sync Master Checkbox
                const mainCb = document.getElementById(`main-exam-checkbox-${examID}`);
                if (mainCb) mainCb.checked = (newStatus === 'Completed');

                if (newStatus === 'Completed') {
                    showNotification('Assessment marked as completed.', 'success');
                }

            } catch (err) {
                showNotification(err.message || 'Could not save progress', 'error');
                checkboxElem.checked = !isCompleted;
                updateNextSessionHighlight(examID);
            }
        }

        // Toggle master checkbox for full exam completion
        async function toggleMainExamCheckbox(examID, checkboxElem) {
            const exam = existingExams.find(e => e.examID == examID);
            if (!exam) return;

            const isCompleted = checkboxElem.checked;
            const newStatus = isCompleted ? 'Completed' : 'Not Started';
            exam.examStatus = newStatus;

            if (exam.studyTasks && Array.isArray(exam.studyTasks)) {
                exam.studyTasks.forEach(task => {
                    task.isCompleted = isCompleted;
                    const textSpan = document.getElementById(`session-text-${examID}-${task.studyTaskID}`);
                    if (textSpan) {
                        textSpan.style.textDecoration = isCompleted ? 'line-through' : 'none';
                        textSpan.style.color = isCompleted ? '#94a3b8' : 'inherit';
                    }
                    const cb = textSpan ? textSpan.parentElement.querySelector('input[type="checkbox"]') : null;
                    if (cb) cb.checked = isCompleted;
                });

                const total = exam.studyTasks.length;
                const completed = isCompleted ? total : 0;
                const pct = isCompleted ? 100 : 0;

                const countElem = document.getElementById(`progress-count-${examID}`);
                const pctElem = document.getElementById(`progress-text-${examID}`);
                const barElem = document.getElementById(`progress-bar-${examID}`);

                if (countElem) countElem.innerText = `${completed}/${total} completed`;
                if (pctElem) pctElem.innerText = `${pct}%`;
                if (barElem) barElem.style.width = `${pct}%`;

                updateNextSessionHighlight(examID);
            }

            const card = document.getElementById(`exam-${examID}`);
            if (card) {
                card.setAttribute('data-status', newStatus);
                if (isCompleted) card.classList.add('completed-card');
                else card.classList.remove('completed-card');

                const statusBadge = document.getElementById(`exam-status-badge-${examID}`);
                if (statusBadge) {
                    statusBadge.innerText = newStatus;
                    statusBadge.className = 'badge badge-status-' + newStatus.toLowerCase().replace(/\s+/g, '-');
                }

                moveExamCardDOM(examID, newStatus);
            }

            try {
                const response = await fetch('exam.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'updateExamStudyProgress',
                        examID: examID,
                        isCompleted: isCompleted,
                        examStatus: newStatus
                    })
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Failed to update status');

                showNotification(isCompleted ? 'Assessment marked as completed.' : 'Assessment progress reset.', 'success');
            } catch (err) {
                showNotification(err.message || 'Failed to update status', 'error');
                checkboxElem.checked = !isCompleted;
            }
        }

        // Expand/Collapse sessions list if greater than 3
        function toggleSubtasksVisibility(examID, btn) {
            const list = document.getElementById(`session-list-${examID}`);
            if (!list) return;

            const extraSessions = list.querySelectorAll('.extra-subtask');
            const isExpanded = btn.getAttribute('data-expanded') === 'true';

            extraSessions.forEach(session => {
                // Keep the active Next Session visible even when collapsing extra sessions
                if (isExpanded && session.classList.contains('next-session-item')) {
                    session.style.display = 'flex';
                } else {
                    session.style.display = isExpanded ? 'none' : 'flex';
                }
            });

            if (isExpanded) {
                btn.setAttribute('data-expanded', 'false');
                btn.innerHTML = `Show ${extraSessions.length} More Sessions <i class="ph ph-caret-down"></i>`;
            } else {
                btn.setAttribute('data-expanded', 'true');
                btn.innerHTML = 'Show Less <i class="ph ph-caret-up"></i>';
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
            applyExamFilters();
        }

        function setStatusFilter(status, btnElement) {
            document.querySelectorAll('#statusFilterGroup .status-pill').forEach(b => b.classList.remove('active'));
            btnElement.classList.add('active');
            currentStatusFilter = status;
            applyExamFilters();
        }

        function setPriorityFilter(priority, btnElement) {
            document.querySelectorAll('#priorityFilterGroup .priority-pill').forEach(b => b.classList.remove('active'));
            btnElement.classList.add('active');
            currentPriorityFilter = priority;
            applyExamFilters();
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
            applyExamFilters();
        }

        function triggerExamFilterByName(filterName) {
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

        function applyExamFilters() {
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

                panel.querySelectorAll('.exam-item').forEach(item => {
                    const status = item.dataset.status;
                    const priority = item.dataset.priority;

                    const matchesSearch = (term === '' || courseHeader.includes(term) || item.innerText.toLowerCase().includes(term));
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
            }
        }

        function moveExamCardDOM(examID, newStatus) {
            const card = document.getElementById(`exam-${examID}`);
            if (!card) return;

            // Keep completed cards at the bottom inside the same course folder drawer
            const drawer = card.closest('.folder-items-drawer');
            if (drawer && newStatus === 'Completed') {
                drawer.appendChild(card);
            }

            // Live-update the folder card's overall progress bar
            if (drawer) {
                const slug = drawer.dataset.folderSlug;
                const allCards = drawer.querySelectorAll('.exam-item');
                let unitsTotal = 0;
                let unitsDone = 0;

                allCards.forEach(item => {
                    const cbs = item.querySelectorAll('.subtask-list input[type="checkbox"]');
                    if (cbs.length > 0) {
                        unitsTotal += cbs.length;
                        cbs.forEach(cb => { if (cb.checked) unitsDone++; });
                    } else {
                        unitsTotal += 1;
                        if (item.dataset.status === 'Completed') unitsDone += 1;
                    }
                });

                const pct = unitsTotal > 0 ? Math.round((unitsDone / unitsTotal) * 100) : 0;
                const pctEl = document.getElementById('folder-pct-' + slug);
                const stepsEl = document.getElementById('folder-steps-' + slug);
                const barEl = document.getElementById('folder-bar-' + slug);
                if (pctEl) pctEl.innerText = `${pct}% Ready`;
                if (stepsEl) stepsEl.innerText = `${unitsDone}/${unitsTotal} sessions`;
                if (barEl) barEl.style.width = `${pct}%`;
            }
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

        // Drag and drop functionality for PDF upload
        const dropZone = document.getElementById('examDropZone');

        if (dropZone) {
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
            });

            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                    handleExamPdfUpload(e.dataTransfer.files);
                }
            });
        }
    </script>
</body>
</html>