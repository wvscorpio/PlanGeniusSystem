<?php
include 'dbConn.php';
session_start();
header('Content-Type: application/json');

$studentID = $_SESSION['studentID'] ?? null;
if (!$studentID) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$semesterName = trim($input['semesterName'] ?? '');
$startDate = trim($input['startDate'] ?? '');
$endDate = trim($input['endDate'] ?? '');
$force = !empty($input['force']);

if ($semesterName === '') {
    echo json_encode(['success' => false, 'error' => 'Semester name is required']);
    exit;
}
if ($startDate === '') {
    echo json_encode(['success' => false, 'error' => 'Start date is required']);
    exit;
}
if ($endDate === '') {
    echo json_encode(['success' => false, 'error' => 'End date is required']);
    exit;
}

$today = new DateTime('today');
$start = new DateTime($startDate);
$end = new DateTime($endDate);

if ($start > $end) {
    echo json_encode(['success' => false, 'error' => 'Start date must be before or equal to end date']);
    exit;
}

if (!$force) {
    if ($start < $today) {
        echo json_encode(['success' => false, 'error' => 'Semester start date must be today or a future date']);
        exit;
    }

    if ($end < $today) {
        echo json_encode(['success' => false, 'error' => 'Semester end date must be today or a future date']);
        exit;
    }
}

/*try {
    $latestEndStmt = $conn->prepare('SELECT MAX("endDate") AS latestEndDate FROM "SemesterPlan" WHERE "studentID" = :studentID');
    $latestEndStmt->execute([':studentID' => $studentID]);
    $latestEndResult = $latestEndStmt->fetch(PDO::FETCH_ASSOC);
    $latestEndDate = $latestEndResult['latestEndDate'] ?? null;

    if (!$force && $latestEndDate && $startDate <= $latestEndDate) {
        echo json_encode(['success' => false, 'error' => 'Semester dates overlap an existing semester. New semester must start after the latest existing semester end date.']);
        exit;
    }*/

        try {
    // REPLACE lines 44-53 with this overlap validation check:
    if (!$force) {
        $overlapStmt = $conn->prepare(
            'SELECT "semesterName" FROM "SemesterPlan" 
             WHERE "studentID" = :studentID 
             AND NOT ("endDate" < :startDate OR "startDate" > :endDate) 
             LIMIT 1'
        );
        $overlapStmt->execute([
            ':studentID' => $studentID,
            ':startDate' => $startDate,
            ':endDate' => $endDate
        ]);
        
        $overlappingSem = $overlapStmt->fetch(PDO::FETCH_ASSOC);
        if ($overlappingSem) {
            echo json_encode([
                'success' => false, 
                'error' => 'Semester dates overlap with "' . $overlappingSem['semesterName'] . '". Active semesters cannot overlap.'
            ]);
            exit;
        }
    }
    
    $check = $conn->prepare('SELECT * FROM "SemesterPlan" WHERE "studentID" = :studentID AND "semesterName" = :semesterName');
    $check->execute([':studentID' => $studentID, ':semesterName' => $semesterName]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo json_encode(['success' => false, 'error' => 'This semester already exists.']);
        exit;
    }

    $stmt = $conn->prepare(
        'INSERT INTO "SemesterPlan" ("studentID", "semesterName", "startDate", "endDate") VALUES (:studentID, :semesterName, :startDate, :endDate)'
    );
    $stmt->execute([
        ':studentID' => $studentID,
        ':semesterName' => $semesterName,
        ':startDate' => $startDate,
        ':endDate' => $endDate
    ]);

    $semesterID = $conn->lastInsertId();
    $status = 'Upcoming';
    $today = new DateTime('today');
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    if ($today < $start) {
        $status = 'Upcoming';
    } elseif ($today > $end) {
        $status = 'Inactive';
    } else {
        $status = 'Active';
    }

    echo json_encode([
        'success' => true,
        'semester' => [
            'semesterID' => $semesterID,
            'semesterName' => $semesterName,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'status' => $status,
            'courses' => []
        ]
    ]);
    exit;
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to add semester: ' . $e->getMessage()]);
    exit;
}
