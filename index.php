<?php
// Default index page - redirects to login or dashboard
session_start();

if (isset($_SESSION['user_id'])) {
    // User is logged in, redirect to dashboard
    header("Location: dashboard.php");
} else {
    // User is not logged in, redirect to login
    header("Location: login.php");
}
exit();
?>
