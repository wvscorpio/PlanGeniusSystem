<?php
$current_page = 'assignments';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PlanGenius — Assignments</title>
  <link rel="stylesheet" href="css/style.css">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <style>
    .page-header{ padding:28px 0 }
    .page-title{ font-size:28px;margin:0 }
    .page-subtitle{ color:#7b7f87;margin:6px 0 18px }
    .assignments-container{ padding:24px }
    .actions{ display:flex;justify-content:flex-end }
    .add-btn{ background:#060412;color:#fff;padding:10px 18px;border-radius:10px;border:none;font-weight:700;cursor:pointer }

    .empty-card{ border-radius:14px;border:1px solid #eceff3;padding:40px;text-align:center;color:#9aa0a6 }
    .empty-card i{ font-size:48px;margin-bottom:10px;color:#97a0af }

    .content-wrap{ padding:32px }
  </style>
</head>
<body>
  <?php include 'includes/sidebar.php'; ?>
  <main class="main-content">
    <?php include 'includes/header.php'; ?>

    <div class="content-wrap">
      <div class="page-header">
        <h1 class="page-title">Assignment Management</h1>
        <p class="page-subtitle">Manage your assignments with priority levels and subtasks</p>
      </div>

      <div class="actions">
        <button class="add-btn">+ Add Assignment</button>
      </div>

      <div class="assignments-container">
        <div class="empty-card">
          <i class="ph ph-file-text"></i>
          <div style="margin-top:12px">No assignments yet. Click "Add Assignment" to get started!</div>
        </div>
      </div>
    </div>

    <button class="help-btn"><i class="ph ph-question"></i></button>
  </main>
</body>
</html>