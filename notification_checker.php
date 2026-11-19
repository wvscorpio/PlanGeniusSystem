<?php
declare(strict_types=1);

require_once __DIR__ . '/dbConn.php';
require_once __DIR__ . '/email/EmailService.php';

/**
 * Single authoritative implementation of PlanGenius notification business rules.
 */
final class NotificationRules
{
    public const ASSIGNMENT_LEAD_DAYS = [3, 2, 1];
    public const EXAM_LEAD_DAYS = [6, 3, 2, 1];
    public const STUDY_SESSION_LEAD_MINUTES = 30;
    public const ASSIGNMENT_SUBMISSION_LEAD_MINUTES = 60;
}

final class NotificationChecker
{
    private DateTimeZone $timezone;
    private DateTimeImmutable $now;
    private array $settingsCache = [];

    public function __construct(private PDO $conn)
    {
        $this->timezone = new DateTimeZone('Asia/Kuala_Lumpur');
        $this->now = new DateTimeImmutable('now', $this->timezone);
    }

    public function run(?int $studentID = null): array
    {
        $results = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        $assignments = $this->fetchAssignments($studentID);
        foreach ($assignments as $item) {
            $this->processDateReminder($item, 'assignment', $results);
        }

        foreach ($this->fetchExams($studentID) as $item) {
            $this->processDateReminder($item, 'exam', $results);
        }

        foreach ($this->fetchStudySessions($studentID) as $item) {
            $this->processTimeReminder($item, 'study_session_30_minutes', NotificationRules::STUDY_SESSION_LEAD_MINUTES, $results);
        }

        foreach ($assignments as $item) {
            $this->processTimeReminder($item, 'submission_60_minutes', NotificationRules::ASSIGNMENT_SUBMISSION_LEAD_MINUTES, $results);
        }

        return $results;
    }

    private function processDateReminder(array $item, string $category, array &$results): void
    {
        $settingKey = $category === 'exam' ? 'examReminders' : 'assignmentReminders';
        if (!$this->emailEnabled((int)$item['studentID'], $settingKey)) {
            $results['skipped']++;
            return;
        }

        $dueDate = new DateTimeImmutable((string)$item['dueDate'], $this->timezone);
        $daysRemaining = (int)$this->now->setTime(0, 0)->diff($dueDate->setTime(0, 0))->format('%r%a');
        
        // 1. Handle Overdue Items
        if ($daysRemaining < 0) {
            if (!$this->emailEnabled((int)$item['studentID'], 'overdueReminders')) return;
            $courseTag = !empty($item['courseCode']) ? '[' . $item['courseCode'] . '] ' : '';
            $subject = 'PlanGenius Alert: ' . $courseTag . $item['title'] . ' is OVERDUE!';
            $this->deliver($item, $category . '_overdue', $subject, $results);
            return;
        }

        // 2. Handle Due Today Items
        if ($daysRemaining === 0) {
            $courseTag = !empty($item['courseCode']) ? '[' . $item['courseCode'] . '] ' : '';
            $subject = 'PlanGenius Reminder: ' . $courseTag . $item['title'] . ' is due TODAY!';
            $this->deliver($item, $category . '_due_today', $subject, $results);
            return;
        }

        // 3. Handle Countdown Reminders (3, 2, 1 days)
        $allowedDays = $category === 'exam' ? NotificationRules::EXAM_LEAD_DAYS : NotificationRules::ASSIGNMENT_LEAD_DAYS;
        if (!in_array($daysRemaining, $allowedDays, true)) {
            return;
        }

        $typeKey = $category . '_' . $daysRemaining . '_day' . ($daysRemaining === 1 ? '' : 's');
        $item['timeLeft'] = $daysRemaining . ' day' . ($daysRemaining === 1 ? '' : 's');
        $item['time'] = $item['time'] ?: 'TBA';
        
        $courseTag = !empty($item['courseCode']) ? '[' . $item['courseCode'] . '] ' : '';
        $subject = 'PlanGenius Reminder: ' . $courseTag . $item['type'] . ' ' . $this->dayLabel($daysRemaining);
        
        $this->deliver($item, $typeKey, $subject, $results);
    }

    private function processTimeReminder(array $item, string $notificationType, int $minutes, array &$results): void
    {
        $settingKey = ($notificationType === 'study_session_30_minutes') ? 'studySessionReminders' : 'assignmentSubmissionAlert';
        if (!$this->emailEnabled((int)$item['studentID'], $settingKey)) {
            $results['skipped']++;
            return;
        }

        // Set default fallbacks if eventAt is missing or empty
        if ((empty($item['eventAt']) || trim($item['eventAt']) === '') && !empty($item['dueDate'])) {
            if ($notificationType === 'submission_60_minutes') {
                $item['eventAt'] = $item['dueDate'] . ' 23:59:00';
                $item['time'] = '23:59';
            } elseif ($notificationType === 'study_session_30_minutes') {
                // Default study session time if none is set
                $item['eventAt'] = $item['dueDate'] . ' 09:00:00';
                $item['time'] = '09:00';
            }
        }

        if (empty($item['eventAt']) || trim($item['eventAt']) === '') {
            return;
        }

        $eventAt = new DateTimeImmutable($item['eventAt'], $this->timezone);
        $minutesRemaining = (int)floor(($eventAt->getTimestamp() - $this->now->getTimestamp()) / 60);
        
        if ($minutesRemaining < 0 || $minutesRemaining > $minutes) {
            return;
        }

        $item['timeLeft'] = $minutesRemaining . ' minute' . ($minutesRemaining === 1 ? '' : 's');
        $label = ($notificationType === 'study_session_30_minutes') ? 'Study session' : 'Submission deadline';
        $courseTag = !empty($item['courseCode']) ? '[' . $item['courseCode'] . '] ' : '';
        $subject = 'PlanGenius Reminder: ' . $courseTag . $label . ' in ' . $item['timeLeft'];

        $this->deliver($item, $notificationType, $subject, $results);
    }

    private function deliver(array $item, string $notificationType, string $subject, array &$results): void
    {
        if ($this->notificationExists((int)$item['studentID'], (int)$item['referenceID'], (string)$item['referenceType'], $notificationType)) {
            $results['skipped']++;
            return;
        }

        $service = new EmailService();
        $sent = $service->sendReminder(
            (string)$item['email'],
            trim((string)$item['firstName'] . ' ' . (string)$item['lastName']),
            $subject,
            (string)$item['type'],
            $item,
            (string)$item['timeLeft']
        );

        if (!$sent) {
            $results['failed']++;
            return;
        }

        try {

            $utcNow = new DateTime('now', new DateTimeZone('UTC'));
            $sentDateUtc = $utcNow->format('Y-m-d H:i:s');

            /*$stmt = $this->conn->prepare('INSERT INTO "Notification"
                ("studentID", "notificationMessage", "notificationStatus", "sentDate", "referenceID", "referenceType", "notificationType", "triggerDate")
                VALUES (:studentID, :message, \'Unread\', :sentDate, :referenceID, :referenceType, :notificationType, :triggerDate)');

            $stmt->execute([
                ':studentID'        => $item['studentID'],
                ':message'          => $subject,
                ':sentDate'         => $this->now->format('Y-m-d H:i:s'), // Waktu tempatan Malaysia
                ':referenceID'      => $item['referenceID'],
                ':referenceType'    => $item['referenceType'],
                ':notificationType' => $notificationType,
                ':triggerDate'      => $this->now->format('Y-m-d H:i:s'),
            ]);*/

            $stmt = $this->conn->prepare('INSERT INTO "Notification"
                ("studentID", "notificationMessage", "notificationStatus", "sentDate", "referenceID", "referenceType", "notificationType", "triggerDate")
                VALUES (:studentID, :message, \'Unread\', :sentDate, :referenceID, :referenceType, :notificationType, :triggerDate)');

            $stmt->execute([
                ':studentID'        => $item['studentID'],
                ':message'          => $subject,
                ':sentDate'         => $sentDateUtc,
                ':referenceID'      => $item['referenceID'],
                ':referenceType'    => $item['referenceType'],
                ':notificationType' => $notificationType,
                ':triggerDate'      => $sentDateUtc,
            ]);
            $results['sent']++;
        } catch (Throwable $exception) {
            $results['failed']++;
            $results['errors'][] = 'Email sent but notification record failed for ' . $notificationType . ': ' . $exception->getMessage();
            error_log(end($results['errors']));
        }
    }

    private function fetchAssignments(?int $studentID): array
    {
        $where = $studentID === null ? '' : ' AND s."studentID" = :studentID';
        $stmt = $this->conn->prepare('SELECT s."studentID", s.email, s."firstName", s."lastName",
                a."assignmentID" AS "referenceID", \'Assignment\' AS "referenceType",
                a."assignmentTitle" AS title, a."assignmentType" AS type, a."dueDate" AS "dueDate",
                NULL AS time, NULL AS location, c."courseCode", c."courseName", a."priorityLevel" AS priority
            FROM "Assignment" a 
            JOIN "Course" c ON c."courseID" = a."courseID"
            JOIN "SemesterPlan" sp ON sp."semesterID" = c."semesterID"
            JOIN "Student" s ON s."studentID" = sp."studentID"
            WHERE COALESCE(a."taskStatus", \'\') <> \'Completed\'' . $where);
        if ($studentID !== null) {
            $stmt->bindValue(':studentID', $studentID, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchExams(?int $studentID): array
    {
        $where = $studentID === null ? '' : ' AND s."studentID" = :studentID';
        $stmt = $this->conn->prepare('SELECT s."studentID", s.email, s."firstName", s."lastName",
                e."examID" AS "referenceID", \'Exam\' AS "referenceType",
                COALESCE(NULLIF(e."examTitle", \'\'), e."examType") AS title,
                e."examType" AS type, e."examDate" AS "dueDate", e."examTime" AS time,
                e."examLocation" AS location, c."courseCode", c."courseName", e."difficultyLevel" AS priority
            FROM "Exam" e 
            JOIN "Course" c ON c."courseID" = e."courseID"
            JOIN "SemesterPlan" sp ON sp."semesterID" = c."semesterID"
            JOIN "Student" s ON s."studentID" = sp."studentID"
            WHERE COALESCE(e."examStatus", \'\') <> \'Completed\'' . $where);
        if ($studentID !== null) {
            $stmt->bindValue(':studentID', $studentID, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchStudySessions(?int $studentID): array
    {
        $where = $studentID === null ? '' : ' AND sch."studentID" = :studentID';
        $stmt = $this->conn->prepare('SELECT sch."studentID", st.email, st."firstName", st."lastName",
                sch."scheduleID" AS "referenceID", \'StudySession\' AS "referenceType",
                COALESCE(a."assignmentTitle", e."examTitle", \'Study session\') AS title,
                COALESCE(a."assignmentType", e."examType", \'Study Session\') AS type,
                sch."studyDate" AS "dueDate", sch."studyTime" AS time, NULL AS location,
                COALESCE(c1."courseCode", c2."courseCode", c3."courseCode") AS "courseCode",
                COALESCE(c1."courseName", c2."courseName", c3."courseName") AS "courseName",
                sch."studyDate"::text || \' \' || sch."studyTime"::text AS "eventAt", sch."priorityLevel" AS priority
            FROM "Schedule" sch 
            JOIN "Student" st ON st."studentID" = sch."studentID"
            LEFT JOIN "Course" c1 ON c1."courseID" = sch."courseID"
            LEFT JOIN "Assignment" a ON LOWER(TRIM(sch."referenceType")) = \'assignment\' AND CAST(sch."referenceID" AS bigint) = a."assignmentID"
            LEFT JOIN "Course" c2 ON c2."courseID" = a."courseID"
            LEFT JOIN "Exam" e ON LOWER(TRIM(sch."referenceType")) = \'exam\' AND CAST(sch."referenceID" AS bigint) = e."examID"
            LEFT JOIN "Course" c3 ON c3."courseID" = e."courseID"
            WHERE COALESCE(sch."status", \'Pending\') <> \'Completed\'' . $where);
        if ($studentID !== null) {
            $stmt->bindValue(':studentID', $studentID, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function notificationExists(int $studentID, int $referenceID, string $referenceType, string $notificationType): bool
    {
        $stmt = $this->conn->prepare('SELECT 1 FROM "Notification" WHERE "studentID" = :studentID AND "referenceID" = :referenceID AND "referenceType" = :referenceType AND "notificationType" = :notificationType LIMIT 1');
        $stmt->execute(compact('studentID', 'referenceID', 'referenceType', 'notificationType'));
        return (bool)$stmt->fetchColumn();
    }

    private function emailEnabled(int $studentID, string $setting): bool
    {
        if (!isset($this->settingsCache[$studentID])) {
            $stmt = $this->conn->prepare('SELECT * FROM "NotificationSetting" WHERE "studentID" = :studentID LIMIT 1');
            $stmt->execute([':studentID' => $studentID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->settingsCache[$studentID] = $row ?: [
                'emailReminders'            => true,
                'assignmentReminders'       => true,
                'examReminders'             => true,
                'studySessionReminders'     => true,
                'assignmentSubmissionAlert' => true,
                'overdueReminders'          => true,
            ];
        }

        $userSettings = $this->settingsCache[$studentID];

        // Global master toggle check
        if (empty($userSettings['emailReminders'])) {
            return false;
        }

        // Map alternate key conventions safely
        $keyMap = [
            'email_reminders'              => 'emailReminders',
            'assignment_reminders'         => 'assignmentReminders',
            'exam_reminders'               => 'examReminders',
            'study_session_reminders'      => 'studySessionReminders',
            'assignment_submission_alert'  => 'assignmentSubmissionAlert',
            'overdue_reminders'            => 'overdueReminders',
        ];

        $targetKey = $keyMap[$setting] ?? $setting;
        return !empty($userSettings[$targetKey]);
    }

    private function dayLabel(int $days): string
    {
        return $days === 1 ? 'Tomorrow' : 'in ' . $days . ' days';
    }
}

$isCli = PHP_SAPI === 'cli';
$studentID = null;

if (!$isCli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $studentID = $_SESSION['studentID'] ?? null;
    if (!$studentID) {
        http_response_code(401);
        exit(json_encode(['error' => 'Please log in before running the notification checker.']));
    }
    $studentID = (int)$studentID;
}

try {
    $result = (new NotificationChecker($conn))->run($studentID);
    if ($isCli) {
        echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        header('Content-Type: application/json');
        echo json_encode($result);
    }
} catch (Throwable $exception) {
    error_log('PlanGenius notification checker failed: ' . $exception->getMessage());
    http_response_code(500);
    echo $isCli ? $exception->getMessage() . PHP_EOL : json_encode(['error' => 'Notification checker failed.']);
}