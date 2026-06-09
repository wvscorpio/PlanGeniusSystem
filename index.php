<?php
// Execute the Python script to fetch dashboard data
$python_command = "python data_processor.py"; // Use python or py depending on your Windows setup
$output = shell_exec($python_command);

// Decode the JSON data
$dashboard_data = json_decode($output, true);

// Fallback data in case the script fails
if (!$dashboard_data) {
    $dashboard_data = [
        'stats' => [
            'total_assignments' => ['value' => 0, 'label' => 'completed'],
            'upcoming_exams' => ['value' => 0, 'label' => 'In the next month'],
            'completion_rate' => ['value' => 0, 'label' => '%', 'progress' => 0],
            'study_hours' => ['value' => 0, 'label' => 'Estimated total']
        ],
        'upcoming_assignments' => [],
        'upcoming_exams_list' => []
    ];
}

$stats = $dashboard_data['stats'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PlanGenius - Student Planner</title>
    <link rel="stylesheet" href="css/style.css">
    <!-- Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body>
    <?php 
    $current_page = 'dashboard';
    include 'includes/sidebar.php'; 
    ?>

    <!-- Main Content -->
    <main class="main-content">
        <?php include 'includes/header.php'; ?>

        <div class="dashboard-container">
            <div class="page-header">
                <h1 class="page-title">Dashboard</h1>
                <p class="page-subtitle">Overview of your academic progress and upcoming tasks</p>
            </div>

            <div class="stats-grid">
                <!-- Total Assignments -->
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Total Assignments</span>
                        <i class="ph ph-file-text stat-icon"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_assignments']['value']; ?></div>
                    <div class="stat-label"><?php echo $stats['total_assignments']['value'] . " " . $stats['total_assignments']['label']; ?></div>
                </div>

                <!-- Upcoming Exams -->
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Upcoming Exams</span>
                        <i class="ph ph-graduation-cap stat-icon"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['upcoming_exams']['value']; ?></div>
                    <div class="stat-label"><?php echo $stats['upcoming_exams']['label']; ?></div>
                </div>

                <!-- Completion Rate -->
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Completion Rate</span>
                        <i class="ph ph-trend-up stat-icon"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['completion_rate']['value'] . $stats['completion_rate']['label']; ?></div>
                    <div class="progress-bar-container">
                        <div class="progress-bar" style="width: <?php echo $stats['completion_rate']['progress']; ?>%; background-color: <?php echo $stats['completion_rate']['progress'] > 0 ? 'var(--primary-color)' : '#cbd5e1'; ?>"></div>
                    </div>
                </div>

                <!-- Study Hours -->
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Study Hours</span>
                        <i class="ph ph-calendar-blank stat-icon"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['study_hours']['value']; ?>h</div>
                    <div class="stat-label"><?php echo $stats['study_hours']['label']; ?></div>
                </div>
            </div>

            <div class="wide-cards-grid">
                <!-- Upcoming Assignments List -->
                <div class="wide-card">
                    <h2 class="wide-card-title">Upcoming Assignments</h2>
                    <p class="wide-card-subtitle">Your next assignments and deadlines</p>
                    <div class="empty-state">
                        No upcoming assignments
                    </div>
                </div>

                <!-- Upcoming Exams List -->
                <div class="wide-card">
                    <h2 class="wide-card-title">Upcoming Exams</h2>
                    <p class="wide-card-subtitle">Your next examinations</p>
                    <div class="empty-state">
                        No upcoming exams
                    </div>
                </div>
            </div>
        </div>
        
        <button class="help-btn"><i class="ph ph-question"></i></button>
    </main>
</body>
</html>
