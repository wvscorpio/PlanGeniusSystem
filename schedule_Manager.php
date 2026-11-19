<?php
declare(strict_types=1);

require_once __DIR__ . '/email/EmailService.php';

class TaskTypeHelper 
{
    public static function normalizeType(string $type): string 
    {
        $t = strtolower(trim($type));

        if ($t === 'final' || str_contains($t, 'final')) return 'Final';
        if ($t === 'test' || str_contains($t, 'test')) return 'Test';
        if ($t === 'quiz' || str_contains($t, 'quiz')) return 'Quiz';
        if (str_contains($t, 'lab')) return 'Lab';
        if (str_contains($t, 'project')) return 'Project';
        if (str_contains($t, 'exam')) return 'Exam';
        
        return 'Assignment';
    }

    public static function getActivityLabel(string $type): string 
    {
        return match (self::normalizeType($type)) {
            'Final'      => 'Study for Final Examination',
            'Test'       => 'Study for Test',
            'Quiz'       => 'Study for Quiz',
            'Exam'       => 'Study for Exam',
            'Lab'        => 'Work on Lab',
            'Project'    => 'Work on Project',
            default      => 'Work on Assignment',
        };
    }

    public static function getDeadlineNotification(string $type, int $daysRemaining): string 
    {
        return match (self::normalizeType($type)) {
            'Final'      => "Final Examination in {$daysRemaining} days",
            'Test'       => "Test in {$daysRemaining} days",
            'Quiz'       => "Quiz in {$daysRemaining} days",
            'Exam'       => "Exam in {$daysRemaining} days",
            'Lab'        => "Lab submission due in {$daysRemaining} days",
            'Project'    => "Project deadline in {$daysRemaining} days",
            default      => "Assignment due in {$daysRemaining} days",
        };
    }

    public static function getReminderMessage(string $type): string 
    {
        $activity = self::getActivityLabel($type);
        return "{$activity} starts in 30 minutes";
    }
}

class ScheduleManager
{
    private PDO $conn;

    public function __construct(PDO $dbConnection)
    {
        $this->conn = $dbConnection;
    }

    /**
     * Repair any existing legacy Schedule rows where courseID was saved as NULL
     */
    public function repairMissingCourseIDs(int $studentID): void
    {
        try {
            // Repair Exam schedules
            $stmt1 = $this->conn->prepare('
                UPDATE "Schedule" s
                SET "courseID" = e."courseID"
                FROM "Exam" e
                WHERE s."studentID" = :studentID
                  AND s."courseID" IS NULL
                  AND LOWER(TRIM(s."referenceType")) = \'exam\'
                  AND CAST(s."referenceID" AS INTEGER) = e."examID"
            ');
            $stmt1->execute([':studentID' => $studentID]);

            // Repair Assignment schedules
            $stmt2 = $this->conn->prepare('
                UPDATE "Schedule" s
                SET "courseID" = a."courseID"
                FROM "Assignment" a
                WHERE s."studentID" = :studentID
                  AND s."courseID" IS NULL
                  AND LOWER(TRIM(s."referenceType")) = \'assignment\'
                  AND CAST(s."referenceID" AS INTEGER) = a."assignmentID"
            ');
            $stmt2->execute([':studentID' => $studentID]);
        } catch (Exception $e) {
            // Silence background maintenance errors
        }
    }

    private function getUserSettings(int $studentID): array
    {
        $defaults = [
            'email_reminders' => true,
            'assignment_reminders' => true,
            'exam_reminders' => true,
            'study_session_reminders' => true,
            'overdue_reminders' => true,
            'assignment_reminder_days' => [3, 2, 1],
            'exam_reminder_days' => [6, 3, 2, 1],
            'study_session_reminder_minutes' => 30,
            'submission_reminder_minutes' => 60,
        ];

        $settingsFile = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'notification_settings.json';
        
        if (file_exists($settingsFile)) {
            $raw = json_decode((string)file_get_contents($settingsFile), true) ?: [];
            if (isset($raw[$studentID])) {
                $userSet = $raw[$studentID];
                return array_merge($defaults, [
                    'email_reminders' => (bool)($userSet['email_reminders'] ?? true),
                    'assignment_reminders' => (bool)($userSet['assignment_reminders'] ?? true),
                    'exam_reminders' => (bool)($userSet['exam_reminders'] ?? true),
                    'study_session_reminders' => (bool)($userSet['study_session_reminders'] ?? true),
                    'overdue_reminders' => (bool)($userSet['overdue_reminders'] ?? true),
                    'assignment_reminder_days' => [(int)($userSet['assignment_reminder_days'] ?? 3), 2, 1],
                    'exam_reminder_days' => [(int)($userSet['exam_reminder_days'] ?? 6), 3, 2, 1],
                    'study_session_reminder_minutes' => (int)($userSet['study_session_reminder_minutes'] ?? 30),
                    'submission_reminder_minutes' => (int)($userSet['submission_reminder_minutes'] ?? 60),
                ]);
            }
        }

        return $defaults;
    }

    /**
     * Retrieves the student's preferred study time from settings/profile/database.
     * Uses 7:00 PM (19:00:00) strictly as a fallback default if no preference is configured.
     */
    public function getPreferredStudyTime(int $studentID): string
    {
        $defaultStudyTime = '19:00:00'; // Fallback default: 7:00 PM

        try {
            $stmt = $this->conn->prepare('
                SELECT COALESCE(NULLIF("preferredStudyTime"::text, \'\'), NULLIF("preferred_study_time"::text, \'\')) 
                FROM "Student" 
                WHERE "studentID" = :student_id 
                LIMIT 1
            ');
            $stmt->execute([':student_id' => $studentID]);
            $val = $stmt->fetchColumn();
            if ($val !== false && !empty(trim((string)$val))) {
                return date('H:i:s', strtotime((string)$val));
            }
        } catch (Exception $e) {
            // Ignore missing column or table exceptions
        }

        try {
            $stmt = $this->conn->prepare('
                SELECT COALESCE(NULLIF("preferredStudyTime"::text, \'\'), NULLIF("preferred_study_time"::text, \'\')) 
                FROM "NotificationSetting" 
                WHERE "studentID" = :student_id 
                LIMIT 1
            ');
            $stmt->execute([':student_id' => $studentID]);
            $val = $stmt->fetchColumn();
            if ($val !== false && !empty(trim((string)$val))) {
                return date('H:i:s', strtotime((string)$val));
            }
        } catch (Exception $e) {
            // Ignore missing column or table exceptions
        }

        $settingsFile = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'notification_settings.json';
        if (file_exists($settingsFile)) {
            $raw = json_decode((string)file_get_contents($settingsFile), true) ?: [];
            if (!empty($raw[$studentID]['preferred_study_time'])) {
                return date('H:i:s', strtotime((string)$raw[$studentID]['preferred_study_time']));
            }
            if (!empty($raw[$studentID]['preferredStudyTime'])) {
                return date('H:i:s', strtotime((string)$raw[$studentID]['preferredStudyTime']));
            }
        }

        return $defaultStudyTime;
    }

    /**
     * Updates and saves the student's preferred study time.
     */
    public function updatePreferredStudyTime(int $studentID, string $studyTime): bool
    {
        $formattedTime = date('H:i:s', strtotime($studyTime));
        $success = false;

        // Attempt DB update on Student table
        try {
            $stmt = $this->conn->prepare('
                UPDATE "Student" 
                SET "preferredStudyTime" = :studyTime 
                WHERE "studentID" = :studentID
            ');
            $stmt->execute([':studyTime' => $formattedTime, ':studentID' => $studentID]);
            if ($stmt->rowCount() > 0) {
                $success = true;
            }
        } catch (Exception $e) {
            // Silence if column/table missing
        }

        // Attempt DB update on NotificationSetting table
        if (!$success) {
            try {
                $stmt = $this->conn->prepare('
                    UPDATE "NotificationSetting" 
                    SET "preferredStudyTime" = :studyTime 
                    WHERE "studentID" = :studentID
                ');
                $stmt->execute([':studyTime' => $formattedTime, ':studentID' => $studentID]);
                if ($stmt->rowCount() > 0) {
                    $success = true;
                }
            } catch (Exception $e) {
                // Silence if column/table missing
            }
        }

        // Update JSON storage fallback
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
        if (!file_exists($dir)) {
            @mkdir($dir, 0777, true);
        }
        $settingsFile = $dir . DIRECTORY_SEPARATOR . 'notification_settings.json';
        $raw = [];
        if (file_exists($settingsFile)) {
            $raw = json_decode((string)file_get_contents($settingsFile), true) ?: [];
        }
        $raw[$studentID]['preferred_study_time'] = $formattedTime;
        $raw[$studentID]['preferredStudyTime'] = $formattedTime;
        file_put_contents($settingsFile, json_encode($raw, JSON_PRETTY_PRINT));

        return true;
    }

    /**
     * Counts active auto-generated study sessions for a given date (1 session = 1 hour).
     */
    private function getDailySessionCount(int $studentID, string $dateStr): int
    {
        try {
            $stmt = $this->conn->prepare('
                SELECT COUNT(*) FROM "Schedule"
                WHERE "studentID" = :student_id
                  AND "studyDate" = :study_date
                  AND "status" != \'Completed\'
            ');
            $stmt->execute([
                ':student_id' => $studentID,
                ':study_date' => $dateStr
            ]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Prevents adding duplicate study sessions for the same item on the same day.
     */
    private function sessionExists(int $studentID, int $referenceID, string $referenceType, string $dateStr): bool
    {
        try {
            $stmt = $this->conn->prepare('
                SELECT 1 FROM "Schedule"
                WHERE "studentID" = :student_id
                  AND CAST("referenceID" AS INTEGER) = :reference_id
                  AND LOWER(TRIM("referenceType")) = LOWER(TRIM(:reference_type))
                  AND "studyDate" = :study_date
                LIMIT 1
            ');
            $stmt->execute([
                ':student_id'     => $studentID,
                ':reference_id'   => $referenceID,
                ':reference_type' => $referenceType,
                ':study_date'     => $dateStr
            ]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Finds the nearest date with available capacity (< 2 sessions) if target day is full.
     */
    private function findNearestAvailableDay(int $studentID, DateTime $targetDate, array $availableDays): DateTime
    {
        $targetStr = $targetDate->format('Y-m-d');
        if ($this->getDailySessionCount($studentID, $targetStr) < 2) {
            return $targetDate;
        }

        $availMap = [];
        foreach ($availableDays as $d) {
            $availMap[$d->format('Y-m-d')] = $d;
        }

        for ($offset = 1; $offset <= 30; $offset++) {
            foreach ([-$offset, $offset] as $dir) {
                $candidate = (clone $targetDate)->modify("{$dir} day");
                $candStr = $candidate->format('Y-m-d');
                if (isset($availMap[$candStr]) && $this->getDailySessionCount($studentID, $candStr) < 2) {
                    return $candidate;
                }
            }
        }

        return $targetDate;
    }

    /**
     * Inserts a study session using the student's preferred study time while observing daily limits.
     */
    private function insertStudySession(
        int $studentID,
        ?int $courseID,
        DateTime $targetDate,
        string $priority,
        int $referenceID,
        string $referenceType,
        array $availableDays
    ): void {
        $finalDate = $this->findNearestAvailableDay($studentID, $targetDate, $availableDays);
        $dateStr = $finalDate->format('Y-m-d');

        if ($this->sessionExists($studentID, $referenceID, $referenceType, $dateStr)) {
            return;
        }

        $currentCount = $this->getDailySessionCount($studentID, $dateStr);
        if ($currentCount >= 2) {
            return; // Enforces max 2 hours (2 sessions) per day limit
        }

        // Retrieve preferred study time from student settings/profile/database
        $preferredTime = $this->getPreferredStudyTime($studentID);

        // Retrieve existing session times on this date to handle same-day time collisions
        $stmtExisting = $this->conn->prepare('
            SELECT "studyTime" FROM "Schedule"
            WHERE "studentID" = :student_id
              AND "studyDate" = :study_date
              AND "status" != \'Completed\'
        ');
        $stmtExisting->execute([
            ':student_id' => $studentID,
            ':study_date' => $dateStr
        ]);
        $existingTimes = $stmtExisting->fetchAll(PDO::FETCH_COLUMN);

        $studyTime = $preferredTime;

        // If the student's preferred time is already taken on this day, offset the second session by 1 hour
        if (in_array($studyTime, $existingTimes, true)) {
            $timeObj = new DateTime($studyTime);
            $timeObj->modify('+1 hour');
            $candidateTime = $timeObj->format('H:i:s');

            if (!in_array($candidateTime, $existingTimes, true) && $candidateTime > $studyTime) {
                $studyTime = $candidateTime;
            } else {
                $timeObj = new DateTime($preferredTime);
                $timeObj->modify('-1 hour');
                $studyTime = $timeObj->format('H:i:s');
            }
        }

        $stmt = $this->conn->prepare('
            INSERT INTO "Schedule" ("studentID", "courseID", "studyDate", "studyTime", "priorityLevel", "referenceID", "referenceType", "status") 
            VALUES (:student_id, :course_id, :study_date, :study_time, :priority, :reference_id, :reference_type, \'Pending\')
        ');
        $stmt->execute([
            ':student_id'     => $studentID,
            ':course_id'      => $courseID,
            ':study_date'     => $dateStr,
            ':study_time'     => $studyTime,
            ':priority'       => $priority,
            ':reference_id'   => $referenceID,
            ':reference_type' => $referenceType
        ]);
    }

    public function syncAssignmentSchedule(
        int $studentID,
        int $assignmentID,
        string $dueDate,
        array $subtasks,
        string $semStart,
        string $semEnd,
        string $taskType = 'Assignment',
        string $status = 'Pending'
    ): void {
        $today = new DateTime('today');
        $sStart = new DateTime($semStart);
        $sEnd = new DateTime($semEnd);

        // Do not generate schedule entries or reminders for inactive or upcoming semesters
        if ($today < $sStart || $today > $sEnd) {
            return;
        }

        if (strtolower($status) === 'completed') {
            $this->cleanupTaskData($studentID, $assignmentID, 'Assignment');
            return;
        }

        // Clear previous pending sessions for this assignment
        $stmt = $this->conn->prepare('
            DELETE FROM "Schedule" 
            WHERE "studentID" = :student_id 
            AND CAST("referenceID" AS INTEGER) = :reference_id 
            AND "referenceType" = \'Assignment\' 
            AND "status" != \'Completed\'
        ');
        $stmt->execute([
            ':student_id'   => $studentID,
            ':reference_id' => $assignmentID
        ]);

        $today = new DateTime('today');
        $due = new DateTime($dueDate);
        $daysRemaining = (int)$today->diff($due)->format('%r%a');

        if ($daysRemaining < 0) {
            $this->createOverdueNotification($studentID, $assignmentID, 'Assignment', $taskType);
            return;
        }

        // Retrieve stored assignment record details
        $details = $this->getTaskDetails($assignmentID, 'Assignment');
        $courseID = $details['courseID'] ?? null;
        $priority = $details['priorityLevel'] ?? 'Medium';

        $activeSubtasks = array_filter($subtasks, function ($st): bool {
            return !($st['isCompleted'] === true || $st['isCompleted'] === 't' || $st['isCompleted'] == 1);
        });

        $rawTaskCount = !empty($activeSubtasks) ? count($activeSubtasks) : 1;
        $availableDays = $this->calculateAvailableDays($today, $due, $semStart, $semEnd);
        $availCount = count($availableDays);

        // Adjust session density and multipliers based on the assignment's actual priorityLevel
        $priorityKey = strtolower($priority);
        if ($priorityKey === 'high') {
            $daysInterval = 2;
            $multiplier = 1.5;
        } elseif ($priorityKey === 'low') {
            $daysInterval = 4;
            $multiplier = 0.75;
        } else {
            $daysInterval = 3;
            $multiplier = 1.0;
        }

        // Cap total sessions based on available timeline density and priority multiplier
        $maxSessions = min(6, max(1, (int)ceil($availCount / $daysInterval)));
        $targetSessions = max(1, min((int)ceil($rawTaskCount * $multiplier), $maxSessions));

        // Space sessions out evenly with forced rest days
        $selectedDays = [];
        if ($availCount <= $targetSessions) {
            // Short timeline: take available days
            $selectedDays = $availableDays;
        } elseif ($targetSessions === 1) {
            // Single session: place midway or near start
            $midIdx = (int)floor($availCount / 2);
            $selectedDays[] = $availableDays[$midIdx];
        } else {
            // Spread evenly across available days
            $step = ($availCount - 1) / ($targetSessions - 1);
            for ($i = 0; $i < $targetSessions; $i++) {
                $idx = (int)round($i * $step);
                $selectedDays[] = $availableDays[$idx];
            }
        }

        foreach ($selectedDays as $schedDate) {
            $this->insertStudySession(
                $studentID,
                $courseID,
                $schedDate,
                $priority,
                $assignmentID,
                'Assignment',
                $availableDays
            );
        }

        $this->syncNotifications($studentID, $assignmentID, 'Assignment', $taskType, $due, $today, $daysRemaining);
    }

    public function syncExamSchedule(
        int $studentID,
        int $examID,
        string $examDate,
        string $examType,
        string $semStart,
        string $semEnd,
        string $difficultyLevel = 'Medium',
        string $status = 'Scheduled',
        ?int $explicitCourseID = null
    ): void {
        $today = new DateTime('today');
        $sStart = new DateTime($semStart);
        $sEnd = new DateTime($semEnd);

        // Do not generate schedule entries or reminders for inactive or upcoming semesters
        if ($today < $sStart || $today > $sEnd) {
            return;
        }

        if (strtolower($status) === 'completed') {
            $this->cleanupTaskData($studentID, $examID, 'Exam');
            return;
        }

        // Removes existing pending/auto-generated sessions for this specific assessment
        $stmt = $this->conn->prepare('
            DELETE FROM "Schedule" 
            WHERE "studentID" = :student_id 
              AND CAST("referenceID" AS INTEGER) = :reference_id 
              AND "referenceType" = \'Exam\' 
              AND "status" != \'Completed\'
        ');
        $stmt->execute([
            ':student_id'   => $studentID,
            ':reference_id' => $examID
        ]);

        $today = new DateTime('today');
        $exam = new DateTime($examDate);
        $daysRemaining = (int)$today->diff($exam)->format('%r%a');

        if ($daysRemaining < 0) {
            $this->createOverdueNotification($studentID, $examID, 'Exam', $examType);
            return;
        }

        $details = $this->getTaskDetails($examID, 'Exam');
        $courseID = $explicitCourseID ?? ($details['courseID'] ?? null);
        
        // Use stored DB difficulty level if available, falling back to method parameter
        $actualDifficulty = $details['difficultyLevel'] ?? $difficultyLevel;

        $lastStudyDay = (clone $exam)->modify('-1 day');
        $availableDays = $this->calculateAvailableDays($today, $lastStudyDay, $semStart, $semEnd);

        // Adjust revision days based on normalized assessment type + difficulty level
        $normType = TaskTypeHelper::normalizeType($examType);
        $revisionDays = match ($normType) {
            'Quiz' => match (ucfirst(strtolower((string)$actualDifficulty))) {
                'Hard'  => 2,
                default => 1,
            },
            'Test' => match (ucfirst(strtolower((string)$actualDifficulty))) {
                'Hard'  => 3,
                'Easy'  => 1,
                default => 2,
            },
            default => match (ucfirst(strtolower((string)$actualDifficulty))) { // Final, Midterm, Exam
                'Hard'   => 6,
                'Medium' => 4,
                'Easy'   => 2,
                default  => 3,
            }
        };

        // Evenly spread revision days across available days up to the exam day
        $availCount = count($availableDays);
        if ($availCount > $revisionDays && $revisionDays > 1) {
            $step = ($availCount - 1) / ($revisionDays - 1);
            $selectedDays = [];
            for ($i = 0; $i < $revisionDays; $i++) {
                $idx = (int)round($i * $step);
                $selectedDays[] = $availableDays[$idx];
            }
        } else {
            $selectedDays = array_slice($availableDays, -min($availCount, $revisionDays));
        }

        if (empty($selectedDays)) {
            $selectedDays = [$today];
        }

        // Map difficulty directly to session priority level
        $mappedPriority = match (ucfirst(strtolower((string)$actualDifficulty))) {
            'Hard'  => 'High',
            'Easy'  => 'Low',
            default => 'Medium',
        };

        foreach ($selectedDays as $schedDate) {
            // Strictly enforce that study sessions are NOT scheduled on the actual exam date
            if ($schedDate->format('Y-m-d') === $exam->format('Y-m-d')) {
                continue;
            }

            $this->insertStudySession(
                $studentID,
                $courseID,
                $schedDate,
                $mappedPriority,
                $examID,
                'Exam',
                $availableDays
            );
        }

        $this->syncNotifications($studentID, $examID, 'Exam', $examType, $exam, $today, $daysRemaining);
    }

    private function calculateAvailableDays(DateTime $start, DateTime $end, string $semStart, string $semEnd): array
    {
        $days = [];
        $current = clone $start;
        $sStart = new DateTime($semStart);
        $sEnd = new DateTime($semEnd);

        $actualStart = max($current, $sStart);
        $actualEnd = min($end, $sEnd);

        while ($actualStart <= $actualEnd) {
            $days[] = clone $actualStart;
            $actualStart->modify('+1 day');
        }

        return empty($days) ? [$start] : $days;
    }

    private function syncNotifications(
        int $studentID,
        int $referenceID,
        string $referenceType,
        string $taskType,
        DateTime $dueDateObj,
        DateTime $today,
        int $daysRemaining
    ): void {
        $settings = $this->getUserSettings($studentID);
        $this->removeFutureNotificationsForReference($studentID, $referenceID, $referenceType);

        $isExam = strtolower($referenceType) === 'exam';
        if ($isExam && !$settings['exam_reminders']) return;
        if (!$isExam && !$settings['assignment_reminders']) return;

        $daysList = $isExam ? $settings['exam_reminder_days'] : $settings['assignment_reminder_days'];
        $details = $this->getTaskDetails($referenceID, $referenceType);
        $courseCode = !empty($details['courseCode']) ? $details['courseCode'] : 'GENERAL';
        $title = $details['title'] ?? $referenceType;

        foreach ($daysList as $d) {
            if ($daysRemaining === $d) {
                $time = (new DateTime())->format('Y-m-d H:i:s');
                $deadlineText = TaskTypeHelper::getDeadlineNotification($taskType, $d);
                $msg = sprintf('[%s] %s: %s', $courseCode, $title, $deadlineText);
                $this->createNotification($studentID, $msg, $time, $referenceID, $referenceType, 'Reminder', new DateTime($time));
            }
        }

        if ($daysRemaining === 0) {
            $time = (new DateTime())->format('Y-m-d H:i:s');
            $msg = sprintf('[%s] %s is due TODAY!', $courseCode, $title);
            $this->createNotification($studentID, $msg, $time, $referenceID, $referenceType, 'DueToday', new DateTime($time));
        }
    }

    private function createOverdueNotification(int $studentID, int $referenceID, string $referenceType, string $taskType): void
    {
        $settings = $this->getUserSettings($studentID);
        if (!$settings['overdue_reminders']) return;

        $details = $this->getTaskDetails($referenceID, $referenceType);
        $courseCode = $details['courseCode'] ?? 'COURSE';
        $title = $details['title'] ?? $referenceType;
        $normalizedType = TaskTypeHelper::normalizeType($taskType);

        $time = (new DateTime())->format('Y-m-d H:i:s');
        $msg = sprintf('[%s] OVERDUE (%s): %s was due on %s', $courseCode, $normalizedType, $title, $details['dueDate'] ?? 'earlier');
        $this->createNotification($studentID, $msg, $time, $referenceID, $referenceType, 'Overdue', new DateTime($time));
    }

    public function getTaskDetails(int $referenceID, string $referenceType): array
    {
        try {
            if ($referenceType === 'Assignment') {
                $stmt = $this->conn->prepare('
                    SELECT a."assignmentTitle" AS title, a."dueDate", a."courseID", c."courseCode", c."courseName", a."priorityLevel" AS priority
                    FROM "Assignment" a
                    JOIN "Course" c ON a."courseID" = c."courseID"
                    WHERE a."assignmentID" = :id
                ');
            } else {
                $stmt = $this->conn->prepare('
                    SELECT 
                        CASE 
                            WHEN e."examTitle" IS NOT NULL AND e."examTitle" != \'\' THEN 
                                CASE 
                                    WHEN e."examTitle" ILIKE e."examType" || \'%\' THEN e."examTitle"
                                    ELSE e."examType" || \': \' || e."examTitle"
                                END
                            ELSE e."examType"
                        END AS title, 
                        e."examDate" AS "dueDate", 
                        e."courseID", 
                        c."courseCode", 
                        c."courseName", 
                        e."difficultyLevel" AS priority
                    FROM "Exam" e
                    JOIN "Course" c ON e."courseID" = c."courseID"
                    WHERE e."examID" = :id
                ');
            }
            $stmt->execute([':id' => $referenceID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                foreach ($row as $k => $v) {
                    $lk = strtolower($k);
                    if ($lk === 'courseid') $row['courseID'] = (int)$v;
                    if ($lk === 'coursecode') $row['courseCode'] = $v;
                    if ($lk === 'coursename') $row['courseName'] = $v;
                }
                return $row;
            }
            return [];
        } catch (Exception $e) {
            return [];
        }
    }

    private function notificationExists(int $studentID, int $referenceID, string $referenceType, string $notificationType): bool
    {
        try {
            $stmt = $this->conn->prepare('
                SELECT 1 FROM "Notification" 
                WHERE "studentID" = :student_id
                  AND "referenceID" = :reference_id
                  AND "referenceType" = :reference_type
                  AND "notificationType" = :notification_type
                LIMIT 1
            ');
            $stmt->execute([
                ':student_id' => $studentID,
                ':reference_id' => $referenceID,
                ':reference_type' => $referenceType,
                ':notification_type' => $notificationType,
            ]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }

    private function createNotification(
        int $studentID,
        string $message,
        string $sentDate,
        int $referenceID,
        string $referenceType,
        string $notificationType,
        DateTime $triggerDate
    ) {
        try {
            if ($this->notificationExists($studentID, $referenceID, $referenceType, $notificationType)) return false;

            $stmt = $this->conn->prepare('
                INSERT INTO "Notification"
                    ("studentID", "notificationMessage", "notificationStatus", "sentDate", "referenceID", "referenceType", "notificationType", "triggerDate")
                VALUES
                    (:student_id, :message, \'Unread\', :sent_date, :reference_id, :reference_type, :notification_type, :trigger_date)
            ');
            $stmt->execute([
                ':student_id'        => $studentID,
                ':message'           => $message,
                ':sent_date'         => $sentDate,
                ':reference_id'      => $referenceID,
                ':reference_type'    => $referenceType,
                ':notification_type' => $notificationType,
                ':trigger_date'      => $triggerDate->format('Y-m-d H:i:s')
            ]);

            return $this->conn->lastInsertId();
        } catch (Exception $e) {
            return false;
        }
    }

    private function removeFutureNotificationsForReference(int $studentID, int $referenceID, string $referenceType): void
    {
        try {
            $stmt = $this->conn->prepare('
                DELETE FROM "Notification" 
                WHERE "studentID" = :student_id 
                  AND "referenceID" = :reference_id
                  AND "referenceType" = :reference_type
                  AND "triggerDate" > CURRENT_TIMESTAMP
            ');
            $stmt->execute([
                ':student_id'     => $studentID,
                ':reference_id'   => $referenceID,
                ':reference_type' => $referenceType
            ]);
        } catch (Exception $e) {
            // Ignore missing notification cleanups
        }
    }

    public function cleanupTaskData(int $studentID, int $referenceID, string $referenceType): void
    {
        try {
            $stmt = $this->conn->prepare('
                DELETE FROM "Schedule" 
                WHERE "studentID" = :student_id 
                  AND CAST("referenceID" AS INTEGER) = :reference_id 
                  AND "referenceType" = :reference_type 
                  AND "status" != \'Completed\'
            ');
            $stmt->execute([
                ':student_id'     => $studentID,
                ':reference_id'   => $referenceID,
                ':reference_type' => $referenceType
            ]);

            $this->removeFutureNotificationsForReference($studentID, $referenceID, $referenceType);
        } catch (Exception $e) {
            // Ignore cleanup exceptions
        }
    }
}