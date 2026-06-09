<?php
include 'dbConn.php';
session_start();
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

            <!-- Empty State -->
            <div class="empty-state-card" id="emptyState">
                No semesters created yet. Click "Add Semester" to get started!
            </div>

            <!-- Semesters List -->
            <div class="semesters-list" id="semestersList">
                <!-- Dynamically populated -->
            </div>
        </div>
        
        <button class="help-btn"><i class="ph ph-question"></i></button>

        <!-- Notification Toast -->
        <div class="notification-toast" id="notificationToast">
            <i class="ph-fill ph-check-circle"></i> <span id="notificationMessage">Semester added successfully!</span>
        </div>
    </main>

    <!-- Modal Overlay -->
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
                    <input type="text" class="form-control" placeholder="e.g., Fall 2026" required>
                </div>
                <div class="form-group">
                    <label>Start Date</label>
                    <div class="date-input-wrapper">
                        <input type="date" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <div class="date-input-wrapper">
                        <input type="date" class="form-control" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="addSemesterBtn">Add Semester</button>
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
                    <input type="text" class="form-control" placeholder="e.g., CS101" required>
                </div>
                <div class="form-group">
                    <label>Course Name</label>
                    <input type="text" class="form-control" placeholder="e.g., Introduction to Programming" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Add Course</button>
            </form>
        </div>
    </div>

    <script>
        let semesterCounter = 0;

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

        function showNotification(message) {
            const toast = document.getElementById('notificationToast');
            document.getElementById('notificationMessage').innerText = message;
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        async function handleAddSemester(e) {
            e.preventDefault();
            
            const form = e.target;
            const btn = document.getElementById('addSemesterBtn');
            const name = form.querySelector('input[placeholder="e.g., Fall 2026"]').value;
            const dates = form.querySelectorAll('input[type="date"]');
            const startDate = dates[0].value;
            const endDate = dates[1].value;

            btn.innerText = "Checking...";
            btn.disabled = true;

            try {
                // Call PHP API to invoke Python script for status check
                const response = await fetch('api_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ start_date: startDate, end_date: endDate })
                });
                const data = await response.json();
                const status = data.status || "Unknown";
                
                const statusClass = status.toLowerCase() === 'active' ? 'status-active' : (status.toLowerCase() === 'expired' ? 'status-expired' : 'status-upcoming');

                semesterCounter++;
                const semId = 'semester-' + semesterCounter;

                // Create semester HTML
                const html = `
                <div class="semester-card" id="${semId}">
                    <div class="semester-header">
                        <div class="semester-info">
                            <h3 class="semester-title">${name}</h3>
                            <p class="semester-dates">${startDate} to ${endDate}</p>
                        </div>
                        <div class="semester-actions">
                            <span class="status-badge ${statusClass}">${status}</span>
                            <button class="btn btn-danger btn-icon" onclick="deleteSemester('${semId}')"><i class="ph ph-trash"></i></button>
                        </div>
                    </div>
                    
                    <div class="courses-section">
                        <div class="courses-header">
                            <span class="courses-title" id="count-${semId}"><i class="ph ph-book-open"></i> Courses (0)</span>
                            <button class="btn btn-outline btn-sm" onclick="openAddCourse('${semId}', '${name}')">
                                <i class="ph ph-plus"></i> Add Course
                            </button>
                        </div>
                        <div class="courses-list" id="list-${semId}"></div>
                        <div class="courses-empty" id="empty-${semId}">
                            No courses added yet
                        </div>
                    </div>
                </div>
                `;

                document.getElementById('semestersList').insertAdjacentHTML('beforeend', html);
                document.getElementById('emptyState').style.display = 'none';

                closeModal('addSemesterModal');
                form.reset();
                showNotification('Semester added successfully!');

            } catch (err) {
                console.error(err);
                alert('Error checking status');
            } finally {
                btn.innerText = "Add Semester";
                btn.disabled = false;
            }
        }

        function deleteSemester(semId) {
            document.getElementById(semId).remove();
            
            // Check if empty
            if (document.getElementById('semestersList').children.length === 0) {
                document.getElementById('emptyState').style.display = 'block';
            }
            
            showNotification('Semester deleted');
        }

        function openAddCourse(semId, semName) {
            document.getElementById('targetSemesterId').value = semId;
            document.getElementById('addCourseModalTitle').innerText = 'Add Course to ' + semName;
            openModal('addCourseModal');
        }

        function handleAddCourse(e) {
            e.preventDefault();
            
            const form = e.target;
            const code = form.querySelector('input[placeholder="e.g., CS101"]').value;
            const name = form.querySelector('input[placeholder="e.g., Introduction to Programming"]').value;
            const semId = document.getElementById('targetSemesterId').value;

            // Hide empty state for this semester
            document.getElementById('empty-' + semId).style.display = 'none';

            // Add course to list
            const coursesList = document.getElementById('list-' + semId);
            const courseId = 'course-' + Date.now();
            const courseHtml = `
                <div class="course-item" id="${courseId}">
                    <div class="course-info">
                        <div class="course-code">${code}</div>
                        <div class="course-name">${name.toUpperCase()}</div>
                    </div>
                    <button class="btn btn-icon text-muted" onclick="deleteCourse('${courseId}', '${semId}')"><i class="ph ph-trash"></i></button>
                </div>
            `;
            coursesList.insertAdjacentHTML('beforeend', courseHtml);

            // Update count
            const countEl = document.getElementById('count-' + semId);
            const numCourses = coursesList.children.length;
            countEl.innerHTML = `<i class="ph ph-book-open"></i> Courses (${numCourses})`;

            closeModal('addCourseModal');
            form.reset();
            showNotification('Course added successfully!');
        }

        function deleteCourse(courseId, semId) {
            document.getElementById(courseId).remove();
            
            const coursesList = document.getElementById('list-' + semId);
            const numCourses = coursesList.children.length;
            
            document.getElementById('count-' + semId).innerHTML = `<i class="ph ph-book-open"></i> Courses (${numCourses})`;
            
            if (numCourses === 0) {
                document.getElementById('empty-' + semId).style.display = 'block';
            }
            
            showNotification('Course deleted');
        }
    </script>
</body>
</html>
