<?php
include 'dbConn.php';
require_once 'schedule_Manager.php';
session_start();

$studentID = $_SESSION['studentID'] ?? null;

if (!$studentID) {
    header('Location: login.php');
    exit;
}

$events = [];
$activeSemStart = null;
$activeSemEnd = null;

// Fetch Active Semester Plan
try {
    $semStmt = $conn->prepare('
        SELECT "startDate", "endDate"
        FROM "SemesterPlan"
        WHERE "studentID" = :studentID
          AND CURRENT_DATE >= "startDate" AND CURRENT_DATE <= "endDate"
        LIMIT 1
    ');
    $semStmt->execute([':studentID' => $studentID]);
    $activeSem = $semStmt->fetch(PDO::FETCH_ASSOC);
    if ($activeSem) {
        $activeSemStart = $activeSem['startDate'];
        $activeSemEnd = $activeSem['endDate'];
    }
} catch (PDOException $e) {}

// 1. Fetch Assignments (Within Active Semester Range)
try {
    $stmt = $conn->prepare('
        SELECT a."assignmentID", a."assignmentTitle", a."dueDate", a."taskStatus", 
               a."priorityLevel", a."assignmentType", c."courseCode", c."courseName"
        FROM "Assignment" a
        JOIN "Course" c ON a."courseID" = c."courseID"
        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE s."studentID" = :studentID
          AND CURRENT_DATE >= s."startDate" AND CURRENT_DATE <= s."endDate"
          AND a."dueDate" >= s."startDate" AND a."dueDate" <= s."endDate"
    ');
    $stmt->execute([':studentID' => $studentID]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    foreach ($assignments as $a) {
        $events[] = [
            'id' => $a['assignmentID'],
            'type' => 'assignment',
            'title' => $a['assignmentTitle'],
            'courseCode' => $a['courseCode'],
            'courseName' => $a['courseName'],
            'date' => $a['dueDate'],
            'status' => $a['taskStatus'],
            'priority' => $a['priorityLevel'],
            'assignmentType' => $a['assignmentType'],
            'subTasks' => $subtasksMap[$a['assignmentID']] ?? []
        ];
    }
} catch (PDOException $e) {}

// 2. Fetch Exams (Within Active Semester Range)
try {
    $stmt = $conn->prepare('
        SELECT e."examID", e."examType", e."examTitle", e."examDate", e."examTime", e."examLocation", e."examStatus", e."difficultyLevel", c."courseCode", c."courseName"
        FROM "Exam" e
        JOIN "Course" c ON e."courseID" = c."courseID"
        JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID"
        WHERE s."studentID" = :studentID
          AND CURRENT_DATE >= s."startDate" AND CURRENT_DATE <= s."endDate"
          AND e."examDate" >= s."startDate" AND e."examDate" <= s."endDate"
    ');
    $stmt->execute([':studentID' => $studentID]);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($exams as $e) {
        $examType = $e['examType'] ?? 'Exam';
        $examTitle = $e['examTitle'] ?? '';
        
        if (!empty($examTitle)) {
            $fullTitle = (stripos($examTitle, $examType) !== false) ? $examTitle : ($examType . ': ' . $examTitle);
        } else {
            $fullTitle = $examType;
        }

        $events[] = [
            'id' => $e['examID'],
            'type' => 'exam',
            'specificType' => TaskTypeHelper::normalizeType($examType),
            'title' => $fullTitle,
            'courseCode' => $e['courseCode'],
            'courseName' => $e['courseName'],
            'date' => $e['examDate'],
            'time' => $e['examTime'],
            'location' => $e['examLocation'],
            'status' => $e['examStatus'],
            'difficulty' => $e['difficultyLevel'],
            'priority' => $e['difficultyLevel']
        ];
    }
} catch (PDOException $e) {}

// 3. Fetch Schedule / Study Sessions (Strictly Within Active Semester)
try {
    $schedStmt = $conn->prepare('
        SELECT 
            s."scheduleID", 
            s."courseID" AS "schedCourseID",
            s."studyDate", 
            s."studyTime",
            s."priorityLevel",
            s."referenceID", 
            s."referenceType", 
            s."status",
            COALESCE(c_sched."courseCode", c_asg."courseCode", c_exm."courseCode", \'GEN\') AS "courseCode",
            COALESCE(c_sched."courseName", c_asg."courseName", c_exm."courseName") AS "courseName",
            a."assignmentTitle",
            a."assignmentType", 
            e."examType",
            e."examTitle"
        FROM "Schedule" s
        LEFT JOIN "Course" c_sched ON s."courseID" = c_sched."courseID"
        LEFT JOIN "Assignment" a ON LOWER(TRIM(s."referenceType")) = \'assignment\' AND CAST(s."referenceID" AS INTEGER) = a."assignmentID"
        LEFT JOIN "Course" c_asg ON a."courseID" = c_asg."courseID"
        LEFT JOIN "Exam" e ON LOWER(TRIM(s."referenceType")) = \'exam\' AND CAST(s."referenceID" AS INTEGER) = e."examID"
        LEFT JOIN "Course" c_exm ON e."courseID" = c_exm."courseID"
        JOIN "SemesterPlan" sp ON sp."semesterID" = COALESCE(c_sched."semesterID", c_asg."semesterID", c_exm."semesterID")
        WHERE s."studentID" = :studentID 
          AND CURRENT_DATE >= sp."startDate" AND CURRENT_DATE <= sp."endDate"
          AND s."studyDate" >= sp."startDate" AND s."studyDate" <= sp."endDate"
        ORDER BY s."studyDate" ASC, s."studyTime" ASC
    ');

    $schedStmt->execute([':studentID' => $studentID]);
    $srows = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($srows as $s) {
        $isAssignment = (strtolower($s['referenceType']) === 'assignment');
        
        if ($isAssignment) {
            $taskTitle = $s['assignmentTitle'] ?? 'Assignment Work';
            $rawType = $s['assignmentType'] ?? 'Assignment';
        } else {
            $examType = $s['examType'] ?? 'Exam';
            $examTitle = $s['examTitle'] ?? '';
            
            if (!empty($examTitle)) {
                $taskTitle = (stripos($examTitle, $examType) !== false) ? $examTitle : ($examType . ': ' . $examTitle);
            } else {
                $taskTitle = $examType;
            }
            $rawType = $examType;
        }

        $activityLabel = TaskTypeHelper::getActivityLabel($rawType);

        $events[] = [
            'id' => 'sched-' . ($s['scheduleID'] ?? uniqid()),
            'type' => 'study_session',
            'referenceType' => $s['referenceType'],
            'title' => $taskTitle,
            'activityLabel' => $activityLabel,
            'courseCode' => $s['courseCode'],
            'courseName' => $s['courseName'],
            'date' => $s['studyDate'],
            'time' => $s['studyTime'],
            'priority' => $s['priorityLevel'],
            'status' => $s['status']
        ];
    }
} catch (Exception $e) {}

$eventsJson = json_encode($events);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Study Schedule - PlanGenius</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body>
    <?php 
    $current_page = 'schedule';
    include 'includes/sidebar.php'; 
    ?>

    <main class="main-content">
        <?php include 'includes/header.php'; ?>

        <div class="dashboard-container">
            <div class="page-header">
                <h1 class="page-title">Study Schedule</h1>
                <p class="page-subtitle">Your academic calendar generated directly from assignments and exams</p>
            </div>

            <!-- Calendar Section -->
            <div class="calendar-container">
                <div class="calendar-header" style="flex-direction: column; align-items: flex-start; gap: 10px;">
                    <div style="display: flex; justify-content: space-between; width: 100%; align-items: center;">
                        <h2 id="calendarMonthYear">Month Year</h2>
                        <div class="calendar-nav">
                            <button onclick="changeMonth(-1)"><i class="ph ph-caret-left"></i> Prev</button>
                            <button onclick="changeMonth(1)">Next <i class="ph ph-caret-right"></i></button>
                        </div>
                    </div>

                    <div class="calendar-legend">
                        <div class="legend-item"><span class="event-badge" style="background:#ef4444;">DUE</span> Assignment Deadline</div>
                        <div class="legend-item"><span class="event-badge" style="background:#f97316;">EXM</span> Exam / Quiz / Test</div>
                        <div class="legend-item"><span class="event-badge" style="background:#6366f1;">ASG</span> Assignment Work</div>
                        <div class="legend-item"><span class="event-badge" style="background:#a855f7;">STD</span> Study Session</div>
                    </div>
                </div>
                
                <div class="calendar-grid" id="calendarGrid"></div>
            </div>

            <!-- Upcoming Items Section -->
            <h3 style="font-size: 18px; font-weight: 700; color: #1e293b; margin-bottom: 15px;">All Scheduled Items</h3>
            <div id="upcomingList" class="upcoming-list"></div>

        </div>
    </main>

    <div class="modal-overlay" id="modalOverlay" onclick="closeAllModals()"></div>

    <!-- Details Modal -->
    <div class="modal" id="eventDetailsModal" style="max-width: 500px;">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle">Event Details</h2>
            <button class="close-modal-btn" onclick="closeModal('eventDetailsModal')"><i class="ph ph-x"></i></button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>

    <div class="modal" id="dayViewModal" style="max-width: 400px;">
        <div class="modal-header">
            <h2 class="modal-title" id="dayViewTitle">Events</h2>
            <button class="close-modal-btn" onclick="closeModal('dayViewModal')"><i class="ph ph-x"></i></button>
        </div>
        <div class="modal-body" id="dayViewBody" style="display: flex; flex-direction: column; gap: 8px;"></div>
    </div>

    <script>
        const eventsData = <?php echo $eventsJson; ?>;
        const semStart = "<?php echo $activeSemStart ?? ''; ?>";
        const semEnd = "<?php echo $activeSemEnd ?? ''; ?>";

        let currentDate = new Date();
        let displayMonth = currentDate.getMonth();
        let displayYear = currentDate.getFullYear();

        // Lock navigation bounds if active semester is present
        let minYear = null, minMonth = null, maxYear = null, maxMonth = null;
        if (semStart && semEnd) {
            const startDateObj = new Date(semStart);
            const endDateObj = new Date(semEnd);
            minYear = startDateObj.getFullYear();
            minMonth = startDateObj.getMonth();
            maxYear = endDateObj.getFullYear();
            maxMonth = endDateObj.getMonth();
        }

        const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

        document.addEventListener('DOMContentLoaded', () => {
            renderCalendar();
            renderUpcoming();
        });

        function changeMonth(offset) {
            let newMonth = displayMonth + offset;
            let newYear = displayYear;

            if (newMonth < 0) {
                newMonth = 11;
                newYear--;
            } else if (newMonth > 11) {
                newMonth = 0;
                newYear++;
            }

            // Restrict navigation outside active semester date range
            if (minYear !== null && maxYear !== null) {
                if (newYear < minYear || (newYear === minYear && newMonth < minMonth)) {
                    return; // Prevent navigating before semester start month
                }
                if (newYear > maxYear || (newYear === maxYear && newMonth > maxMonth)) {
                    return; // Prevent navigating after semester end month
                }
            }

            displayMonth = newMonth;
            displayYear = newYear;
            renderCalendar();
        }

        function renderCalendar() {
            const grid = document.getElementById('calendarGrid');
            document.getElementById('calendarMonthYear').innerText = `${monthNames[displayMonth]} ${displayYear}`;
            
            grid.innerHTML = `
                <div class="calendar-day-name">Mon</div>
                <div class="calendar-day-name">Tue</div>
                <div class="calendar-day-name">Wed</div>
                <div class="calendar-day-name">Thu</div>
                <div class="calendar-day-name">Fri</div>
                <div class="calendar-day-name">Sat</div>
                <div class="calendar-day-name">Sun</div>
            `;

            const firstDay = new Date(displayYear, displayMonth, 1);
            const lastDay = new Date(displayYear, displayMonth + 1, 0);
            
            let startDay = firstDay.getDay() - 1;
            if (startDay === -1) startDay = 6; 

            const prevMonthLastDay = new Date(displayYear, displayMonth, 0).getDate();
            const totalCells = 42; 
            let dayCount = 1;
            let nextMonthDayCount = 1;

            const todayStr = new Date().toISOString().split('T')[0];

            for (let i = 0; i < totalCells; i++) {
                const cell = document.createElement('div');
                cell.className = 'calendar-cell';
                
                let cellDateStr = '';

                if (i < startDay) {
                    cell.classList.add('other-month');
                    cell.innerHTML = `<span class="calendar-date-number">${prevMonthLastDay - startDay + i + 1}</span>`;
                } else if (dayCount > lastDay.getDate()) {
                    cell.classList.add('other-month');
                    cell.innerHTML = `<span class="calendar-date-number">${nextMonthDayCount++}</span>`;
                } else {
                    const cellDate = new Date(displayYear, displayMonth, dayCount);
                    const localOffset = cellDate.getTimezoneOffset() * 60000;
                    cellDateStr = new Date(cellDate.getTime() - localOffset).toISOString().split('T')[0];
                    
                    if (cellDateStr === todayStr) {
                        cell.classList.add('today');
                    }
                    
                    cell.innerHTML = `<span class="calendar-date-number">${dayCount}</span>`;
                    
                    const dayEvents = eventsData.filter(e => e.date === cellDateStr);
                    
                    const maxVisible = 3;
                    const visibleEvents = dayEvents.slice(0, maxVisible);
                    const hiddenCount = dayEvents.length - maxVisible;

                    visibleEvents.forEach(ev => {
                        const evtDiv = buildEventElement(ev);
                        cell.appendChild(evtDiv);
                    });

                    if (hiddenCount > 0) {
                        const moreBtn = document.createElement('div');
                        moreBtn.className = 'more-events';
                        moreBtn.innerText = `+ ${hiddenCount} more`;
                        moreBtn.onclick = (e) => {
                            e.stopPropagation();
                            openDayViewModal(cellDateStr, dayEvents);
                        };
                        cell.appendChild(moreBtn);
                    }

                    dayCount++;
                }
                
                grid.appendChild(cell);
            }
        }

        function buildEventElement(ev) {
            const evtDiv = document.createElement('div');
            const isCompleted = ev.status === 'Completed';
            evtDiv.className = `cal-event ${isCompleted ? 'completed-event' : ''}`;
            
            let badgeText, colorClass;

            if (ev.type === 'assignment') {
                badgeText = 'DUE';
                colorClass = 'event-due';
            } else if (ev.type === 'study_session') {
                if (ev.referenceType === 'Assignment') {
                    badgeText = 'ASG';
                    colorClass = 'event-asg';
                } else {
                    badgeText = 'STD';
                    colorClass = 'event-std';
                }
            } else {
                badgeText = 'EXM';
                colorClass = 'event-exm';
            }

            evtDiv.classList.add(colorClass);

            const courseCode = ev.courseCode || 'GEN';
            
            evtDiv.innerHTML = `
                <div class="event-row">
                    <span class="event-badge">${badgeText}</span>
                    <span class="event-course ${isCompleted ? 'completed-text' : ''}">${courseCode}</span>
                </div>
                <div class="event-title ${isCompleted ? 'completed-text' : ''}" title="${ev.title}">${ev.title}</div>
            `;
            
            evtDiv.onclick = () => openEventModal(ev);
            return evtDiv;
        }

        function openDayViewModal(dateStr, dayEvents) {
            const d = new Date(dateStr);
            document.getElementById('dayViewTitle').innerText = `${d.getDate()} ${monthNames[d.getMonth()]} Events`;
            const body = document.getElementById('dayViewBody');
            body.innerHTML = '';
            
            dayEvents.forEach(ev => {
                const el = buildEventElement(ev);
                el.style.padding = "8px";
                el.style.fontSize = "13px";
                el.querySelector('.event-title').style.fontSize = "12px";
                el.querySelector('.event-title').style.whiteSpace = "normal";
                body.appendChild(el);
            });

            document.getElementById('modalOverlay').classList.add('active');
            document.getElementById('dayViewModal').classList.add('active');
        }

        function renderUpcoming() {
            const list = document.getElementById('upcomingList');
            list.innerHTML = '';
            
            const todayStr = new Date().toISOString().split('T')[0];
            const upcomingEvents = eventsData
                .filter(e => e.date >= todayStr)
                .sort((a, b) => new Date(a.date) - new Date(b.date));

            if (upcomingEvents.length === 0) {
                list.innerHTML = `<div class="empty-upcoming">No scheduled items yet.</div>`;
                return;
            }

            upcomingEvents.forEach(ev => {
                const dateObj = new Date(ev.date);
                const month = monthNames[dateObj.getMonth()].substring(0, 3).toUpperCase();
                const day = dateObj.getDate();
                
                const item = document.createElement('div');
                item.className = 'upcoming-item';
                item.style.cursor = 'pointer';
                item.onclick = () => openEventModal(ev);
                
                const isCompleted = ev.status === 'Completed';
                const titleClass = isCompleted ? 'completed-text' : '';
                
                let badgeLabel, badgeBg, categoryLabel, metaIcon, metaText;

                if (ev.type === 'assignment') {
                    badgeLabel = 'DUE';
                    badgeBg = '#ef4444';
                    categoryLabel = 'Assignment Deadline';
                    metaIcon = 'ph-file-text';
                    metaText = ev.assignmentType;
                } else if (ev.type === 'study_session') {
                    if (ev.referenceType === 'Assignment') {
                        badgeLabel = 'ASG';
                        badgeBg = '#6366f1';
                        categoryLabel = ev.activityLabel || 'Assignment Work';
                    } else {
                        badgeLabel = 'STD';
                        badgeBg = '#a855f7';
                        categoryLabel = ev.activityLabel || 'Study Session';
                    }
                    metaIcon = 'ph-notebook';
                    metaText = categoryLabel;
                } else {
                    badgeLabel = 'EXM';
                    badgeBg = '#f97316';
                    categoryLabel = 'Exam Schedule';
                    metaIcon = 'ph-clock';
                    metaText = ev.time ? formatTime(ev.time) : 'TBA';
                }

                const courseCode = ev.courseCode || 'GEN';

                item.innerHTML = `
                    <div class="upcoming-date">
                        <div style="font-size:12px; font-weight:700; color:#64748b;">${month}</div>
                        <div style="font-size:20px; font-weight:800; color:#0f172a; line-height:1;">${day}</div>
                    </div>
                    <div class="upcoming-details">
                        <div class="upcoming-header-row">
                            <span class="event-badge" style="background:${badgeBg};">${badgeLabel}</span>
                            <span class="upcoming-course ${titleClass}">${courseCode}</span>
                        </div>
                        <div class="upcoming-title ${titleClass}">${ev.title}</div>
                        <div class="upcoming-meta">
                            <span><i class="ph ${metaIcon}"></i> ${metaText}</span>
                            <span><i class="ph ph-warning-circle"></i> ${ev.priority} Priority</span>
                            ${ev.location ? `<span><i class="ph ph-map-pin"></i> ${ev.location}</span>` : ''}
                        </div>
                    </div>
                `;
                list.appendChild(item);
            });
        }

        function openEventModal(ev) {
            document.querySelectorAll('.modal').forEach(m => m.classList.remove('active'));

            const modalTitle = document.getElementById('modalTitle');
            const modalBody = document.getElementById('modalBody');
            const isCompleted = ev.status === 'Completed';
            const titleClass = isCompleted ? 'completed-event' : '';

            const courseText = ev.courseCode 
                ? `${ev.courseCode}${ev.courseName ? ' - ' + ev.courseName : ''}` 
                : 'Unassigned Course';

            if (ev.type === 'study_session') {
                modalTitle.innerText = "Scheduled Activity Details";
                modalBody.innerHTML = `
                    <div style="margin-bottom:12px;">
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Course</span>
                        <div style="font-size:15px; font-weight:600; color:#0f172a;">${courseText}</div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Task / Target</span>
                        <div style="font-size:15px; color:#1e293b;" class="${titleClass}">${ev.title}</div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Activity Type</span>
                        <div style="font-size:14px; font-weight:600; color:#8b5cf6;">${ev.activityLabel || 'Study / Work Session'}</div>
                    </div>
                    <div style="display:flex; gap:20px; margin-bottom:12px;">
                        <div>
                            <span style="font-size:12px; font-weight:700; color:#64748b;">Scheduled Date</span>
                            <div style="font-size:14px; color:#1e293b;">${formatDate(ev.date)}</div>
                        </div>
                        <div>
                            <span style="font-size:12px; font-weight:700; color:#64748b;">Scheduled Time</span>
                            <div style="font-size:14px; color:#1e293b;">${ev.time ? formatTime(ev.time) : 'TBA'}</div>
                        </div>
                        <div>
                            <span style="font-size:12px; font-weight:700; color:#64748b;">Priority</span>
                            <div style="font-size:14px; color:#1e293b;">${ev.priority}</div>
                        </div>
                        <div>
                            <span style="font-size:12px; font-weight:700; color:#64748b;">Status</span>
                            <div style="font-size:14px; color:#1e293b;">${ev.status}</div>
                        </div>
                    </div>`;

            } else if (ev.type === 'assignment') {
                modalTitle.innerText = "Assignment Details";
                
                let subtasksHtml = '';
                if (ev.subTasks && Array.isArray(ev.subTasks) && ev.subTasks.length > 0) {
                    subtasksHtml = '<div class="modal-subtask-list"><strong style="font-size:13px; color:#475569; display:block; margin-bottom:4px;">Subtasks</strong>';
                    ev.subTasks.forEach(st => {
                        const isChecked = st.isCompleted ? 'checked' : '';
                        subtasksHtml += `
                            <label class="modal-subtask-item">
                                <input type="checkbox" disabled ${isChecked}>
                                <span class="${st.isCompleted ? 'completed-event' : ''}">${st.subTaskDescription}</span>
                            </label>
                        `;
                    });
                    subtasksHtml += '</div>';
                }

                modalBody.innerHTML = `
                    <div style="margin-bottom:12px;">
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Course</span>
                        <div style="font-size:15px; font-weight:600; color:#0f172a;">${courseText}</div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Assignment</span>
                        <div style="font-size:15px; color:#1e293b;" class="${titleClass}">${ev.title}</div>
                    </div>
                    <div style="display:flex; gap:20px; margin-bottom:12px;">
                        <div>
                            <span style="font-size:12px; font-weight:700; color:#64748b;">Due Date</span>
                            <div style="font-size:14px; color:#1e293b;">${formatDate(ev.date)}</div>
                        </div>
                        <div>
                            <span style="font-size:12px; font-weight:700; color:#64748b;">Priority</span>
                            <div style="font-size:14px; color:#1e293b;">${ev.priority}</div>
                        </div>
                        <div>
                            <span style="font-size:12px; font-weight:700; color:#64748b;">Status</span>
                            <div style="font-size:14px; color:#1e293b;">${ev.status}</div>
                        </div>
                    </div>
                    ${subtasksHtml}
                `;
            } else {
                modalTitle.innerText = "Exam Details";
                modalBody.innerHTML = `
                    <div style="margin-bottom:12px;">
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Course</span>
                        <div style="font-size:15px; font-weight:600; color:#0f172a;">${courseText}</div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Exam / Assessment</span>
                        <div style="font-size:15px; color:#1e293b;" class="${titleClass}">${ev.title}</div>
                    </div>
                    <div style="display:flex; gap:20px; margin-bottom:12px;">
                        <div>
                            <span style="font-size:12px; font-weight:700; color:#64748b;">Date</span>
                            <div style="font-size:14px; color:#1e293b;">${formatDate(ev.date)}</div>
                        </div>
                        <div>
                            <span style="font-size:12px; font-weight:700; color:#64748b;">Time</span>
                            <div style="font-size:14px; color:#1e293b;">${ev.time ? formatTime(ev.time) : 'TBA'}</div>
                        </div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Location</span>
                        <div style="font-size:15px; color:#1e293b;">${ev.location || 'Not Specified'}</div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Status</span>
                        <div style="font-size:14px; color:#1e293b;">${ev.status}</div>
                    </div>
                `;
            }

            document.getElementById('modalOverlay').classList.add('active');
            document.getElementById('eventDetailsModal').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById('modalOverlay').classList.remove('active');
            document.getElementById(id).classList.remove('active');
        }

        function closeAllModals() {
            document.querySelectorAll('.modal').forEach(m => m.classList.remove('active'));
            document.getElementById('modalOverlay').classList.remove('active');
        }

        function formatTime(timeStr) {  
            const [hour, minute] = timeStr.split(':');
            const h = parseInt(hour, 10);
            const ampm = h >= 12 ? 'PM' : 'AM';
            const h12 = h % 12 || 12;
            return `${h12}:${minute} ${ampm}`;
        }
        function formatDate(dateStr) {
            const d = new Date(dateStr);
            return `${d.getDate()} ${monthNames[d.getMonth()]} ${d.getFullYear()}`;
        }
    </script>
</body>
</html>