<?php
include 'dbConn.php';
session_start();

// Ensure the user is logged in
$studentID = $_SESSION['studentID'] ?? null;
if (!$studentID) {
    header('Location: login.php');
    exit;
}

// Curated Avatar Gradient Themes
$avatarThemes = [
    'indigo'  => ['label' => 'Indigo',       'gradient' => 'linear-gradient(135deg, #6366f1 0%, #4f46e5 100%)', 'shadow' => 'rgba(79, 70, 229, 0.25)'],
    'violet'  => ['label' => 'Royal Purple', 'gradient' => 'linear-gradient(135deg, #9333ea 0%, #7c3aed 100%)', 'shadow' => 'rgba(124, 58, 237, 0.25)'],
    'blue'    => ['label' => 'Ocean Blue',   'gradient' => 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)', 'shadow' => 'rgba(29, 78, 216, 0.25)'],
    'emerald' => ['label' => 'Emerald',      'gradient' => 'linear-gradient(135deg, #10b981 0%, #047857 100%)', 'shadow' => 'rgba(4, 120, 87, 0.25)'],
    'amber'   => ['label' => 'Sunset Amber', 'gradient' => 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)', 'shadow' => 'rgba(217, 119, 6, 0.25)'],
    'rose'    => ['label' => 'Crimson Rose', 'gradient' => 'linear-gradient(135deg, #f43f5e 0%, #be123c 100%)', 'shadow' => 'rgba(190, 18, 60, 0.25)'],
    'cyan'    => ['label' => 'Teal Cyan',    'gradient' => 'linear-gradient(135deg, #06b6d4 0%, #0e7490 100%)', 'shadow' => 'rgba(14, 116, 144, 0.25)'],
    'slate'   => ['label' => 'Midnight',     'gradient' => 'linear-gradient(135deg, #475569 0%, #1e293b 100%)', 'shadow' => 'rgba(30, 41, 59, 0.25)'],
];

// Ensure avatarColor column exists safely in PostgreSQL
try {
    $conn->exec('ALTER TABLE "Student" ADD COLUMN IF NOT EXISTS "avatarColor" VARCHAR(20) DEFAULT \'indigo\'');
} catch (PDOException $e) {}

// Handle Async JSON Request for Instant Avatar Color Save
$rawInput = file_get_contents('php://input');
$jsonPayload = json_decode($rawInput, true);

if ($jsonPayload && isset($jsonPayload['action']) && $jsonPayload['action'] === 'updateAvatarColor') {
    header('Content-Type: application/json');
    $chosenColor = trim($jsonPayload['avatarColor'] ?? 'indigo');
    if (!isset($avatarThemes[$chosenColor])) {
        $chosenColor = 'indigo';
    }

    try {
        $colorStmt = $conn->prepare('UPDATE "Student" SET "avatarColor" = :color WHERE "studentID" = :studentID');
        $ok = $colorStmt->execute([':color' => $chosenColor, ':studentID' => $studentID]);
        $_SESSION['avatarColor'] = $chosenColor;
        echo json_encode(['success' => (bool)$ok, 'avatarColor' => $chosenColor]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to save avatar color.']);
    }
    exit;
}

$toastMsg = '';
$toastType = 'success';

// Fetch Current Profile Data
$profile = [
    'first_name'   => '',
    'last_name'    => '',
    'email'        => '',
    'password'     => '',
    'phoneNo'      => '',
    'avatar_color' => 'indigo'
];

try {
    $stmt = $conn->prepare('SELECT "firstName", "lastName", "email", "password", "phoneNo", "avatarColor" FROM "Student" WHERE "studentID" = :studentID');
    $stmt->execute([':studentID' => $studentID]);
    $studentData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($studentData) {
        $dbColor = $studentData['avatarColor'] ?? 'indigo';
        if (!isset($avatarThemes[$dbColor])) {
            $dbColor = 'indigo';
        }
        $profile = [
            'first_name'   => $studentData['firstName'] ?? '',
            'last_name'    => $studentData['lastName'] ?? '',
            'email'        => $studentData['email'] ?? '',
            'password'     => $studentData['password'] ?? '',
            'phoneNo'      => $studentData['phoneNo'] ?? '',
            'avatar_color' => $dbColor
        ];
        $_SESSION['avatarColor'] = $dbColor;
    }
} catch (PDOException $e) {
    $toastMsg = 'Failed to load profile data.';
    $toastType = 'error';
}

// Fetch Quick Academic Summary Stats for Hero Banner
$accountStats = [
    'semesters' => 0,
    'courses'   => 0,
    'items'     => 0
];

try {
    $statStmt = $conn->prepare('
        SELECT
            (SELECT COUNT(*) FROM "SemesterPlan" WHERE "studentID" = :sid1) AS sem_count,
            (SELECT COUNT(*) FROM "Course" c JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" WHERE s."studentID" = :sid2) AS course_count,
            (
                (SELECT COUNT(*) FROM "Assignment" a JOIN "Course" c ON a."courseID" = c."courseID" JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" WHERE s."studentID" = :sid3) +
                (SELECT COUNT(*) FROM "Exam" e JOIN "Course" c ON e."courseID" = c."courseID" JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" WHERE s."studentID" = :sid4)
            ) AS item_count
    ');
    $statStmt->execute([
        ':sid1' => $studentID,
        ':sid2' => $studentID,
        ':sid3' => $studentID,
        ':sid4' => $studentID
    ]);
    $statRow = $statStmt->fetch(PDO::FETCH_ASSOC);
    if ($statRow) {
        $accountStats['semesters'] = (int)$statRow['sem_count'];
        $accountStats['courses']   = (int)$statRow['course_count'];
        $accountStats['items']     = (int)$statRow['item_count'];
    }
} catch (PDOException $e) {}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ACTION: UPDATE PERSONAL INFO
    if (isset($_POST['update_profile'])) {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $phoneNo = trim($_POST['phoneNo'] ?? '');
        $avatarColor = trim($_POST['avatar_color'] ?? $profile['avatar_color']);
        if (!isset($avatarThemes[$avatarColor])) {
            $avatarColor = 'indigo';
        }

        if ($firstName && $lastName) {
            try {
                $updateStmt = $conn->prepare('
                    UPDATE "Student" 
                    SET "firstName" = :firstName, 
                        "lastName" = :lastName, 
                        "phoneNo" = :phoneNo,
                        "avatarColor" = :avatarColor
                    WHERE "studentID" = :studentID
                ');
                $updateStmt->execute([
                    ':firstName'   => $firstName,
                    ':lastName'    => $lastName,
                    ':phoneNo'     => $phoneNo,
                    ':avatarColor' => $avatarColor,
                    ':studentID'   => $studentID
                ]);
                
                $profile['first_name'] = $firstName;
                $profile['last_name'] = $lastName;
                $profile['phoneNo'] = $phoneNo;
                $profile['avatar_color'] = $avatarColor;
                
                if (isset($_SESSION['firstName'])) {
                    $_SESSION['firstName'] = $firstName;
                }
                $_SESSION['avatarColor'] = $avatarColor;
                
                $toastMsg = 'Personal information updated successfully.';
                $toastType = 'success';
            } catch (PDOException $e) {
                $toastMsg = 'Failed to update profile information.';
                $toastType = 'error';
            }
        } else {
            $toastMsg = 'First Name and Last Name are required.';
            $toastType = 'error';
        }
    }
    
    // ACTION: CHANGE PASSWORD
    if (isset($_POST['change_password'])) {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!$currentPassword || !$newPassword || !$confirmPassword) {
            $toastMsg = 'All password fields are required.';
            $toastType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $toastMsg = 'New password and confirm password do not match.';
            $toastType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $toastMsg = 'New password must be at least 8 characters long.';
            $toastType = 'error';
        } else {
            $isPasswordCorrect = password_verify($currentPassword, $profile['password']) || $currentPassword === $profile['password'];

            if (!$isPasswordCorrect) {
                $toastMsg = 'Incorrect current password.';
                $toastType = 'error';
            } elseif ($currentPassword === $newPassword) {
                $toastMsg = 'New password must be different from your current password.';
                $toastType = 'error';
            } else {
                try {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pwdStmt = $conn->prepare('UPDATE "Student" SET "password" = :password WHERE "studentID" = :studentID');
                    $pwdStmt->execute([
                        ':password'  => $hashedPassword,
                        ':studentID' => $studentID
                    ]);
                    
                    $profile['password'] = $hashedPassword;
                    $toastMsg = 'Password changed successfully.';
                    $toastType = 'success';
                } catch (PDOException $e) {
                    $toastMsg = 'Failed to update password. Please try again.';
                    $toastType = 'error';
                }
            }
        }
    }

    // ACTION: DELETE ACCOUNT
    if (isset($_POST['delete_account'])) {
        $confirmPhrase = trim($_POST['delete_confirm_text'] ?? '');
        $verifyPassword = $_POST['delete_confirm_password'] ?? '';

        $isPasswordCorrect = password_verify($verifyPassword, $profile['password']) || $verifyPassword === $profile['password'];

        if ($confirmPhrase !== 'DELETE') {
            $toastMsg = 'Please type DELETE in uppercase to confirm account deletion.';
            $toastType = 'error';
        } elseif (!$verifyPassword || !$isPasswordCorrect) {
            $toastMsg = 'Incorrect password. Account deletion cancelled.';
            $toastType = 'error';
        } else {
            try {
                $conn->beginTransaction();

                $cleanupQueries = [
                    'DELETE FROM "AnalyticsCourseHistory" WHERE "semesterID" IN (SELECT "semesterID" FROM "SemesterPlan" WHERE "studentID" = :sid)',
                    'DELETE FROM "AnalyticsHistory" WHERE "studentID" = :sid',
                    'DELETE FROM "Notification" WHERE "studentID" = :sid',
                    'DELETE FROM "NotificationSetting" WHERE "studentID" = :sid',
                    'DELETE FROM "Schedule" WHERE "studentID" = :sid',
                    'DELETE FROM "SubTask" WHERE "assignmentID" IN (SELECT a."assignmentID" FROM "Assignment" a JOIN "Course" c ON a."courseID" = c."courseID" JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" WHERE s."studentID" = :sid)',
                    'DELETE FROM public."StudyTask" WHERE "examID" IN (SELECT e."examID" FROM "Exam" e JOIN "Course" c ON e."courseID" = c."courseID" JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" WHERE s."studentID" = :sid)',
                    'DELETE FROM "Assignment" WHERE "courseID" IN (SELECT c."courseID" FROM "Course" c JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" WHERE s."studentID" = :sid)',
                    'DELETE FROM "Exam" WHERE "courseID" IN (SELECT c."courseID" FROM "Course" c JOIN "SemesterPlan" s ON c."semesterID" = s."semesterID" WHERE s."studentID" = :sid)',
                    'DELETE FROM "Course" WHERE "semesterID" IN (SELECT "semesterID" FROM "SemesterPlan" WHERE "studentID" = :sid)',
                    'DELETE FROM "SemesterPlan" WHERE "studentID" = :sid',
                    'DELETE FROM "Student" WHERE "studentID" = :sid'
                ];

                foreach ($cleanupQueries as $sql) {
                    $delStmt = $conn->prepare($sql);
                    $delStmt->execute([':sid' => $studentID]);
                }

                $conn->commit();

                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(
                        session_name(),
                        '',
                        time() - 42000,
                        $params['path'],
                        $params['domain'],
                        $params['secure'],
                        $params['httponly']
                    );
                }
                session_destroy();

                header('Location: login.php?account_deleted=1');
                exit;
            } catch (PDOException $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $toastMsg = 'Unable to delete account right now. Please try again.';
                $toastType = 'error';
            }
        }
    }
}

// Compute avatar initials & active theme
$firstInitial = strtoupper(mb_substr(trim($profile['first_name']), 0, 1));
$lastInitial  = strtoupper(mb_substr(trim($profile['last_name']), 0, 1));
$initials     = ($firstInitial . $lastInitial) ?: 'ST';
$fullName     = trim($profile['first_name'] . ' ' . $profile['last_name']);
$activeTheme  = $avatarThemes[$profile['avatar_color']] ?? $avatarThemes['indigo'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - PlanGenius</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body>
    <?php 
    $current_page = 'profile';
    include 'includes/sidebar.php'; 
    ?>

    <main class="main-content">
        <?php include 'includes/header.php'; ?>

        <div class="dashboard-container">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Personal Profile</h1>
                    <p class="page-subtitle">Manage your account details, avatar theme, and security settings</p>
                </div>
            </div>

            <div class="profile-container">

                <!-- Profile Overview Hero Card with Lightweight Avatar Color Picker -->
                <div class="profile-hero-card">
                    <div class="profile-hero-left">
                        <div class="profile-hero-avatar" id="heroAvatarPreview"
                             style="background: <?= htmlspecialchars($activeTheme['gradient']) ?>; box-shadow: 0 6px 16px <?= htmlspecialchars($activeTheme['shadow']) ?>;">
                            <?= htmlspecialchars($initials) ?>
                        </div>
                        <div class="profile-hero-info">
                            <h2 id="heroFullName"><?= htmlspecialchars($fullName ?: 'Student Account') ?></h2>
                            <div class="profile-hero-email">
                                <i class="ph-fill ph-envelope-simple" style="color:#6366f1;"></i>
                                <span><?= htmlspecialchars($profile['email']) ?></span>
                            </div>

                            <!-- Avatar Color Swatches -->
                            <div class="avatar-picker-bar">
                                <span class="avatar-picker-label"><i class="ph-fill ph-palette"></i> Avatar Color:</span>
                                <div class="avatar-swatches" role="radiogroup" aria-label="Choose avatar color">
                                    <?php foreach ($avatarThemes as $key => $theme): ?>
                                        <button type="button"
                                                class="avatar-swatch <?= $profile['avatar_color'] === $key ? 'active' : '' ?>"
                                                data-color="<?= htmlspecialchars($key) ?>"
                                                data-gradient="<?= htmlspecialchars($theme['gradient']) ?>"
                                                data-shadow="<?= htmlspecialchars($theme['shadow']) ?>"
                                                title="<?= htmlspecialchars($theme['label']) ?>"
                                                aria-label="<?= htmlspecialchars($theme['label']) ?>"
                                                style="background: <?= htmlspecialchars($theme['gradient']) ?>;"
                                                onclick="selectAvatarColor('<?= htmlspecialchars($key) ?>', this)">
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="profile-hero-stats">
                        <div class="profile-stat-pill">
                            <strong><?= $accountStats['semesters'] ?></strong>
                            <span>Semesters</span>
                        </div>
                        <div class="profile-stat-pill">
                            <strong><?= $accountStats['courses'] ?></strong>
                            <span>Courses</span>
                        </div>
                        <div class="profile-stat-pill">
                            <strong><?= $accountStats['items'] ?></strong>
                            <span>Deliverables</span>
                        </div>
                    </div>
                </div>
                
                <!-- Card 1: Personal Information -->
                <div class="profile-card">
                    <h2 class="profile-card-title"><i class="ph ph-user-circle"></i> Personal Information</h2>
                    <p class="profile-card-subtitle">Update your display name and contact phone number</p>

                    <form id="personalInfoForm" method="POST" action="profile.php">
                        <input type="hidden" id="avatar_color_input" name="avatar_color" value="<?= htmlspecialchars($profile['avatar_color']) ?>">

                        <div class="form-row">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="first_name">First Name</label>
                                <input id="first_name" name="first_name" type="text" class="form-control" 
                                       value="<?= htmlspecialchars($profile['first_name']) ?>" 
                                       data-initial="<?= htmlspecialchars($profile['first_name']) ?>" 
                                       oninput="checkProfileChanges()" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="last_name">Last Name</label>
                                <input id="last_name" name="last_name" type="text" class="form-control" 
                                       value="<?= htmlspecialchars($profile['last_name']) ?>" 
                                       data-initial="<?= htmlspecialchars($profile['last_name']) ?>" 
                                       oninput="checkProfileChanges()" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <div class="input-icon-wrapper">
                                <input id="email" name="email" type="email" class="form-control" value="<?= htmlspecialchars($profile['email']) ?>" readonly>
                                <i class="ph-fill ph-lock-simple input-right-icon" title="Read-only field"></i>
                            </div>
                            <div class="field-helper-text">
                                <i class="ph ph-info"></i> Your registered account email cannot be changed.
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="phoneNo">Phone Number</label>
                            <input id="phoneNo" name="phoneNo" type="tel" class="form-control" 
                                   placeholder="e.g. 0163556695"
                                   value="<?= htmlspecialchars($profile['phoneNo']) ?>" 
                                   data-initial="<?= htmlspecialchars($profile['phoneNo']) ?>" 
                                   oninput="checkProfileChanges()">
                        </div>

                        <div class="buttons-group">
                            <button type="submit" id="saveProfileBtn" name="update_profile" class="btn btn-primary" disabled>
                                <i class="ph ph-floppy-disk"></i> Save Changes
                            </button>
                            <button type="button" id="resetProfileBtn" class="btn btn-outline" style="display:none;" onclick="resetProfileChanges()">
                                Discard
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Card 2: Change Password -->
                <div class="profile-card">
                    <h2 class="profile-card-title"><i class="ph ph-lock-key"></i> Change Password</h2>
                    <p class="profile-card-subtitle">Choose a strong password with at least 8 characters to keep your account secure</p>

                    <form method="POST" action="profile.php">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <div class="input-icon-wrapper">
                                <input id="current_password" name="current_password" type="password" class="form-control" placeholder="Enter your current password" required>
                                <button type="button" class="password-toggle-btn" onclick="togglePasswordField('current_password', this)" aria-label="Toggle password visibility">
                                    <i class="ph ph-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-row" style="margin-bottom: 10px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="new_password">New Password</label>
                                <div class="input-icon-wrapper">
                                    <input id="new_password" name="new_password" type="password" class="form-control" placeholder="Minimum 8 characters" oninput="evaluatePasswordUX()" required>
                                    <button type="button" class="password-toggle-btn" onclick="togglePasswordField('new_password', this)" aria-label="Toggle password visibility">
                                        <i class="ph ph-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="confirm_password">Confirm New Password</label>
                                <div class="input-icon-wrapper">
                                    <input id="confirm_password" name="confirm_password" type="password" class="form-control" placeholder="Re-enter new password" oninput="evaluatePasswordUX()" required>
                                    <button type="button" class="password-toggle-btn" onclick="togglePasswordField('confirm_password', this)" aria-label="Toggle password visibility">
                                        <i class="ph ph-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="password-strength-wrap">
                            <div class="strength-meter-track">
                                <div class="strength-meter-fill" id="strengthMeterFill"></div>
                            </div>
                            <div class="strength-meta-row">
                                <span id="strengthLabelText">Use at least 8 characters with letters and numbers.</span>
                                <span id="passwordMatchText" style="font-weight: 600;"></span>
                            </div>
                        </div>

                        <div class="buttons-group">
                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="ph ph-shield-check"></i> Update Password
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Card 3: Danger Zone (Delete Account) -->
                <div class="danger-zone-card">
                    <div class="danger-zone-header">
                        <div>
                            <h2 class="danger-zone-title">
                                <i class="ph-fill ph-warning-octagon"></i> Delete Account
                            </h2>
                            <p class="danger-zone-desc">
                                Permanently delete your PlanGenius account along with all your semesters, courses, assignments, exams, study schedules, and analytics history. This action cannot be undone.
                            </p>
                        </div>
                        <button type="button" class="btn-danger-solid" onclick="openDeleteAccountModal()">
                            <i class="ph-bold ph-trash"></i> Delete Account
                        </button>
                    </div>
                </div>

            </div>
        </div>

        <!-- Delete Account Confirmation Modal -->
        <div class="modal-overlay" id="deleteAccountOverlay" onclick="closeDeleteAccountModal()"></div>
        <div class="modal" id="deleteAccountModal" style="max-width: 470px;">
            <div class="modal-header">
                <h2 class="modal-title" style="color: #991b1b; display: flex; align-items: center; gap: 8px;">
                    <i class="ph-fill ph-warning-circle" style="color: #dc2626; font-size: 20px;"></i>
                    Confirm Account Deletion
                </h2>
                <button type="button" class="close-modal-btn" onclick="closeDeleteAccountModal()"><i class="ph ph-x"></i></button>
            </div>
            <div class="modal-body">
                <div class="delete-warning-box">
                    <strong>This will permanently erase your account and all data:</strong>
                    <ul>
                        <li><?= $accountStats['semesters'] ?> semester plan(s) and <?= $accountStats['courses'] ?> enrolled course(s)</li>
                        <li><?= $accountStats['items'] ?> assignment(s) &amp; exam(s), subtasks, and study schedules</li>
                        <li>Notification settings, alerts, and weekly analytics history</li>
                    </ul>
                </div>

                <form method="POST" action="profile.php">
                    <div class="form-group">
                        <label for="delete_confirm_password">Verify Current Password</label>
                        <div class="input-icon-wrapper">
                            <input id="delete_confirm_password" name="delete_confirm_password" type="password" class="form-control" placeholder="Enter your password" oninput="validateDeleteForm()" required>
                            <button type="button" class="password-toggle-btn" onclick="togglePasswordField('delete_confirm_password', this)" aria-label="Toggle password visibility">
                                <i class="ph ph-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="delete_confirm_text">Type <strong>DELETE</strong> to confirm</label>
                        <input id="delete_confirm_text" name="delete_confirm_text" type="text" class="form-control" placeholder="DELETE" autocomplete="off" oninput="validateDeleteForm()" required>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                        <button type="button" class="btn btn-outline" onclick="closeDeleteAccountModal()">Cancel</button>
                        <button type="submit" id="confirmDeleteAccountBtn" name="delete_account" class="btn-danger-solid" disabled>
                            <i class="ph-bold ph-trash"></i> Permanently Delete Account
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Consistent Notification Toast Structure -->
        <div class="notification-toast" id="notificationToast">
            <i class="ph-fill ph-check-circle"></i> <span id="notificationMessage"></span>
        </div>
    </main>

    <script>
        let notificationTimeout = null;

        function showNotification(message, type = 'success') {
            const toast = document.getElementById('notificationToast');
            const msgEl = document.getElementById('notificationMessage');
            const iconEl = toast ? toast.querySelector('i') : null;

            if (!toast || !msgEl) return;

            if (notificationTimeout) {
                clearTimeout(notificationTimeout);
            }

            msgEl.innerText = message;
            if (iconEl) {
                iconEl.className = type === 'error' ? 'ph-fill ph-warning-circle' : 'ph-fill ph-check-circle';
            }

            const cls = type === 'error' ? 'error' : 'success';
            toast.classList.remove('success', 'error');
            toast.classList.add(cls, 'show');

            notificationTimeout = setTimeout(() => {
                toast.classList.remove('show', 'success', 'error');
            }, 3000);
        }

        // Instant Avatar Color Selection & Auto-Save
        async function selectAvatarColor(colorKey, btnEl) {
            if (!btnEl) return;

            const gradient = btnEl.getAttribute('data-gradient');
            const shadow = btnEl.getAttribute('data-shadow');

            // 1. Update active swatch state
            document.querySelectorAll('.avatar-swatch').forEach(s => s.classList.remove('active'));
            btnEl.classList.add('active');

            // 2. Sync hidden form input
            const hiddenInput = document.getElementById('avatar_color_input');
            if (hiddenInput) hiddenInput.value = colorKey;

            // 3. Live update Hero Avatar
            const heroAvatar = document.getElementById('heroAvatarPreview');
            if (heroAvatar && gradient) {
                heroAvatar.style.background = gradient;
                if (shadow) heroAvatar.style.boxShadow = `0 6px 16px ${shadow}`;
            }

            // 4. Live update Sidebar Avatar if present
            const sidebarAvatar = document.querySelector('.sidebar .avatar');
            if (sidebarAvatar && gradient) {
                sidebarAvatar.style.background = gradient;
                sidebarAvatar.style.color = '#ffffff';
            }

            // 5. Persist asynchronously
            try {
                const res = await fetch('profile.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'updateAvatarColor', avatarColor: colorKey })
                });
                const data = await res.json();
                if (data.success) {
                    showNotification('Avatar color updated.', 'success');
                }
            } catch (err) {
                // Silent fallback; hidden input will still save on form submit
            }
        }

        // Dirty-state detection + Live Initials Preview for Personal Information form
        function checkProfileChanges() {
            const fields = ['first_name', 'last_name', 'phoneNo'];
            let hasChanges = false;

            fields.forEach(id => {
                const el = document.getElementById(id);
                if (el && el.value !== el.getAttribute('data-initial')) {
                    hasChanges = true;
                }
            });

            // Live update hero initials preview while typing
            const fn = (document.getElementById('first_name')?.value || '').trim();
            const ln = (document.getElementById('last_name')?.value || '').trim();
            const heroAvatar = document.getElementById('heroAvatarPreview');
            if (heroAvatar) {
                const initials = ((fn.charAt(0) + ln.charAt(0)).toUpperCase()) || 'ST';
                heroAvatar.innerText = initials;
            }

            const saveBtn = document.getElementById('saveProfileBtn');
            const resetBtn = document.getElementById('resetProfileBtn');
            if (saveBtn) saveBtn.disabled = !hasChanges;
            if (resetBtn) resetBtn.style.display = hasChanges ? 'inline-flex' : 'none';
        }

        function resetProfileChanges() {
            ['first_name', 'last_name', 'phoneNo'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = el.getAttribute('data-initial') || '';
            });
            checkProfileChanges();
        }

        // Show / Hide password toggle
        function togglePasswordField(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            if (!input || !icon) return;

            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'ph ph-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'ph ph-eye';
            }
        }

        // Real-time password strength & match evaluation
        function evaluatePasswordUX() {
            const pwd = document.getElementById('new_password')?.value || '';
            const confirm = document.getElementById('confirm_password')?.value || '';
            const fill = document.getElementById('strengthMeterFill');
            const label = document.getElementById('strengthLabelText');
            const matchText = document.getElementById('passwordMatchText');

            if (fill && label) {
                if (pwd.length === 0) {
                    fill.style.width = '0%';
                    label.innerText = 'Use at least 8 characters with letters and numbers.';
                    label.style.color = '#64748b';
                } else {
                    let score = 0;
                    if (pwd.length >= 8) score++;
                    if (/[A-Z]/.test(pwd) && /[a-z]/.test(pwd)) score++;
                    if (/[0-9]/.test(pwd)) score++;
                    if (/[^A-Za-z0-9]/.test(pwd)) score++;

                    if (pwd.length < 8) {
                        fill.style.width = '25%';
                        fill.style.backgroundColor = '#ef4444';
                        label.innerText = 'Too short (minimum 8 characters required)';
                        label.style.color = '#dc2626';
                    } else if (score <= 2) {
                        fill.style.width = '55%';
                        fill.style.backgroundColor = '#f59e0b';
                        label.innerText = 'Fair strength — add uppercase, numbers, or symbols';
                        label.style.color = '#d97706';
                    } else if (score === 3) {
                        fill.style.width = '80%';
                        fill.style.backgroundColor = '#3b82f6';
                        label.innerText = 'Good password strength';
                        label.style.color = '#2563eb';
                    } else {
                        fill.style.width = '100%';
                        fill.style.backgroundColor = '#10b981';
                        label.innerText = 'Strong password';
                        label.style.color = '#059669';
                    }
                }
            }

            if (matchText) {
                if (!confirm) {
                    matchText.innerText = '';
                } else if (pwd === confirm) {
                    matchText.innerHTML = '<i class="ph-bold ph-check"></i> Passwords match';
                    matchText.style.color = '#059669';
                } else {
                    matchText.innerHTML = '<i class="ph-bold ph-x"></i> Passwords do not match';
                    matchText.style.color = '#dc2626';
                }
            }
        }

        // Delete Account Modal controls
        function openDeleteAccountModal() {
            document.getElementById('delete_confirm_password').value = '';
            document.getElementById('delete_confirm_text').value = '';
            validateDeleteForm();
            document.getElementById('deleteAccountOverlay').classList.add('active');
            document.getElementById('deleteAccountModal').classList.add('active');
        }

        function closeDeleteAccountModal() {
            document.getElementById('deleteAccountOverlay').classList.remove('active');
            document.getElementById('deleteAccountModal').classList.remove('active');
        }

        function validateDeleteForm() {
            const pwd = document.getElementById('delete_confirm_password')?.value || '';
            const phrase = (document.getElementById('delete_confirm_text')?.value || '').trim();
            const submitBtn = document.getElementById('confirmDeleteAccountBtn');
            if (submitBtn) {
                submitBtn.disabled = !(pwd.length > 0 && phrase === 'DELETE');
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Apply saved avatar color to sidebar on initial load
            const activeSwatch = document.querySelector('.avatar-swatch.active');
            const sidebarAvatar = document.querySelector('.sidebar .avatar');
            if (activeSwatch && sidebarAvatar) {
                sidebarAvatar.style.background = activeSwatch.getAttribute('data-gradient');
                sidebarAvatar.style.color = '#ffffff';
            }

            const serverMessage = <?= json_encode($toastMsg) ?>;
            const serverType = <?= json_encode($toastType) ?>;
            if (serverMessage) {
                showNotification(serverMessage, serverType);
            }
        });
    </script>
</body>
</html>