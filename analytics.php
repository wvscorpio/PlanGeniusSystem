<?php
$current_page = 'analytics';

// Placeholder data: in future this can be computed from assignments/exams data
$stats = [
    'overall_completion' => 0.0,
    'average_course_progress' => 0.0,
    'upcoming_exams' => 0,
    'total_assignments' => 0,
    'courses' => []
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PlanGenius — Analytics</title>
  <link rel="stylesheet" href="css/style.css">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <style>
    .content-wrap{ padding:28px }
    .page-title{ font-size:28px;margin:0 }
    .page-subtitle{ color:#7b7f87;margin:6px 0 18px }

    .grid{ display:grid;grid-template-columns:repeat(3,1fr);gap:18px }
    .card{ background:#fff;padding:18px;border-radius:12px;border:1px solid #eef2f6 }
    .card h3{ margin:0 0 6px }
    .big{ font-size:28px;font-weight:800 }
    .progress{ height:10px;background:#eef2f6;border-radius:999px;margin-top:10px }
    .progress-bar{ height:10px;border-radius:999px;background:linear-gradient(90deg,#5b3bff,#00b4d8); width:0% }

    .wide-grid{ display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:18px }
    .wide{ background:#fff;padding:22px;border-radius:12px;border:1px solid #eef2f6;min-height:220px }

    @media (max-width:1000px){ .grid{ grid-template-columns:1fr } .wide-grid{ grid-template-columns:1fr } }
  </style>
</head>
<body>
  <?php include 'includes/sidebar.php'; ?>
  <main class="main-content">
    <?php include 'includes/header.php'; ?>

    <div class="content-wrap">
      <div class="page-header">
        <h1 class="page-title">Analytics Dashboard</h1>
        <p class="page-subtitle">View your progress statistics and performance insights</p>
      </div>

      <div class="grid">
        <div class="card">
          <h3>Overall Completion</h3>
          <div class="big"><?php echo number_format($stats['overall_completion'],1); ?>%</div>
          <div class="progress"><div class="progress-bar" style="width:<?php echo $stats['overall_completion']; ?>%"></div></div>
          <div style="color:#7b7f87;margin-top:8px"><?php echo $stats['total_assignments']; ?> of 0 assignments</div>
        </div>

        <div class="card">
          <h3>Average Course Progress</h3>
          <div class="big"><?php echo number_format($stats['average_course_progress'],1); ?>%</div>
          <div class="progress"><div class="progress-bar" style="width:<?php echo $stats['average_course_progress']; ?>%"></div></div>
          <div style="color:#7b7f87;margin-top:8px">Across <?php echo count($stats['courses']); ?> courses</div>
        </div>

        <div class="card">
          <h3>Upcoming Exams</h3>
          <div class="big"><?php echo $stats['upcoming_exams']; ?></div>
          <div style="color:#7b7f87;margin-top:8px">Total exams: <?php echo $stats['upcoming_exams']; ?></div>
        </div>
      </div>

      <div class="wide-grid">
        <div class="wide">
          <h3>Task Status Distribution</h3>
          <p style="color:#7b7f87">Breakdown of assignment statuses</p>
          <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#b0b7be">No data yet</div>
        </div>

        <div class="wide">
          <h3>Priority Distribution</h3>
          <p style="color:#7b7f87">Pending tasks by priority level</p>
          <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#b0b7be">No data yet</div>
        </div>
      </div>

      <div class="wide" style="margin-top:18px">
        <h3>Course Progress Overview</h3>
        <p style="color:#7b7f87">Completion rate for each course</p>
        <div style="height:180px;display:flex;align-items:center;justify-content:center;color:#b0b7be">No course data available yet</div>
      </div>

    </div>

    <button class="help-btn"><i class="ph ph-question"></i></button>
  </main>
</body>
</html>