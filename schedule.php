<?php
// Semester period configuration (change as needed)
$semester_start = new DateTime('2026-01-01');
$semester_end = new DateTime('2026-06-30');

// Determine requested month/year from query or default to current
$y = isset($_GET['y']) ? intval($_GET['y']) : (int)date('Y');
$m = isset($_GET['m']) ? intval($_GET['m']) : (int)date('n');

// Normalize month/year
if ($m < 1) { $m = 1; }
if ($m > 12) { $m = 12; }

$requested = DateTime::createFromFormat('!Y-n', "$y-$m");
if (!$requested) { $requested = new DateTime(); }

// Helper: month start and end
$month_start = new DateTime($requested->format('Y-m-01'));
$month_end = (clone $month_start)->modify('last day of this month');

// Determine navigation availability
$prev_month = (clone $month_start)->modify('-1 month');
$next_month = (clone $month_start)->modify('+1 month');
$can_prev = $prev_month->format('Y-m-d') >= $semester_start->format('Y-m-d') || (clone $prev_month)->modify('last day of this month')->format('Y-m-d') >= $semester_start->format('Y-m-d');
$can_next = $next_month->format('Y-m-d') <= $semester_end->format('Y-m-d') || (clone $next_month)->format('Y-m-d') <= $semester_end->format('Y-m-d');

// If requested month outside semester, clamp to nearest valid month
if ($month_end < $semester_start) {
    $month_start = new DateTime($semester_start->format('Y-m-01'));
    $month_end = (clone $month_start)->modify('last day of this month');
    $requested = clone $month_start;
}
if ($month_start > $semester_end) {
    $month_start = new DateTime($semester_end->format('Y-m-01'));
    $month_end = (clone $month_start)->modify('last day of this month');
    $requested = clone $month_start;
}

// Load events from JSON file
$events = [];
$schedule_file = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'schedule.json';
if (file_exists($schedule_file)) {
    $raw = file_get_contents($schedule_file);
    $arr = json_decode($raw, true);
    if (is_array($arr)) {
        foreach ($arr as $e) {
            // expect date in YYYY-MM-DD
            if (!empty($e['date'])) {
                $events[$e['date']][] = $e;
            }
        }
    }
}

// Build calendar matrix (weeks starting Monday)
$first_day_weekday = (int)$month_start->format('N'); // 1 (Mon) -7
$days_in_month = (int)$month_start->format('t');

$weeks = [];
$week = [];
// prepend empty days until first weekday
for ($i = 1; $i < $first_day_weekday; $i++) { $week[] = null; }
for ($day = 1; $day <= $days_in_month; $day++) {
    $date = $month_start->format('Y-m-') . str_pad($day,2,'0',STR_PAD_LEFT);
    $week[] = $date;
    if (count($week) === 7) { $weeks[] = $week; $week = []; }
}
if (count($week) > 0) {
    while (count($week) < 7) { $week[] = null; }
    $weeks[] = $week;
}

function monthNavLink($dt) {
    return '?y=' . $dt->format('Y') . '&m=' . $dt->format('n');
}

$current_page = 'schedule';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PlanGenius — Schedule</title>
  <link rel="stylesheet" href="css/style.css">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <style>
    .content-wrap{ padding:28px }
    .page-header{ margin-bottom:12px }
    .page-title{ font-size:28px;margin:0 }
    .page-subtitle{ color:#7b7f87;margin:6px 0 18px }

    .nav-row{ display:flex;align-items:center;justify-content:space-between;margin-bottom:18px }
    .month-nav{ display:flex;align-items:center;gap:12px }
    .nav-btn{ padding:8px 12px;border-radius:8px;border:1px solid #e6e9ef;background:#fff;cursor:pointer }
    .nav-btn.disabled{ opacity:0.4; cursor:not-allowed }

    .calendar{ background:#fff;border-radius:12px;padding:18px;border:1px solid #eef2f6 }
    .calendar-grid{ display:grid;grid-template-columns:repeat(7,1fr);gap:8px }
    .dow{ text-align:center;color:#9aa0a6;font-weight:600 }
    .day-cell{ min-height:110px;border-radius:10px;padding:8px;border:1px solid #f0f3f6;background:#fbfdff;position:relative }
    .day-cell.outside{ background:#f7f9fb;color:#b7bdc4 }
    .date-num{ font-weight:700;color:#111 }
    .events{ margin-top:8px;display:flex;flex-direction:column;gap:6px }
    .event-pill{ background:#eef3ff;color:#15367a;padding:4px 8px;border-radius:8px;font-size:13px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis }

    .all-items{ margin-top:20px;background:#fff;padding:22px;border-radius:12px;border:1px solid #eef2f6 }
    .empty-state{ text-align:center;padding:36px;color:#9aa0a6 }

    @media (max-width:800px){ .calendar-grid .day-cell{ min-height:90px } }
  </style>
</head>
<body>
  <?php include 'includes/sidebar.php'; ?>
  <main class="main-content">
    <?php include 'includes/header.php'; ?>

    <div class="content-wrap">
      <div class="page-header">
        <h1 class="page-title">Study Schedule</h1>
        <p class="page-subtitle">Automatically generated schedule based on your assignments and exams</p>
      </div>

      <div class="nav-row">
        <div>
          <strong>This Month</strong>
          <div style="color:#7b7f87"><?php echo $month_start->format('M d, Y'); ?> - <?php echo $month_end->format('M d, Y'); ?></div>
        </div>

        <div class="month-nav">
          <?php if ($can_prev): ?>
            <a class="nav-btn" href="<?php echo monthNavLink($prev_month); ?>">&larr; Prev</a>
          <?php else: ?>
            <span class="nav-btn disabled">&larr; Prev</span>
          <?php endif; ?>

          <div style="font-weight:700"><?php echo $month_start->format('F Y'); ?></div>

          <?php if ($can_next): ?>
            <a class="nav-btn" href="<?php echo monthNavLink($next_month); ?>">Next &rarr;</a>
          <?php else: ?>
            <span class="nav-btn disabled">Next &rarr;</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="calendar">
        <div class="calendar-grid">
          <?php
          $dows = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
          foreach ($dows as $dow) { echo "<div class=\"dow\">$dow</div>"; }
          foreach ($weeks as $w) {
              foreach ($w as $cell) {
                  $outside = false;
                  if ($cell === null) {
                      echo '<div class="day-cell outside"></div>';
                      continue;
                  }
                  $cellDate = new DateTime($cell);
                  // if outside semester
                  if ($cellDate < $semester_start || $cellDate > $semester_end) { $outside = true; }
                  $dayNum = (int)$cellDate->format('j');
                  echo '<div class="day-cell' . ($outside ? ' outside' : '') . '" data-date="' . $cell . '">';
                  echo '<div class="date-num">' . $dayNum . '</div>';
                  echo '<div class="events">';
                  if (isset($events[$cell])) {
                      foreach ($events[$cell] as $ev) {
                          echo '<div class="event-pill" onclick="showEvent(' . htmlspecialchars(json_encode($ev), ENT_QUOTES, 'UTF-8') . ')">' . htmlspecialchars($ev['title']) . '</div>';
                      }
                  }
                  echo '</div>';
                  echo '</div>';
              }
          }
          ?>
        </div>
      </div>

      <div class="all-items">
        <h3>All Scheduled Items</h3>
        <p style="color:#7b7f87">Complete list of tasks organized by date</p>
        <div class="empty-state">
          <i class="ph ph-calendar-blank" style="font-size:36px;color:#97a0af"></i>
          <div style="margin-top:8px">No scheduled items yet</div>
          <div style="color:#b0b7be">Add assignments and exams to see them in your schedule</div>
        </div>
      </div>

    </div>

    <button class="help-btn"><i class="ph ph-question"></i></button>
  </main>

  <script>
    function showEvent(ev) {
      if (!ev) return;
      alert(ev.title + "\n\n" + (ev.details || ''));
    }
  </script>
</body>
</html>
