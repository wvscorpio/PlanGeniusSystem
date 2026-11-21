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

function getSemesterStatus($startDate, $endDate) {
    $today = new DateTime('today');
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);

    if ($today < $start) {
        return 'Upcoming';
    }
    if ($today > $end) {
        return 'Inactive';
    }
    return 'Active';
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

    // ACTION: ADD COURSE (Scoped duplicate check per semester so retakes across terms are allowed)
    if ($action === 'addCourse') {
        $semesterID = intval($input['semesterID'] ?? 0);
        $courseCode = strtoupper(trim($input['courseCode'] ?? ''));
        $courseName = trim($input['courseName'] ?? '');
        $section = trim($input['section'] ?? '');

        if (!$semesterID || $courseCode === '' || $courseName === '' || $section === '') {
            echo json_encode(['success' => false, 'error' => 'All course fields are required']);
            exit;
        }

        try {
            // Verify semester belongs to logged-in student
            $ownerStmt = $conn->prepare('SELECT 1 FROM "SemesterPlan" WHERE "semesterID" = :semesterID AND "studentID" = :studentID LIMIT 1');
            $ownerStmt->execute([':semesterID' => $semesterID, ':studentID' => $studentID]);
            if (!$ownerStmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Semester not found']);
                exit;
            }

            // Prevent duplicate course code or name within the SAME semester
            $sameStmt = $conn->prepare('
                SELECT 1 FROM "Course" 
                WHERE "semesterID" = :semesterID 
                  AND (LOWER("courseCode") = LOWER(:courseCode) OR LOWER("courseName") = LOWER(:courseName)) 
                LIMIT 1
            ');
            $sameStmt->execute([':semesterID' => $semesterID, ':courseCode' => $courseCode, ':courseName' => $courseName]);
            if ($sameStmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'This course code or name already exists in this semester.']);
                exit;
            }

            $stmt = $conn->prepare('
                INSERT INTO "Course" ("semesterID", "courseCode", "courseName", "section") 
                VALUES (:semesterID, :courseCode, :courseName, :section) 
                RETURNING "courseID"
            ');
            $stmt->execute([
                ':semesterID' => $semesterID,
                ':courseCode' => $courseCode,
                ':courseName' => $courseName,
                ':section'    => $section
            ]);

            $newCourseID = (int)$stmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'course' => [
                    'courseID'         => $newCourseID,
                    'courseCode'       => $courseCode,
                    'courseName'       => $courseName,
                    'section'          => $section,
                    'assignment_count' => 0,
                    'exam_count'       => 0
                ]
            ]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to add course: ' . $e->getMessage()]);
            exit;
        }
    }

    // ACTION: DELETE SEMESTER
    if ($action === 'deleteSemester') {
        $semesterID = intval($input['semesterID'] ?? 0);
        if (!$semesterID) {
            echo json_encode(['success' => false, 'error' => 'Semester ID is required']);
            exit;
        }

        try {
            $check = $conn->prepare('SELECT 1 FROM "SemesterPlan" WHERE "semesterID" = :semesterID AND "studentID" = :studentID');
            $check->execute([':semesterID' => $semesterID, ':studentID' => $studentID]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Semester not found']);
                exit;
            }

            $conn->beginTransaction();

            $delCourses = $conn->prepare('DELETE FROM "Course" WHERE "semesterID" = :semesterID');
            $delCourses->execute([':semesterID' => $semesterID]);

            $delSem = $conn->prepare('DELETE FROM "SemesterPlan" WHERE "semesterID" = :semesterID');
            $delSem->execute([':semesterID' => $semesterID]);

            $conn->commit();

            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            echo json_encode(['success' => false, 'error' => 'Failed to delete semester: ' . $e->getMessage()]);
            exit;
        }
    }

    // ACTION: UPDATE SEMESTER
    if ($action === 'updateSemester') {
        $semesterID = intval($input['semesterID'] ?? 0);
        $semesterName = trim($input['semesterName'] ?? '');
        $startDate = trim($input['startDate'] ?? '');
        $endDate = trim($input['endDate'] ?? '');

        if (!$semesterID || $semesterName === '' || $startDate === '' || $endDate === '') {
            echo json_encode(['success' => false, 'error' => 'All semester fields are required']);
            exit;
        }

        try {
            $semesterStmt = $conn->prepare('SELECT * FROM "SemesterPlan" WHERE "semesterID" = :semesterID AND "studentID" = :studentID');
            $semesterStmt->execute([':semesterID' => $semesterID, ':studentID' => $studentID]);
            $semester = $semesterStmt->fetch(PDO::FETCH_ASSOC);
            if (!$semester) {
                echo json_encode(['success' => false, 'error' => 'Semester not found']);
                exit;
            }

            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            if ($start > $end) {
                echo json_encode(['success' => false, 'error' => 'Start date must be before or equal to end date']);
                exit;
            }

            $checkName = $conn->prepare('SELECT 1 FROM "SemesterPlan" WHERE "studentID" = :studentID AND LOWER("semesterName") = LOWER(:semesterName) AND "semesterID" <> :semesterID LIMIT 1');
            $checkName->execute([':studentID' => $studentID, ':semesterName' => $semesterName, ':semesterID' => $semesterID]);
            if ($checkName->fetch()) {
                echo json_encode(['success' => false, 'error' => 'A semester with that name already exists']);
                exit;
            }

            $overlapStmt = $conn->prepare(
                'SELECT "semesterName" FROM "SemesterPlan" 
                 WHERE "studentID" = :studentID 
                   AND "semesterID" <> :semesterID 
                   AND NOT ("endDate" < :startDate OR "startDate" > :endDate) 
                 LIMIT 1'
            );
            $overlapStmt->execute([
                ':studentID'  => $studentID,
                ':semesterID' => $semesterID,
                ':startDate'  => $startDate,
                ':endDate'    => $endDate
            ]);
            
            $overlappingSem = $overlapStmt->fetch(PDO::FETCH_ASSOC);
            if ($overlappingSem) {
                echo json_encode([
                    'success' => false, 
                    'error'   => 'Semester dates overlap with "' . $overlappingSem['semesterName'] . '".'
                ]);
                exit;
            }

            $updateStmt = $conn->prepare(
                'UPDATE "SemesterPlan" SET "semesterName" = :semesterName, "startDate" = :startDate, "endDate" = :endDate WHERE "semesterID" = :semesterID'
            );
            $updateStmt->execute([
                ':semesterName' => $semesterName,
                ':startDate'    => $startDate,
                ':endDate'      => $endDate,
                ':semesterID'   => $semesterID
            ]);

            $status = getSemesterStatus($startDate, $endDate);

            echo json_encode([
                'success' => true,
                'semester' => [
                    'semesterID'   => $semesterID,
                    'semesterName' => $semesterName,
                    'startDate'    => $startDate,
                    'endDate'      => $endDate,
                    'status'       => $status
                ]
            ]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to update semester: ' . $e->getMessage()]);
            exit;
        }
    }

    // ACTION: UPDATE COURSE (Scoped duplicate check within same semester)
    if ($action === 'updateCourse') {
        $courseID = intval($input['courseID'] ?? 0);
        $courseCode = strtoupper(trim($input['courseCode'] ?? ''));
        $courseName = trim($input['courseName'] ?? '');
        $section = trim($input['section'] ?? '');

        if (!$courseID || $courseCode === '' || $courseName === '' || $section === '') {
            echo json_encode(['success' => false, 'error' => 'All course fields are required']);
            exit;
        }

        try {
            $courseStmt = $conn->prepare('
                SELECT c.* FROM "Course" c 
                JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" 
                WHERE c."courseID" = :courseID AND s."studentID" = :studentID
            ');
            $courseStmt->execute([':courseID' => $courseID, ':studentID' => $studentID]);
            $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
            if (!$course) {
                echo json_encode(['success' => false, 'error' => 'Course not found']);
                exit;
            }

            $dupStmt = $conn->prepare('
                SELECT 1 FROM "Course" 
                WHERE "semesterID" = :semesterID 
                  AND (LOWER("courseCode") = LOWER(:courseCode) OR LOWER("courseName") = LOWER(:courseName)) 
                  AND "courseID" <> :courseID 
                LIMIT 1
            ');
            $dupStmt->execute([
                ':semesterID' => $course['semesterID'],
                ':courseCode' => $courseCode,
                ':courseName' => $courseName,
                ':courseID'   => $courseID
            ]);
            if ($dupStmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'This course code or name already exists in this semester']);
                exit;
            }

            $updateCourse = $conn->prepare('UPDATE "Course" SET "courseCode" = :courseCode, "courseName" = :courseName, "section" = :section WHERE "courseID" = :courseID');
            $updateCourse->execute([
                ':courseCode' => $courseCode,
                ':courseName' => $courseName,
                ':section'    => $section,
                ':courseID'   => $courseID
            ]);

            echo json_encode([
                'success' => true,
                'course' => [
                    'courseID'   => $courseID,
                    'courseCode' => $courseCode,
                    'courseName' => $courseName,
                    'section'    => $section
                ]
            ]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to update course: ' . $e->getMessage()]);
            exit;
        }
    }

    // ACTION: DELETE COURSE
    if ($action === 'deleteCourse') {
        $courseID = intval($input['courseID'] ?? 0);
        if (!$courseID) {
            echo json_encode(['success' => false, 'error' => 'Course ID is required']);
            exit;
        }

        try {
            $check = $conn->prepare('
                SELECT 1 FROM "Course" c 
                JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" 
                WHERE c."courseID" = :courseID AND s."studentID" = :studentID
            ');
            $check->execute([':courseID' => $courseID, ':studentID' => $studentID]);
            
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Course not found or unauthorized']);
                exit;
            }

            $delCourse = $conn->prepare('DELETE FROM "Course" WHERE "courseID" = :courseID');
            $delCourse->execute([':courseID' => $courseID]);

            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to delete course: ' . $e->getMessage()]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// Fetch existing semesters and their courses (with linked assignment & exam counts)
$semesters = [];
try {
    $stmt = $conn->prepare('SELECT * FROM "SemesterPlan" WHERE "studentID" = :studentID ORDER BY "startDate" DESC');
    $stmt->execute([':studentID' => $studentID]);
    $semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($semesters as &$semester) {
        $courseStmt = $conn->prepare('
            SELECT c.*,
                (SELECT COUNT(*) FROM "Assignment" a WHERE a."courseID" = c."courseID") AS assignment_count,
                (SELECT COUNT(*) FROM "Exam" e WHERE e."courseID" = c."courseID") AS exam_count
            FROM "Course" c
            WHERE c."semesterID" = :semesterID
            ORDER BY c."courseCode" ASC
        ');
        $courseStmt->execute([':semesterID' => $semester['semesterID']]);
        $semester['courses'] = $courseStmt->fetchAll(PDO::FETCH_ASSOC);
        $semester['status'] = getSemesterStatus($semester['startDate'], $semester['endDate']);
    }
    unset($semester);
} catch (PDOException $e) {
    $errors[] = 'Failed to fetch semesters: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Structure - PlanGenius</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body>
    <?php 
    $current_page = 'academic_structure';
    include 'includes/sidebar.php'; 
    ?>

    <main class="main-content">
        <?php include 'includes/header.php'; ?>

        <div class="dashboard-container">
            <div class="page-header flex-between">
                <div>
                    <h1 class="page-title">Academic Structure</h1>
                    <p class="page-subtitle">Organize your semesters, enrolled courses, and section details</p>
                </div>
                <button class="btn btn-primary" onclick="openModal('addSemesterModal')">
                    <i class="ph ph-plus"></i> Add Semester Plan
                </button>
            </div>

            <!-- 3-Card Academic KPI Strip -->
            <div class="acad-kpi-grid">
                <div class="acad-kpi-card kpi-emerald">
                    <div class="acad-kpi-top">
                        <span class="acad-kpi-label">Active Semester</span>
                        <div class="acad-kpi-icon" style="background:#ecfdf5; color:#059669;">
                            <i class="ph-fill ph-calendar-check"></i>
                        </div>
                    </div>
                    <div class="acad-kpi-value" id="kpiActiveSemName">—</div>
                    <div class="acad-kpi-sub" id="kpiActiveSemSub">No active semester right now</div>
                </div>

                <div class="acad-kpi-card">
                    <div class="acad-kpi-top">
                        <span class="acad-kpi-label">Enrolled Courses</span>
                        <div class="acad-kpi-icon" style="background:#eef2ff; color:#4f46e5;">
                            <i class="ph-fill ph-books"></i>
                        </div>
                    </div>
                    <div class="acad-kpi-value" id="kpiTotalCourses">0</div>
                    <div class="acad-kpi-sub" id="kpiActiveCoursesSub">0 in active semester</div>
                </div>

                <div class="acad-kpi-card kpi-blue">
                    <div class="acad-kpi-top">
                        <span class="acad-kpi-label">Semester Plans</span>
                        <div class="acad-kpi-icon" style="background:#eff6ff; color:#2563eb;">
                            <i class="ph-fill ph-graduation-cap"></i>
                        </div>
                    </div>
                    <div class="acad-kpi-value" id="kpiTotalSemesters">0</div>
                    <div class="acad-kpi-sub" id="kpiSemBreakdown">0 active · 0 upcoming · 0 past</div>
                </div>
            </div>

            <!-- Filter Pills & Search Toolbar -->
            <div class="acad-toolbar" id="acadToolbar" style="<?= count($semesters) > 0 ? '' : 'display:none;' ?>">
                <div class="acad-filter-pills" id="acadFilterPills">
                    <button type="button" class="acad-pill active" data-status="all" onclick="setStatusFilter('all', this)">
                        All <span class="acad-pill-count" id="pill-count-all">0</span>
                    </button>
                    <button type="button" class="acad-pill" data-status="active" onclick="setStatusFilter('active', this)">
                        Active <span class="acad-pill-count" id="pill-count-active">0</span>
                    </button>
                    <button type="button" class="acad-pill" data-status="upcoming" onclick="setStatusFilter('upcoming', this)">
                        Upcoming <span class="acad-pill-count" id="pill-count-upcoming">0</span>
                    </button>
                    <button type="button" class="acad-pill" data-status="inactive" onclick="setStatusFilter('inactive', this)">
                        Past <span class="acad-pill-count" id="pill-count-inactive">0</span>
                    </button>
                </div>

                <div class="acad-search-wrap">
                    <i class="ph ph-magnifying-glass"></i>
                    <input type="text" id="acadSearchInput" placeholder="Search semester, course code, or title..." oninput="applyAcademicFilters()">
                </div>
            </div>

            <div class="empty-state-card" id="emptyState" style="<?= count($semesters) > 0 ? 'display:none' : '' ?>">
                No semesters created yet. Click <strong>"Add Semester Plan"</strong> to set up your first term!
            </div>

            <div class="empty-state-card" id="noFilterMatchState" style="display:none;">
                No semesters or courses match your current filter.
            </div>

            <div class="semesters-list" id="semestersList"></div>
        </div>
        
        <!-- Unified Delete Confirmation Modal (Semesters & Courses) -->
        <div class="modal" id="deleteConfirmModal" style="max-width: 440px;">
            <div class="modal-header">
                <h2 class="modal-title" id="deleteConfirmTitle">Confirm Delete</h2>
                <button class="close-modal-btn" onclick="closeModal('deleteConfirmModal')"><i class="ph ph-x"></i></button>
            </div>
            <div class="modal-body">
                <p id="deleteConfirmMessage" style="color:#475569; font-size:14px; line-height:1.5;">
                    Are you sure you want to delete this item?
                </p>
                <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:18px;">
                    <button type="button" class="btn btn-outline" onclick="closeModal('deleteConfirmModal')">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
                </div>
            </div>
        </div>

        <!-- Quick Guide Modal -->
        <div class="modal" id="helpGuideModal" style="max-width: 500px;">
            <div class="modal-header">
                <h2 class="modal-title"><i class="ph-fill ph-info" style="color:#4f46e5;"></i> Academic Structure Guide</h2>
                <button class="close-modal-btn" onclick="closeModal('helpGuideModal')"><i class="ph ph-x"></i></button>
            </div>
            <div class="modal-body">
                <div class="guide-list">
                    <div class="guide-item">
                        <i class="ph-fill ph-calendar-check"></i>
                        <div>
                            <strong>Automatic Semester Status</strong><br>
                            Semesters automatically switch between <em>Upcoming</em>, <em>Active</em>, and <em>Inactive</em> based on today's date. Only courses in your <strong>Active</strong> semester appear on your Dashboard, Assignments, and Exam trackers.
                        </div>
                    </div>
                    <div class="guide-item">
                        <i class="ph-fill ph-arrows-clockwise"></i>
                        <div>
                            <strong>Retaking Courses Across Terms</strong><br>
                            Duplicate course codes are blocked within the same semester, but you can add the same course code to a different semester if you are retaking it.
                        </div>
                    </div>
                    <div class="guide-item">
                        <i class="ph-fill ph-caret-circle-down"></i>
                        <div>
                            <strong>Collapsible Past Semesters</strong><br>
                            Past semesters start collapsed to keep your workspace focused. Click the chevron icon on any semester header to expand or collapse its course list.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Undo Snackbar -->
        <div class="undo-snackbar" id="undoSnackbar" style="display:none; position:fixed; bottom:24px; left:24px; z-index:2000;">
            <div class="undo-content" style="background:#111827;color:#fff;padding:12px 16px;border-radius:8px;display:flex;gap:12px;align-items:center;box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                <span id="undoMessage">Semester deleted</span>
                <button class="btn" id="undoBtn" style="background:transparent;border:1px solid rgba(255,255,255,0.25);color:#fff;padding:5px 10px;border-radius:6px;cursor:pointer;">Undo</button>
            </div>
        </div>

        <button type="button" class="help-btn" title="Academic Structure Guide" onclick="openModal('helpGuideModal')">
            <i class="ph ph-question"></i>
        </button>

        <div class="notification-toast" id="notificationToast">
            <i class="ph-fill ph-check-circle"></i> <span id="notificationMessage">Semester added successfully!</span>
        </div>
    </main>

    <div class="modal-overlay" id="modalOverlay" onclick="closeAllModals()"></div>

    <!-- Add Semester Modal -->
    <div class="modal" id="addSemesterModal">
        <div class="modal-header">
            <h2 class="modal-title">Add New Semester</h2>
            <button class="close-modal-btn" onclick="closeModal('addSemesterModal')"><i class="ph ph-x"></i></button>
        </div>
        <div class="modal-body">
            <form id="addSemesterForm" onsubmit="handleAddSemester(event)">
                <div class="form-group">
                    <label>Semester Name</label>
                    <input id="semesterName" name="semesterName" type="text" class="form-control" placeholder="e.g., Year 3 Sem 2" required>
                </div>
                <div class="form-row">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Start Date</label>
                        <input id="startDate" name="startDate" type="date" class="form-control" required>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>End Date</label>
                        <input id="endDate" name="endDate" type="date" class="form-control" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="addSemesterBtn" style="margin-top:18px;">Add Semester Plan</button>
            </form>
        </div>
    </div>

    <!-- Add Course Modal -->
    <div class="modal" id="addCourseModal">
        <div class="modal-header">
            <h2 class="modal-title" id="addCourseModalTitle">Add Course</h2>
            <button class="close-modal-btn" onclick="closeModal('addCourseModal')"><i class="ph ph-x"></i></button>
        </div>
        <div class="modal-body">
            <form id="addCourseForm" onsubmit="handleAddCourse(event)">
                <input type="hidden" id="targetSemesterId">
                <div class="form-group">
                    <label>Course Code</label>
                    <input id="courseCode" type="text" class="form-control" placeholder="e.g., BIE30803" style="text-transform:uppercase;" required>
                </div>
                <div class="form-group">
                    <label>Course Name</label>
                    <input id="courseName" type="text" class="form-control" placeholder="e.g., Software Testing" required>
                </div>
                <div class="form-group">
                    <label>Section</label>
                    <input id="courseSection" type="text" class="form-control" placeholder="e.g., 01" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="addCourseBtn">Add Course</button>
            </form>
        </div>
    </div>

    <!-- Edit Semester Modal -->
    <div class="modal" id="editSemesterModal">
        <div class="modal-header">
            <h2 class="modal-title">Edit Semester</h2>
            <button class="close-modal-btn" onclick="closeModal('editSemesterModal')"><i class="ph ph-x"></i></button>
        </div>
        <div class="modal-body">
            <form id="editSemesterForm" onsubmit="handleEditSemester(event)">
                <input type="hidden" id="editSemesterId">
                <div class="form-group">
                    <label>Semester Name</label>
                    <input id="editSemesterName" name="semesterName" type="text" class="form-control" required>
                </div>
                <div class="form-row">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Start Date</label>
                        <input id="editStartDate" name="startDate" type="date" class="form-control" required>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>End Date</label>
                        <input id="editEndDate" name="endDate" type="date" class="form-control" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="editSemesterBtn" style="margin-top:18px;">Save Changes</button>
            </form>
        </div>
    </div>

    <!-- Edit Course Modal -->
    <div class="modal" id="editCourseModal">
        <div class="modal-header">
            <h2 class="modal-title">Edit Course</h2>
            <button class="close-modal-btn" onclick="closeModal('editCourseModal')"><i class="ph ph-x"></i></button>
        </div>
        <div class="modal-body">
            <form id="editCourseForm" onsubmit="handleEditCourse(event)">
                <input type="hidden" id="editCourseId">
                <div class="form-group">
                    <label>Course Code</label>
                    <input id="editCourseCode" type="text" class="form-control" style="text-transform:uppercase;" required>
                </div>
                <div class="form-group">
                    <label>Course Name</label>
                    <input id="editCourseName" type="text" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Section</label>
                    <input id="editCourseSection" type="text" class="form-control" placeholder="e.g., 01" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="editCourseBtn">Save Changes</button>
            </form>
        </div>
    </div>

    <script>
        const existingSemesters = <?php echo json_encode($semesters); ?>;
        let activeStatusFilter = 'all';

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

        let notificationTimeout = null;

        function showNotification(message, type = 'success') {
            const toast = document.getElementById('notificationToast');
            const msgEl = document.getElementById('notificationMessage');
            const iconEl = toast ? toast.querySelector('i') : null;

            if (!toast || !msgEl) return;

            if (notificationTimeout) {
                clearTimeout(notificationTimeout);
            }

            msgEl.innerText = message;
            if (iconEl) {
                iconEl.className = type === 'error' ? 'ph-fill ph-warning-circle' : 'ph-fill ph-check-circle';
            }

            const cls = type === 'error' ? 'error' : 'success';
            toast.classList.remove('success', 'error');
            toast.classList.add(cls, 'show');

            notificationTimeout = setTimeout(() => {
                toast.classList.remove('show', 'success', 'error');
            }, 3000);
        }

        function showUndoSnackbar(message, undoCallback, timeout = 6000) {
            const wrapper = document.getElementById('undoSnackbar');
            const msg = document.getElementById('undoMessage');
            const btn = document.getElementById('undoBtn');
            if (!wrapper || !msg || !btn) return;
            msg.innerText = message;
            wrapper.style.display = 'block';

            let finished = false;
            const hide = () => {
                if (finished) return;
                finished = true;
                wrapper.style.display = 'none';
                btn.removeEventListener('click', onUndo);
            };

            const onUndo = () => {
                hide();
                if (typeof undoCallback === 'function') undoCallback();
            };

            btn.addEventListener('click', onUndo);
            setTimeout(hide, timeout);
        }

        function getStatusClass(status) {
            if (!status) return 'status-upcoming';
            const normalized = status.toLowerCase();
            return normalized === 'active'
                ? 'status-active'
                : normalized === 'inactive' || normalized === 'passed' || normalized === 'expired'
                    ? 'status-expired'
                    : 'status-upcoming';
        }

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function computeSemesterTimeline(startDateStr, endDateStr, status) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const start = new Date(startDateStr + 'T00:00:00');
            const end = new Date(endDateStr + 'T00:00:00');
            const msPerDay = 1000 * 60 * 60 * 24;

            if (status === 'Active') {
                const totalDays = Math.max(1, Math.round((end - start) / msPerDay) + 1);
                const elapsedDays = Math.max(1, Math.round((today - start) / msPerDay) + 1);
                const remainingDays = Math.max(0, Math.round((end - today) / msPerDay));
                const totalWeeks = Math.max(1, Math.ceil(totalDays / 7));
                const currentWeek = Math.min(totalWeeks, Math.max(1, Math.ceil(elapsedDays / 7)));
                const pct = Math.min(100, Math.max(4, Math.round((elapsedDays / totalDays) * 100)));

                return {
                    isActive: true,
                    pct,
                    leftLabel: `Week ${currentWeek} of ${totalWeeks}`,
                    rightLabel: remainingDays === 0 ? 'Ends today' : `${remainingDays} day${remainingDays === 1 ? '' : 's'} remaining`
                };
            }

            if (status === 'Upcoming') {
                const daysUntil = Math.max(1, Math.round((start - today) / msPerDay));
                return {
                    isActive: false,
                    subText: `Starts in ${daysUntil} day${daysUntil === 1 ? '' : 's'}`
                };
            }

            return {
                isActive: false,
                subText: 'Completed term'
            };
        }

        function buildCourseItemHtml(course, semId) {
            const courseId = 'course-' + course.courseID;
            const assignCount = parseInt(course.assignment_count || 0, 10);
            const examCount = parseInt(course.exam_count || 0, 10);

            return `
                <div class="course-item" id="${courseId}">
                    <div class="course-info" style="min-width:0;">
                        <div class="course-top-badges">
                            <span class="course-code-pill">${escapeHtml(course.courseCode)}</span>
                            <span class="course-section-pill">Section ${escapeHtml(course.section || '-')}</span>
                        </div>
                        <div class="course-name" style="font-weight:700; color:#0f172a; font-size:13.5px; line-height:1.35;">
                            ${escapeHtml(course.courseName.toUpperCase())}
                        </div>
                        <div class="course-workload-row">
                            <span class="workload-chip"><i class="ph-fill ph-file-text"></i> ${assignCount} Assignment${assignCount === 1 ? '' : 's'}</span>
                            <span class="workload-chip"><i class="ph-fill ph-exam"></i> ${examCount} Exam${examCount === 1 ? '' : 's'}</span>
                        </div>
                    </div>
                    <div class="course-card-actions">
                        <button type="button" class="btn btn-icon text-muted" title="Edit Course" onclick="openEditCourse('${courseId}', '${semId}')"><i class="ph ph-pencil"></i></button>
                        <button type="button" class="btn btn-icon text-muted" title="Delete Course" onclick="confirmDeleteCourse('${courseId}', '${semId}')"><i class="ph ph-trash"></i></button>
                    </div>
                </div>
            `;
        }

        function buildSemesterHtml(semester) {
            const semId = 'semester-' + semester.semesterID;
            const status = semester.status || 'Upcoming';
            const statusLower = status.toLowerCase();
            const statusClass = getStatusClass(status);
            const isInactive = (statusLower === 'inactive');
            const timeline = computeSemesterTimeline(semester.startDate, semester.endDate, status);

            let coursesHtml = '';
            let courseCount = 0;
            if (semester.courses && semester.courses.length > 0) {
                semester.courses.forEach(course => {
                    coursesHtml += buildCourseItemHtml(course, semId);
                });
                courseCount = semester.courses.length;
            }

            const progressHtml = timeline.isActive ? `
                <div class="semester-progress-wrap">
                    <div class="semester-progress-meta">
                        <span>${timeline.leftLabel}</span>
                        <span>${timeline.rightLabel}</span>
                    </div>
                    <div class="semester-progress-track">
                        <div class="semester-progress-fill" style="width:${timeline.pct}%;"></div>
                    </div>
                </div>
            ` : '';

            return `
                <div class="semester-card ${statusLower === 'active' ? 'is-active-sem' : ''} ${isInactive ? 'collapsed' : ''}" 
                     id="${semId}" 
                     data-status="${statusLower}">
                    <div class="semester-header">
                        <div class="semester-info">
                            <div class="semester-title-row">
                                <h3 class="semester-title">${escapeHtml(semester.semesterName)}</h3>
                                <span class="semester-collapsed-hint" id="hint-${semId}">${courseCount} course${courseCount === 1 ? '' : 's'}</span>
                            </div>
                            <p class="semester-dates">${escapeHtml(formatDate(semester.startDate))} to ${escapeHtml(formatDate(semester.endDate))}</p>
                            ${progressHtml}
                        </div>
                        <div class="semester-actions">
                            <span class="status-badge ${statusClass}">${escapeHtml(status)}</span>
                            <button type="button" class="btn btn-outline btn-icon btn-collapse-toggle" title="Expand / Collapse Courses" onclick="toggleSemesterCollapse('${semId}')">
                                <i class="ph ph-caret-down"></i>
                            </button>
                            <button type="button" class="btn btn-outline btn-icon" title="Edit Semester" onclick="openEditSemesterModal('${semId}')">
                                <i class="ph ph-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-danger btn-icon" title="Delete Semester" onclick="confirmDeleteSemester('${semId}')">
                                <i class="ph ph-trash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="courses-section">
                        <div class="courses-header">
                            <span class="courses-title" id="count-${semId}"><i class="ph ph-book-open"></i> Courses (${courseCount})</span>
                            <button type="button" class="btn btn-outline btn-sm" onclick="openAddCourse('${semId}', '${escapeHtml(semester.semesterName)}')">
                                <i class="ph ph-plus"></i> Add Course
                            </button>
                        </div>
                        <div class="courses-grid" id="list-${semId}">${coursesHtml}</div>
                        <div class="courses-empty" id="empty-${semId}" ${courseCount > 0 ? 'style="display:none"' : ''}>
                            No courses added yet
                        </div>
                    </div>
                </div>
            `;
        }

        function toggleSemesterCollapse(semId) {
            const card = document.getElementById(semId);
            if (card) card.classList.toggle('collapsed');
        }

        function formatDate(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString + 'T00:00:00');
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
        }

        function renderAllSemesters() {
            const container = document.getElementById('semestersList');
            container.innerHTML = '';

            // Sort semesters by startDate descending
            existingSemesters.sort((a, b) => (b.startDate || '').localeCompare(a.startDate || ''));

            existingSemesters.forEach(sem => {
                container.insertAdjacentHTML('beforeend', buildSemesterHtml(sem));
            });

            updateDashboardSummary();
            applyAcademicFilters();
        }

        function updateDashboardSummary() {
            const totalSem = existingSemesters.length;
            let activeCount = 0;
            let upcomingCount = 0;
            let inactiveCount = 0;
            let totalCourses = 0;
            let activeCourses = 0;
            let activeSemObj = null;

            existingSemesters.forEach(s => {
                const st = (s.status || '').toLowerCase();
                const cLen = (s.courses || []).length;
                totalCourses += cLen;

                if (st === 'active') {
                    activeCount++;
                    activeCourses += cLen;
                    activeSemObj = s;
                } else if (st === 'upcoming') {
                    upcomingCount++;
                } else {
                    inactiveCount++;
                }
            });

            document.getElementById('kpiTotalSemesters').innerText = totalSem;
            document.getElementById('kpiSemBreakdown').innerText = `${activeCount} active · ${upcomingCount} upcoming · ${inactiveCount} past`;

            document.getElementById('kpiTotalCourses').innerText = totalCourses;
            document.getElementById('kpiActiveCoursesSub').innerText = `${activeCourses} in active semester`;

            if (activeSemObj) {
                const timeline = computeSemesterTimeline(activeSemObj.startDate, activeSemObj.endDate, 'Active');
                document.getElementById('kpiActiveSemName').innerText = activeSemObj.semesterName;
                document.getElementById('kpiActiveSemSub').innerText = `${timeline.leftLabel} · ${timeline.rightLabel}`;
            } else {
                document.getElementById('kpiActiveSemName').innerText = 'None Active';
                document.getElementById('kpiActiveSemSub').innerText = upcomingCount > 0 ? 'Upcoming semester scheduled' : 'Add or activate a semester';
            }

            // Update filter pill counters
            document.getElementById('pill-count-all').innerText = totalSem;
            document.getElementById('pill-count-active').innerText = activeCount;
            document.getElementById('pill-count-upcoming').innerText = upcomingCount;
            document.getElementById('pill-count-inactive').innerText = inactiveCount;

            document.getElementById('emptyState').style.display = totalSem === 0 ? 'block' : 'none';
            document.getElementById('acadToolbar').style.display = totalSem === 0 ? 'none' : 'flex';
        }

        function setStatusFilter(status, btn) {
            activeStatusFilter = status;
            document.querySelectorAll('#acadFilterPills .acad-pill').forEach(p => p.classList.remove('active'));
            if (btn) btn.classList.add('active');
            applyAcademicFilters();
        }

        function applyAcademicFilters() {
            const term = (document.getElementById('acadSearchInput')?.value || '').trim().toLowerCase();
            const cards = document.querySelectorAll('.semester-card');
            let visibleCards = 0;

            cards.forEach(card => {
                const status = card.getAttribute('data-status');
                const text = card.innerText.toLowerCase();

                const matchesStatus = (activeStatusFilter === 'all' || status === activeStatusFilter);
                const matchesSearch = (term === '' || text.includes(term));

                if (matchesStatus && matchesSearch) {
                    card.style.display = 'block';
                    if (term !== '') {
                        card.classList.remove('collapsed');
                    }
                    visibleCards++;
                } else {
                    card.style.display = 'none';
                }
            });

            const noMatch = document.getElementById('noFilterMatchState');
            if (noMatch) {
                noMatch.style.display = (cards.length > 0 && visibleCards === 0) ? 'block' : 'none';
            }
        }

        function openEditSemesterModal(semId) {
            const semester = existingSemesters.find(s => 'semester-' + s.semesterID === semId);
            if (!semester) return;
            document.getElementById('editSemesterId').value = semId;
            document.getElementById('editSemesterName').value = semester.semesterName;
            document.getElementById('editStartDate').value = semester.startDate;
            document.getElementById('editEndDate').value = semester.endDate;
            openModal('editSemesterModal');
        }

        async function handleEditSemester(e) {
            e.preventDefault();
            const btn = document.getElementById('editSemesterBtn');
            const semId = document.getElementById('editSemesterId').value;
            const semesterID = parseInt(semId.replace('semester-', ''), 10);
            const semesterName = document.getElementById('editSemesterName').value;
            const startDate = document.getElementById('editStartDate').value;
            const endDate = document.getElementById('editEndDate').value;

            btn.innerText = 'Saving...';
            btn.disabled = true;

            try {
                const response = await fetch('academic_structure.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'updateSemester',
                        semesterID,
                        semesterName,
                        startDate,
                        endDate
                    })
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Unable to update semester');

                const semesterIndex = existingSemesters.findIndex(s => Number(s.semesterID) === semesterID);
                if (semesterIndex !== -1) {
                    const courses = existingSemesters[semesterIndex].courses || [];
                    existingSemesters[semesterIndex] = { ...existingSemesters[semesterIndex], ...data.semester, courses };
                }

                renderAllSemesters();
                closeModal('editSemesterModal');
                showNotification('Semester updated successfully!');
            } catch (err) {
                console.error(err);
                showNotification(err.message || 'Error updating semester', 'error');
            } finally {
                btn.innerText = 'Save Changes';
                btn.disabled = false;
            }
        }

        function openEditCourse(courseId, semId) {
            const semester = existingSemesters.find(s => 'semester-' + s.semesterID === semId);
            if (!semester || !semester.courses) return;
            const course = semester.courses.find(c => 'course-' + c.courseID === courseId);
            if (!course) return;
            document.getElementById('editCourseId').value = courseId;
            document.getElementById('editCourseCode').value = course.courseCode;
            document.getElementById('editCourseName').value = course.courseName;
            document.getElementById('editCourseSection').value = course.section || '';
            openModal('editCourseModal');
        }

        async function handleEditCourse(e) {
            e.preventDefault();
            const btn = document.getElementById('editCourseBtn');
            const courseId = document.getElementById('editCourseId').value;
            const courseID = parseInt(courseId.replace('course-', ''), 10);
            const courseCode = document.getElementById('editCourseCode').value;
            const courseName = document.getElementById('editCourseName').value;
            const section = document.getElementById('editCourseSection').value;

            btn.innerText = 'Saving...';
            btn.disabled = true;

            try {
                const response = await fetch('academic_structure.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'updateCourse',
                        courseID,
                        courseCode,
                        courseName,
                        section
                    })
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Unable to update course');

                existingSemesters.forEach(semester => {
                    if (!semester.courses) return;
                    const found = semester.courses.find(c => Number(c.courseID) === courseID);
                    if (found) {
                        found.courseCode = data.course.courseCode;
                        found.courseName = data.course.courseName;
                        found.section = data.course.section;
                    }
                });

                renderAllSemesters();
                closeModal('editCourseModal');
                showNotification('Course updated successfully!');
            } catch (err) {
                console.error(err);
                showNotification(err.message || 'Error updating course', 'error');
            } finally {
                btn.innerText = 'Save Changes';
                btn.disabled = false;
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            renderAllSemesters();
        });

        async function handleAddSemester(e) {
            e.preventDefault();
            
            const form = e.target;
            const btn = document.getElementById('addSemesterBtn');
            const name = document.getElementById('semesterName').value;
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;

            btn.innerText = "Saving...";
            btn.disabled = true;

            try {
                const response = await fetch('add_semester.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ semesterName: name, startDate, endDate })
                });

                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Unable to save semester');

                existingSemesters.push(data.semester);
                renderAllSemesters();
                form.reset();
                closeModal('addSemesterModal');
                showNotification('Semester added successfully!');
            } catch (err) {
                console.error(err);
                showNotification(err.message || 'Error adding semester', 'error');
            } finally {
                btn.innerText = "Add Semester Plan";
                btn.disabled = false;
            }
        }

        // Unified Pending Delete State (supports both 'semester' and 'course')
        window.pendingDeleteAction = null;
        window.lastDeletedSemester = null;

        function confirmDeleteSemester(semId) {
            const semObj = existingSemesters.find(s => 'semester-' + s.semesterID === semId);
            const courseCount = semObj && semObj.courses ? semObj.courses.length : 0;
            window.pendingDeleteAction = { type: 'semester', semId };

            document.getElementById('deleteConfirmTitle').innerText = 'Delete Semester Plan';
            document.getElementById('deleteConfirmMessage').innerText = courseCount > 0
                ? `Are you sure you want to delete "${semObj.semesterName}" and all ${courseCount} enrolled course(s)?`
                : `Are you sure you want to delete "${semObj ? semObj.semesterName : 'this semester'}"?`;
            openModal('deleteConfirmModal');
        }

        function confirmDeleteCourse(courseId, semId) {
            const semObj = existingSemesters.find(s => 'semester-' + s.semesterID === semId);
            const courseObj = semObj && semObj.courses ? semObj.courses.find(c => 'course-' + c.courseID === courseId) : null;
            const linkedTotal = courseObj ? (parseInt(courseObj.assignment_count || 0, 10) + parseInt(courseObj.exam_count || 0, 10)) : 0;

            window.pendingDeleteAction = { type: 'course', courseId, semId };

            document.getElementById('deleteConfirmTitle').innerText = 'Delete Course';
            document.getElementById('deleteConfirmMessage').innerText = linkedTotal > 0
                ? `Delete ${courseObj.courseCode} (${courseObj.courseName})? This course currently has ${linkedTotal} linked assignment(s)/exam(s).`
                : `Are you sure you want to delete ${courseObj ? courseObj.courseCode + ' (' + courseObj.courseName + ')' : 'this course'}?`;
            openModal('deleteConfirmModal');
        }

        document.getElementById('confirmDeleteBtn').addEventListener('click', async function () {
            const pending = window.pendingDeleteAction;
            closeModal('deleteConfirmModal');
            if (!pending) return;

            if (pending.type === 'semester') {
                const semIdNum = parseInt(String(pending.semId).replace('semester-', ''), 10);
                const idx = existingSemesters.findIndex(s => Number(s.semesterID) === semIdNum);
                const semObj = idx !== -1 ? JSON.parse(JSON.stringify(existingSemesters[idx])) : null;

                try {
                    const resp = await fetch('academic_structure.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'deleteSemester', semesterID: semIdNum })
                    });
                    const data = await resp.json();
                    if (!data.success) throw new Error(data.error || 'Failed to delete semester');

                    if (idx !== -1) existingSemesters.splice(idx, 1);
                    renderAllSemesters();

                    window.lastDeletedSemester = semObj;
                    showNotification('Semester deleted', 'success');

                    showUndoSnackbar('Semester deleted', async function undo() {
                        if (!window.lastDeletedSemester) return;
                        const s = window.lastDeletedSemester;
                        try {
                            const r = await fetch('add_semester.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ semesterName: s.semesterName, startDate: s.startDate, endDate: s.endDate, force: true })
                            });
                            const rd = await r.json();
                            if (!rd.success) throw new Error(rd.error || 'Failed to restore semester');

                            const restoredCourses = [];
                            for (const c of (s.courses || [])) {
                                const cr = await fetch('academic_structure.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({
                                        action: 'addCourse',
                                        semesterID: rd.semester.semesterID,
                                        courseCode: c.courseCode,
                                        courseName: c.courseName,
                                        section: c.section || ''
                                    })
                                });
                                const crd = await cr.json();
                                if (crd.success && crd.course) {
                                    restoredCourses.push(crd.course);
                                }
                            }

                            rd.semester.courses = restoredCourses;
                            existingSemesters.push(rd.semester);
                            renderAllSemesters();
                            window.lastDeletedSemester = null;
                            showNotification('Semester restored', 'success');
                        } catch (err) {
                            console.error(err);
                            showNotification(err.message || 'Failed to undo delete', 'error');
                        }
                    });
                } catch (err) {
                    console.error(err);
                    showNotification(err.message || 'Error deleting semester', 'error');
                }
            } else if (pending.type === 'course') {
                const courseIDNum = parseInt(String(pending.courseId).replace('course-', ''), 10);
                const semIdNum = parseInt(String(pending.semId).replace('semester-', ''), 10);

                try {
                    const response = await fetch('academic_structure.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'deleteCourse',
                            courseID: courseIDNum
                        })
                    });
                    const data = await response.json();
                    if (!data.success) throw new Error(data.error || 'Failed to remove course');

                    const foundSem = existingSemesters.find(s => Number(s.semesterID) === semIdNum);
                    if (foundSem && foundSem.courses) {
                        foundSem.courses = foundSem.courses.filter(c => Number(c.courseID) !== courseIDNum);
                    }

                    renderAllSemesters();
                    showNotification('Course deleted successfully');
                } catch (err) {
                    console.error(err);
                    showNotification(err.message || 'Error deleting course', 'error');
                }
            }

            window.pendingDeleteAction = null;
        });

        function openAddCourse(semId, semName) {
            document.getElementById('targetSemesterId').value = semId;
            document.getElementById('addCourseModalTitle').innerText = 'Add Course to ' + semName;
            openModal('addCourseModal');
        }

        async function handleAddCourse(e) {
            e.preventDefault();
            
            const form = e.target;
            const btn = document.getElementById('addCourseBtn');
            const code = document.getElementById('courseCode').value;
            const name = document.getElementById('courseName').value;
            const section = document.getElementById('courseSection').value;
            const semesterIdStr = document.getElementById('targetSemesterId').value;
            const semesterID = parseInt(semesterIdStr.replace('semester-', ''), 10);

            btn.innerText = "Saving...";
            btn.disabled = true;

            try {
                const response = await fetch('academic_structure.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'addCourse',
                        semesterID: semesterID,
                        courseCode: code,
                        courseName: name,
                        section: section
                    })
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Unable to save course');

                const foundSem = existingSemesters.find(s => Number(s.semesterID) === semesterID);
                if (foundSem) {
                    if (!foundSem.courses) foundSem.courses = [];
                    foundSem.courses.push(data.course);
                }

                renderAllSemesters();
                closeModal('addCourseModal');
                form.reset();
                showNotification('Course added successfully!');
            } catch (err) {
                console.error(err);
                showNotification(err.message || 'Error adding course', 'error');
            } finally {
                btn.innerText = "Add Course";
                btn.disabled = false;
            }
        }
    </script>
</body>
</html>