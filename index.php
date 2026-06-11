<?php
session_start();

// If the student is already logged in, redirect them directly to the dashboard
if (isset($_SESSION['studentID'])) {
    header('Location: dashboard.php');
    exit;
} else {
    // Otherwise, direct them to the login page
    header('Location: login.php');
    exit;
}
?>