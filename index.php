<?php
session_start();

// If the student is already logged in, redirect them directly to the dashboard
if (isset($_SESSION['studentID'])) {
    header('Location: academic_structure.php');
    exit;
} else {
    // Otherwise, direct them to the login page
    header('Location: landing_page.php');
    exit;
}
?>