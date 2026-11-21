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
$activeSemName = '';

// Fetch Active Semester Plan
try {
    $semStmt = $conn->prepare('
        SELECT "semesterName", "startDate", "endDate"
        FROM "SemesterPlan"
        WHERE "studentID" = :studentID
          AND CURRENT_DATE >= "startDate" AND CURRENT_DATE <= "endDate"
        LIMIT 1
    ');
    $semStmt->execute([':studentID' => $studentID]);
    $activeSem = $semStmt->fetch(PDO::FETCH_ASSOC);
    if ($activeSem) {
        $activeSemName = $activeSem['semesterName'] ?? '';
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
            'categoryKey' => 'DUE',
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
            'categoryKey' => 'EXM',
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
        $isAssignment = (strtolower(trim($s['referenceType'])) === 'assignment');
        
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
            'categoryKey' => $isAssignment ? 'ASG' : 'STD',
            'referenceType' => $isAssignment ? 'Assignment' : 'Exam',
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

// Build distinct course list for quick filtering
$courseOptions = [];
foreach ($events as $ev) {
    $code = $ev['courseCode'] ?? '';
    if ($code !== '' && !isset($courseOptions[$code])) {
        $courseOptions[$code] = $ev['courseName'] ?? '';
    }
}
ksort($courseOptions);

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
            <div class="page-header flex-between">
                <div>
                    <h1 class="page-title">Study Schedule</h1>
                    <p class="page-subtitle">
                        Your academic calendar generated directly from assignments and exams
                    </p>
                </div>
            </div>

            <!-- Calendar Section -->
            <div class="calendar-container">
                <div class="calendar-header" style="flex-direction: column; align-items: flex-start; gap: 12px;">
                    <div style="display: flex; justify-content: space-between; width: 100%; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <h2 id="calendarMonthYear">Month Year</h2>
                        <div class="calendar-nav">
                            <button type="button" id="prevMonthBtn" onclick="changeMonth(-1)"><i class="ph ph-caret-left"></i> Prev</button>
                            <button type="button" id="todayMonthBtn" class="btn-today-nav" onclick="goToToday()">Today</button>
                            <button type="button" id="nextMonthBtn" onclick="changeMonth(1)">Next <i class="ph ph-caret-right"></i></button>
                        </div>
                    </div>

                    <!-- Interactive Legend Toggles & Course Filter -->
                    <div class="schedule-controls-bar">
                        <div class="calendar-legend-interactive" title="Click badges to show or hide categories">
                            <button type="button" class="legend-filter-btn active" data-cat="DUE" onclick="toggleCategoryFilter('DUE', this)">
                                <span class="event-badge" style="background:#ef4444;">DUE</span> Assignment Deadline
                            </button>
                            <button type="button" class="legend-filter-btn active" data-cat="EXM" onclick="toggleCategoryFilter('EXM', this)">
                                <span class="event-badge" style="background:#f97316;">EXM</span> Exam / Quiz / Test
                            </button>
                            <button type="button" class="legend-filter-btn active" data-cat="ASG" onclick="toggleCategoryFilter('ASG', this)">
                                <span class="event-badge" style="background:#6366f1;">ASG</span> Assignment Work
                            </button>
                            <button type="button" class="legend-filter-btn active" data-cat="STD" onclick="toggleCategoryFilter('STD', this)">
                                <span class="event-badge" style="background:#a855f7;">STD</span> Study Session
                            </button>
                        </div>

                        <div class="schedule-filter-right">
                            <select id="scheduleCourseFilter" class="schedule-course-select" onchange="onCourseFilterChange(this.value)">
                                <option value="ALL">All Courses</option>
                                <?php foreach ($courseOptions as $code => $name): ?>
                                    <option value="<?= htmlspecialchars($code) ?>">
                                        <?= htmlspecialchars($code) ?><?= $name !== '' ? ' - ' . htmlspecialchars($name) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="resetScheduleFiltersBtn" class="btn-reset-filters" style="display: none;" onclick="resetScheduleFilters()">
                                <i class="ph-bold ph-arrow-counter-clockwise"></i> Reset
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="calendar-grid" id="calendarGrid"></div>
            </div>

            <!-- Scheduled Items Section -->
            <div class="scheduled-section-header">
                <div class="scheduled-section-title-group">
                    <h3 style="font-size: 18px; font-weight: 700; color: #1e293b; margin: 0;">Scheduled Items</h3>
                    <div class="schedule-scope-tabs" id="scheduleScopeTabs">
                        <button type="button" class="scope-tab-btn active" data-scope="all" onclick="setScheduleScope('all', this)">All Upcoming</button>
                        <button type="button" class="scope-tab-btn" data-scope="today" onclick="setScheduleScope('today', this)">Today</button>
                        <button type="button" class="scope-tab-btn" data-scope="week" onclick="setScheduleScope('week', this)">Next 7 Days</button>
                    </div>
                </div>

                <div class="schedule-search-box">
                    <i class="ph ph-magnifying-glass"></i>
                    <input type="text" id="scheduleSearchInput" placeholder="Search title or course..." oninput="onScheduleSearch(this.value)">
                </div>
            </div>

            <div id="upcomingList" class="upcoming-list"></div>

        </div>
    </main>

    <div class="modal-overlay" id="modalOverlay" onclick="closeAllModals()"></div>

    <!-- Details Modal -->
    <div class="modal" id="eventDetailsModal" style="max-width: 520px;">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle">Event Details</h2>
            <button class="close-modal-btn" onclick="closeModal('eventDetailsModal')"><i class="ph ph-x"></i></button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>

    <!-- Day View Modal -->
    <div class="modal" id="dayViewModal" style="max-width: 440px;">
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

        // Timezone-safe local date helpers
        function parseLocalDate(dateStr) {
            const parts = dateStr.split('-').map(Number);
            return new Date(parts[0], parts[1] - 1, parts[2]);
        }

        function formatLocalYYYYMMDD(d) {
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${day}`;
        }

        const todayObj = new Date();
        todayObj.setHours(0, 0, 0, 0);
        const todayStr = formatLocalYYYYMMDD(todayObj);

        const next7Obj = new Date(todayObj);
        next7Obj.setDate(todayObj.getDate() + 7);
        const next7Str = formatLocalYYYYMMDD(next7Obj);

        let displayMonth = todayObj.getMonth();
        let displayYear = todayObj.getFullYear();

        // Filter states
        const activeCategories = new Set(['DUE', 'EXM', 'ASG', 'STD']);
        let selectedCourse = 'ALL';
        let selectedScope = 'all';
        let scheduleSearchTerm = '';

        // Lock navigation bounds if active semester is present
        let minYear = null, minMonth = null, maxYear = null, maxMonth = null;
        if (semStart && semEnd) {
            const startDateObj = parseLocalDate(semStart);
            const endDateObj = parseLocalDate(semEnd);
            minYear = startDateObj.getFullYear();
            minMonth = startDateObj.getMonth();
            maxYear = endDateObj.getFullYear();
            maxMonth = endDateObj.getMonth();
        }

        const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

        document.addEventListener('DOMContentLoaded', () => {
            renderAll();
        });

        function renderAll() {
            updateNavButtonsState();
            updateResetButtonVisibility();
            renderCalendar();
            renderUpcoming();
        }

        function getFilteredEvents() {
            const term = scheduleSearchTerm.trim().toLowerCase();
            return eventsData.filter(ev => {
                if (!activeCategories.has(ev.categoryKey)) return false;
                if (selectedCourse !== 'ALL' && ev.courseCode !== selectedCourse) return false;
                if (term !== '') {
                    const hay = `${ev.courseCode || ''} ${ev.courseName || ''} ${ev.title || ''} ${ev.activityLabel || ''}`.toLowerCase();
                    if (!hay.includes(term)) return false;
                }
                return true;
            });
        }

        function toggleCategoryFilter(cat, btn) {
            if (activeCategories.has(cat)) {
                // Prevent turning off all categories at once
                if (activeCategories.size === 1) return;
                activeCategories.delete(cat);
                btn.classList.remove('active');
                btn.classList.add('inactive');
            } else {
                activeCategories.add(cat);
                btn.classList.add('active');
                btn.classList.remove('inactive');
            }
            renderAll();
        }

        function onCourseFilterChange(courseVal) {
            selectedCourse = courseVal;
            renderAll();
        }

        function onScheduleSearch(val) {
            scheduleSearchTerm = val;
            renderAll();
        }

        function setScheduleScope(scope, btn) {
            selectedScope = scope;
            document.querySelectorAll('#scheduleScopeTabs .scope-tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            renderUpcoming();
        }

        function resetScheduleFilters() {
            activeCategories.clear();
            ['DUE', 'EXM', 'ASG', 'STD'].forEach(c => activeCategories.add(c));
            document.querySelectorAll('.legend-filter-btn').forEach(btn => {
                btn.classList.add('active');
                btn.classList.remove('inactive');
            });

            selectedCourse = 'ALL';
            const courseSel = document.getElementById('scheduleCourseFilter');
            if (courseSel) courseSel.value = 'ALL';

            scheduleSearchTerm = '';
            const searchInput = document.getElementById('scheduleSearchInput');
            if (searchInput) searchInput.value = '';

            renderAll();
        }

        function updateResetButtonVisibility() {
            const isFiltered = activeCategories.size < 4 || selectedCourse !== 'ALL' || scheduleSearchTerm.trim() !== '';
            const resetBtn = document.getElementById('resetScheduleFiltersBtn');
            if (resetBtn) resetBtn.style.display = isFiltered ? 'inline-flex' : 'none';
        }

        function updateNavButtonsState() {
            const prevBtn = document.getElementById('prevMonthBtn');
            const nextBtn = document.getElementById('nextMonthBtn');
            if (!prevBtn || !nextBtn) return;

            if (minYear !== null && maxYear !== null) {
                prevBtn.disabled = (displayYear < minYear || (displayYear === minYear && displayMonth <= minMonth));
                nextBtn.disabled = (displayYear > maxYear || (displayYear === maxYear && displayMonth >= maxMonth));
            }
        }

        function goToToday() {
            displayMonth = todayObj.getMonth();
            displayYear = todayObj.getFullYear();
            renderAll();
        }

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

            if (minYear !== null && maxYear !== null) {
                if (newYear < minYear || (newYear === minYear && newMonth < minMonth)) return;
                if (newYear > maxYear || (newYear === maxYear && newMonth > maxMonth)) return;
            }

            displayMonth = newMonth;
            displayYear = newYear;
            renderAll();
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

            const filteredEvents = getFilteredEvents();

            for (let i = 0; i < totalCells; i++) {
                const cell = document.createElement('div');
                cell.className = 'calendar-cell';

                if (i < startDay) {
                    cell.classList.add('other-month');
                    cell.innerHTML = `<span class="calendar-date-number">${prevMonthLastDay - startDay + i + 1}</span>`;
                } else if (dayCount > lastDay.getDate()) {
                    cell.classList.add('other-month');
                    cell.innerHTML = `<span class="calendar-date-number">${nextMonthDayCount++}</span>`;
                } else {
                    const cellDate = new Date(displayYear, displayMonth, dayCount);
                    const cellDateStr = formatLocalYYYYMMDD(cellDate);
                    
                    if (cellDateStr === todayStr) {
                        cell.classList.add('today');
                    }
                    
                    cell.innerHTML = `<span class="calendar-date-number">${dayCount}</span>`;
                    
                    const dayEvents = filteredEvents
                        .filter(e => e.date === cellDateStr)
                        .sort((a, b) => (a.time || '23:59').localeCompare(b.time || '23:59'));
                    
                    if (dayEvents.length > 0) {
                        cell.classList.add('has-events');
                        cell.onclick = () => openDayViewModal(cellDateStr, dayEvents);
                    }

                    const maxVisible = 3;
                    const visibleEvents = dayEvents.slice(0, maxVisible);
                    const hiddenCount = dayEvents.length - maxVisible;

                    visibleEvents.forEach(ev => {
                        const evtDiv = buildEventElement(ev, true);
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

        function buildEventElement(ev, compactTime = false) {
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
            const timeLabel = ev.time ? formatTime(ev.time, compactTime) : '';
            
            evtDiv.innerHTML = `
                <div class="event-row">
                    <div class="event-row-left">
                        <span class="event-badge">${badgeText}</span>
                        <span class="event-course ${isCompleted ? 'completed-text' : ''}">${courseCode}</span>
                    </div>
                    ${timeLabel ? `<span class="event-time-chip">${timeLabel}</span>` : ''}
                </div>
                <div class="event-title ${isCompleted ? 'completed-text' : ''}" title="${ev.title}">${ev.title}</div>
            `;
            
            evtDiv.onclick = (e) => {
                e.stopPropagation();
                openEventModal(ev);
            };
            return evtDiv;
        }

        function openDayViewModal(dateStr, dayEvents) {
            const d = parseLocalDate(dateStr);
            document.getElementById('dayViewTitle').innerText = `${d.getDate()} ${monthNames[d.getMonth()]} ${d.getFullYear()} (${dayEvents.length})`;
            const body = document.getElementById('dayViewBody');
            body.innerHTML = '';
            
            dayEvents.forEach(ev => {
                const el = buildEventElement(ev, false);
                el.style.padding = "10px";
                el.style.fontSize = "13px";
                const titleEl = el.querySelector('.event-title');
                if (titleEl) {
                    titleEl.style.fontSize = "13px";
                    titleEl.style.whiteSpace = "normal";
                }
                body.appendChild(el);
            });

            document.getElementById('modalOverlay').classList.add('active');
            document.getElementById('dayViewModal').classList.add('active');
        }

        function getRelativeDayTag(dateStr) {
            const target = parseLocalDate(dateStr);
            const diffDays = Math.round((target - todayObj) / (1000 * 60 * 60 * 24));
            if (diffDays === 0) return { text: 'Today', cls: 'is-today' };
            if (diffDays === 1) return { text: 'Tomorrow', cls: 'is-soon' };
            if (diffDays <= 7) return { text: `In ${diffDays} days`, cls: 'is-soon' };
            return { text: `In ${diffDays} days`, cls: '' };
        }

        function renderUpcoming() {
            const list = document.getElementById('upcomingList');
            list.innerHTML = '';

            const baseUpcoming = getFilteredEvents()
                .filter(e => e.date >= todayStr)
                .sort((a, b) => {
                    if (a.date !== b.date) return a.date.localeCompare(b.date);
                    return (a.time || '23:59').localeCompare(b.time || '23:59');
                });

            // Update counts on scope tabs
            const todayCount = baseUpcoming.filter(e => e.date === todayStr).length;
            const weekCount = baseUpcoming.filter(e => e.date <= next7Str).length;

            const tabAll = document.querySelector('#scheduleScopeTabs [data-scope="all"]');
            const tabToday = document.querySelector('#scheduleScopeTabs [data-scope="today"]');
            const tabWeek = document.querySelector('#scheduleScopeTabs [data-scope="week"]');
            if (tabAll) tabAll.innerText = `All Upcoming (${baseUpcoming.length})`;
            if (tabToday) tabToday.innerText = `Today (${todayCount})`;
            if (tabWeek) tabWeek.innerText = `Next 7 Days (${weekCount})`;

            let scopedEvents = baseUpcoming;
            if (selectedScope === 'today') {
                scopedEvents = baseUpcoming.filter(e => e.date === todayStr);
            } else if (selectedScope === 'week') {
                scopedEvents = baseUpcoming.filter(e => e.date <= next7Str);
            }

            if (scopedEvents.length === 0) {
                list.innerHTML = `<div class="empty-upcoming">No scheduled items match your current filter.</div>`;
                return;
            }

            scopedEvents.forEach(ev => {
                const dateObj = parseLocalDate(ev.date);
                const month = monthNames[dateObj.getMonth()].substring(0, 3).toUpperCase();
                const day = dateObj.getDate();
                const isTodayItem = (ev.date === todayStr);
                const relTag = getRelativeDayTag(ev.date);
                
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
                    metaIcon = 'ph-exam';
                    metaText = ev.specificType || 'Exam';
                }

                const courseCode = ev.courseCode || 'GEN';
                const formattedTime = ev.time ? formatTime(ev.time, false) : '';

                item.innerHTML = `
                    <div class="upcoming-item-left">
                        <div class="upcoming-date ${isTodayItem ? 'today-tile' : ''}">
                            <div style="font-size:12px; font-weight:700; color:#64748b;">${month}</div>
                            <div style="font-size:20px; font-weight:800; color:#0f172a; line-height:1;">${day}</div>
                        </div>
                        <div class="upcoming-details">
                            <div class="upcoming-header-row">
                                <span class="event-badge" style="background:${badgeBg};">${badgeLabel}</span>
                                <span class="upcoming-course ${titleClass}">${courseCode}</span>
                                ${isCompleted ? '<span class="badge badge-status-completed" style="font-size:11px; padding:2px 8px;">Completed</span>' : ''}
                            </div>
                            <div class="upcoming-title ${titleClass}">${ev.title}</div>
                            <div class="upcoming-meta">
                                ${formattedTime ? `<span><i class="ph-bold ph-clock"></i> ${formattedTime}</span>` : ''}
                                <span><i class="ph ${metaIcon}"></i> ${metaText}</span>
                                <span><i class="ph ph-warning-circle"></i> ${ev.priority} Priority</span>
                                ${ev.location ? `<span><i class="ph ph-map-pin"></i> ${ev.location}</span>` : ''}
                            </div>
                        </div>
                    </div>
                    <div class="upcoming-item-right">
                        <span class="rel-day-pill ${relTag.cls}">${relTag.text}</span>
                        <i class="ph-bold ph-caret-right" style="color: #94a3b8; font-size: 16px;"></i>
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

            const isAssignmentRelated = (ev.type === 'assignment' || (ev.type === 'study_session' && ev.referenceType === 'Assignment'));
            const targetUrl = isAssignmentRelated ? 'assignments.php' : 'exam.php';
            const targetBtnText = isAssignmentRelated ? 'Open in Assignments' : 'Open in Exams';

            if (ev.type === 'study_session') {
                modalTitle.innerText = "Scheduled Activity Details";
                modalBody.innerHTML = `
                    <div style="margin-bottom:8px;">
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Course</span>
                        <div style="font-size:15px; font-weight:700; color:#0f172a;">${courseText}</div>
                    </div>
                    <div>
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Task / Target</span>
                        <div style="font-size:15px; color:#1e293b;" class="${titleClass}">${ev.title}</div>
                    </div>
                    <div class="modal-detail-grid">
                        <div class="modal-detail-box">
                            <span>Activity Type</span>
                            <strong style="color:#6366f1;">${ev.activityLabel || 'Study / Work Session'}</strong>
                        </div>
                        <div class="modal-detail-box">
                            <span>Status</span>
                            <strong>${ev.status}</strong>
                        </div>
                        <div class="modal-detail-box">
                            <span>Scheduled Date</span>
                            <strong>${formatDate(ev.date)}</strong>
                        </div>
                        <div class="modal-detail-box">
                            <span>Scheduled Time</span>
                            <strong>${ev.time ? formatTime(ev.time, false) : 'TBA'}</strong>
                        </div>
                    </div>
                    <div class="modal-footer-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('eventDetailsModal')">Close</button>
                        <a href="${targetUrl}" class="btn btn-primary">${targetBtnText} <i class="ph-bold ph-arrow-right"></i></a>
                    </div>
                `;

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
                    <div style="margin-bottom:8px;">
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Course</span>
                        <div style="font-size:15px; font-weight:700; color:#0f172a;">${courseText}</div>
                    </div>
                    <div>
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Assignment</span>
                        <div style="font-size:15px; color:#1e293b;" class="${titleClass}">${ev.title}</div>
                    </div>
                    <div class="modal-detail-grid">
                        <div class="modal-detail-box">
                            <span>Due Date</span>
                            <strong>${formatDate(ev.date)}</strong>
                        </div>
                        <div class="modal-detail-box">
                            <span>Type</span>
                            <strong>${ev.assignmentType}</strong>
                        </div>
                        <div class="modal-detail-box">
                            <span>Priority</span>
                            <strong>${ev.priority}</strong>
                        </div>
                        <div class="modal-detail-box">
                            <span>Status</span>
                            <strong>${ev.status}</strong>
                        </div>
                    </div>
                    ${subtasksHtml}
                    <div class="modal-footer-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('eventDetailsModal')">Close</button>
                        <a href="${targetUrl}" class="btn btn-primary">${targetBtnText} <i class="ph-bold ph-arrow-right"></i></a>
                    </div>
                `;
            } else {
                modalTitle.innerText = "Exam Details";
                modalBody.innerHTML = `
                    <div style="margin-bottom:8px;">
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Course</span>
                        <div style="font-size:15px; font-weight:700; color:#0f172a;">${courseText}</div>
                    </div>
                    <div>
                        <span style="font-size:12px; font-weight:700; color:#64748b;">Exam / Assessment</span>
                        <div style="font-size:15px; color:#1e293b;" class="${titleClass}">${ev.title}</div>
                    </div>
                    <div class="modal-detail-grid">
                        <div class="modal-detail-box">
                            <span>Exam Date</span>
                            <strong>${formatDate(ev.date)}</strong>
                        </div>
                        <div class="modal-detail-box">
                            <span>Time</span>
                            <strong>${ev.time ? formatTime(ev.time, false) : 'TBA'}</strong>
                        </div>
                        <div class="modal-detail-box">
                            <span>Location</span>
                            <strong>${ev.location || 'Not Specified'}</strong>
                        </div>
                        <div class="modal-detail-box">
                            <span>Status</span>
                            <strong>${ev.status}</strong>
                        </div>
                    </div>
                    <div class="modal-footer-actions">
                        <button type="button" class="btn btn-outline" onclick="closeModal('eventDetailsModal')">Close</button>
                        <a href="${targetUrl}" class="btn btn-primary">${targetBtnText} <i class="ph-bold ph-arrow-right"></i></a>
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

        function formatTime(timeStr, compact = false) {  
            const [hour, minute] = timeStr.split(':');
            const h = parseInt(hour, 10);
            const ampm = h >= 12 ? (compact ? 'p' : ' PM') : (compact ? 'a' : ' AM');
            const h12 = h % 12 || 12;
            return `${h12}:${minute}${ampm}`;
        }

        function formatDate(dateStr) {
            const d = parseLocalDate(dateStr);
            return `${d.getDate()} ${monthNames[d.getMonth()]} ${d.getFullYear()}`;
        }
    </script>
</body>
</html>