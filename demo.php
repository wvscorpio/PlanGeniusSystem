<?php
/**
 * PlanGenius - Guided Visual Interactive Demo Page (demo.php)
 * 
 * Features:
 * - Publicly accessible guided walkthrough displaying full-screen visual screenshots for all 8 modules.
 * - Windows desktop browser frame styling with persistent left sidebar navigation.
 * - Dynamic step progress bar, top badge, and contextual walkthrough explanations.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Explore PlanGenius — Interactive Visual System Demo</title>
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        lilac: {
                            50: '#faf8ff',
                            100: '#f3e8ff',
                            200: '#e9d5ff',
                            300: '#d8b4fe',
                            400: '#c084fc',
                            500: '#a855f7',
                            600: '#9333ea',
                            700: '#7e22ce',
                            800: '#6b21a8',
                            900: '#581c87',
                            950: '#2e1065',
                        },
                        accent: {
                            purple: '#7c3aed',
                            light: '#f5f3ff',
                            border: '#e9d5ff',
                            text: '#2e1065',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    
    <!-- Google Fonts & Phosphor Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #1e293b; }
        .sidebar-item-active { background-color: #f3e8ff; color: #7c3aed; font-weight: 600; border-radius: 12px; }
    </style>
</head>
<body class="min-h-screen flex flex-col antialiased selection:bg-lilac-200 selection:text-lilac-900">

    <!-- TOP DEMO NOTICE BANNER -->
    <div class="bg-amber-50 border-b border-amber-200 px-4 py-2.5 text-xs text-amber-900 text-center font-medium flex items-center justify-center gap-2 shadow-sm">
        <i class="ph ph-info text-amber-600 text-base"></i>
        <span><strong>Interactive System Demo:</strong> Exploring sample student records for <em>ANIS AQILAH ABU</em> across all 8 modules by academic flow. Interactions take place in-browser without modifying production data.</span>
    </div>

    <!-- WINDOWS DESKTOP BROWSER FRAME CONTAINER -->
    <div class="flex-1 flex flex-col max-w-[1600px] w-full mx-auto my-0 sm:my-3 bg-white sm:rounded-2xl border border-slate-200 shadow-2xl overflow-hidden">
        
        <!-- Windows Desktop Browser Header Bar -->
        <div class="bg-slate-100 border-b border-slate-200 px-4 py-2 flex items-center justify-between select-none">
            <div class="flex items-center gap-3 flex-1 max-w-xl">
                <div class="flex items-center gap-1.5 text-slate-400">
                    <button onclick="navigateStep(-1)" title="Previous Step" class="hover:text-slate-700 p-1.5 rounded-lg hover:bg-slate-200/60 transition"><i class="ph ph-arrow-left font-bold"></i></button>
                    <button onclick="navigateStep(1)" title="Next Step" class="hover:text-slate-700 p-1.5 rounded-lg hover:bg-slate-200/60 transition"><i class="ph ph-arrow-right font-bold"></i></button>
                    <button onclick="location.reload()" title="Reload Page" class="hover:text-slate-700 p-1.5 rounded-lg hover:bg-slate-200/60 transition"><i class="ph ph-arrow-counter-clockwise font-bold"></i></button>
                </div>
                <div class="flex items-center gap-2 bg-white px-3.5 py-1 rounded-lg border border-slate-200 text-xs text-slate-600 w-full shadow-inner">
                    <i class="ph ph-lock-key text-emerald-600 font-bold"></i>
                    <span class="font-mono text-slate-500 truncate">https://plangenius.app/demo</span>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <div class="hidden lg:flex items-center gap-1 bg-purple-50 border border-purple-200/60 text-xs font-semibold px-3 py-1 rounded-lg text-purple-900">
                    <i class="ph ph-compass text-accent-purple"></i>
                    <span id="currentStepHeaderBadge">Flow Step: Step 1 of 8 (Academic Structure)</span>
                </div>

                <a href="index.php" class="text-xs font-semibold text-slate-600 hover:text-slate-900 px-3 py-1.5 rounded-lg border border-slate-200 bg-white shadow-sm hover:bg-slate-50 transition">
                    Exit Demo
                </a>
                <a href="register.php" class="text-xs font-semibold text-white bg-accent-purple hover:bg-lilac-700 px-3.5 py-1.5 rounded-lg shadow-sm transition">
                    Get Started
                </a>
            </div>
        </div>

        <!-- MAIN PLANGENIUS APP LAYOUT -->
        <div class="flex flex-col md:flex-row flex-1 min-h-[850px]">

            <!-- PERSISTENT LEFT SIDEBAR NAVIGATION -->
            <aside class="w-full md:w-64 bg-white border-r border-slate-100 p-5 flex flex-col justify-between shrink-0">
                <div class="space-y-6">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-accent-purple text-white flex items-center justify-center font-bold text-xl shadow-md shadow-purple-500/20">
                            <i class="ph ph-books"></i>
                        </div>
                        <div>
                            <h1 class="font-bold text-xl text-lilac-950 leading-none">Plan<span class="text-accent-purple">Genius</span></h1>
                            <span class="text-[10px] text-slate-400 font-medium tracking-wide">Student Planner</span>
                        </div>
                    </div>

                    <div>
                        <p class="text-[10px] uppercase font-bold text-slate-400 tracking-wider mb-2 px-1">Walkthrough Modules</p>
                        <nav class="space-y-1 text-xs font-medium" id="sidebarMenu">
                            <button onclick="selectModule('dashboard')" id="nav-dashboard" class="w-full flex items-center gap-3 px-3.5 py-2.5 text-slate-600 hover:bg-slate-50 rounded-xl transition-all text-left">
                                <i class="ph ph-squares-four text-lg"></i> Dashboard
                            </button>

                            <button onclick="selectModule('academic')" id="nav-academic" class="w-full flex items-center gap-3 px-3.5 py-2.5 text-slate-600 hover:bg-slate-50 rounded-xl transition-all text-left sidebar-item-active">
                                <i class="ph ph-book-open text-lg"></i> Academic Structure
                            </button>

                            <button onclick="selectModule('assignments')" id="nav-assignments" class="w-full flex items-center gap-3 px-3.5 py-2.5 text-slate-600 hover:bg-slate-50 rounded-xl transition-all text-left">
                                <i class="ph ph-file-text text-lg"></i> Assignments
                            </button>

                            <button onclick="selectModule('exams')" id="nav-exams" class="w-full flex items-center gap-3 px-3.5 py-2.5 text-slate-600 hover:bg-slate-50 rounded-xl transition-all text-left">
                                <i class="ph ph-graduation-cap text-lg"></i> Exams
                            </button>

                            <button onclick="selectModule('schedule')" id="nav-schedule" class="w-full flex items-center gap-3 px-3.5 py-2.5 text-slate-600 hover:bg-slate-50 rounded-xl transition-all text-left">
                                <i class="ph ph-calendar-blank text-lg"></i> Schedule
                            </button>

                            <button onclick="selectModule('notifications')" id="nav-notifications" class="w-full flex items-center gap-3 px-3.5 py-2.5 text-slate-600 hover:bg-slate-50 rounded-xl transition-all text-left">
                                <i class="ph ph-bell text-lg"></i> Notifications
                            </button>

                            <button onclick="selectModule('analytics')" id="nav-analytics" class="w-full flex items-center gap-3 px-3.5 py-2.5 text-slate-600 hover:bg-slate-50 rounded-xl transition-all text-left">
                                <i class="ph ph-chart-line-up text-lg"></i> Analytics
                            </button>

                            <button onclick="selectModule('profile')" id="nav-profile" class="w-full flex items-center gap-3 px-3.5 py-2.5 text-slate-600 hover:bg-slate-50 rounded-xl transition-all text-left">
                                <i class="ph ph-user text-lg"></i> Profile
                            </button>
                        </nav>
                    </div>
                </div>

                <div class="pt-6 border-t border-slate-100 space-y-3">
                    <div class="flex items-center gap-3 p-2.5 bg-slate-50 rounded-xl border border-slate-100">
                        <div class="w-8 h-8 rounded-full bg-lilac-200 text-accent-purple font-bold text-xs flex items-center justify-center shrink-0">
                            AA
                        </div>
                        <div class="overflow-hidden">
                            <p class="text-xs font-bold text-slate-800 truncate">ANIS AQILAH ABU</p>
                            <p class="text-[10px] text-slate-400 truncate">aqilahsairi0310@gmail.com</p>
                        </div>
                    </div>
                    <a href="index.php" class="w-full py-1.5 text-center text-xs text-slate-500 font-semibold hover:text-slate-800 flex items-center justify-center gap-1">
                        <i class="ph ph-sign-out"></i> Logout
                    </a>
                </div>
            </aside>

            <!-- MAIN DEMO VISUAL CONTENT AREA -->
            <main class="flex-1 bg-slate-50/60 p-4 sm:p-8 overflow-y-auto flex flex-col justify-between">

                <div>
                    <!-- TOP STEP HEADER & OVERALL PROGRESS -->
                    <div class="mb-6 pb-4 border-b border-slate-200/80 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <span id="workflowBadge" class="px-2.5 py-0.5 rounded-full bg-lilac-100 text-accent-purple text-[11px] font-bold">Step 1: Setup Academic Structure</span>
                            </div>
                            <h1 id="stepTitle" class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">Module 1: Academic Structure</h1>
                            <p id="stepSubtitle" class="text-xs sm:text-sm text-slate-500 mt-1">Foundational setup for semesters and course registration</p>
                        </div>

                        <div class="w-full sm:w-64 bg-white p-3 rounded-xl border border-slate-200 shadow-sm space-y-1.5">
                            <div class="flex justify-between items-center text-xs">
                                <span class="font-bold text-slate-700">Walkthrough Progress</span>
                                <span id="progressPercentageText" class="font-extrabold text-accent-purple">12%</span>
                            </div>
                            <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                <div id="progressBarFill" class="bg-accent-purple h-full transition-all duration-300" style="width: 12.5%;"></div>
                            </div>
                            <p id="stepCounterText" class="text-[11px] text-slate-400 text-right">Step 1 of 8</p>
                        </div>
                    </div>

                    <!-- FULL VISUAL SCREENSHOT CONTAINER -->
                    <div class="space-y-6">
                        <div class="bg-white p-3 sm:p-4 rounded-2xl border border-slate-200/90 shadow-sm overflow-hidden flex justify-center items-center">
                            <img id="screenshotImg" src="input_file_0.png" alt="PlanGenius Visual Screenshot" class="w-full h-auto max-h-[640px] object-contain rounded-xl border border-slate-100 shadow-inner transition-opacity duration-200">
                        </div>

                        <!-- EXPLANATION CARD -->
                        <div class="bg-white p-6 rounded-2xl border border-slate-200/90 shadow-sm space-y-3">
                            <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                                <i class="ph ph-info text-accent-purple text-lg"></i> Module Functionality Overview
                            </h3>
                            <p id="stepExplanation" class="text-xs sm:text-sm text-slate-600 leading-relaxed">
                                Set up your study plans, group courses by semester (e.g., Year 3 Sem 2), and track active vs. inactive academic periods. Courses registered here serve as the foundation for all assignment, exam, calendar, and analytics features.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- BOTTOM NAVIGATION FOOTER CONTROLS -->
                <div class="pt-6 mt-8 border-t border-slate-200/80 flex flex-col sm:flex-row justify-between items-center gap-4">
                    <button onclick="navigateStep(-1)" id="btnPrevBottom" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold flex items-center gap-1.5 transition">
                        <i class="ph ph-arrow-left"></i> Previous Step
                    </button>

                    <button onclick="navigateStep(1)" id="btnNextBottom" class="px-5 py-2.5 bg-accent-purple hover:bg-lilac-700 text-white rounded-xl text-xs font-bold flex items-center gap-2 shadow-md shadow-purple-500/20 transition">
                        <span>Continue to Step 2: Assignments</span> <i class="ph ph-arrow-right"></i>
                    </button>
                </div>

            </main>
        </div>
    </div>

    <!-- WALKTHROUGH SCRIPT LOGIC -->
    <script>
        const modulesData = [
                        {
                id: 'dashboard',
                name: 'Student Dashboard',
                stepNum: 1,
                badge: 'Step 1: Daily Overview Dashboard',
                screenshot: 'demo/dashboard.png',
                title: 'Module 1: Student Dashboard',
                subtitle: 'Daily Command Hub & Progress Overview',
                explanation: 'The central dashboard features an overall completion percentage gauge, stat cards for total assignments, past due items, exams, and study hours, along with quick-view lists for upcoming deadlines.'
            },
            {
                id: 'academic',
                name: 'Academic Structure',
                stepNum: 2,
                badge: 'Step 2: Setup Academic Structure',
                screenshot: 'demo/academic.png',
                title: 'Module 2: Academic Structure',
                subtitle: 'Semester & Course Registration Setup',
                explanation: 'Students begin by creating semester plans (e.g., Year 3 Sem 2) and registering enrolled courses (BIT20903 Artificial Intelligence, BIE33103 Dotnet Programming, BIE30803 Software Testing, UHB33103). Active semesters maintain active status while previous terms remain archived as historical records.'
            },
            {
                id: 'assignments',
                name: 'Assignments Management',
                stepNum: 3,
                badge: 'Step 3: Coursework & Assignment Input',
                screenshot: 'demo/assignment.png',
                title: 'Module 3: Assignments Management',
                subtitle: 'Task Tracking, Subtask Checklists & Priority Levels',
                explanation: 'Students organize assignments by course code with due dates, priority tags (High, Medium, Low), and status indicators. Automatic PDF parsing extracts rubric requirements directly into interactive subtask checklists with live progress tracking.'
            },
            {
                id: 'exams',
                name: 'Exam Management',
                stepNum: 4,
                badge: 'Step 4: Exam & Revision Planning',
                screenshot: 'demo/exam.png',
                title: 'Module 4: Examination Management',
                subtitle: 'Exams, Quizzes, Venues & Revision Schedules',
                explanation: 'Students track major examinations, tests, and quizzes with details including date, time, venue, difficulty rating, and priority level. Scheduled revision study sessions enable step-by-step preparation prior to exam dates.'
            },
            {
                id: 'schedule',
                name: 'Study Schedule',
                stepNum: 5,
                badge: 'Step 5: Automated Schedule Generation',
                screenshot: 'demo/schedule.png',
                title: 'Module 5: Study Schedule',
                subtitle: 'Automated Academic Calendar Generation',
                explanation: 'PlanGenius automatically builds a monthly calendar from registered assignments and exams. Color-coded badges distinguish assignment deadlines (DUE), exams (EXM), coursework (ASG), and study revision sessions (STD).'
            },
            {
                id: 'notifications',
                name: 'Notification Management',
                stepNum: 6,
                badge: 'Step 6: Reminders & Notification Triggers',
                screenshot: 'demo/notification.png',
                title: 'Module 6: Notification Management',
                subtitle: 'Automated Triggers, Countdown Alerts & Email Preferences',
                explanation: 'Automated triggers alert students to overdue coursework, upcoming tests, and scheduled study sessions. The notification hub displays total alerts, unread counts, and email delivery settings.'
            },
            {
                id: 'analytics',
                name: 'Analytics Dashboard',
                stepNum: 7,
                badge: 'Step 7: Semester Performance Analytics',
                screenshot: 'demo/analytics.png',
                title: 'Module 7: Analytics Dashboard',
                subtitle: 'Semester Progress Metrics & Status Breakdown Charts',
                explanation: 'Analytics display overall progress metrics and interactive charts for assessment completion status, priority distributions, and subject-by-subject completion rates.'
            },
            {
                id: 'profile',
                name: 'Profile Management',
                stepNum: 8,
                badge: 'Step 8: Account Profile & Preferences',
                screenshot: 'demo/profile.png',
                title: 'Module 8: Profile Management',
                subtitle: 'Personal Student Account Details & Security',
                explanation: 'Students view and update personal account details (Name, Email, Phone Number) and manage security options such as password updates.'
            }
        ];

        let currentIndex = 0;

        function updateWalkthroughDisplay() {
            const current = modulesData[currentIndex];

            // Update Image & Text
            document.getElementById('screenshotImg').src = current.screenshot;
            document.getElementById('stepTitle').innerText = current.title;
            document.getElementById('stepSubtitle').innerText = current.subtitle;
            document.getElementById('stepExplanation').innerText = current.explanation;
            document.getElementById('workflowBadge').innerText = current.badge;

            // Update Counters & Badges
            document.getElementById('stepCounterText').innerText = `Step ${current.stepNum} of ${modulesData.length}`;
            document.getElementById('currentStepHeaderBadge').innerText = `Flow Step: Step ${current.stepNum} of ${modulesData.length} (${current.name})`;

            // Update Progress Bar
            const percent = Math.round((current.stepNum / modulesData.length) * 100);
            document.getElementById('progressPercentageText').innerText = `${percent}%`;
            document.getElementById('progressBarFill').style.width = `${percent}%`;

            // Update Sidebar Highlight
            modulesData.forEach(m => {
                const navBtn = document.getElementById('nav-' + m.id);
                if (navBtn) {
                    if (m.id === current.id) {
                        navBtn.className = "w-full flex items-center gap-3 px-3.5 py-2.5 text-accent-purple bg-lilac-100 font-semibold rounded-xl transition-all text-left sidebar-item-active";
                    } else {
                        navBtn.className = "w-full flex items-center gap-3 px-3.5 py-2.5 text-slate-600 hover:bg-slate-50 rounded-xl transition-all text-left";
                    }
                }
            });

            // Update Next Button Text
            const isLast = (currentIndex === modulesData.length - 1);
            const btnNextBottom = document.getElementById('btnNextBottom');
            if (isLast) {
                btnNextBottom.innerHTML = `<span>Finish Walkthrough & Register Account</span> <i class="ph ph-check-circle text-base"></i>`;
            } else {
                const nextModule = modulesData[currentIndex + 1];
                btnNextBottom.innerHTML = `<span>Continue to Step ${nextModule.stepNum}: ${nextModule.name}</span> <i class="ph ph-arrow-right"></i>`;
            }

            document.querySelector('main').scrollTop = 0;
        }

        function navigateStep(direction) {
            if (direction === 1) {
                if (currentIndex < modulesData.length - 1) {
                    currentIndex++;
                    updateWalkthroughDisplay();
                } else {
                    window.location.href = 'register.php';
                }
            } else if (direction === -1) {
                if (currentIndex > 0) {
                    currentIndex--;
                    updateWalkthroughDisplay();
                }
            }
        }

        function selectModule(moduleId) {
            const targetIdx = modulesData.findIndex(m => m.id === moduleId);
            if (targetIdx !== -1) {
                currentIndex = targetIdx;
                updateWalkthroughDisplay();
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            updateWalkthroughDisplay();
        });
    </script>
</body>
</html>