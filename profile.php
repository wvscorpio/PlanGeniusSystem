<?php
include 'dbConn.php';
session_start();

// Ensure the user is logged in
$studentID = $_SESSION['studentID'] ?? null;
if (!$studentID) {
    header('Location: login.php');
    exit;
}

$toastMsg = '';
$toastType = 'success';

// Fetch Current Profile Data
$profile = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'password' => '',
    'phoneNo' => ''
];

try {
    $stmt = $conn->prepare('SELECT "firstName", "lastName", "email", "password", "phoneNo" FROM "Student" WHERE "studentID" = :studentID');
    $stmt->execute([':studentID' => $studentID]);
    $studentData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($studentData) {
        $profile = [
            'first_name' => $studentData['firstName'] ?? '',
            'last_name'  => $studentData['lastName'] ?? '',
            'email'      => $studentData['email'] ?? '',
            'password'   => $studentData['password'] ?? '',
            'phoneNo'    => $studentData['phoneNo'] ?? ''
        ];
    }
} catch (PDOException $e) {
    $toastMsg = 'Failed to load profile data.';
    $toastType = 'error';
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ACTION: UPDATE PERSONAL INFO
    if (isset($_POST['update_profile'])) {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $phoneNo = trim($_POST['phoneNo'] ?? '');

        if ($firstName && $lastName) {
            try {
                $updateStmt = $conn->prepare('
                    UPDATE "Student" 
                    SET "firstName" = :firstName, 
                        "lastName" = :lastName, 
                        "phoneNo" = :phoneNo 
                    WHERE "studentID" = :studentID
                ');
                $updateStmt->execute([
                    ':firstName' => $firstName,
                    ':lastName'  => $lastName,
                    ':phoneNo'   => $phoneNo,
                    ':studentID' => $studentID
                ]);
                
                $profile['first_name'] = $firstName;
                $profile['last_name'] = $lastName;
                $profile['phoneNo'] = $phoneNo;
                
                if (isset($_SESSION['firstName'])) {
                    $_SESSION['firstName'] = $firstName;
                }
                
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
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - PlanGenius</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        .profile-container {
            display: flex;
            flex-direction: column;
            gap: 24px;
            max-width: 650px;
        }
        .profile-card {
            background-color: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 30px;
            box-shadow: var(--shadow-sm);
        }
        .profile-card-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            flex: 1;
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 14px;
            color: #334155;
        }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 15px;
            color: #1e293b;
            transition: border-color 0.2s;
        }
        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .form-control[readonly] {
            background-color: #f8fafc;
            color: #64748b;
            cursor: not-allowed;
            border-color: #e2e8f0;
        }
        .buttons-group {
            display: flex;
            gap: 12px;
            margin-top: 10px;
        }
        .password-requirements {
            font-size: 12px;
            color: #64748b;
            margin-top: -12px;
            margin-bottom: 20px;
        }
    </style>
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
                    <p class="page-subtitle">View and manage your account details</p>
                </div>
            </div>

            <div class="profile-container">
                
                <div class="profile-card">
                    <h2 class="profile-card-title"><i class="ph ph-user-circle"></i> Personal Information</h2>

                    <form method="POST" action="profile.php">
                        <div class="form-row">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="first_name">First Name</label>
                                <input id="first_name" name="first_name" type="text" class="form-control" value="<?= htmlspecialchars($profile['first_name']) ?>" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="last_name">Last Name</label>
                                <input id="last_name" name="last_name" type="text" class="form-control" value="<?= htmlspecialchars($profile['last_name']) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input id="email" name="email" type="email" class="form-control" value="<?= htmlspecialchars($profile['email']) ?>" readonly title="Email cannot be changed">
                        </div>

                        <div class="form-group">
                            <label for="phoneNo">Phone Number</label>
                            <input id="phoneNo" name="phoneNo" type="tel" class="form-control" value="<?= htmlspecialchars($profile['phoneNo']) ?>">
                        </div>

                        <div class="buttons-group">
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="ph ph-floppy-disk"></i> Update Profile
                            </button>
                        </div>
                    </form>
                </div>

                <div class="profile-card">
                    <h2 class="profile-card-title"><i class="ph ph-lock-key"></i> Change Password</h2>

                    <form method="POST" action="profile.php">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input id="current_password" name="current_password" type="password" class="form-control" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="new_password">New Password</label>
                                <input id="new_password" name="new_password" type="password" class="form-control" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="confirm_password">Confirm New Password</label>
                                <input id="confirm_password" name="confirm_password" type="password" class="form-control" required>
                            </div>
                        </div>
                        <div class="password-requirements">
                            Password must be at least 8 characters long.
                        </div>

                        <div class="buttons-group">
                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="ph ph-shield-check"></i> Update Password
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </main>

    <script>
        function showNotification(message, type = 'success') {
            let toast = document.querySelector('.notification-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.className = 'notification-toast';
                document.body.appendChild(toast);
            }
            const icon = type === 'success' 
                ? '<i class="ph-fill ph-check-circle" style="color: #16a34a;"></i>' 
                : '<i class="ph-fill ph-warning-circle" style="color: #dc2626;"></i>';
            
            toast.innerHTML = `${icon}<span>${message}</span>`;
            toast.classList.add('show');
            
            clearTimeout(toast.timeoutId);
            toast.timeoutId = setTimeout(() => {
                toast.classList.remove('show');
            }, 4000);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const serverMessage = <?= json_encode($toastMsg) ?>;
            const serverType = <?= json_encode($toastType) ?>;
            if (serverMessage) {
                showNotification(serverMessage, serverType);
            }
        });
    </script>
</body>
</html>