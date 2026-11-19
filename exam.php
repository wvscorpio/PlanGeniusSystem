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
                    SELECT c."courseCode", c."courseName", s."startDate", s."endDate" 
                    FROM "Course" c
                    JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
                    WHERE c."courseID" = :courseID AND s."studentID" = :studentID
                ');
                $cCheck->execute([':courseID' => $courseID, ':studentID' => $studentID]);
                $cData = $cCheck->fetch(PDO::FETCH_ASSOC);
                $cCheck->closeCursor();

                if (!$cData) continue;

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

        .toggle-subtasks-btn { margin-top: 10px; background: none; border: none; color: #2563eb; font-size: 13px; font-weight: 600; cursor: pointer; padding: 0; display: inline-flex; align-items: center; gap: 4px; }
        .toggle-subtasks-btn:hover { text-decoration: underline; }

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

        /* Progress Bar & Step Colors */
        .upload-progress-container { margin-top: 20px; }
        .upload-status-text { display: flex; justify-content: space-between; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #475569; }
        .upload-progress-bar { width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
        .upload-progress-fill { height: 100%; background: #3b82f6; width: 0%; transition: width 0.4s ease; }

        .upload-steps-list { margin-top: 15px; display: flex; flex-direction: column; gap: 10px; text-align: left; background: #f8fafc; padding: 16px; border-radius: 10px; border: 1px solid #e2e8f0; }
        .step-item { font-size: 13px; font-weight: 500; color: #94a3b8; display: flex; align-items: center; gap: 10px; transition: all 0.3s ease; }
        .step-item.active { color: #2563eb; font-weight: 600; }
        .step-item.done { color: #16a34a; font-weight: 600; }
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
                                        
                                        // Process study progress directly from StudyTask records
                                        $parsedTasks = $exam['studyTasks'] ?? [];
                                        $totalTasks = count($parsedTasks);
                                        $completedTasks = 0;
                                        foreach ($parsedTasks as $st) {
                                            $isTaskDone = filter_var($st['isCompleted'], FILTER_VALIDATE_BOOLEAN) || $st['isCompleted'] === 't' || $st['isCompleted'] === 'true' || $st['isCompleted'] === 1 || $st['isCompleted'] === '1';
                                            if ($isTaskDone) {
                                                $completedTasks++;
                                            }
                                        }
                                        $progressPercent = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

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
                                                <div class="exam-meta">
                                                    <span><i class="ph ph-calendar"></i> Date: <?= date('M d, Y', strtotime($exam['examDate'])) ?></span>
                                                    <?php if(!empty($exam['examTime'])): ?>
                                                        <span><i class="ph ph-clock"></i> Time: <?= date('g:i A', strtotime($exam['examTime'])) ?><?= !empty($exam['examEndTime']) ? ' - ' . date('g:i A', strtotime($exam['examEndTime'])) : '' ?></span>
                                                    <?php endif; ?>
                                                    <?php if(!empty($exam['examLocation'])): ?>
                                                        <span><i class="ph ph-map-pin"></i> Venue: <?= htmlspecialchars($exam['examLocation']) ?></span>
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

                                        <!-- STUDY PROGRESS SECTION (POWERED BY STUDYTASK TABLE) -->
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
                                                            $isExtra = $index >= 5;
                                                            $displayStyle = $isExtra ? 'display: none;' : '';
                                                            $extraClass = $isExtra ? ' extra-subtask' : '';
                                                            $isTaskDone = filter_var($st['isCompleted'], FILTER_VALIDATE_BOOLEAN) || $st['isCompleted'] === 't' || $st['isCompleted'] === 'true' || $st['isCompleted'] === 1 || $st['isCompleted'] === '1';
                                                            $taskID = (int)$st['studyTaskID'];
                                                            $schedID = isset($exam['sessions'][$index]['scheduleID']) ? (int)$exam['sessions'][$index]['scheduleID'] : 0;
                                                        ?>
                                                        <label class="subtask-item<?= $extraClass ?>" style="<?= $displayStyle ?>">
                                                            <input type="checkbox" 
                                                                   data-task-id="<?= $taskID ?>"
                                                                   onchange="toggleExamStudySession(<?= $exam['examID'] ?>, <?= $taskID ?>, <?= $schedID ?>, this)" 
                                                                   <?= $isTaskDone ? 'checked' : '' ?>>
                                                            <span id="session-text-<?= $exam['examID'] ?>-<?= $taskID ?>" 
                                                                  style="<?= $isTaskDone ? 'text-decoration: line-through; color: #94a3b8;' : '' ?>">
                                                                <?= htmlspecialchars($st['taskDescription']) ?>
                                                            </span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>

                                                <?php if ($totalTasks > 5): ?>
                                                    <button type="button" 
                                                            class="toggle-subtasks-btn" 
                                                            data-expanded="false" 
                                                            onclick="toggleSubtasksVisibility(<?= $exam['examID'] ?>, this)">
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
                            <div class="course-select-card selected" 
                                data-course-id="<?= htmlspecialchars($c['courseID'] ?? $c['courseid'] ?? '') ?>" 
                                data-course-code="<?= htmlspecialchars($c['courseCode'] ?? $c['coursecode'] ?? '') ?>"
                                data-course-name="<?= htmlspecialchars($c['courseName'] ?? $c['coursename'] ?? '') ?>"
                                onclick="toggleCourseCardSelection(this)">
                                
                                <!-- Top Right Icon -->
                                <div class="course-card-status">
                                    <i class="ph-fill ph-check-circle" style="font-size: 20px; color: #16a34a;"></i>
                                </div>

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
            document.getElementById('examUploadProgress').style.display = 'none';
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
            const statusIcon = card.querySelector('.course-card-status i');
            
            if (statusIcon) {
                if (card.classList.contains('selected')) {
                    // Selected: Green checkmark icon
                    statusIcon.className = 'ph-fill ph-check-circle';
                    statusIcon.style.color = '#16a34a';
                } else {
                    // Unselected: Red X-mark icon
                    statusIcon.className = 'ph-fill ph-x-circle';
                    statusIcon.style.color = '#dc2626';
                }
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
            } else {
                item.className = 'step-item';
                if (icon) icon.className = 'ph ph-circle';
            }
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

            // Hide drop zone & show progress steps
            dropZone.style.display = 'none';
            progressContainer.style.display = 'block';

            // Step 1: Uploading
            updateExamStepStatus('examStepUpload', 'active');
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

                await new Promise(r => setTimeout(r, 500));

                // Step 3: Verifying Courses & Sections
                updateExamStepStatus('examStepExtract', 'done');
                updateExamStepStatus('examStepVerify', 'active');
                statusText.textContent = 'Verifying Courses & Sections...';
                progressBar.style.width = '75%';
                percentText.textContent = '75%';

                uploadedPdfPath = data.filePath || '';
                extractedPdfExams = data.extractedData || data.exams || [];

                await new Promise(r => setTimeout(r, 500));

                // Render extracted course cards
                renderExtractedExamsUI();

                // Step 4: Complete
                updateExamStepStatus('examStepVerify', 'done');
                updateExamStepStatus('examStepComplete', 'done');
                statusText.textContent = 'PDF Extracted Successfully';
                progressBar.style.width = '100%';
                percentText.textContent = '100%';

            } catch (err) {
                showNotification(err.message || 'Error processing PDF', 'error');
                
                // Reset view on error
                dropZone.style.display = 'block';
                progressContainer.style.display = 'none';
                document.getElementById('examPdfInput').value = '';
                ['examStepUpload', 'examStepExtract', 'examStepVerify', 'examStepComplete'].forEach(id => updateExamStepStatus(id, 'reset'));
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

        function renderExtractedExamsUI() {
            const selectedCourseCards = Array.from(document.querySelectorAll('.course-select-card.selected'));
            if (selectedCourseCards.length === 0) {
                showNotification('Please select at least one active course.', 'error');
                return;
            }

            const container = document.getElementById('extractedExamCardsList');
            container.innerHTML = '';
            let errorFound = false;

            for (const card of selectedCourseCards) {
                const cId = card.getAttribute('data-course-id') || card.dataset.courseId;
                const cCode = (card.getAttribute('data-course-code') || card.dataset.courseCode || '').trim();
                const cName = card.getAttribute('data-course-name') || card.dataset.courseName;
                const sectionInput = (card.querySelector('.course-section-input')?.value || '').trim();

                const cleanCode = cCode.replace(/[\s-]/g, '').toUpperCase();

                // 1. Filter extracted PDF records matching the Course Code
                const courseMatches = extractedPdfExams.filter(e => {
                    if (!e.course) return false;
                    const cleanPdfCourse = e.course.replace(/[\s-]/g, '').toUpperCase();
                    return cleanPdfCourse.includes(cleanCode) || cleanCode.includes(cleanPdfCourse);
                });

                // 2. Error if Course Code does not exist in the PDF
                if (courseMatches.length === 0) {
                    showNotification(`No examination record found for Course Code ${cCode}.`, 'error');
                    errorFound = true;
                    break;
                }

                let matchedExtracted = null;

                // 3. Verify Course Code + Section combination
                if (sectionInput) {
                    const normInput = normalizeSection(sectionInput);
                    matchedExtracted = courseMatches.find(e => normalizeSection(e.section) === normInput);

                    if (!matchedExtracted) {
                        // Display required error format when course exists but section is missing
                        showNotification(`No examination record found for Course Code ${cCode}, Section ${sectionInput}.`, 'error');
                        errorFound = true;
                        break;
                    }
                } else {
                    // Default to exact section match check if section was provided in extracted row
                    matchedExtracted = courseMatches[0];
                }

                const dateVal = matchedExtracted.iso_date || matchedExtracted.day || matchedExtracted.date || '';
                const displayDate = matchedExtracted.display_date || dateVal;
                const timeVal = matchedExtracted.time_slot || matchedExtracted.time || '09:00 AM - 12:00 PM';
                const locVal = matchedExtracted.location || 'DK1';
                const sectionVal = matchedExtracted.section || sectionInput;

                const cardHtml = `
                    <div class="extracted-exam-card" data-course-id="${cId}">
                        <div class="extracted-exam-header">${cCode} - ${cName}</div>
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
                                <label style="font-size: 11px; font-weight: 600; color: #475569;">Section</label>
                                <input type="text" class="form-control ex-section" value="${sectionVal}" readonly style="padding: 6px 10px; font-size: 13px; background-color: #e2e8f0; cursor: not-allowed;">
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

            if (!errorFound && container.children.length > 0) {
                document.getElementById('extractedResultsContainer').style.display = 'block';
                document.getElementById('dropZoneText').innerText = 'PDF Extracted Successfully';
            } else {
                document.getElementById('extractedResultsContainer').style.display = 'none';
            }
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

            } catch (err) {
                showNotification(err.message || 'Could not save progress', 'error');
                checkboxElem.checked = !isCompleted;
            }
        }

        // Toggle master checkbox for full exam completion
        async function toggleMainExamCheckbox(examID, checkboxElem) {
            const exam = existingExams.find(e => e.examID == examID);
            if (!exam) return;

            const isCompleted = checkboxElem.checked;
            const newStatus = isCompleted ? 'Completed' : 'Not Started';

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

        // Expand/Collapse sessions list if greater than 5
        function toggleSubtasksVisibility(examID, btn) {
            const list = document.getElementById(`session-list-${examID}`);
            if (!list) return;

            const extraSessions = list.querySelectorAll('.extra-subtask');
            const isExpanded = btn.getAttribute('data-expanded') === 'true';

            extraSessions.forEach(session => {
                session.style.display = isExpanded ? 'none' : 'flex';
            });

            if (isExpanded) {
                btn.setAttribute('data-expanded', 'false');
                btn.innerHTML = 'Show More <i class="ph ph-caret-down"></i>';
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

        function moveExamCardDOM(examID, newStatus) {
            const card = document.getElementById(`exam-${examID}`);
            if (!card) return;

            let completedSection = document.getElementById('completedSection');
            let activeSection = document.getElementById('activeSection');

            if (!completedSection && newStatus === 'Completed') {
                const examsList = document.getElementById('examsList');
                completedSection = document.createElement('div');
                completedSection.id = 'completedSection';
                completedSection.style.marginTop = '40px';
                completedSection.innerHTML = `<div class="completed-section-title"><i class="ph-fill ph-check-circle"></i> Completed Assessments</div>`;
                examsList.appendChild(completedSection);
            }

            const currentCourseGroup = card.closest('.course-group');
            const courseTitleText = currentCourseGroup ? currentCourseGroup.querySelector('.course-group-title')?.innerHTML : '';

            const targetSection = (newStatus === 'Completed') ? completedSection : activeSection;
            if (!targetSection) return;

            let targetCourseGroup = Array.from(targetSection.querySelectorAll('.course-group')).find(cg => {
                const titleElem = cg.querySelector('.course-group-title');
                return titleElem && titleElem.innerHTML === courseTitleText;
            });

            if (!targetCourseGroup) {
                targetCourseGroup = document.createElement('div');
                targetCourseGroup.className = 'course-group';
                targetCourseGroup.innerHTML = `<h3 class="course-group-title">${courseTitleText}</h3>`;
                targetSection.appendChild(targetCourseGroup);
            }

            targetCourseGroup.appendChild(card);

            if (currentCourseGroup && currentCourseGroup.querySelectorAll('.exam-item').length === 0) {
                currentCourseGroup.remove();
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