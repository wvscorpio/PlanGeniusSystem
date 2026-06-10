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

    // ACTION: ADD COURSE
    if ($action === 'addCourse') {
        $semesterID = intval($input['semesterID'] ?? 0);
        $courseCode = trim($input['courseCode'] ?? '');
        $courseName = trim($input['courseName'] ?? '');

        if (!$semesterID || $courseCode === '' || $courseName === '') {
            echo json_encode(['success' => false, 'error' => 'All course fields are required']);
            exit;
        }

        try {
            $sameStmt = $conn->prepare('SELECT 1 FROM "Course" WHERE "semesterID" = :semesterID AND "courseCode" = :courseCode LIMIT 1');
            $sameStmt->execute([':semesterID' => $semesterID, ':courseCode' => $courseCode]);
            if ($sameStmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'This course already exists in this semester.']);
                exit;
            }

            $dupStmt = $conn->prepare(
                'SELECT 1 FROM "Course" c JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" WHERE s."studentID" = :studentID AND c."courseCode" = :courseCode LIMIT 1'
            );
            $dupStmt->execute([':studentID' => $studentID, ':courseCode' => $courseCode]);
            if ($dupStmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'This course already taken. Duplicate courses are not allowed across semesters.']);
                exit;
            }

            $stmt = $conn->prepare('INSERT INTO "Course" ("semesterID", "courseCode", "courseName") VALUES (:semesterID, :courseCode, :courseName)');
            $stmt->execute([':semesterID' => $semesterID, ':courseCode' => $courseCode, ':courseName' => $courseName]);

            echo json_encode([
                'success' => true,
                'course' => [
                    'courseID' => $conn->lastInsertId(),
                    'courseCode' => $courseCode,
                    'courseName' => $courseName
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

            $delCourses = $conn->prepare('DELETE FROM "Course" WHERE "semesterID" = :semesterID');
            $delCourses->execute([':semesterID' => $semesterID]);

            $delSem = $conn->prepare('DELETE FROM "SemesterPlan" WHERE "semesterID" = :semesterID');
            $delSem->execute([':semesterID' => $semesterID]);

            echo json_encode(['success' => true]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to delete semester: ' . $e->getMessage()]);
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

// Fetch existing semesters and their courses for page layout
$semesters = [];
try {
    $stmt = $conn->prepare('SELECT * FROM "SemesterPlan" WHERE "studentID" = :studentID ORDER BY "startDate" DESC');
    $stmt->execute([':studentID' => $studentID]);
    $semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($semesters as &$semester) {
        $courseStmt = $conn->prepare('SELECT * FROM "Course" WHERE "semesterID" = :semesterID');
        $courseStmt->execute([':semesterID' => $semester['semesterID']]);
        $semester['courses'] = $courseStmt->fetchAll(PDO::FETCH_ASSOC);
        $semester['status'] = getSemesterStatus($semester['startDate'], $semester['endDate']);
    }
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
                    <p class="page-subtitle">Manage your study plans, semesters, and courses</p>
                </div>
                <button class="btn btn-primary" onclick="openModal('addSemesterModal')">
                    <i class="ph ph-plus"></i> Add Semester
                </button>
            </div>

            <div class="empty-state-card" id="emptyState" style="<?= count($semesters) > 0 ? 'display:none' : '' ?>">
                No semesters created yet. Click "Add Semester" to get started!
            </div>

            <div class="semesters-list" id="semestersList">
                </div>
        </div>
        
        <div class="modal" id="deleteConfirmModal">
            <div class="modal-header">
                <h2 class="modal-title">Confirm Delete</h2>
                <button class="close-modal-btn" onclick="closeModal('deleteConfirmModal')"><i class="ph ph-x"></i></button>
            </div>
            <div class="modal-body">
                <p id="deleteConfirmMessage">Are you sure you want to delete this semester and all its courses?</p>
                <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">
                    <button class="btn btn-outline" onclick="closeModal('deleteConfirmModal')">Cancel</button>
                    <button class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
                </div>
            </div>
        </div>

        <div class="undo-snackbar" id="undoSnackbar" style="display:none; position:fixed; bottom:24px; left:24px; z-index:2000;">
            <div class="undo-content" style="background:#111827;color:#fff;padding:12px 16px;border-radius:8px;display:flex;gap:12px;align-items:center;">
                <span id="undoMessage">Semester deleted</span>
                <button class="btn" id="undoBtn" style="background:transparent;border:1px solid rgba(255,255,255,0.2);color:#fff;padding:6px 10px;border-radius:6px;">Undo</button>
            </div>
        </div>

        <button class="help-btn"><i class="ph ph-question"></i></button>

        <div class="notification-toast" id="notificationToast">
            <i class="ph-fill ph-check-circle"></i> <span id="notificationMessage">Semester added successfully!</span>
        </div>
    </main>

    <div class="modal-overlay" id="modalOverlay" onclick="closeAllModals()"></div>

    <div class="modal" id="addSemesterModal">
        <div class="modal-header">
            <h2 class="modal-title">Add New Semester</h2>
            <button class="close-modal-btn" onclick="closeModal('addSemesterModal')"><i class="ph ph-x"></i></button>
        </div>
        <div class="modal-body">
            <form id="addSemesterForm" onsubmit="handleAddSemester(event)">
                <div class="form-group">
                    <label>Semester Name</label>
                    <input id="semesterName" name="semesterName" type="text" class="form-control" placeholder="e.g., Year 1 2025/2026" required>
                </div>
                <div class="form-group">
                    <label>Start Date</label>
                    <div class="date-input-wrapper">
                        <input id="startDate" name="startDate" type="date" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <div class="date-input-wrapper">
                        <input id="endDate" name="endDate" type="date" class="form-control" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="addSemesterBtn">Add Semester</button>
            </form>
        </div>
    </div>

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
                    <input id="courseCode" type="text" class="form-control" placeholder="e.g., BIE20303" required>
                </div>
                <div class="form-group">
                    <label>Course Name</label>
                    <input id="courseName" type="text" class="form-control" placeholder="e.g., Software Design" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="addCourseBtn">Add Course</button>
            </form>
        </div>
    </div>

    <script>
        const existingSemesters = <?php echo json_encode($semesters); ?>;

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
            document.getElementById('notificationMessage').innerText = message;
            const cls = type === 'error' ? 'error' : 'success';
            toast.classList.remove('success', 'error');
            toast.classList.add(cls, 'show');
            setTimeout(() => {
                toast.classList.remove('show');
                toast.classList.remove(cls);
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

        function buildSemesterHtml(semester) {
            const semId = 'semester-' + semester.semesterID;
            const status = semester.status || 'Upcoming';
            const statusClass = getStatusClass(status);
            
            let coursesHtml = '';
            let courseCount = 0;
            if (semester.courses && semester.courses.length > 0) {
                semester.courses.forEach(course => {
                    coursesHtml += `
                        <div class="course-item" id="course-${course.courseID}">
                            <div class="course-info">
                                <div class="course-code">${escapeHtml(course.courseCode)}</div>
                                <div class="course-name">${escapeHtml(course.courseName.toUpperCase())}</div>
                            </div>
                            <button class="btn btn-icon text-muted" onclick="deleteCourse('course-${course.courseID}', '${semId}')"><i class="ph ph-trash"></i></button>
                        </div>
                    `;
                });
                courseCount = semester.courses.length;
            }

            return `
                <div class="semester-card" id="${semId}">
                    <div class="semester-header">
                        <div class="semester-info">
                            <h3 class="semester-title">${escapeHtml(semester.semesterName)}</h3>
                            <p class="semester-dates">${escapeHtml(semester.startDate)} to ${escapeHtml(semester.endDate)}</p>
                        </div>
                        <div class="semester-actions">
                            <span class="status-badge ${statusClass}">${escapeHtml(status)}</span>
                            <button class="btn btn-danger btn-icon" onclick="deleteSemester('${semId}')"><i class="ph ph-trash"></i></button>
                        </div>
                    </div>
                    <div class="courses-section">
                        <div class="courses-header">
                            <span class="courses-title" id="count-${semId}"><i class="ph ph-book-open"></i> Courses (${courseCount})</span>
                            <button class="btn btn-outline btn-sm" onclick="openAddCourse('${semId}', '${escapeHtml(semester.semesterName)}')">
                                <i class="ph ph-plus"></i> Add Course
                            </button>
                        </div>
                        <div class="courses-list" id="list-${semId}">${coursesHtml}</div>
                        <div class="courses-empty" id="empty-${semId}" ${courseCount > 0 ? 'style="display:none"' : ''}>
                            No courses added yet
                        </div>
                    </div>
                </div>
            `;
        }

        function renderSemester(semester) {
            const html = buildSemesterHtml(semester);
            document.getElementById('semestersList').insertAdjacentHTML('beforeend', html);
            document.getElementById('emptyState').style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (existingSemesters && existingSemesters.length) {
                existingSemesters.forEach(renderSemester);
            }
        });

        // FIXED: Now accurately maps to consolidated handling logic inside this file
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
                    body: JSON.stringify({
                        semesterName: name,
                        startDate,
                        endDate
                    })
                });

                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Unable to save semester');

                existingSemesters.push(data.semester);
                renderSemester(data.semester);
                form.reset();
                closeModal('addSemesterModal');
                showNotification('Semester added successfully!');
            } catch (err) {
                console.error(err);
                showNotification(err.message || 'Error adding semester', 'error');
            } finally {
                btn.innerText = "Add Semester";
                btn.disabled = false;
            }
        }

        window.pendingDelete = null;
        window.lastDeleted = null;

        function deleteSemester(semId) {
            window.pendingDelete = semId;
            document.getElementById('deleteConfirmMessage').innerText = 'Delete this semester and all its courses?';
            openModal('deleteConfirmModal');
        }

        document.getElementById('confirmDeleteBtn').addEventListener('click', async function () {
            const semId = window.pendingDelete;
            closeModal('deleteConfirmModal');
            if (!semId) return;
            const semIdNum = parseInt(String(semId).replace('semester-', ''), 10);

            const idx = existingSemesters.findIndex(s => String(s.semesterID) === String(semIdNum));
            const semObj = idx !== -1 ? JSON.parse(JSON.stringify(existingSemesters[idx])) : null;

            try {
                const resp = await fetch('academic_structure.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'deleteSemester', semesterID: semIdNum })
                });
                const data = await resp.json();
                if (!data.success) throw new Error(data.error || 'Failed to delete');

                const el = document.getElementById(semId);
                if (el) el.remove();

                if (idx !== -1) existingSemesters.splice(idx, 1);

                if (document.getElementById('semestersList').children.length === 0) {
                    document.getElementById('emptyState').style.display = 'block';
                }

                window.lastDeleted = semObj;

                showNotification('Semester deleted', 'success');
                showUndoSnackbar('Semester deleted', async function undo() {
                    if (!window.lastDeleted) return;
                    const s = window.lastDeleted;
                    try {
                        const r = await fetch('add_semester.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ semesterName: s.semesterName, startDate: s.startDate, endDate: s.endDate, force: true })
                        });
                        const rd = await r.json();
                        if (!rd.success) throw new Error(rd.error || 'Failed to restore semester');

                        for (const c of (s.courses || [])) {
                            await fetch('academic_structure.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'addCourse', semesterID: rd.semester.semesterID, courseCode: c.courseCode, courseName: c.courseName })
                            });
                        }

                        rd.semester.courses = s.courses || [];
                        existingSemesters.push(rd.semester);
                        renderSemester(rd.semester);
                        window.lastDeleted = null;
                        showNotification('Semester restored', 'success');
                    } catch (err) {
                        console.error(err);
                        showNotification(err.message || 'Failed to undo delete', 'error');
                    }
                });
            } catch (err) {
                console.error(err);
                showNotification(err.message || 'Error deleting semester', 'error');
            } finally {
                window.pendingDelete = null;
            }
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
                        courseName: name
                    })
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.error || 'Unable to save course');

                const semId = 'semester-' + semesterID;
                document.getElementById('empty-' + semId).style.display = 'none';

                const coursesList = document.getElementById('list-' + semId);
                const courseId = 'course-' + data.course.courseID;
                const courseHtml = `
                    <div class="course-item" id="${courseId}">
                        <div class="course-info">
                            <div class="course-code">${escapeHtml(data.course.courseCode)}</div>
                            <div class="course-name">${escapeHtml(data.course.courseName.toUpperCase())}</div>
                        </div>
                        <button class="btn btn-icon text-muted" onclick="deleteCourse('${courseId}', '${semId}')"><i class="ph ph-trash"></i></button>
                    </div>
                `;
                coursesList.insertAdjacentHTML('beforeend', courseHtml);

                const countEl = document.getElementById('count-' + semId);
                const numCourses = coursesList.children.length;
                countEl.innerHTML = `<i class="ph ph-book-open"></i> Courses (${numCourses})`;

                const foundSem = existingSemesters.find(s => String(s.semesterID) === String(semesterID));
                if (foundSem) {
                    if (!foundSem.courses) foundSem.courses = [];
                    foundSem.courses.push(data.course);
                }

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

        // FIXED: Now communicates changes accurately with the database
        async function deleteCourse(courseId, semId) {
            const courseIDNum = parseInt(courseId.replace('course-', ''), 10);
            const semIdNum = parseInt(semId.replace('semester-', ''), 10);

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
                if (!data.success) throw new Error(data.error || 'Failed to remove course from database');

                // Update UI layout on success response
                document.getElementById(courseId).remove();
                const coursesList = document.getElementById('list-' + semId);
                const numCourses = coursesList.children.length;
                
                document.getElementById('count-' + semId).innerHTML = `<i class="ph ph-book-open"></i> Courses (${numCourses})`;
                if (numCourses === 0) {
                    document.getElementById('empty-' + semId).style.display = 'block';
                }

                const foundSem = existingSemesters.find(s => String(s.semesterID) === String(semIdNum));
                if (foundSem && foundSem.courses) {
                    foundSem.courses = foundSem.courses.filter(c => String(c.courseID) !== String(courseIDNum));
                }

                showNotification('Course deleted successfully');
            } catch (err) {
                console.error(err);
                showNotification(err.message || 'Error deleting course', 'error');
            }
        }
    </script>
</body>
</html>