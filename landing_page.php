<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PlanGenius — Academic Planning System for University Students</title>
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #faf8ff; color: #2e1065; }
        .grid-bg { background-image: radial-gradient(rgba(124, 58, 237, 0.08) 1px, transparent 1px); background-size: 24px 24px; }
    </style>
</head>
<body class="min-h-screen flex flex-col antialiased selection:bg-lilac-200 selection:text-lilac-900 grid-bg">

    <!-- NAVIGATION BAR -->
    <header class="sticky top-0 z-50 backdrop-blur-md bg-white/85 border-b border-lilac-100 transition-all">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                
                <!-- Logo -->
                <a href="index.php" class="flex items-center gap-3 group">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-lilac-600 to-accent-purple flex items-center justify-center text-white shadow-md shadow-lilac-500/20 group-hover:scale-105 transition-transform">
                        <i class="ph ph-books text-xl"></i>
                    </div>
                    <div class="flex flex-col">
                        <span class="font-bold text-xl tracking-tight text-lilac-950">Plan<span class="text-accent-purple">Genius</span></span>
                        <span class="text-[10px] font-medium text-lilac-600 uppercase tracking-widest -mt-1 hidden sm:inline-block">Academic Planner</span>
                    </div>
                </a>

                <!-- Desktop Navigation Links -->
                <nav class="hidden md:flex items-center gap-7 text-sm font-medium text-lilac-900">
                    <a href="#home" class="hover:text-accent-purple transition-colors">Home</a>
                    <a href="#features" class="hover:text-accent-purple transition-colors">Features</a>
                    <a href="#how-it-works" class="hover:text-accent-purple transition-colors">How It Works</a>
                    <a href="demo.php" class="px-3 py-1.5 rounded-lg bg-lilac-100 text-accent-purple font-semibold hover:bg-lilac-200 transition-colors">Demo</a>
                    <a href="#about" class="hover:text-accent-purple transition-colors">About</a>
                </nav>

                <!-- Auth Action Buttons -->
                <div class="hidden md:flex items-center gap-3">
                    <a href="login.php" class="px-4 py-2.5 text-sm font-medium text-lilac-900 hover:text-accent-purple hover:bg-lilac-100/60 rounded-xl transition-all">
                        Login
                    </a>
                    <a href="register.php" class="px-5 py-2.5 text-sm font-semibold text-white bg-accent-purple hover:bg-lilac-700 rounded-xl shadow-md shadow-lilac-500/20 hover:shadow-lg transition-all active:scale-[0.98]">
                        Get Started
                    </a>
                </div>

                <!-- Mobile Navigation Toggle -->
                <button id="mobileMenuBtn" type="button" class="md:hidden p-2 rounded-xl text-lilac-900 hover:bg-lilac-100 focus:outline-none">
                    <i class="ph ph-list text-2xl"></i>
                </button>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div id="mobileMenu" class="hidden md:hidden border-b border-lilac-100 bg-white px-4 pt-2 pb-6 space-y-3">
            <a href="#home" class="block px-3 py-2 rounded-lg text-base font-medium text-lilac-900 hover:bg-lilac-50">Home</a>
            <a href="#features" class="block px-3 py-2 rounded-lg text-base font-medium text-lilac-900 hover:bg-lilac-50">Features</a>
            <a href="#how-it-works" class="block px-3 py-2 rounded-lg text-base font-medium text-lilac-900 hover:bg-lilac-50">How It Works</a>
            <a href="demo.php" class="block px-3 py-2 rounded-lg text-base font-semibold text-accent-purple bg-lilac-100">Interactive Demo</a>
            <a href="#about" class="block px-3 py-2 rounded-lg text-base font-medium text-lilac-900 hover:bg-lilac-50">About</a>
            <div class="pt-4 flex flex-col gap-2">
                <a href="login.php" class="w-full py-2.5 text-center text-sm font-semibold text-lilac-900 bg-lilac-100 rounded-xl">Login</a>
                <a href="register.php" class="w-full py-2.5 text-center text-sm font-semibold text-white bg-accent-purple rounded-xl">Get Started</a>
            </div>
        </div>
    </header>

    <!-- HERO SECTION -->
    <section id="home" class="pt-12 pb-20 md:pt-20 md:pb-28">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                
                <!-- Hero Content Left -->
                <div class="lg:col-span-6 space-y-8 text-center lg:text-left">
                    <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-lilac-100 border border-lilac-200 text-xs font-semibold text-accent-purple">
                        <i class="ph ph-sparkle"></i> Academic Management System
                    </div>
                    
                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-lilac-950 tracking-tight leading-[1.15]">
                        Plan Smarter.<br>
                        <span class="text-accent-purple">Study Better.</span>
                    </h1>
                    
                    <p class="text-base sm:text-lg text-slate-600 max-w-xl mx-auto lg:mx-0 font-normal leading-relaxed">
                        Manage your assignments, prepare for examinations, and organize your study sessions in one place with PlanGenius.
                    </p>

                    <div class="flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-4">
                        <a href="register.php" class="w-full sm:w-auto px-7 py-3.5 text-base font-semibold text-white bg-accent-purple hover:bg-lilac-700 rounded-xl shadow-lg shadow-lilac-500/25 transition-all text-center">
                            Get Started
                        </a>
                        <a href="demo.php" class="w-full sm:w-auto px-7 py-3.5 text-base font-semibold text-lilac-950 bg-white hover:bg-lilac-50 border border-lilac-200 rounded-xl shadow-sm transition-all text-center flex items-center justify-center gap-2">
                            <i class="ph ph-play-circle text-xl text-accent-purple"></i> Explore Demo
                        </a>
                    </div>

                    <div class="pt-4 flex items-center justify-center lg:justify-start gap-6 text-xs text-slate-500 font-medium">
                        <span class="flex items-center gap-1.5"><i class="ph-fill ph-check-circle text-emerald-500 text-base"></i> PDF Document Parsing</span>
                        <span class="flex items-center gap-1.5"><i class="ph-fill ph-check-circle text-emerald-500 text-base"></i> Rule-Based Scheduling</span>
                    </div>
                </div>

                <!-- Windows Desktop System Preview -->
                <div class="lg:col-span-6">
                    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xl overflow-hidden transform hover:-translate-y-1 transition-all duration-300">
                        
                        <!-- Windows Browser Header -->
                        <div class="bg-slate-100 border-b border-slate-200 px-4 py-2.5 flex items-center justify-between select-none">
                            <div class="flex items-center gap-2 flex-1 max-w-md">
                                <div class="flex items-center gap-2 bg-white px-3 py-1 rounded-md border border-slate-200 text-xs text-slate-600 w-full shadow-inner">
                                    <i class="ph ph-lock text-slate-400"></i>
                                    <span class="truncate">https://plangenius.app/dashboard</span>
                                </div>
                            </div>
                            <!-- Windows Controls -->
                            <div class="flex items-center gap-3 text-slate-500 text-xs ml-4">
                                <span class="hover:text-slate-800 cursor-pointer">―</span>
                                <span class="hover:text-slate-800 cursor-pointer">☐</span>
                                <span class="hover:text-red-600 cursor-pointer">✕</span>
                            </div>
                        </div>

                        <!-- Interface Preview Body -->
                        <div class="p-5 bg-slate-50 space-y-4">
                            <!-- Top Bar inside app -->
                            <div class="flex justify-between items-center bg-white p-3.5 rounded-xl border border-slate-200/80 shadow-sm">
                                <div>
                                    <h2 class="text-sm font-bold text-slate-800">Welcome back, Anis!</h2>
                                    <p class="text-[11px] text-slate-500">Semester 2 2025/2026 • Active</p>
                                </div>
                                <span class="px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-700 text-xs font-semibold">68% Completed</span>
                            </div>

                            <!-- Stat Cards Grid -->
                            <div class="grid grid-cols-3 gap-3">
                                <div class="bg-white p-3 rounded-xl border border-slate-200/80 shadow-sm">
                                    <div class="text-[11px] text-slate-500 font-medium">Assignments</div>
                                    <div class="text-lg font-extrabold text-indigo-600">3 Active</div>
                                </div>
                                <div class="bg-white p-3 rounded-xl border border-slate-200/80 shadow-sm">
                                    <div class="text-[11px] text-slate-500 font-medium">Upcoming Exams</div>
                                    <div class="text-lg font-extrabold text-amber-600">2 Scheduled</div>
                                </div>
                                <div class="bg-white p-3 rounded-xl border border-slate-200/80 shadow-sm">
                                    <div class="text-[11px] text-slate-500 font-medium">Study Sessions</div>
                                    <div class="text-lg font-extrabold text-lilac-700">12 Hours</div>
                                </div>
                            </div>

                            <!-- Academic Task Preview List -->
                            <div class="bg-white rounded-xl border border-slate-200/80 p-3.5 shadow-sm space-y-2.5">
                                <div class="flex justify-between items-center text-xs font-bold text-slate-700 border-b border-slate-100 pb-2">
                                    <span>Upcoming Tasks & Exams</span>
                                    <span class="text-[10px] text-lilac-600 font-semibold">Priority View</span>
                                </div>

                                <div class="flex items-center justify-between p-2 rounded-lg bg-slate-50 border border-slate-100 text-xs">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-2 h-2 rounded-full bg-red-500"></div>
                                        <div>
                                            <p class="font-semibold text-slate-800">BIE20303 — Software Architecture</p>
                                            <p class="text-[10px] text-slate-500">Due: Jun 12, 2026 • Assignment</p>
                                        </div>
                                    </div>
                                    <span class="px-2 py-0.5 rounded bg-red-100 text-red-700 text-[10px] font-semibold">High</span>
                                </div>

                                <div class="flex items-center justify-between p-2 rounded-lg bg-slate-50 border border-slate-100 text-xs">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-2 h-2 rounded-full bg-amber-500"></div>
                                        <div>
                                            <p class="font-semibold text-slate-800">BFC20803 — Final Examination</p>
                                            <p class="text-[10px] text-slate-500">Date: Jun 28, 2026 • 09:00 AM</p>
                                        </div>
                                    </div>
                                    <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-700 text-[10px] font-semibold">Medium</span>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- FEATURES SECTION -->
    <section id="features" class="py-20 bg-white border-y border-lilac-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-16 space-y-3">
                <h2 class="text-xs font-bold text-accent-purple uppercase tracking-widest">System Capabilities</h2>
                <p class="text-3xl font-extrabold text-lilac-950 tracking-tight">Built for University Academic Needs</p>
                <p class="text-sm text-slate-600">PlanGenius provides structured, honest tools designed to support your semester workflow.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                
                <!-- Feature 1 -->
                <div class="p-6 rounded-2xl bg-lilac-50/60 border border-lilac-100 hover:border-lilac-300 transition-all space-y-3">
                    <div class="w-12 h-12 rounded-xl bg-lilac-100 text-accent-purple flex items-center justify-center text-2xl">
                        <i class="ph ph-file-text"></i>
                    </div>
                    <h3 class="text-lg font-bold text-lilac-950">1. Assignment Management</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Organize coursework by title, course code, due date, subtasks, and completion statuses. Track your progress step-by-step.</p>
                </div>

                <!-- Feature 2 -->
                <div class="p-6 rounded-2xl bg-lilac-50/60 border border-lilac-100 hover:border-lilac-300 transition-all space-y-3">
                    <div class="w-12 h-12 rounded-xl bg-lilac-100 text-accent-purple flex items-center justify-center text-2xl">
                        <i class="ph ph-graduation-cap"></i>
                    </div>
                    <h3 class="text-lg font-bold text-lilac-950">2. Examination Management</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Keep track of final examinations, mid-term tests, and quizzes with dates, times, venues, and difficulty assessments.</p>
                </div>

                <!-- Feature 3 -->
                <div class="p-6 rounded-2xl bg-lilac-50/60 border border-lilac-100 hover:border-lilac-300 transition-all space-y-3">
                    <div class="w-12 h-12 rounded-xl bg-lilac-100 text-accent-purple flex items-center justify-center text-2xl">
                        <i class="ph ph-calendar-blank"></i>
                    </div>
                    <h3 class="text-lg font-bold text-lilac-950">3. Intelligent Study Scheduling</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Automatically generate study sessions across your semester timeframe based on assignment deadlines and exam schedules.</p>
                </div>

                <!-- Feature 4 -->
                <div class="p-6 rounded-2xl bg-lilac-50/60 border border-lilac-100 hover:border-lilac-300 transition-all space-y-3">
                    <div class="w-12 h-12 rounded-xl bg-lilac-100 text-accent-purple flex items-center justify-center text-2xl">
                        <i class="ph ph-warning-circle"></i>
                    </div>
                    <h3 class="text-lg font-bold text-lilac-950">4. Priority-Based Planning</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Calculate task urgency based on remaining days, prioritizing High, Medium, and Low tasks so you prepare well in advance.</p>
                </div>

                <!-- Feature 5 -->
                <div class="p-6 rounded-2xl bg-lilac-50/60 border border-lilac-100 hover:border-lilac-300 transition-all space-y-3">
                    <div class="w-12 h-12 rounded-xl bg-lilac-100 text-accent-purple flex items-center justify-center text-2xl">
                        <i class="ph ph-cloud-arrow-up"></i>
                    </div>
                    <h3 class="text-lg font-bold text-lilac-950">5. PDF Information Extraction</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Upload official assignment briefs and exam timetables in PDF format to automatically extract key details and dates.</p>
                </div>

                <!-- Feature 6 -->
                <div class="p-6 rounded-2xl bg-lilac-50/60 border border-lilac-100 hover:border-lilac-300 transition-all space-y-3">
                    <div class="w-12 h-12 rounded-xl bg-lilac-100 text-accent-purple flex items-center justify-center text-2xl">
                        <i class="ph ph-chart-line-up"></i>
                    </div>
                    <h3 class="text-lg font-bold text-lilac-950">6. Progress Tracking</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Monitor overall task completion rates, completed study hours, and subject progress using clean visual charts.</p>
                </div>

            </div>
        </div>
    </section>

    <!-- HOW IT WORKS SECTION -->
    <section id="how-it-works" class="py-20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-16 space-y-3">
                <h2 class="text-xs font-bold text-accent-purple uppercase tracking-widest">Simple Workflow</h2>
                <p class="text-3xl font-extrabold text-lilac-950 tracking-tight">How PlanGenius Works</p>
                <p class="text-sm text-slate-600">Get organized in three straightforward steps.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                
                <!-- Step 1 -->
                <div class="bg-white p-8 rounded-2xl border border-lilac-100 shadow-sm text-center space-y-4">
                    <div class="w-12 h-12 rounded-full bg-accent-purple text-white font-bold text-lg flex items-center justify-center mx-auto">
                        1
                    </div>
                    <h3 class="text-lg font-bold text-lilac-950">Step 1: Set Up Your Semester</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Create your semester plan and add your registered courses with their course codes and names.</p>
                </div>

                <!-- Step 2 -->
                <div class="bg-white p-8 rounded-2xl border border-lilac-100 shadow-sm text-center space-y-4">
                    <div class="w-12 h-12 rounded-full bg-accent-purple text-white font-bold text-lg flex items-center justify-center mx-auto">
                        2
                    </div>
                    <h3 class="text-lg font-bold text-lilac-950">Step 2: Organize Your Academic Tasks</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Manage assignments and examinations, including uploading supported PDF documents for automatic details extraction.</p>
                </div>

                <!-- Step 3 -->
                <div class="bg-white p-8 rounded-2xl border border-lilac-100 shadow-sm text-center space-y-4">
                    <div class="w-12 h-12 rounded-full bg-accent-purple text-white font-bold text-lg flex items-center justify-center mx-auto">
                        3
                    </div>
                    <h3 class="text-lg font-bold text-lilac-950">Step 3: Follow Your Study Schedule</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Review scheduled study sessions, prioritize tasks, and monitor your overall semester progress.</p>
                </div>

            </div>
        </div>
    </section>

    <!-- ABOUT SECTION -->
    <section id="about" class="py-16 bg-white border-t border-lilac-100">
        <div class="max-w-4xl mx-auto px-4 text-center space-y-4">
            <h2 class="text-2xl font-bold text-lilac-950">Designed for University Students</h2>
            <p class="text-sm text-slate-600 leading-relaxed">
                PlanGenius is a web application designed specifically for university students facing demanding academic schedules. Built with standard PHP, HTML, CSS, JavaScript, and Supabase database services, PlanGenius replaces fragmented notes with a structured academic workflow.
            </p>
            <div class="pt-4">
                <a href="demo.php" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-lilac-100 text-accent-purple font-semibold text-sm hover:bg-lilac-200 transition-colors">
                    Explore PlanGenius Interactive Demo <i class="ph ph-arrow-right"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="mt-auto bg-lilac-950 text-white py-10 border-t border-lilac-900">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-lilac-300">
            <div class="flex items-center gap-2">
                <div class="w-6 h-6 rounded bg-accent-purple flex items-center justify-center text-white">
                    <i class="ph ph-books text-xs"></i>
                </div>
                <span class="font-bold text-white text-sm">PlanGenius</span>
            </div>
            <p>© 2026 PlanGenius. Academic Planning System for University Students.</p>
            <div class="flex gap-4 text-lilac-200">
                <a href="login.php" class="hover:underline">Login</a>
                <a href="register.php" class="hover:underline">Get Started</a>
                <a href="demo.php" class="hover:underline">Demo</a>
            </div>
        </div>
    </footer>

    <script>
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('mobileMenu').classList.toggle('hidden');
        });
    </script>
</body>
</html>